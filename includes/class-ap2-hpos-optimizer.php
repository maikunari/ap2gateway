<?php
/**
 * AP2 HPOS Optimizer
 *
 * Advanced HPOS features and optimizations for agent orders.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * AP2 HPOS Optimizer Class.
 */
class AP2_HPOS_Optimizer {

	/**
	 * Custom table name for agent order indexes.
	 *
	 * @var string
	 */
	private $custom_table;

	/**
	 * Cache group for agent orders.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'ap2_agent_orders';

	/**
	 * Cache expiration time (1 hour).
	 *
	 * @var int
	 */
	const CACHE_EXPIRATION = 3600;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->custom_table = $wpdb->prefix . 'ap2_agent_order_index';

		// Initialize hooks.
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'woocommerce_init', array( $this, 'register_custom_order_tables' ) );
		add_filter( 'woocommerce_order_data_store', array( $this, 'customize_order_data_store' ) );

		// Cache management.
		add_action( 'woocommerce_new_order', array( $this, 'invalidate_cache' ) );
		add_action( 'woocommerce_update_order', array( $this, 'invalidate_cache' ) );
		add_action( 'woocommerce_trash_order', array( $this, 'invalidate_cache' ) );

		// Bulk operations.
		add_action( 'admin_init', array( $this, 'register_bulk_actions' ) );

		// Background processing.
		add_action( 'ap2_process_agent_analytics', array( $this, 'process_agent_analytics_batch' ) );
	}

	/**
	 * Initialize optimizer.
	 */
	public function init() {
		// Create custom index table if needed.
		$this->maybe_create_index_table();

		// Register custom order query vars.
		add_filter( 'woocommerce_order_query_args', array( $this, 'add_custom_query_args' ) );

		// Schedule background tasks.
		if ( ! wp_next_scheduled( 'ap2_optimize_agent_orders' ) ) {
			wp_schedule_event( time(), 'hourly', 'ap2_optimize_agent_orders' );
		}
	}

	/**
	 * Register custom order table columns.
	 */
	public function register_custom_order_tables() {
		if ( ! $this->is_hpos_enabled() ) {
			return;
		}

		// Add custom columns to orders table for frequently accessed agent data.
		add_filter( 'woocommerce_orders_table_datastore_extra_db_rows_for_order', array( $this, 'add_custom_table_data' ), 10, 4 );
		add_filter( 'woocommerce_orders_table_datastore_db_rows_for_order', array( $this, 'save_custom_table_data' ), 10, 4 );
	}

	/**
	 * Create custom index table for agent orders.
	 */
	private function maybe_create_index_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->custom_table} (
			order_id bigint(20) unsigned NOT NULL,
			agent_id varchar(100) NOT NULL,
			mandate_token varchar(100),
			transaction_type varchar(50),
			transaction_id varchar(100),
			payment_timestamp datetime DEFAULT NULL,
			total_amount decimal(10,2) DEFAULT 0,
			processing_time int(11) DEFAULT 0,
			PRIMARY KEY (order_id),
			KEY idx_agent_id (agent_id),
			KEY idx_transaction_type (transaction_type),
			KEY idx_payment_timestamp (payment_timestamp),
			KEY idx_mandate_token (mandate_token)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get agent orders with advanced filtering.
	 *
	 * @param array $args Query arguments.
	 * @return array Orders.
	 */
	public function get_agent_orders( $args = array() ) {
		$defaults = array(
			'limit'           => 10,
			'offset'          => 0,
			'orderby'         => 'date',
			'order'           => 'DESC',
			'status'          => 'any',
			'agent_id'        => '',
			'mandate_token'   => '',
			'date_after'      => '',
			'date_before'     => '',
			'min_amount'      => 0,
			'max_amount'      => 0,
			'transaction_type' => '',
		);

		$args = wp_parse_args( $args, $defaults );

		// Check cache first.
		$cache_key = 'agent_orders_' . md5( serialize( $args ) );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		// Build query.
		$query_args = array(
			'limit'   => $args['limit'],
			'offset'  => $args['offset'],
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
			'status'  => $args['status'],
			'meta_query' => array(
				array(
					'key'   => '_ap2_is_agent_order',
					'value' => 'yes',
				),
			),
		);

		// Add specific agent filtering.
		if ( ! empty( $args['agent_id'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_ap2_agent_id',
				'value' => $args['agent_id'],
			);
		}

		if ( ! empty( $args['mandate_token'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_ap2_mandate_token',
				'value' => $args['mandate_token'],
			);
		}

		if ( ! empty( $args['transaction_type'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_ap2_transaction_type',
				'value' => $args['transaction_type'],
			);
		}

		// Date filtering.
		if ( ! empty( $args['date_after'] ) ) {
			$query_args['date_created'] = '>' . strtotime( $args['date_after'] );
		}

		if ( ! empty( $args['date_before'] ) ) {
			$query_args['date_created'] = '<' . strtotime( $args['date_before'] );
		}

		// Use optimized query with custom index if available.
		if ( $this->is_hpos_enabled() && $this->has_custom_index() ) {
			$orders = $this->query_with_custom_index( $query_args );
		} else {
			$orders = wc_get_orders( $query_args );
		}

		// Cache results.
		wp_cache_set( $cache_key, $orders, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $orders;
	}

	/**
	 * Query orders using custom index table.
	 *
	 * @param array $args Query arguments.
	 * @return array Orders.
	 */
	private function query_with_custom_index( $args ) {
		global $wpdb;

		$where = array( '1=1' );
		$values = array();

		// Build WHERE clause.
		if ( ! empty( $args['meta_query'] ) ) {
			foreach ( $args['meta_query'] as $meta ) {
				if ( '_ap2_agent_id' === $meta['key'] ) {
					$where[] = 'idx.agent_id = %s';
					$values[] = $meta['value'];
				} elseif ( '_ap2_mandate_token' === $meta['key'] ) {
					$where[] = 'idx.mandate_token = %s';
					$values[] = $meta['value'];
				} elseif ( '_ap2_transaction_type' === $meta['key'] ) {
					$where[] = 'idx.transaction_type = %s';
					$values[] = $meta['value'];
				}
			}
		}

		$where_clause = implode( ' AND ', $where );

		// Prepare query.
		$sql = "SELECT idx.order_id
				FROM {$this->custom_table} idx
				WHERE {$where_clause}
				ORDER BY idx.payment_timestamp DESC
				LIMIT %d OFFSET %d";

		$values[] = $args['limit'];
		$values[] = $args['offset'];

		$order_ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );

		// Get full order objects.
		if ( empty( $order_ids ) ) {
			return array();
		}

		return array_map( 'wc_get_order', $order_ids );
	}

	/**
	 * Get agent order statistics with caching.
	 *
	 * @param string $period Time period (day, week, month, year).
	 * @return array Statistics.
	 */
	public function get_agent_statistics( $period = 'month' ) {
		$cache_key = 'agent_stats_' . $period;
		$stats = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $stats ) {
			return $stats;
		}

		$stats = array(
			'total_orders'      => 0,
			'total_revenue'     => 0,
			'unique_agents'     => 0,
			'avg_order_value'   => 0,
			'top_agents'        => array(),
			'mandate_breakdown' => array(),
			'hourly_distribution' => array(),
		);

		// Calculate date range.
		$date_after = '-1 ' . $period;

		// Get orders.
		$orders = $this->get_agent_orders( array(
			'limit'      => -1,
			'date_after' => $date_after,
		) );

		$agents = array();
		$mandates = array();

		foreach ( $orders as $order ) {
			$stats['total_orders']++;
			$stats['total_revenue'] += $order->get_total();

			$agent_id = $order->get_meta( '_ap2_agent_id' );
			$mandate_token = $order->get_meta( '_ap2_mandate_token' );

			// Track unique agents.
			if ( ! isset( $agents[ $agent_id ] ) ) {
				$agents[ $agent_id ] = array(
					'count' => 0,
					'revenue' => 0,
				);
			}
			$agents[ $agent_id ]['count']++;
			$agents[ $agent_id ]['revenue'] += $order->get_total();

			// Track mandate types.
			$mandate_type = substr( $mandate_token, 0, 3 ); // Simple categorization.
			if ( ! isset( $mandates[ $mandate_type ] ) ) {
				$mandates[ $mandate_type ] = 0;
			}
			$mandates[ $mandate_type ]++;

			// Track hourly distribution.
			$hour = $order->get_date_created()->format( 'H' );
			if ( ! isset( $stats['hourly_distribution'][ $hour ] ) ) {
				$stats['hourly_distribution'][ $hour ] = 0;
			}
			$stats['hourly_distribution'][ $hour ]++;
		}

		// Calculate statistics.
		$stats['unique_agents'] = count( $agents );
		$stats['avg_order_value'] = $stats['total_orders'] > 0
			? $stats['total_revenue'] / $stats['total_orders']
			: 0;

		// Get top agents.
		uasort( $agents, function( $a, $b ) {
			return $b['revenue'] - $a['revenue'];
		} );
		$stats['top_agents'] = array_slice( $agents, 0, 5, true );

		$stats['mandate_breakdown'] = $mandates;

		// Cache results.
		wp_cache_set( $cache_key, $stats, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $stats;
	}

	/**
	 * Batch update agent orders.
	 *
	 * @param array  $order_ids Order IDs to update.
	 * @param array  $data Data to update.
	 * @param string $operation Operation type (update, delete, archive).
	 * @return bool Success status.
	 */
	public function batch_update_orders( $order_ids, $data, $operation = 'update' ) {
		if ( empty( $order_ids ) ) {
			return false;
		}

		// Use Action Scheduler for large batches.
		if ( count( $order_ids ) > 50 ) {
			return $this->schedule_batch_update( $order_ids, $data, $operation );
		}

		// Process immediately for small batches.
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			switch ( $operation ) {
				case 'update':
					foreach ( $data as $key => $value ) {
						$order->update_meta_data( $key, $value );
					}
					$order->save();
					break;

				case 'delete':
					$order->delete( true );
					break;

				case 'archive':
					$order->update_status( 'wc-archived' );
					break;
			}

			// Update index.
			$this->update_order_index( $order_id );
		}

		// Clear cache.
		$this->invalidate_cache();

		return true;
	}

	/**
	 * Schedule batch update using Action Scheduler.
	 *
	 * @param array  $order_ids Order IDs.
	 * @param array  $data Update data.
	 * @param string $operation Operation type.
	 * @return bool Success status.
	 */
	private function schedule_batch_update( $order_ids, $data, $operation ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		// Split into chunks.
		$chunks = array_chunk( $order_ids, 25 );

		foreach ( $chunks as $chunk ) {
			as_enqueue_async_action( 'ap2_process_batch_update', array(
				'order_ids' => $chunk,
				'data'      => $data,
				'operation' => $operation,
			), 'ap2_batch_operations' );
		}

		return true;
	}

	/**
	 * Update order in custom index.
	 *
	 * @param int $order_id Order ID.
	 */
	public function update_order_index( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( '_ap2_is_agent_order' ) ) {
			// Remove from index if not an agent order.
			$wpdb->delete( $this->custom_table, array( 'order_id' => $order_id ) );
			return;
		}

		// Prepare index data.
		$data = array(
			'order_id'          => $order_id,
			'agent_id'          => $order->get_meta( '_ap2_agent_id' ),
			'mandate_token'     => $order->get_meta( '_ap2_mandate_token' ),
			'transaction_type'  => $order->get_meta( '_ap2_transaction_type' ),
			'transaction_id'    => $order->get_meta( '_ap2_transaction_id' ),
			'payment_timestamp' => $order->get_meta( '_ap2_payment_timestamp' ),
			'total_amount'      => $order->get_total(),
		);

		// Insert or update.
		$wpdb->replace( $this->custom_table, $data );
	}

	/**
	 * Process agent analytics in background.
	 */
	public function process_agent_analytics_batch() {
		// Get unprocessed agent orders.
		$orders = $this->get_agent_orders( array(
			'limit' => 100,
			'meta_query' => array(
				array(
					'key'     => '_ap2_analytics_processed',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $orders as $order ) {
			// Process analytics.
			$this->process_order_analytics( $order );

			// Mark as processed.
			$order->update_meta_data( '_ap2_analytics_processed', 'yes' );
			$order->save();
		}

		// Update aggregate statistics.
		$this->update_aggregate_statistics();
	}

	/**
	 * Process analytics for a single order.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function process_order_analytics( $order ) {
		// Calculate processing time.
		$created = $order->get_date_created();
		$completed = $order->get_date_completed();

		if ( $created && $completed ) {
			$processing_time = $completed->getTimestamp() - $created->getTimestamp();
			$order->update_meta_data( '_ap2_processing_time', $processing_time );
		}

		// Add to analytics queue.
		do_action( 'ap2_order_analytics_processed', $order );
	}

	/**
	 * Update aggregate statistics.
	 */
	private function update_aggregate_statistics() {
		global $wpdb;

		// Update daily statistics.
		$today = current_time( 'Y-m-d' );

		$daily_stats = $wpdb->get_row( $wpdb->prepare( "
			SELECT
				COUNT(*) as order_count,
				SUM(total_amount) as total_revenue,
				AVG(total_amount) as avg_order_value,
				COUNT(DISTINCT agent_id) as unique_agents
			FROM {$this->custom_table}
			WHERE DATE(payment_timestamp) = %s
		", $today ) );

		// Store in options or custom table.
		update_option( 'ap2_daily_stats_' . $today, $daily_stats );

		// Trigger cache warming.
		$this->warm_cache();
	}

	/**
	 * Warm cache with frequently accessed data.
	 */
	public function warm_cache() {
		// Pre-load common queries.
		$periods = array( 'day', 'week', 'month' );

		foreach ( $periods as $period ) {
			$this->get_agent_statistics( $period );
		}

		// Pre-load recent orders.
		$this->get_agent_orders( array( 'limit' => 50 ) );

		// Pre-load top agents.
		$this->get_top_agents();
	}

	/**
	 * Get top performing agents.
	 *
	 * @param int    $limit Number of agents to return.
	 * @param string $metric Metric to sort by (orders, revenue).
	 * @return array Top agents.
	 */
	public function get_top_agents( $limit = 10, $metric = 'revenue' ) {
		global $wpdb;

		$cache_key = "top_agents_{$limit}_{$metric}";
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$order_by = 'revenue' === $metric ? 'SUM(total_amount)' : 'COUNT(*)';

		$results = $wpdb->get_results( $wpdb->prepare( "
			SELECT
				agent_id,
				COUNT(*) as order_count,
				SUM(total_amount) as total_revenue,
				AVG(total_amount) as avg_order_value,
				MAX(payment_timestamp) as last_order_date
			FROM {$this->custom_table}
			WHERE payment_timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
			GROUP BY agent_id
			ORDER BY {$order_by} DESC
			LIMIT %d
		", $limit ) );

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, self::CACHE_EXPIRATION );

		return $results;
	}

	/**
	 * Invalidate cache.
	 *
	 * @param int $order_id Order ID.
	 */
	public function invalidate_cache( $order_id = 0 ) {
		// Clear group cache if supported.
		if ( function_exists( 'wp_cache_delete_group' ) ) {
			wp_cache_delete_group( self::CACHE_GROUP );
		} else {
			// Fallback: clear specific keys.
			wp_cache_delete( 'agent_stats_day', self::CACHE_GROUP );
			wp_cache_delete( 'agent_stats_week', self::CACHE_GROUP );
			wp_cache_delete( 'agent_stats_month', self::CACHE_GROUP );
			wp_cache_delete( 'agent_stats_year', self::CACHE_GROUP );
		}

		// Update index if order specified.
		if ( $order_id ) {
			$this->update_order_index( $order_id );
		}

		// Trigger cache warming in background.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'ap2_warm_cache', array(), 'ap2_cache' );
		}
	}

	/**
	 * Check if HPOS is enabled.
	 *
	 * @return bool True if enabled.
	 */
	private function is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
		       OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Check if custom index exists.
	 *
	 * @return bool True if exists.
	 */
	private function has_custom_index() {
		global $wpdb;
		return $wpdb->get_var( "SHOW TABLES LIKE '{$this->custom_table}'" ) === $this->custom_table;
	}

	/**
	 * Add custom query arguments.
	 *
	 * @param array $args Query arguments.
	 * @return array Modified arguments.
	 */
	public function add_custom_query_args( $args ) {
		// Add support for agent-specific queries.
		$custom_args = array( 'agent_id', 'mandate_token', 'transaction_type' );

		foreach ( $custom_args as $arg ) {
			if ( isset( $_GET[ 'ap2_' . $arg ] ) ) {
				$args['meta_query'][] = array(
					'key'   => '_ap2_' . $arg,
					'value' => sanitize_text_field( wp_unslash( $_GET[ 'ap2_' . $arg ] ) ),
				);
			}
		}

		return $args;
	}

	/**
	 * Register bulk actions for agent orders.
	 */
	public function register_bulk_actions() {
		if ( ! $this->is_hpos_enabled() ) {
			return;
		}

		// Add bulk actions for agent order processing.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_agent_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_agent_bulk_actions' ), 10, 3 );
	}

	/**
	 * Add agent bulk actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array Modified actions.
	 */
	public function add_agent_bulk_actions( $actions ) {
		$actions['ap2_reindex'] = __( 'Reindex Agent Orders', 'ap2-gateway' );
		$actions['ap2_export_agents'] = __( 'Export Agent Data', 'ap2-gateway' );
		return $actions;
	}

	/**
	 * Handle agent bulk actions.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction Action being performed.
	 * @param array $order_ids Order IDs.
	 * @return string Redirect URL.
	 */
	public function handle_agent_bulk_actions( $redirect_to, $doaction, $order_ids ) {
		if ( 'ap2_reindex' === $doaction ) {
			foreach ( $order_ids as $order_id ) {
				$this->update_order_index( $order_id );
			}
			$redirect_to = add_query_arg( 'ap2_reindexed', count( $order_ids ), $redirect_to );
		} elseif ( 'ap2_export_agents' === $doaction ) {
			$this->export_agent_data( $order_ids );
		}
		return $redirect_to;
	}

	/**
	 * Export agent data for selected orders.
	 *
	 * @param array $order_ids Order IDs to export.
	 */
	private function export_agent_data( $order_ids ) {
		$data = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && $this->is_agent_order( $order ) ) {
				$data[] = array(
					'order_id' => $order_id,
					'agent_id' => $order->get_meta( '_ap2_agent_id' ),
					'mandate_token' => $order->get_meta( '_ap2_mandate_token' ),
					'transaction_id' => $order->get_meta( '_ap2_transaction_id' ),
					'total' => $order->get_total(),
					'date' => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
				);
			}
		}

		if ( ! empty( $data ) ) {
			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="agent-orders-' . date('Y-m-d') . '.csv"' );
			$output = fopen( 'php://output', 'w' );
			fputcsv( $output, array_keys( $data[0] ) );
			foreach ( $data as $row ) {
				fputcsv( $output, $row );
			}
			fclose( $output );
			exit;
		}
	}

	/**
	 * Check if order is an agent order.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_agent_order( $order ) {
		return $order->get_payment_method() === 'ap2_gateway' || $order->get_meta( '_ap2_agent_id' );
	}

	/**
	 * Customize order data store for performance.
	 *
	 * @param string $classname Data store class name.
	 * @return string Modified class name.
	 */
	public function customize_order_data_store( $classname ) {
		// Only customize if HPOS is enabled.
		if ( ! $this->is_hpos_enabled() ) {
			return $classname;
		}

		// Use default class but with our optimizations applied via filters.
		return $classname;
	}

	/**
	 * Add custom table data for orders.
	 *
	 * @param array $data Order data.
	 * @param WC_Order $order Order object.
	 * @param string $context Context.
	 * @param array $changes Changes.
	 * @return array Modified data.
	 */
	public function add_custom_table_data( $data, $order, $context, $changes ) {
		// Add agent-specific data to custom index if this is an agent order.
		if ( $this->is_agent_order( $order ) ) {
			$this->update_order_index( $order->get_id() );
		}

		return $data;
	}

	/**
	 * Save custom table data for orders.
	 *
	 * @param array $data Order data.
	 * @param WC_Order $order Order object.
	 * @param string $context Context.
	 * @param array $changes Changes.
	 * @return array Modified data.
	 */
	public function save_custom_table_data( $data, $order, $context, $changes ) {
		// Ensure agent data is saved to index.
		if ( $this->is_agent_order( $order ) ) {
			$this->update_order_index( $order->get_id() );
		}

		return $data;
	}

}

// Initialize optimizer.
new AP2_HPOS_Optimizer();