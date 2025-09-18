<?php
/**
 * AP2 Gateway Analytics Integration
 *
 * Integrates with WooCommerce Analytics for agent order reporting.
 *
 * @package AP2_Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Admin\API\Reports\ParameterException;

/**
 * AP2 Analytics Integration Class.
 */
class AP2_Analytics_Integration {

	/**
	 * Single instance.
	 *
	 * @var AP2_Analytics_Integration
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AP2_Analytics_Integration
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Register with WooCommerce Analytics.
		add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'add_analytics_menu' ) );

		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_api_endpoints' ) );

		// Register scripts for Analytics page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_analytics_scripts' ) );

		// Add navigation item.
		add_filter( 'woocommerce_admin_navigation_menu_items', array( $this, 'add_navigation_items' ) );

		// Register analytics page.
		add_action( 'admin_menu', array( $this, 'register_analytics_page' ) );

		// Handle the analytics page rendering.
		add_action( 'woocommerce_analytics_ap2-agents', array( $this, 'render_analytics_page' ) );
	}

	/**
	 * Add menu item to WooCommerce Analytics.
	 *
	 * @param array $report_pages Report pages.
	 * @return array Modified report pages.
	 */
	public function add_analytics_menu( $report_pages ) {
		$report_pages[] = array(
			'id'     => 'ap2-agents',
			'title'  => __( 'AP2 Agents', 'ap2-gateway' ),
			'parent' => 'woocommerce-analytics',
			'path'   => '/analytics/ap2-agents',
		);

		return $report_pages;
	}

	/**
	 * Add navigation items for WC Admin.
	 *
	 * @param array $items Navigation items.
	 * @return array Modified items.
	 */
	public function add_navigation_items( $items ) {
		$items[] = array(
			'id'         => 'analytics-ap2-agents',
			'title'      => __( 'AP2 Agents', 'ap2-gateway' ),
			'url'        => 'admin.php?page=wc-admin&path=/analytics/ap2-agents',
			'parent'     => 'analytics',
			'order'      => 60,
			'capability' => 'manage_woocommerce',
		);

		return $items;
	}

	/**
	 * Register the analytics page.
	 */
	public function register_analytics_page() {
		// Check if WooCommerce Admin is available.
		if ( ! function_exists( 'wc_admin_register_page' ) ) {
			return;
		}

		wc_admin_register_page( array(
			'id'       => 'ap2-agents-analytics',
			'title'    => __( 'AP2 Agents', 'ap2-gateway' ),
			'parent'   => 'woocommerce-analytics',
			'path'     => '/analytics/ap2-agents',
			'nav_args' => array(
				'order'  => 110,
				'parent' => 'analytics',
			),
		) );
	}

