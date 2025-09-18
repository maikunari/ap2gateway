<?php
/**
 * AP2 Agent Order DataStore
 *
 * Custom DataStore for agent order analytics and reporting.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Admin\API\Reports\DataStore;
use Automattic\WooCommerce\Admin\API\Reports\DataStoreInterface;
use Automattic\WooCommerce\Admin\API\Reports\TimeInterval;
use Automattic\WooCommerce\Admin\API\Reports\SqlQuery;

/**
 * AP2 Agent Orders DataStore.
 */
class AP2_Agent_Orders_DataStore extends DataStore implements DataStoreInterface {

	/**
	 * Table used to get the data.
	 *
	 * @var string
	 */
	protected static $table_name = 'ap2_agent_order_stats';

	/**
	 * Cache identifier.
	 *
	 * @var string
	 */
	protected $cache_key = 'agent_orders';

	/**
	 * Mapping columns to data type to return correct response types.
	 *
	 * @var array
	 */
	protected $column_types = array(
		'order_id'          => 'intval',
		'agent_id'          => 'strval',
		'date_created'      => 'strval',
		'total_sales'       => 'floatval',
		'order_count'       => 'intval',
		'unique_agents'     => 'intval',
		'avg_order_value'   => 'floatval',
		'mandate_count'     => 'intval',
		'processing_time'   => 'intval',
	);

	/**
	 * Data store context used to pass to filters.
	 *
	 * @var string
	 */
	protected $context = 'agent_orders';

	/**
	 * Assign report columns once full table name has been assigned.
	 */
	protected function assign_report_columns() {
		global $wpdb;

		$this->report_columns = array(
			'order_id'        => 'order_id',
			'agent_id'        => 'agent_id',
			'date_created'    => 'date_created',
			'total_sales'     => 'SUM(total_amount) as total_sales',
			'order_count'     => 'COUNT(DISTINCT order_id) as order_count',
			'unique_agents'   => 'COUNT(DISTINCT agent_id) as unique_agents',
			'avg_order_value' => 'AVG(total_amount) as avg_order_value',
		);
	}

