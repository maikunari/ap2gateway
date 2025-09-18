<?php
/**
 * AP2 Analytics Integration for WooCommerce Admin
 *
 * Integrates with WooCommerce Analytics using native components.
 *
 * @package AP2_Gateway
 * @subpackage Admin
 * @since 1.0.0
 */

namespace AP2_Gateway\Admin;

use Automattic\WooCommerce\Admin\API\Reports\DataStore as ReportsDataStore;
use Automattic\WooCommerce\Admin\API\Reports\DataStoreInterface;
use Automattic\WooCommerce\Admin\API\Reports\TimeInterval;
use Automattic\WooCommerce\Admin\Features\Navigation\Menu;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Analytics class.
 */
class AP2_Analytics {

	/**
	 * Single instance.
	 *
	 * @var AP2_Analytics
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AP2_Analytics
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
		// Only load if WooCommerce Admin is available.
		if ( ! $this->is_wc_admin_available() ) {
			// Fall back to simple analytics if WC Admin not available.
			add_action( 'admin_menu', array( $this, 'add_fallback_menu' ), 99 );
			return;
		}

		// Register with WooCommerce Analytics.
		add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'add_report_menu_item' ) );
		add_filter( 'woocommerce_admin_reports_list', array( $this, 'add_reports_list' ) );

		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Add data stores.
		add_filter( 'woocommerce_data_stores', array( $this, 'register_data_stores' ) );
		add_filter( 'woocommerce_admin_reports_data_stores', array( $this, 'register_reports_data_stores' ) );

		// Register scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );

		// Add to navigation if exists.
		add_action( 'admin_menu', array( $this, 'possibly_add_navigation' ) );

		// Visibility rules.
		add_filter( 'woocommerce_admin_shared_settings', array( $this, 'add_component_settings' ) );
	}

	/**
	 * Check if WooCommerce Admin is available.
	 *
	 * @return bool
	 */
	private function is_wc_admin_available() {
		return class_exists( '\Automattic\WooCommerce\Admin\Loader' )
			&& function_exists( 'wc_admin_register_page' );
	}

	/**
	 * Add report menu item.
	 *
	 * @param array $items Report menu items.
	 * @return array
	 */
	public function add_report_menu_item( $items ) {
		// Only show if user has capability and there are agent orders.
		if ( ! current_user_can( 'view_woocommerce_reports' ) ) {
			return $items;
		}

		// Check if we have any agent orders before showing menu.
		if ( ! $this->has_agent_orders() ) {
			return $items;
		}

		$items[] = array(
			'id'     => 'ap2-agents',
			'title'  => __( 'AP2 Agents', 'ap2-gateway' ),
			'parent' => 'woocommerce-analytics',
			'path'   => '/analytics/ap2-agents',
		);

		return $items;
	}