	/**
	 * Enqueue scripts for analytics page.
	 *
	 * @param string $hook Page hook.
	 */
	public function enqueue_analytics_scripts( $hook ) {
		if ( 'woocommerce_page_wc-admin' !== $hook ) {
			return;
		}

		// Check if we're on the AP2 agents page.
		$page = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		if ( '/analytics/ap2-agents' !== $page ) {
			return;
		}

		wp_enqueue_script(
			'ap2-analytics',
			AP2_GATEWAY_PLUGIN_URL . 'assets/js/analytics.js',
			array( 'wp-element', 'wp-data', 'wc-components', 'wc-navigation' ),
			AP2_GATEWAY_VERSION,
			true
		);

		wp_localize_script( 'ap2-analytics', 'ap2Analytics', array(
			'apiUrl'     => rest_url( 'ap2/v1/' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'currency'   => get_woocommerce_currency_symbol(),
			'dateFormat' => get_option( 'date_format' ),
		) );
	}

	/**
	 * Register REST API endpoints for analytics data.
	 */
	public function register_api_endpoints() {
		register_rest_route( 'ap2/v1', '/analytics/agents', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_agents_data' ),
			'permission_callback' => array( $this, 'check_permissions' ),
			'args'                => array(
				'period' => array(
					'type'        => 'string',
					'default'     => 'week',
					'enum'        => array( 'day', 'week', 'month', 'year' ),
				),
				'date_from' => array(
					'type'        => 'string',
					'format'      => 'date',
				),
				'date_to' => array(
					'type'        => 'string',
					'format'      => 'date',
				),
			),
		) );

		register_rest_route( 'ap2/v1', '/analytics/performance', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_performance_data' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );
	}

	/**
	 * Check if user has permission to view analytics.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Get agents analytics data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	public function get_agents_data( $request ) {
		global $wpdb;

		$period    = $request->get_param( 'period' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		// Set date range based on period.
		if ( ! $date_from || ! $date_to ) {
			$date_to   = current_time( 'Y-m-d' );
			switch ( $period ) {
				case 'day':
					$date_from = $date_to;
					break;
				case 'week':
					$date_from = date( 'Y-m-d', strtotime( '-7 days' ) );
					break;
				case 'month':
					$date_from = date( 'Y-m-d', strtotime( '-30 days' ) );
					break;
				case 'year':
					$date_from = date( 'Y-m-d', strtotime( '-365 days' ) );
					break;
			}
		}

		// Get agent orders.
		$agent_orders = wc_get_orders( array(
			'limit'        => -1,
			'date_created' => $date_from . '...' . $date_to,
			'meta_key'     => '_ap2_agent_id',
			'meta_compare' => 'EXISTS',
			'return'       => 'ids',
		) );

		// Get all orders for comparison.
		$all_orders = wc_get_orders( array(
			'limit'        => -1,
			'date_created' => $date_from . '...' . $date_to,
			'return'       => 'ids',
		) );

		// Calculate statistics.
		$agent_revenue = 0;
		$human_revenue = 0;
		$agent_count = count( $agent_orders );
		$human_count = count( $all_orders ) - $agent_count;

		foreach ( $agent_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			$agent_revenue += $order->get_total();
		}

		$total_revenue = 0;
		foreach ( $all_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			$total_revenue += $order->get_total();
		}
		$human_revenue = $total_revenue - $agent_revenue;

		// Get top agents.
		$top_agents = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				meta_value as agent_id,
				COUNT(*) as order_count,
				SUM(total.meta_value) as total_revenue
			FROM {$wpdb->prefix}wc_orders_meta agent
			LEFT JOIN {$wpdb->prefix}wc_orders_meta total
				ON agent.order_id = total.order_id AND total.meta_key = '_order_total'
			LEFT JOIN {$wpdb->prefix}wc_orders o ON agent.order_id = o.id
			WHERE agent.meta_key = '_ap2_agent_id'
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s
			GROUP BY agent_id
			ORDER BY order_count DESC
			LIMIT 10",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		// Get time series data.
		$time_series = $this->get_time_series_data( $date_from, $date_to );

		return array(
			'summary' => array(
				'agent_orders'    => $agent_count,
				'human_orders'    => $human_count,
				'agent_revenue'   => $agent_revenue,
				'human_revenue'   => $human_revenue,
				'total_revenue'   => $total_revenue,
				'conversion_rate' => $agent_count > 0 && count( $all_orders ) > 0
					? round( ( $agent_count / count( $all_orders ) ) * 100, 2 )
					: 0,
				'avg_agent_order' => $agent_count > 0 ? $agent_revenue / $agent_count : 0,
				'avg_human_order' => $human_count > 0 ? $human_revenue / $human_count : 0,
			),
			'top_agents'  => $top_agents,
			'time_series' => $time_series,
			'date_range'  => array(
				'from' => $date_from,
				'to'   => $date_to,
			),
		);
	}

	/**
	 * Get time series data for charts.
	 *
	 * @param string $date_from Start date.
	 * @param string $date_to End date.
	 * @return array
	 */
	private function get_time_series_data( $date_from, $date_to ) {
		global $wpdb;

		$data = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				DATE(o.date_created_gmt) as order_date,
				COUNT(CASE WHEN agent.meta_value IS NOT NULL THEN 1 END) as agent_orders,
				COUNT(CASE WHEN agent.meta_value IS NULL THEN 1 END) as human_orders,
				SUM(CASE WHEN agent.meta_value IS NOT NULL THEN total.meta_value ELSE 0 END) as agent_revenue,
				SUM(CASE WHEN agent.meta_value IS NULL THEN total.meta_value ELSE 0 END) as human_revenue
			FROM {$wpdb->prefix}wc_orders o
			LEFT JOIN {$wpdb->prefix}wc_orders_meta agent
				ON o.id = agent.order_id AND agent.meta_key = '_ap2_agent_id'
			LEFT JOIN {$wpdb->prefix}wc_orders_meta total
				ON o.id = total.order_id AND total.meta_key = '_order_total'
			WHERE o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s
				AND o.status IN ('wc-completed', 'wc-processing')
			GROUP BY DATE(o.date_created_gmt)
			ORDER BY order_date ASC",
			$date_from . ' 00:00:00',
			$date_to . ' 23:59:59'
		) );

