<?php
/**
 * AP2 Admin Dashboard
 *
 * Comprehensive dashboard for AP2 agent transactions and analytics.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Admin Dashboard Class.
 */
class AP2_Admin_Dashboard {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_dashboard_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_scripts' ) );
		add_action( 'wp_ajax_ap2_refresh_stats', array( $this, 'ajax_refresh_stats' ) );
	}

	/**
	 * Add dashboard menu item.
	 */
	public function add_dashboard_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AP2 Dashboard', 'ap2-gateway' ),
			__( 'AP2 Dashboard', 'ap2-gateway' ),
			'manage_woocommerce',
			'ap2-dashboard',
			array( $this, 'render_dashboard_page' )
		);
	}

	/**
	 * Enqueue dashboard scripts and styles.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_dashboard_scripts( $hook ) {
		if ( 'woocommerce_page_ap2-dashboard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ap2-dashboard',
			AP2_GATEWAY_PLUGIN_URL . 'assets/css/ap2-dashboard.css',
			array(),
			AP2_GATEWAY_VERSION
		);

		wp_enqueue_script(
			'ap2-dashboard',
			AP2_GATEWAY_PLUGIN_URL . 'assets/js/ap2-dashboard.js',
			array( 'jquery' ),
			AP2_GATEWAY_VERSION,
			true
		);

		wp_localize_script(
			'ap2-dashboard',
			'ap2_dashboard',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ap2_dashboard' ),
			)
		);
	}

	/**
	 * Get agent transaction statistics.
	 *
	 * @return array Statistics data.
	 */
	private function get_transaction_statistics() {
		$stats = array(
			'total_agent_orders'    => 0,
			'total_agent_revenue'   => 0,
			'total_human_orders'    => 0,
			'total_human_revenue'   => 0,
			'agent_conversion_rate' => 0,
			'human_conversion_rate' => 0,
			'top_products'          => array(),
			'monthly_revenue'       => array(),
			'agent_order_statuses'  => array(),
		);

		// Check if HPOS is enabled.
		$is_hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
		                   \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $is_hpos_enabled ) {
			// Use HPOS-compatible queries.
			$agent_orders = wc_get_orders( array(
				'limit'      => -1,
				'meta_key'   => '_ap2_is_agent_order',
				'meta_value' => 'yes',
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
			) );

			// Process agent orders.
			foreach ( $agent_orders as $order ) {
				$stats['total_agent_orders']++;
				$stats['total_agent_revenue'] += floatval( $order->get_total() );

				// Track order statuses.
				$status = $order->get_status();
				if ( ! isset( $stats['agent_order_statuses'][ $status ] ) ) {
					$stats['agent_order_statuses'][ $status ] = 0;
				}
				$stats['agent_order_statuses'][ $status ]++;

				// Track monthly revenue.
				$month = $order->get_date_created()->format( 'Y-m' );
				if ( ! isset( $stats['monthly_revenue'][ $month ] ) ) {
					$stats['monthly_revenue'][ $month ] = array(
						'agent' => 0,
						'human' => 0,
					);
				}
				$stats['monthly_revenue'][ $month ]['agent'] += floatval( $order->get_total() );
			}

			// Get all orders for totals.
			$all_orders = wc_get_orders( array(
				'limit'   => -1,
				'status'  => array( 'completed', 'processing', 'pending', 'on-hold' ),
				'return'  => 'objects',
			) );

			$total_orders_count = count( $all_orders );
			$total_orders_revenue = 0;

			foreach ( $all_orders as $order ) {
				$total_orders_revenue += floatval( $order->get_total() );
			}

			$stats['total_human_orders']  = $total_orders_count - $stats['total_agent_orders'];
			$stats['total_human_revenue'] = $total_orders_revenue - $stats['total_agent_revenue'];

		} else {
			// Legacy database queries for non-HPOS.
			global $wpdb;

			$agent_orders = $wpdb->get_results(
				"
				SELECT p.ID, p.post_date, pm.meta_value as total, p.post_status
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order'
				AND pm1.meta_key = '_ap2_is_agent_order'
				AND pm1.meta_value = 'yes'
				ORDER BY p.post_date DESC
				"
			);

			// Calculate agent statistics.
			foreach ( $agent_orders as $order ) {
				$stats['total_agent_orders']++;
				$stats['total_agent_revenue'] += floatval( $order->total );

				// Track order statuses.
				$status = str_replace( 'wc-', '', $order->post_status );
				if ( ! isset( $stats['agent_order_statuses'][ $status ] ) ) {
					$stats['agent_order_statuses'][ $status ] = 0;
				}
				$stats['agent_order_statuses'][ $status ]++;

				// Track monthly revenue.
				$month = gmdate( 'Y-m', strtotime( $order->post_date ) );
				if ( ! isset( $stats['monthly_revenue'][ $month ] ) ) {
					$stats['monthly_revenue'][ $month ] = array(
						'agent' => 0,
						'human' => 0,
					);
				}
				$stats['monthly_revenue'][ $month ]['agent'] += floatval( $order->total );
			}

			// Get total orders count (human orders).
			$total_orders = $wpdb->get_row(
				"
				SELECT COUNT(*) as count, SUM(pm.meta_value) as total
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-pending', 'wc-on-hold')
				"
			);

			$stats['total_human_orders']  = $total_orders->count - $stats['total_agent_orders'];
			$stats['total_human_revenue'] = floatval( $total_orders->total ) - $stats['total_agent_revenue'];
		}

		// Calculate conversion rates (simplified - would need session data for accuracy).
		$agent_visits = get_transient( AP2_Agent_Detector::STATS_TRANSIENT_KEY );
		if ( $agent_visits && isset( $agent_visits['total_visits'] ) && $agent_visits['total_visits'] > 0 ) {
			$stats['agent_conversion_rate'] = round( ( $stats['total_agent_orders'] / $agent_visits['total_visits'] ) * 100, 2 );
		}

		// Get top products purchased by agents (works for both HPOS and legacy).
		if ( ! $is_hpos_enabled ) {
			global $wpdb;
			$top_products = $wpdb->get_results(
				"
				SELECT oi.order_item_name as product_name,
				       COUNT(*) as purchase_count,
				       SUM(oim.meta_value) as total_revenue
				FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
				INNER JOIN {$wpdb->postmeta} pm ON oi.order_id = pm.post_id
				WHERE oi.order_item_type = 'line_item'
				AND oim.meta_key = '_line_total'
				AND pm.meta_key = '_ap2_is_agent_order'
				AND pm.meta_value = 'yes'
				GROUP BY oi.order_item_name
				ORDER BY purchase_count DESC
				LIMIT 5
				"
			);
			$stats['top_products'] = $top_products;
		} else {
			// For HPOS, process top products from the agent orders.
			$product_stats = array();
			foreach ( $agent_orders as $order ) {
				foreach ( $order->get_items() as $item ) {
					$product_name = $item->get_name();
					if ( ! isset( $product_stats[ $product_name ] ) ) {
						$product_stats[ $product_name ] = array(
							'product_name'    => $product_name,
							'purchase_count'  => 0,
							'total_revenue'   => 0,
						);
					}
					$product_stats[ $product_name ]['purchase_count']++;
					$product_stats[ $product_name ]['total_revenue'] += $item->get_total();
				}
			}

			// Sort and limit to top 5.
			usort( $product_stats, function( $a, $b ) {
				return $b['purchase_count'] - $a['purchase_count'];
			} );

			$stats['top_products'] = array_map( function( $item ) {
				return (object) $item;
			}, array_slice( $product_stats, 0, 5 ) );
		}

		return $stats;
	}

	/**
	 * Get recent agent orders.
	 *
	 * @param int $limit Number of orders to retrieve.
	 * @return array Recent orders.
	 */
	private function get_recent_agent_orders( $limit = 10 ) {
		// Check if HPOS is enabled.
		$is_hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
		                   \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $is_hpos_enabled ) {
			// Use HPOS-compatible query.
			$orders = wc_get_orders( array(
				'limit'      => $limit,
				'meta_key'   => '_ap2_is_agent_order',
				'meta_value' => 'yes',
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
			) );

			// Convert to expected format.
			$formatted_orders = array();
			foreach ( $orders as $order ) {
				$formatted_orders[] = (object) array(
					'ID'          => $order->get_id(),
					'post_date'   => $order->get_date_created()->format( 'Y-m-d H:i:s' ),
					'post_status' => 'wc-' . $order->get_status(),
					'agent_id'    => $order->get_meta( '_ap2_agent_id' ),
					'total'       => $order->get_total(),
					'currency'    => $order->get_currency(),
				);
			}

			return $formatted_orders;

		} else {
			// Legacy database query.
			global $wpdb;

			$orders = $wpdb->get_results(
				$wpdb->prepare(
					"
					SELECT p.ID, p.post_date, p.post_status,
					       pm1.meta_value as agent_id,
					       pm2.meta_value as total,
					       pm3.meta_value as currency
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_ap2_agent_id'
					LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_order_total'
					LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_order_currency'
					WHERE p.post_type = 'shop_order'
					AND pm.meta_key = '_ap2_is_agent_order'
					AND pm.meta_value = 'yes'
					ORDER BY p.post_date DESC
					LIMIT %d
					",
					$limit
				)
			);

			return $orders;
		}
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page() {
		$stats = $this->get_transaction_statistics();
		$recent_orders = $this->get_recent_agent_orders( 15 );
		$gateway = new WC_Gateway_AP2();
		$is_test_mode = $gateway->testmode;
		?>
		<div class="wrap ap2-dashboard">
			<h1>
				<?php esc_html_e( '🤖 AP2 Agent Dashboard', 'ap2-gateway' ); ?>
				<?php if ( $is_test_mode ) : ?>
					<span class="ap2-test-mode-badge"><?php esc_html_e( 'TEST MODE', 'ap2-gateway' ); ?></span>
				<?php endif; ?>
			</h1>

			<!-- Quick Actions Bar -->
			<div class="ap2-quick-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ap2_agent_payments' ) ); ?>" class="button">
					⚙️ <?php esc_html_e( 'Gateway Settings', 'ap2-gateway' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ap2-agent-stats' ) ); ?>" class="button">
					📊 <?php esc_html_e( 'Visit Statistics', 'ap2-gateway' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>" class="button">
					📦 <?php esc_html_e( 'All Orders', 'ap2-gateway' ); ?>
				</a>
				<button class="button button-primary" id="ap2-refresh-stats">
					🔄 <?php esc_html_e( 'Refresh Stats', 'ap2-gateway' ); ?>
				</button>
			</div>

			<!-- Statistics Cards -->
			<div class="ap2-stats-cards">
				<!-- Agent Orders Card -->
				<div class="ap2-stat-card">
					<div class="ap2-stat-icon">🤖</div>
					<div class="ap2-stat-content">
						<h3><?php esc_html_e( 'Agent Orders', 'ap2-gateway' ); ?></h3>
						<div class="ap2-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_agent_orders'] ) ); ?></div>
						<div class="ap2-stat-label">
							<?php
							echo esc_html(
								wc_price( $stats['total_agent_revenue'] )
							);
							?>
							<?php esc_html_e( 'revenue', 'ap2-gateway' ); ?>
						</div>
					</div>
				</div>

				<!-- Human Orders Card -->
				<div class="ap2-stat-card">
					<div class="ap2-stat-icon">👤</div>
					<div class="ap2-stat-content">
						<h3><?php esc_html_e( 'Human Orders', 'ap2-gateway' ); ?></h3>
						<div class="ap2-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_human_orders'] ) ); ?></div>
						<div class="ap2-stat-label">
							<?php
							echo esc_html(
								wc_price( $stats['total_human_revenue'] )
							);
							?>
							<?php esc_html_e( 'revenue', 'ap2-gateway' ); ?>
						</div>
					</div>
				</div>

				<!-- Conversion Rate Card -->
				<div class="ap2-stat-card">
					<div class="ap2-stat-icon">📈</div>
					<div class="ap2-stat-content">
						<h3><?php esc_html_e( 'Agent Conversion', 'ap2-gateway' ); ?></h3>
						<div class="ap2-stat-number"><?php echo esc_html( $stats['agent_conversion_rate'] ); ?>%</div>
						<div class="ap2-stat-label">
							<?php
							$agent_percentage = $stats['total_agent_orders'] > 0 && ( $stats['total_agent_orders'] + $stats['total_human_orders'] ) > 0
								? round( ( $stats['total_agent_orders'] / ( $stats['total_agent_orders'] + $stats['total_human_orders'] ) ) * 100, 1 )
								: 0;
							printf(
								/* translators: %s: percentage of orders from agents */
								esc_html__( '%s%% of all orders', 'ap2-gateway' ),
								esc_html( $agent_percentage )
							);
							?>
						</div>
					</div>
				</div>

				<!-- Average Order Value Card -->
				<div class="ap2-stat-card">
					<div class="ap2-stat-icon">💰</div>
					<div class="ap2-stat-content">
						<h3><?php esc_html_e( 'Avg Agent Order', 'ap2-gateway' ); ?></h3>
						<div class="ap2-stat-number">
							<?php
							$avg_order_value = $stats['total_agent_orders'] > 0
								? $stats['total_agent_revenue'] / $stats['total_agent_orders']
								: 0;
							echo esc_html( wc_price( $avg_order_value ) );
							?>
						</div>
						<div class="ap2-stat-label">
							<?php esc_html_e( 'per transaction', 'ap2-gateway' ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Main Content Grid -->
			<div class="ap2-dashboard-grid">
				<!-- Recent Orders Section -->
				<div class="ap2-dashboard-section ap2-recent-orders">
					<h2><?php esc_html_e( 'Recent Agent Orders', 'ap2-gateway' ); ?></h2>
					<?php if ( ! empty( $recent_orders ) ) : ?>
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
								<?php foreach ( $recent_orders as $order ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order->ID . '&action=edit' ) ); ?>">
												#<?php echo esc_html( $order->ID ); ?>
											</a>
										</td>
										<td>
											<?php echo esc_html( gmdate( 'Y-m-d H:i', strtotime( $order->post_date ) ) ); ?>
										</td>
										<td>
											<code><?php echo esc_html( $order->agent_id ?: __( 'Unknown', 'ap2-gateway' ) ); ?></code>
										</td>
										<td>
											<?php
											$status = str_replace( 'wc-', '', $order->post_status );
											$status_label = wc_get_order_status_name( $status );
											?>
											<mark class="order-status status-<?php echo esc_attr( $status ); ?>">
												<span><?php echo esc_html( $status_label ); ?></span>
											</mark>
										</td>
										<td>
											<?php
											echo wp_kses_post(
												wc_price(
													$order->total,
													array( 'currency' => $order->currency )
												)
											);
											?>
										</td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order->ID . '&action=edit' ) ); ?>" class="button button-small">
												<?php esc_html_e( 'View', 'ap2-gateway' ); ?>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="ap2-no-data">
							<?php esc_html_e( 'No agent orders found yet.', 'ap2-gateway' ); ?>
							<?php if ( $is_test_mode ) : ?>
								<br><em><?php esc_html_e( 'Test mode is enabled - try making a test purchase as an agent.', 'ap2-gateway' ); ?></em>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- Top Products Section -->
				<div class="ap2-dashboard-section ap2-top-products">
					<h2><?php esc_html_e( 'Most Purchased by Agents', 'ap2-gateway' ); ?></h2>
					<?php if ( ! empty( $stats['top_products'] ) ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'ap2-gateway' ); ?></th>
									<th><?php esc_html_e( 'Purchases', 'ap2-gateway' ); ?></th>
									<th><?php esc_html_e( 'Revenue', 'ap2-gateway' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $stats['top_products'] as $product ) : ?>
									<tr>
										<td><?php echo esc_html( $product->product_name ); ?></td>
										<td><?php echo esc_html( $product->purchase_count ); ?></td>
										<td><?php echo wp_kses_post( wc_price( $product->total_revenue ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p class="ap2-no-data"><?php esc_html_e( 'No product data available yet.', 'ap2-gateway' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Order Status Distribution -->
				<?php if ( ! empty( $stats['agent_order_statuses'] ) ) : ?>
					<div class="ap2-dashboard-section ap2-status-distribution">
						<h2><?php esc_html_e( 'Agent Order Status Distribution', 'ap2-gateway' ); ?></h2>
						<div class="ap2-status-bars">
							<?php
							$total_status_orders = array_sum( $stats['agent_order_statuses'] );
							foreach ( $stats['agent_order_statuses'] as $status => $count ) :
								$percentage = $total_status_orders > 0 ? ( $count / $total_status_orders ) * 100 : 0;
								?>
								<div class="ap2-status-bar">
									<div class="ap2-status-label">
										<span class="status-name"><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></span>
										<span class="status-count"><?php echo esc_html( $count ); ?></span>
									</div>
									<div class="ap2-status-progress">
										<div class="ap2-status-progress-bar status-<?php echo esc_attr( $status ); ?>" style="width: <?php echo esc_attr( $percentage ); ?>%"></div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<!-- Monthly Comparison -->
				<?php if ( ! empty( $stats['monthly_revenue'] ) ) : ?>
					<div class="ap2-dashboard-section ap2-monthly-comparison">
						<h2><?php esc_html_e( 'Agent vs Human Revenue (Monthly)', 'ap2-gateway' ); ?></h2>
						<div class="ap2-chart-container">
							<?php
							$max_revenue = 0;
							foreach ( $stats['monthly_revenue'] as $data ) {
								$month_total = $data['agent'] + $data['human'];
								if ( $month_total > $max_revenue ) {
									$max_revenue = $month_total;
								}
							}

							foreach ( array_slice( $stats['monthly_revenue'], -6, 6, true ) as $month => $data ) :
								$agent_height = $max_revenue > 0 ? ( $data['agent'] / $max_revenue ) * 100 : 0;
								$human_height = $max_revenue > 0 ? ( $data['human'] / $max_revenue ) * 100 : 0;
								?>
								<div class="ap2-chart-group">
									<div class="ap2-chart-bars">
										<div class="ap2-chart-bar agent" style="height: <?php echo esc_attr( $agent_height ); ?>%;"
											 title="<?php echo esc_attr( sprintf( __( 'Agent: %s', 'ap2-gateway' ), wc_price( $data['agent'] ) ) ); ?>">
										</div>
										<div class="ap2-chart-bar human" style="height: <?php echo esc_attr( $human_height ); ?>%;"
											 title="<?php echo esc_attr( sprintf( __( 'Human: %s', 'ap2-gateway' ), wc_price( $data['human'] ) ) ); ?>">
										</div>
									</div>
									<div class="ap2-chart-label"><?php echo esc_html( gmdate( 'M', strtotime( $month . '-01' ) ) ); ?></div>
								</div>
							<?php endforeach; ?>
						</div>
						<div class="ap2-chart-legend">
							<span class="legend-item agent">🤖 <?php esc_html_e( 'Agent Revenue', 'ap2-gateway' ); ?></span>
							<span class="legend-item human">👤 <?php esc_html_e( 'Human Revenue', 'ap2-gateway' ); ?></span>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Info Section -->
			<div class="ap2-dashboard-info">
				<div class="ap2-info-card">
					<h3><?php esc_html_e( '📘 Quick Help', 'ap2-gateway' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Agent orders are automatically detected and tracked', 'ap2-gateway' ); ?></li>
						<li><?php esc_html_e( 'Statistics update in real-time as orders are placed', 'ap2-gateway' ); ?></li>
						<li><?php esc_html_e( 'Test mode allows safe testing without real transactions', 'ap2-gateway' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: settings page URL */
								esc_html__( 'Configure gateway settings in %s', 'ap2-gateway' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ap2_agent_payments' ) ) . '">' . esc_html__( 'WooCommerce Settings', 'ap2-gateway' ) . '</a>'
							);
							?>
						</li>
					</ul>
				</div>

				<?php if ( $is_test_mode ) : ?>
					<div class="ap2-info-card ap2-test-mode-info">
						<h3><?php esc_html_e( '🧪 Test Mode Active', 'ap2-gateway' ); ?></h3>
						<p><?php esc_html_e( 'The gateway is currently in test mode. Agent payments will be simulated and no real charges will occur.', 'ap2-gateway' ); ?></p>
						<p>
							<?php esc_html_e( 'To test as an agent:', 'ap2-gateway' ); ?>
						</p>
						<ul>
							<li><?php esc_html_e( 'Add ?ap2_agent=true to any URL', 'ap2-gateway' ); ?></li>
							<li><?php esc_html_e( 'Use any Agent ID and Mandate Token', 'ap2-gateway' ); ?></li>
							<li><?php esc_html_e( 'Orders will be marked with TEST- prefix', 'ap2-gateway' ); ?></li>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for refreshing statistics.
	 */
	public function ajax_refresh_stats() {
		check_ajax_referer( 'ap2_dashboard', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$stats = $this->get_transaction_statistics();

		wp_send_json_success( array(
			'agent_orders'   => number_format_i18n( $stats['total_agent_orders'] ),
			'agent_revenue'  => wc_price( $stats['total_agent_revenue'] ),
			'human_orders'   => number_format_i18n( $stats['total_human_orders'] ),
			'human_revenue'  => wc_price( $stats['total_human_revenue'] ),
			'conversion'     => $stats['agent_conversion_rate'] . '%',
		) );
	}
}

// Initialize the dashboard.
new AP2_Admin_Dashboard();