	/**
	 * Get the data based on args.
	 *
	 * @param array $args Query arguments.
	 * @return array|object Query result.
	 */
	public function get_data( $args = array() ) {
		global $wpdb;

		// Initialize HPOS optimizer for advanced queries.
		$optimizer = new AP2_HPOS_Optimizer();

		$defaults = array(
			'per_page'         => get_option( 'posts_per_page' ),
			'page'             => 1,
			'order'            => 'DESC',
			'orderby'          => 'date_created',
			'before'           => TimeInterval::default_before(),
			'after'            => TimeInterval::default_after(),
			'interval'         => 'day',
			'fields'           => '*',
			'agent_id'         => '',
			'mandate_token'    => '',
			'transaction_type' => '',
			'status'           => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		// Generate cache key.
		$cache_key = $this->get_cache_key( $args );
		$cached_data = $this->get_cached_data( $cache_key );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		// Build the query.
		$this->initialize_queries();

		// Add time constraints.
		$this->add_time_period_sql_params( $args, $this->get_table_name() );

		// Add agent-specific filters.
		$this->add_agent_filters( $args );

		// Add order status filters.
		if ( ! empty( $args['status'] ) ) {
			$this->add_status_filter( $args['status'] );
		}

		// Execute query.
		$data = $this->get_query_results( $args );

		// Format the data.
		$formatted_data = $this->format_data( $data, $args );

		// Add intervals if requested.
		if ( ! empty( $args['interval'] ) ) {
			$formatted_data = $this->add_intervals( $formatted_data, $args );
		}

		// Cache the results.
		$this->set_cached_data( $cache_key, $formatted_data );

		return $formatted_data;
	}

	/**
	 * Add agent-specific filters to the query.
	 *
	 * @param array $args Query arguments.
	 */
	protected function add_agent_filters( $args ) {
		global $wpdb;

		if ( ! empty( $args['agent_id'] ) ) {
			$this->add_sql_clause( 'where', $wpdb->prepare(
				'AND agent_id = %s',
				$args['agent_id']
			) );
		}

		if ( ! empty( $args['mandate_token'] ) ) {
			$this->add_sql_clause( 'where', $wpdb->prepare(
				'AND mandate_token = %s',
				$args['mandate_token']
			) );
		}

		if ( ! empty( $args['transaction_type'] ) ) {
			$this->add_sql_clause( 'where', $wpdb->prepare(
				'AND transaction_type = %s',
				$args['transaction_type']
			) );
		}
	}

	/**
	 * Get agent revenue report data.
	 *
	 * @param array $args Query arguments.
	 * @return array Report data.
	 */
	public function get_revenue_report( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ap2_agent_order_index';

		// Base query for revenue data.
		$sql = "SELECT
			DATE(payment_timestamp) as date,
			COUNT(*) as orders,
			SUM(total_amount) as revenue,
			COUNT(DISTINCT agent_id) as agents
		FROM {$table}
		WHERE payment_timestamp BETWEEN %s AND %s
		GROUP BY DATE(payment_timestamp)
		ORDER BY date ASC";

		$results = $wpdb->get_results( $wpdb->prepare(
			$sql,
			$args['after'],
			$args['before']
		) );

		return $this->format_revenue_data( $results );
	}

	/**
	 * Get agent performance metrics.
	 *
	 * @param string $agent_id Agent ID.
	 * @param array  $args Query arguments.
	 * @return array Performance metrics.
	 */
	public function get_agent_performance( $agent_id, $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ap2_agent_order_index';

		$defaults = array(
			'period' => '30 days',
		);

		$args = wp_parse_args( $args, $defaults );

		// Get performance data.
		$sql = "SELECT
			COUNT(*) as total_orders,
			SUM(total_amount) as total_revenue,
			AVG(total_amount) as avg_order_value,
			MIN(total_amount) as min_order_value,
			MAX(total_amount) as max_order_value,
			AVG(processing_time) as avg_processing_time,
			COUNT(DISTINCT mandate_token) as unique_mandates,
			COUNT(DISTINCT transaction_type) as transaction_types
		FROM {$table}
		WHERE agent_id = %s
		AND payment_timestamp > DATE_SUB(NOW(), INTERVAL %s)";

		$result = $wpdb->get_row( $wpdb->prepare(
			$sql,
			$agent_id,
			$args['period']
		) );

		// Add time series data.
		$result->time_series = $this->get_agent_time_series( $agent_id, $args['period'] );

		// Add comparison with average.
		$result->comparison = $this->get_agent_comparison( $agent_id, $result );

		return $result;
	}

	/**
	 * Get agent time series data.
	 *
	 * @param string $agent_id Agent ID.
	 * @param string $period Time period.
	 * @return array Time series data.
	 */
	protected function get_agent_time_series( $agent_id, $period ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ap2_agent_order_index';

		$sql = "SELECT
			DATE(payment_timestamp) as date,
			COUNT(*) as orders,
			SUM(total_amount) as revenue
		FROM {$table}
		WHERE agent_id = %s
		AND payment_timestamp > DATE_SUB(NOW(), INTERVAL %s)
		GROUP BY DATE(payment_timestamp)
		ORDER BY date ASC";

		return $wpdb->get_results( $wpdb->prepare(
			$sql,
			$agent_id,
			$period
		) );
	}

	/**
	 * Get agent comparison with averages.
	 *
	 * @param string $agent_id Agent ID.
	 * @param object $agent_data Agent performance data.
	 * @return array Comparison data.
	 */
	protected function get_agent_comparison( $agent_id, $agent_data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ap2_agent_order_index';

		// Get overall averages.
		$avg_sql = "SELECT
			AVG(total_amount) as avg_order_value,
			AVG(processing_time) as avg_processing_time
		FROM {$table}
		WHERE payment_timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)";

		$averages = $wpdb->get_row( $avg_sql );

		return array(
			'order_value_diff' => ( ( $agent_data->avg_order_value - $averages->avg_order_value ) / $averages->avg_order_value ) * 100,
			'processing_time_diff' => ( ( $agent_data->avg_processing_time - $averages->avg_processing_time ) / $averages->avg_processing_time ) * 100,
			'performance_score' => $this->calculate_performance_score( $agent_data, $averages ),
		);
	}