		return $data;
	}

	/**
	 * Get performance data.
	 *
	 * @return array
	 */
	public function get_performance_data() {
		global $wpdb;

		// Get basic performance metrics.
		$total_agent_orders = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_orders_meta m ON o.id = m.order_id
			WHERE m.meta_key = '_ap2_agent_id'"
		);

		$storage_type = 'legacy';
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$storage_type = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy';
		}

		return array(
			'total_orders' => $total_agent_orders,
			'storage_type' => $storage_type,
			'status'       => 'operational',
		);
	}

	/**
	 * Render the analytics page.
	 */
	public function render_analytics_page() {
		// Get analytics data for initial display.
		$data = $this->get_agents_data( new \WP_REST_Request( 'GET' ) );
		?>
		<div class="wrap woocommerce-analytics">
			<h1><?php esc_html_e( 'AP2 Agent Analytics', 'ap2-gateway' ); ?></h1>

			<!-- Summary Cards -->
			<div class="woocommerce-summary">
				<div class="woocommerce-summary__item-container">
					<div class="woocommerce-summary__item">
						<div class="woocommerce-summary__item-label">
							<?php esc_html_e( 'Agent Orders', 'ap2-gateway' ); ?>
						</div>
						<div class="woocommerce-summary__item-value">
							<?php echo esc_html( number_format_i18n( $data['summary']['agent_orders'] ) ); ?>
						</div>
						<div class="woocommerce-summary__item-delta">
							<?php
							printf(
								/* translators: %s: conversion rate */
								esc_html__( '%s%% of total', 'ap2-gateway' ),
								esc_html( $data['summary']['conversion_rate'] )
							);
							?>
						</div>
					</div>

					<div class="woocommerce-summary__item">
						<div class="woocommerce-summary__item-label">
							<?php esc_html_e( 'Agent Revenue', 'ap2-gateway' ); ?>
						</div>
						<div class="woocommerce-summary__item-value">
							<?php echo wp_kses_post( wc_price( $data['summary']['agent_revenue'] ) ); ?>
						</div>
					</div>

					<div class="woocommerce-summary__item">
						<div class="woocommerce-summary__item-label">
							<?php esc_html_e( 'Avg Agent Order', 'ap2-gateway' ); ?>
						</div>
						<div class="woocommerce-summary__item-value">
							<?php echo wp_kses_post( wc_price( $data['summary']['avg_agent_order'] ) ); ?>
						</div>
					</div>

					<div class="woocommerce-summary__item">
						<div class="woocommerce-summary__item-label">
							<?php esc_html_e( 'Human Orders', 'ap2-gateway' ); ?>
						</div>
						<div class="woocommerce-summary__item-value">
							<?php echo esc_html( number_format_i18n( $data['summary']['human_orders'] ) ); ?>
						</div>
						<div class="woocommerce-summary__item-delta">
							<?php echo wp_kses_post( wc_price( $data['summary']['human_revenue'] ) ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Charts Section -->
			<?php if ( ! empty( $data['time_series'] ) ) : ?>
				<div class="woocommerce-chart">
					<h2><?php esc_html_e( 'Orders Over Time', 'ap2-gateway' ); ?></h2>
					<div id="ap2-chart-container">
						<!-- Chart will be rendered here by JavaScript -->
					</div>
				</div>
			<?php endif; ?>

			<!-- Top Agents Table -->
			<?php if ( ! empty( $data['top_agents'] ) ) : ?>
				<div class="woocommerce-table">
					<h2><?php esc_html_e( 'Top Agents', 'ap2-gateway' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Agent ID', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Orders', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Revenue', 'ap2-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['top_agents'] as $agent ) : ?>
								<tr>
									<td><?php echo esc_html( $agent->agent_id ); ?></td>
									<td><?php echo esc_html( $agent->order_count ); ?></td>
									<td><?php echo wp_kses_post( wc_price( $agent->total_revenue ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No agent orders found yet. Agent orders will appear here once AI agents start making purchases through your store.', 'ap2-gateway' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- React mount point for advanced features -->
			<div id="ap2-analytics-root"></div>
		</div>

		<style>
			.woocommerce-summary {
				margin: 20px 0;
			}
			.woocommerce-summary__item-container {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
				gap: 20px;
				margin-bottom: 30px;
			}
			.woocommerce-summary__item {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 4px;
				padding: 20px;
			}
			.woocommerce-summary__item-label {
				color: #757575;
				font-size: 12px;
				text-transform: uppercase;
				margin-bottom: 8px;
			}
			.woocommerce-summary__item-value {
				font-size: 24px;
				font-weight: 600;
				color: #1e1e1e;
				margin-bottom: 4px;
			}
			.woocommerce-summary__item-delta {
				font-size: 13px;
				color: #757575;
			}
			.woocommerce-chart,
			.woocommerce-table {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 4px;
				padding: 20px;
				margin-bottom: 20px;
			}
			.woocommerce-chart h2,
			.woocommerce-table h2 {
				margin-top: 0;
				margin-bottom: 20px;
				font-size: 18px;
			}
		</style>
		<?php
	}
}

// Initialize.
AP2_Analytics_Integration::instance();