	/**
	 * Add to reports list.
	 *
	 * @param array $reports Reports list.
	 * @return array
	 */
	public function add_reports_list( $reports ) {
		if ( ! $this->has_agent_orders() ) {
			return $reports;
		}

		$reports[] = array(
			'report'  => 'ap2-agents',
			'title'   => __( 'AP2 Agents', 'ap2-gateway' ),
			'charts'  => array(
				array(
					'key'   => 'agent_orders',
					'label' => __( 'Agent Orders', 'ap2-gateway' ),
					'type'  => 'number',
				),
				array(
					'key'   => 'agent_revenue',
					'label' => __( 'Agent Revenue', 'ap2-gateway' ),
					'type'  => 'currency',
				),
			),
			'filters' => array( 'date', 'agent' ),
		);

		return $reports;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Main analytics endpoint.
		register_rest_route(
			'wc-analytics',
			'/reports/ap2-agents',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_report_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		// Stats endpoint.
		register_rest_route(
			'wc-analytics',
			'/reports/ap2-agents/stats',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		// Top agents endpoint.
		register_rest_route(
			'wc-analytics',
			'/reports/ap2-agents/top',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_top_agents' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Get collection params for API.
	 *
	 * @return array
	 */
	private function get_collection_params() {
		return array(
			'before'   => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Limit to items before date.', 'ap2-gateway' ),
			),
			'after'    => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Limit to items after date.', 'ap2-gateway' ),
			),
			'interval' => array(
				'type'        => 'string',
				'default'     => 'day',
				'enum'        => array( 'hour', 'day', 'week', 'month', 'quarter', 'year' ),
				'description' => __( 'Time interval.', 'ap2-gateway' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'ap2-gateway' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Current page.', 'ap2-gateway' ),
			),
		);
	}

	/**
	 * Check API permission.
	 *
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( 'view_woocommerce_reports' );
	}

	/**
	 * Get report data.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	public function get_report_data( $request ) {
		$args = array(
			'before'   => $request->get_param( 'before' ),
			'after'    => $request->get_param( 'after' ),
			'interval' => $request->get_param( 'interval' ),
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
		);

		return $this->get_agent_analytics_data( $args );
	}

	/**
	 * Get stats data.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	public function get_stats_data( $request ) {
		$args = array(
			'before' => $request->get_param( 'before' ),
			'after'  => $request->get_param( 'after' ),
		);

		return array(
			'totals'    => $this->get_totals( $args ),
			'intervals' => $this->get_intervals( $args ),
		);
	}

	/**
	 * Get top agents.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	public function get_top_agents( $request ) {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT
				m.meta_value as agent_id,
				COUNT(DISTINCT o.id) as order_count,
				SUM(o.total_amount) as total_revenue,
				MAX(o.date_created_gmt) as last_order_date
			FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_orders_meta m ON o.id = m.order_id
			WHERE m.meta_key = '_ap2_agent_id'
				AND o.status IN ('wc-completed', 'wc-processing')
			GROUP BY m.meta_value
			ORDER BY order_count DESC
			LIMIT 10",
			ARRAY_A
		);

		return array(
			'data' => $results,
		);
	}

	/**
	 * Get agent analytics data.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function get_agent_analytics_data( $args ) {
		global $wpdb;

		// Set date range.
		$after  = $args['after'] ? $args['after'] : date( 'Y-m-d', strtotime( '-30 days' ) );
		$before = $args['before'] ? $args['before'] : date( 'Y-m-d' );

		// Get agent orders.
		$agent_orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				o.*,
				m.meta_value as agent_id
			FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_orders_meta m ON o.id = m.order_id
			WHERE m.meta_key = '_ap2_agent_id'
				AND o.date_created_gmt >= %s
				AND o.date_created_gmt <= %s
				AND o.status IN ('wc-completed', 'wc-processing')
			ORDER BY o.date_created_gmt DESC",
				$after,
				$before . ' 23:59:59'
			),
			ARRAY_A
		);

		// Calculate totals.
		$total_orders  = count( $agent_orders );
		$total_revenue = array_sum( array_column( $agent_orders, 'total_amount' ) );

		// Get all orders for comparison.
		$all_orders_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
			FROM {$wpdb->prefix}wc_orders
			WHERE date_created_gmt >= %s
				AND date_created_gmt <= %s
				AND status IN ('wc-completed', 'wc-processing')",
				$after,
				$before . ' 23:59:59'
			)
		);

		return array(
			'data' => array(
				'totals'    => array(
					'agent_orders'     => $total_orders,
					'agent_revenue'    => $total_revenue,
					'total_orders'     => (int) $all_orders_count,
					'agent_percentage' => $all_orders_count > 0 ? round( ( $total_orders / $all_orders_count ) * 100, 2 ) : 0,
					'avg_order_value'  => $total_orders > 0 ? $total_revenue / $total_orders : 0,
				),
				'intervals' => $this->group_by_interval( $agent_orders, $args['interval'] ),
			),
		);
	}

	/**
	 * Get totals.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function get_totals( $args ) {
		$data = $this->get_agent_analytics_data( $args );
		return $data['data']['totals'];
	}

	/**
	 * Get intervals.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function get_intervals( $args ) {
		$data = $this->get_agent_analytics_data( $args );
		return $data['data']['intervals'];
	}

	/**
	 * Group orders by interval.
	 *
	 * @param array  $orders Orders.
	 * @param string $interval Interval type.
	 * @return array
	 */
	private function group_by_interval( $orders, $interval = 'day' ) {
		$grouped = array();

		foreach ( $orders as $order ) {
			$date = $order['date_created_gmt'];

			switch ( $interval ) {
				case 'hour':
					$key = date( 'Y-m-d H:00', strtotime( $date ) );
					break;
				case 'week':
					$key = date( 'Y-W', strtotime( $date ) );
					break;
				case 'month':
					$key = date( 'Y-m', strtotime( $date ) );
					break;
				case 'quarter':
					$key = date( 'Y', strtotime( $date ) ) . '-Q' . ceil( date( 'n', strtotime( $date ) ) / 3 );
					break;
				case 'year':
					$key = date( 'Y', strtotime( $date ) );
					break;
				default:
					$key = date( 'Y-m-d', strtotime( $date ) );
			}

			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'interval'       => $key,
					'date_start'     => $key,
					'date_start_gmt' => $key,
					'date_end'       => $key,
					'date_end_gmt'   => $key,
					'subtotals'      => array(
						'agent_orders'  => 0,
						'agent_revenue' => 0,
					),
				);
			}

			++$grouped[ $key ]['subtotals']['agent_orders'];
			$grouped[ $key ]['subtotals']['agent_revenue'] += $order['total_amount'];
		}

		return array_values( $grouped );
	}

	/**
	 * Register data stores.
	 *
	 * @param array $stores Data stores.
	 * @return array
	 */
	public function register_data_stores( $stores ) {
		$stores['report-ap2-agents']       = 'AP2_Gateway\Admin\AP2_Agents_Data_Store';
		$stores['report-ap2-agents-stats'] = 'AP2_Gateway\Admin\AP2_Agents_Stats_Data_Store';
		return $stores;
	}

	/**
	 * Register reports data stores.
	 *
	 * @param array $stores Data stores.
	 * @return array
	 */
	public function register_reports_data_stores( $stores ) {
		$stores['report-ap2-agents']       = 'AP2_Gateway\Admin\AP2_Agents_Data_Store';
		$stores['report-ap2-agents-stats'] = 'AP2_Gateway\Admin\AP2_Agents_Stats_Data_Store';
		return $stores;
	}

	/**
	 * Register scripts.
	 */
	public function register_scripts() {
		if ( ! $this->is_analytics_page() ) {
			return;
		}

		$script_path  = AP2_GATEWAY_PLUGIN_URL . 'assets/js/admin/analytics.js';
		$script_asset = array(
			'dependencies' => array(
				'wp-hooks',
				'wp-element',
				'wp-i18n',
				'wc-components',
				'wc-navigation',
				'wc-date',
				'wc-currency',
				'wc-tracks',
			),
			'version'      => AP2_GATEWAY_VERSION,
		);

		wp_register_script(
			'ap2-analytics',
			$script_path,
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_localize_script(
			'ap2-analytics',
			'ap2Analytics',
			array(
				'currency'    => get_woocommerce_currency_symbol(),
				'date_format' => wc_date_format(),
				'has_orders'  => $this->has_agent_orders(),
			)
		);
	}

	/**
	 * Check if on analytics page.
	 *
	 * @return bool
	 */
	private function is_analytics_page() {
		$screen = get_current_screen();
		return $screen && 'woocommerce_page_wc-admin' === $screen->id
			&& isset( $_GET['path'] ) && '/analytics/ap2-agents' === $_GET['path'];
	}

	/**
	 * Check if there are agent orders.
	 *
	 * @return bool
	 */
	private function has_agent_orders() {
		global $wpdb;

		// Check cache first.
		$cache_key = 'ap2_has_agent_orders';
		$cached    = wp_cache_get( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// Check database.
		$count = $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->prefix}wc_orders o
			INNER JOIN {$wpdb->prefix}wc_orders_meta m ON o.id = m.order_id
			WHERE m.meta_key = '_ap2_agent_id'
			LIMIT 1"
		);

		$has_orders = $count > 0;

		// Cache for 5 minutes.
		wp_cache_set( $cache_key, $has_orders, '', 300 );

		return $has_orders;
	}

	/**
	 * Add navigation menu item.
	 */
	public function possibly_add_navigation() {
		if ( ! $this->has_agent_orders() || ! current_user_can( 'view_woocommerce_reports' ) ) {
			return;
		}

		if ( class_exists( '\Automattic\WooCommerce\Admin\Features\Navigation\Menu' ) ) {
			Menu::add_plugin_item(
				array(
					'id'         => 'ap2-agent-analytics',
					'title'      => __( 'AP2 Agents', 'ap2-gateway' ),
					'capability' => 'view_woocommerce_reports',
					'url'        => 'admin.php?page=wc-admin&path=/analytics/ap2-agents',
					'parent'     => 'analytics',
				)
			);
		}
	}

	/**
	 * Add component settings.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public function add_component_settings( $settings ) {
		$settings['ap2HasAgentOrders'] = $this->has_agent_orders();
		return $settings;
	}

	/**
	 * Add fallback menu if WC Admin not available.
	 */
	public function add_fallback_menu() {
		// Only add if there are agent orders.
		if ( ! $this->has_agent_orders() ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'AP2 Analytics', 'ap2-gateway' ),
			__( 'AP2 Analytics', 'ap2-gateway' ),
			'view_woocommerce_reports',
			'ap2-analytics',
			array( $this, 'render_fallback_page' )
		);
	}

	/**
	 * Render fallback page.
	 */
	public function render_fallback_page() {
		// Use the simple analytics if WC Admin not available.
		if ( class_exists( 'AP2_Analytics_Simple' ) ) {
			\AP2_Analytics_Simple::instance()->render_analytics_page();
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'AP2 Analytics', 'ap2-gateway' ) . '</h1>';
			echo '<p>' . esc_html__( 'WooCommerce Admin is required for full analytics features.', 'ap2-gateway' ) . '</p></div>';
		}
	}
}

// Initialize only if in admin.
if ( is_admin() ) {
	AP2_Analytics::instance();
}
