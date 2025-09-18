<?php
/**
 * AP2 Analytics Simple Integration
 *
 * Simple analytics page under WooCommerce menu.
 *
 * @package AP2_Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Analytics Simple Class.
 */
class AP2_Analytics_Simple {

	/**
	 * Single instance.
	 *
	 * @var AP2_Analytics_Simple
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AP2_Analytics_Simple
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
		// Add submenu page under WooCommerce - high priority to ensure it runs.
		add_action( 'admin_menu', array( $this, 'add_analytics_menu' ), 55 );

		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_api_endpoints' ) );

		// Remove any conflicting Analytics menu items.
		add_filter( 'woocommerce_analytics_report_menu_items', array( $this, 'remove_analytics_conflicts' ), 999 );
		add_filter( 'woocommerce_admin_navigation_menu_items', array( $this, 'remove_nav_conflicts' ), 999 );
	}

	/**
	 * Add analytics submenu under WooCommerce.
	 */
	public function add_analytics_menu() {
		// Check if WooCommerce menu exists first.
		global $menu, $submenu;

		// Try to add under WooCommerce.
		$result = add_submenu_page(
			'woocommerce',
			__( 'AP2 Agent Analytics', 'ap2-gateway' ),
			__( 'AP2 Analytics', 'ap2-gateway' ),
			'manage_woocommerce',
			'ap2-analytics',
			array( $this, 'render_analytics_page' )
		);

		// Debug: Log if menu was added successfully.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AP2 Analytics menu add result: ' . ( $result ? 'success' : 'failed' ) );
			error_log( 'Current user can manage_woocommerce: ' . ( current_user_can( 'manage_woocommerce' ) ? 'yes' : 'no' ) );
		}
	}

	/**
	 * Render the analytics page.
	 */
	public function render_analytics_page() {
		// Check permissions.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ap2-gateway' ) );
		}

		// Get analytics data.
		$data = $this->get_analytics_data();
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-chart-bar" style="font-size: 30px; width: 30px; height: 30px; margin-right: 10px;"></span>
				<?php esc_html_e( 'AP2 Agent Analytics', 'ap2-gateway' ); ?>
			</h1>

			<!-- Date Range Selector -->
			<div class="tablenav top">
				<div class="alignleft actions">
					<form method="get" action="">
						<input type="hidden" name="page" value="ap2-analytics" />
						<select name="range" id="date-range">
							<option value="7day" <?php selected( isset( $_GET['range'] ) ? $_GET['range'] : '7day', '7day' ); ?>>
								<?php esc_html_e( 'Last 7 Days', 'ap2-gateway' ); ?>
							</option>
							<option value="30day" <?php selected( isset( $_GET['range'] ) ? $_GET['range'] : '', '30day' ); ?>>
								<?php esc_html_e( 'Last 30 Days', 'ap2-gateway' ); ?>
							</option>
							<option value="3month" <?php selected( isset( $_GET['range'] ) ? $_GET['range'] : '', '3month' ); ?>>
								<?php esc_html_e( 'Last 3 Months', 'ap2-gateway' ); ?>
							</option>
						</select>
						<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'ap2-gateway' ); ?>" />
					</form>
				</div>
			</div>

			<!-- Summary Cards -->
			<div class="ap2-summary-cards">
				<div class="ap2-card">
					<div class="ap2-card-icon">🤖</div>
					<div class="ap2-card-content">
						<div class="ap2-card-title"><?php esc_html_e( 'Agent Orders', 'ap2-gateway' ); ?></div>
						<div class="ap2-card-value"><?php echo esc_html( number_format_i18n( $data['agent_orders'] ) ); ?></div>
						<div class="ap2-card-subtitle">
							<?php echo wp_kses_post( wc_price( $data['agent_revenue'] ) ); ?>
							<?php esc_html_e( 'revenue', 'ap2-gateway' ); ?>
						</div>
					</div>
				</div>

				<div class="ap2-card">
					<div class="ap2-card-icon">👤</div>
					<div class="ap2-card-content">
						<div class="ap2-card-title"><?php esc_html_e( 'Human Orders', 'ap2-gateway' ); ?></div>
						<div class="ap2-card-value"><?php echo esc_html( number_format_i18n( $data['human_orders'] ) ); ?></div>
						<div class="ap2-card-subtitle">
							<?php echo wp_kses_post( wc_price( $data['human_revenue'] ) ); ?>
							<?php esc_html_e( 'revenue', 'ap2-gateway' ); ?>
						</div>
					</div>
				</div>

				<div class="ap2-card">
					<div class="ap2-card-icon">📈</div>
					<div class="ap2-card-content">
						<div class="ap2-card-title"><?php esc_html_e( 'Agent Share', 'ap2-gateway' ); ?></div>
						<div class="ap2-card-value"><?php echo esc_html( $data['agent_percentage'] ); ?>%</div>
						<div class="ap2-card-subtitle"><?php esc_html_e( 'of total orders', 'ap2-gateway' ); ?></div>
					</div>
				</div>

				<div class="ap2-card">
					<div class="ap2-card-icon">💰</div>
					<div class="ap2-card-content">
						<div class="ap2-card-title"><?php esc_html_e( 'Avg Agent Order', 'ap2-gateway' ); ?></div>
						<div class="ap2-card-value"><?php echo wp_kses_post( wc_price( $data['avg_agent_order'] ) ); ?></div>
						<div class="ap2-card-subtitle"><?php esc_html_e( 'per transaction', 'ap2-gateway' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Top Agents Table -->
			<div class="ap2-section">
				<h2><?php esc_html_e( 'Top Agents', 'ap2-gateway' ); ?></h2>
				<?php if ( ! empty( $data['top_agents'] ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Agent ID', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Orders', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Total Revenue', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Avg Order Value', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Last Order', 'ap2-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['top_agents'] as $agent ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $agent['agent_id'] ); ?></strong>
										<?php if ( $agent['is_test'] ) : ?>
											<span class="ap2-test-badge"><?php esc_html_e( 'TEST', 'ap2-gateway' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $agent['order_count'] ); ?></td>
									<td><?php echo wp_kses_post( wc_price( $agent['total_revenue'] ) ); ?></td>
									<td><?php echo wp_kses_post( wc_price( $agent['avg_order'] ) ); ?></td>
									<td><?php echo esc_html( $agent['last_order_date'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'No agent orders found yet. Agent orders will appear here once AI agents start making purchases.', 'ap2-gateway' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Recent Agent Orders -->
			<div class="ap2-section">
				<h2><?php esc_html_e( 'Recent Agent Orders', 'ap2-gateway' ); ?></h2>
				<?php if ( ! empty( $data['recent_orders'] ) ) : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Date', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Agent ID', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Status', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Total', 'ap2-gateway' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'ap2-gateway' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['recent_orders'] as $order ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( $order['edit_url'] ); ?>">
											#<?php echo esc_html( $order['order_number'] ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $order['date'] ); ?></td>
									<td>
										<?php echo esc_html( $order['agent_id'] ); ?>
										<?php if ( $order['is_test'] ) : ?>
											<span class="ap2-test-badge"><?php esc_html_e( 'TEST', 'ap2-gateway' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<mark class="order-status status-<?php echo esc_attr( $order['status'] ); ?>">
											<span><?php echo esc_html( wc_get_order_status_name( $order['status'] ) ); ?></span>
										</mark>
									</td>
									<td><?php echo wp_kses_post( wc_price( $order['total'] ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( $order['edit_url'] ); ?>" class="button button-small">
											<?php esc_html_e( 'View', 'ap2-gateway' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'No recent agent orders.', 'ap2-gateway' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<style>
			.ap2-summary-cards {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
				gap: 20px;
				margin: 20px 0 30px;
			}

			.ap2-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 20px;
				display: flex;
				align-items: flex-start;
				gap: 15px;
				box-shadow: 0 1px 1px rgba(0,0,0,0.04);
			}

			.ap2-card-icon {
				font-size: 32px;
				line-height: 1;
			}

			.ap2-card-content {
				flex: 1;
			}

			.ap2-card-title {
				color: #50575e;
				font-size: 11px;
				text-transform: uppercase;
				font-weight: 600;
				margin-bottom: 5px;
			}

			.ap2-card-value {
				font-size: 24px;
				font-weight: 400;
				color: #2c3338;
				margin-bottom: 5px;
			}

			.ap2-card-subtitle {
				color: #787c82;
				font-size: 13px;
			}

			.ap2-section {
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
				padding: 20px;
				margin-bottom: 20px;
			}

			.ap2-section h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #e0e0e0;
			}

			.ap2-test-badge {
				background: #ffb900;
				color: #fff;
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 10px;
				font-weight: 600;
				margin-left: 5px;
			}

			.notice.inline {
				margin: 15px 0;
			}
		</style>
		<?php
	}

	/**
	 * Get analytics data.
	 *
	 * @return array
	 */
	private function get_analytics_data() {
		global $wpdb;

		// Get date range.
		$range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7day';
		$date_from = '';

		switch ( $range ) {
			case '30day':
				$date_from = date( 'Y-m-d', strtotime( '-30 days' ) );
				break;
			case '3month':
				$date_from = date( 'Y-m-d', strtotime( '-3 months' ) );
				break;
			default:
				$date_from = date( 'Y-m-d', strtotime( '-7 days' ) );
		}

		// Get agent orders.
		$agent_orders = wc_get_orders( array(
			'limit'        => -1,
			'date_created' => '>=' . $date_from,
			'meta_key'     => '_ap2_agent_id',
			'meta_compare' => 'EXISTS',
			'return'       => 'ids',
		) );

		// Get all orders.
		$all_orders = wc_get_orders( array(
			'limit'        => -1,
			'date_created' => '>=' . $date_from,
			'return'       => 'ids',
		) );

		// Calculate totals.
		$agent_revenue = 0;
		$agent_count = count( $agent_orders );

		foreach ( $agent_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$agent_revenue += $order->get_total();
			}
		}

		$total_revenue = 0;
		$total_count = count( $all_orders );

		foreach ( $all_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$total_revenue += $order->get_total();
			}
		}

		// Get top agents.
		$top_agents_data = array();
		if ( $agent_count > 0 ) {
			$agent_stats = array();

			foreach ( $agent_orders as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$agent_id = $order->get_meta( '_ap2_agent_id' );
					if ( ! isset( $agent_stats[ $agent_id ] ) ) {
						$agent_stats[ $agent_id ] = array(
							'count'      => 0,
							'revenue'    => 0,
							'last_order' => '',
							'is_test'    => false,
						);
					}
					$agent_stats[ $agent_id ]['count']++;
					$agent_stats[ $agent_id ]['revenue'] += $order->get_total();
					$agent_stats[ $agent_id ]['last_order'] = $order->get_date_created()->format( 'Y-m-d' );

					$transaction_id = $order->get_meta( '_ap2_transaction_id' );
					if ( strpos( $transaction_id, 'TEST-' ) === 0 ) {
						$agent_stats[ $agent_id ]['is_test'] = true;
					}
				}
			}

			// Sort by order count.
			uasort( $agent_stats, function( $a, $b ) {
				return $b['count'] - $a['count'];
			} );

			// Format top agents.
			$i = 0;
			foreach ( $agent_stats as $agent_id => $stats ) {
				if ( $i >= 10 ) break;
				$top_agents_data[] = array(
					'agent_id'        => $agent_id,
					'order_count'     => $stats['count'],
					'total_revenue'   => $stats['revenue'],
					'avg_order'       => $stats['revenue'] / $stats['count'],
					'last_order_date' => $stats['last_order'],
					'is_test'         => $stats['is_test'],
				);
				$i++;
			}
		}

		// Get recent orders.
		$recent_orders_data = array();
		$recent_order_ids = array_slice( $agent_orders, 0, 10 );

		foreach ( $recent_order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$transaction_id = $order->get_meta( '_ap2_transaction_id' );
				$recent_orders_data[] = array(
					'order_number' => $order->get_order_number(),
					'date'         => $order->get_date_created()->format( 'Y-m-d H:i' ),
					'agent_id'     => $order->get_meta( '_ap2_agent_id' ),
					'status'       => $order->get_status(),
					'total'        => $order->get_total(),
					'edit_url'     => admin_url( 'post.php?post=' . $order_id . '&action=edit' ),
					'is_test'      => strpos( $transaction_id, 'TEST-' ) === 0,
				);
			}
		}

		return array(
			'agent_orders'     => $agent_count,
			'agent_revenue'    => $agent_revenue,
			'human_orders'     => $total_count - $agent_count,
			'human_revenue'    => $total_revenue - $agent_revenue,
			'agent_percentage' => $total_count > 0 ? round( ( $agent_count / $total_count ) * 100, 1 ) : 0,
			'avg_agent_order'  => $agent_count > 0 ? $agent_revenue / $agent_count : 0,
			'top_agents'       => $top_agents_data,
			'recent_orders'    => $recent_orders_data,
		);
	}

	/**
	 * Register REST API endpoints.
	 */
	public function register_api_endpoints() {
		register_rest_route( 'ap2/v1', '/analytics/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_summary_data' ),
			'permission_callback' => array( $this, 'check_permissions' ),
		) );
	}

	/**
	 * Get summary data for REST API.
	 *
	 * @return array
	 */
	public function get_summary_data() {
		return $this->get_analytics_data();
	}

	/**
	 * Check permissions for REST API.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Remove conflicting Analytics menu items.
	 *
	 * @param array $items Menu items.
	 * @return array Filtered items.
	 */
	public function remove_analytics_conflicts( $items ) {
		// Remove any AP2 items from Analytics menu.
		return array_filter( $items, function( $item ) {
			return ! isset( $item['id'] ) || strpos( $item['id'], 'ap2' ) === false;
		} );
	}

	/**
	 * Remove conflicting navigation items.
	 *
	 * @param array $items Navigation items.
	 * @return array Filtered items.
	 */
	public function remove_nav_conflicts( $items ) {
		// Remove any AP2 items from Analytics navigation.
		return array_filter( $items, function( $item ) {
			return ! isset( $item['id'] ) || strpos( $item['id'], 'ap2' ) === false;
		} );
	}
}

// Initialize.
AP2_Analytics_Simple::instance();