	/**
	 * Calculate agent performance score.
	 *
	 * @param object $agent_data Agent data.
	 * @param object $averages Average data.
	 * @return float Performance score.
	 */
	protected function calculate_performance_score( $agent_data, $averages ) {
		$score = 100;

		// Order value contributes 40%.
		if ( $agent_data->avg_order_value > $averages->avg_order_value ) {
			$score += 40 * ( $agent_data->avg_order_value / $averages->avg_order_value - 1 );
		} else {
			$score -= 40 * ( 1 - $agent_data->avg_order_value / $averages->avg_order_value );
		}

		// Processing time contributes 30%.
		if ( $agent_data->avg_processing_time < $averages->avg_processing_time ) {
			$score += 30 * ( 1 - $agent_data->avg_processing_time / $averages->avg_processing_time );
		} else {
			$score -= 30 * ( $agent_data->avg_processing_time / $averages->avg_processing_time - 1 );
		}

		// Order count contributes 30%.
		$score += min( 30, $agent_data->total_orders / 10 );

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Get mandate usage statistics.
	 *
	 * @param array $args Query arguments.
	 * @return array Mandate statistics.
	 */
	public function get_mandate_stats( $args = array() ) {
		global $wpdb;

		$table = $wpdb->prefix . 'ap2_agent_order_index';

		$defaults = array(
			'limit' => 10,
			'period' => '30 days',
		);

		$args = wp_parse_args( $args, $defaults );

		// Get mandate usage data.
		$sql = "SELECT
			mandate_token,
			COUNT(*) as usage_count,
			SUM(total_amount) as total_value,
			AVG(total_amount) as avg_value,
			COUNT(DISTINCT agent_id) as unique_agents,
			MAX(payment_timestamp) as last_used
		FROM {$table}
		WHERE payment_timestamp > DATE_SUB(NOW(), INTERVAL %s)
		GROUP BY mandate_token
		ORDER BY usage_count DESC
		LIMIT %d";

		$results = $wpdb->get_results( $wpdb->prepare(
			$sql,
			$args['period'],
			$args['limit']
		) );

		// Add categorization.
		foreach ( $results as &$result ) {
			$result->category = $this->categorize_mandate( $result->mandate_token );
			$result->risk_score = $this->calculate_mandate_risk( $result );
		}

		return $results;
	}

	/**
	 * Categorize mandate token.
	 *
	 * @param string $mandate_token Mandate token.
	 * @return string Category.
	 */
	protected function categorize_mandate( $mandate_token ) {
		// Simple categorization based on prefix.
		$prefix = substr( $mandate_token, 0, 3 );

		$categories = array(
			'SUB' => 'Subscription',
			'ONE' => 'One-time',
			'REC' => 'Recurring',
			'LIM' => 'Limited',
		);

		return isset( $categories[ $prefix ] ) ? $categories[ $prefix ] : 'Standard';
	}

	/**
	 * Calculate mandate risk score.
	 *
	 * @param object $mandate_data Mandate data.
	 * @return int Risk score (0-100).
	 */
	protected function calculate_mandate_risk( $mandate_data ) {
		$risk = 0;

		// High usage increases risk.
		if ( $mandate_data->usage_count > 100 ) {
			$risk += 20;
		}

		// High value increases risk.
		if ( $mandate_data->total_value > 10000 ) {
			$risk += 30;
		}

		// Many unique agents increases risk.
		if ( $mandate_data->unique_agents > 50 ) {
			$risk += 25;
		}

		// Recent activity reduces risk.
		$days_since_use = ( time() - strtotime( $mandate_data->last_used ) ) / 86400;
		if ( $days_since_use > 30 ) {
			$risk += 25;
		}

		return min( 100, $risk );
	}

	/**
	 * Format revenue data for output.
	 *
	 * @param array $data Raw data.
	 * @return array Formatted data.
	 */
	protected function format_revenue_data( $data ) {
		$formatted = array();

		foreach ( $data as $row ) {
			$formatted[] = array(
				'date'    => $row->date,
				'orders'  => intval( $row->orders ),
				'revenue' => floatval( $row->revenue ),
				'agents'  => intval( $row->agents ),
				'avg_order_value' => $row->orders > 0 ? $row->revenue / $row->orders : 0,
			);
		}

		return $formatted;
	}

	/**
	 * Initialize table used to store stats.
	 *
	 * @param array $query_args Query arguments.
	 */
	protected function initialize_queries( $query_args = array() ) {
		global $wpdb;

		// Use custom index table if available.
		if ( $this->has_custom_index() ) {
			$this->table_name = $wpdb->prefix . 'ap2_agent_order_index';
		} else {
			// Fallback to orders table with joins.
			$this->table_name = $wpdb->prefix . 'wc_order_stats';
		}

		parent::initialize_queries( $query_args );
	}

	/**
	 * Check if custom index exists.
	 *
	 * @return bool True if exists.
	 */
	protected function has_custom_index() {
		global $wpdb;
		$table = $wpdb->prefix . 'ap2_agent_order_index';
		return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
	}

	/**
	 * Returns the report data schema.
	 *
	 * @return array Report schema.
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'agent_orders',
			'type'       => 'object',
			'properties' => array(
				'date'           => array(
					'description' => __( 'Date', 'ap2-gateway' ),
					'type'        => 'string',
					'format'      => 'date',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'order_count'    => array(
					'description' => __( 'Number of orders', 'ap2-gateway' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'total_sales'    => array(
					'description' => __( 'Total sales', 'ap2-gateway' ),
					'type'        => 'number',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'unique_agents'  => array(
					'description' => __( 'Unique agents', 'ap2-gateway' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'avg_order_value' => array(
					'description' => __( 'Average order value', 'ap2-gateway' ),
					'type'        => 'number',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $schema;
	}

	/**
	 * Get the default query arguments.
	 *
	 * @return array Default query arguments.
	 */
	public function get_default_query_vars() {
		return array(
			'per_page' => 25,
			'page'     => 1,
			'order'    => 'DESC',
			'orderby'  => 'date',
			'interval' => 'day',
		);
	}
}