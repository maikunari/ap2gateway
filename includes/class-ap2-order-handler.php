<?php
/**
 * AP2 Order Handler
 *
 * Handles agent order processing, meta data, and admin display.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Order Handler Class.
 */
class AP2_Order_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Check if HPOS is enabled.
		$is_hpos_enabled = $this->is_hpos_enabled();

		// Order list modifications - support both legacy and HPOS.
		if ( $is_hpos_enabled ) {
			// HPOS-compatible hooks.
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_agent_column' ) );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'display_agent_column_hpos' ), 10, 2 );
			add_filter( 'woocommerce_shop_order_list_table_sortable_columns', array( $this, 'make_agent_column_sortable' ) );
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_agent_filter_dropdown' ) );
			add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'filter_agent_orders_hpos' ) );
		} else {
			// Legacy post-based hooks.
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_agent_column' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'display_agent_column' ), 10, 2 );
			add_filter( 'manage_edit-shop_order_sortable_columns', array( $this, 'make_agent_column_sortable' ) );
			add_action( 'restrict_manage_posts', array( $this, 'add_agent_filter_dropdown' ) );
			add_filter( 'request', array( $this, 'filter_agent_orders' ) );
		}

		// Order admin meta box.
		add_action( 'add_meta_boxes', array( $this, 'add_agent_meta_box' ) );

		// Order details display.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_agent_info_in_order' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_agent_info_frontend' ) );

		// Order status modifications.
		add_filter( 'woocommerce_admin_order_preview_get_order_details', array( $this, 'add_agent_info_to_preview' ), 10, 2 );

		// Bulk actions.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_actions' ), 10, 3 );

		// Order search.
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'add_agent_search_fields' ) );

		// Add styles.
		add_action( 'admin_head', array( $this, 'add_admin_styles' ) );
	}

	/**
	 * Check if HPOS is enabled.
	 *
	 * @return bool True if HPOS is enabled.
	 */
	private function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * Add agent column to orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_agent_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $column_name => $column_info ) {
			$new_columns[ $column_name ] = $column_info;

			// Add agent column after order number.
			if ( 'order_number' === $column_name ) {
				$new_columns['ap2_agent'] = __( 'Agent', 'ap2-gateway' );
			}
		}

		return $new_columns;
	}

	/**
	 * Display agent column content (Legacy).
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 */
	public function display_agent_column( $column, $post_id ) {
		if ( 'ap2_agent' === $column ) {
			$order = wc_get_order( $post_id );
			$this->display_agent_column_content( $order );
		}
	}

	/**
	 * Display agent column content (HPOS).
	 *
	 * @param string   $column Column name.
	 * @param WC_Order $order Order object.
	 */
	public function display_agent_column_hpos( $column, $order ) {
		if ( 'ap2_agent' === $column ) {
			$this->display_agent_column_content( $order );
		}
	}

	/**
	 * Display agent column content helper.
	 *
	 * @param WC_Order|false $order Order object.
	 */
	private function display_agent_column_content( $order ) {
		if ( $order ) {
			$is_agent_order = $order->get_meta( '_ap2_is_agent_order' );
			$agent_id = $order->get_meta( '_ap2_agent_id' );

			if ( 'yes' === $is_agent_order ) {
				echo '<span class="ap2-agent-badge" title="' . esc_attr( sprintf( __( 'Agent ID: %s', 'ap2-gateway' ), $agent_id ) ) . '">';
				echo '🤖 ' . esc_html__( 'Agent', 'ap2-gateway' );
				echo '</span>';
				if ( $agent_id ) {
					echo '<br><small class="ap2-agent-id">' . esc_html( $agent_id ) . '</small>';
				}
			}
		}
	}

	/**
	 * Make agent column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified columns.
	 */
	public function make_agent_column_sortable( $columns ) {
		$columns['ap2_agent'] = 'ap2_agent';
		return $columns;
	}

	/**
	 * Add agent filter dropdown to orders list.
	 */
	public function add_agent_filter_dropdown() {
		global $typenow;

		// Check context for HPOS or legacy.
		$is_orders_screen = ( 'shop_order' === $typenow ) ||
		                    ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] );

		if ( $is_orders_screen ) {
			$selected = isset( $_GET['ap2_agent_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ap2_agent_filter'] ) ) : '';
			?>
			<select name="ap2_agent_filter" id="ap2_agent_filter">
				<option value=""><?php esc_html_e( 'All Orders', 'ap2-gateway' ); ?></option>
				<option value="agent" <?php selected( $selected, 'agent' ); ?>><?php esc_html_e( 'Agent Orders Only', 'ap2-gateway' ); ?></option>
				<option value="human" <?php selected( $selected, 'human' ); ?>><?php esc_html_e( 'Human Orders Only', 'ap2-gateway' ); ?></option>
			</select>
			<?php
		}
	}

	/**
	 * Filter orders based on agent selection.
	 *
	 * @param array $vars Query variables.
	 * @return array Modified query variables.
	 */
	public function filter_agent_orders( $vars ) {
		global $typenow;

		if ( 'shop_order' === $typenow && isset( $_GET['ap2_agent_filter'] ) && ! empty( $_GET['ap2_agent_filter'] ) ) {
			$filter = sanitize_text_field( wp_unslash( $_GET['ap2_agent_filter'] ) );

			if ( 'agent' === $filter ) {
				$vars['meta_query'][] = array(
					'key'     => '_ap2_is_agent_order',
					'value'   => 'yes',
					'compare' => '=',
				);
			} elseif ( 'human' === $filter ) {
				$vars['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'key'     => '_ap2_is_agent_order',
						'value'   => 'yes',
						'compare' => '!=',
					),
					array(
						'key'     => '_ap2_is_agent_order',
						'compare' => 'NOT EXISTS',
					),
				);
			}
		}

		// Handle sorting by agent.
		if ( isset( $vars['orderby'] ) && 'ap2_agent' === $vars['orderby'] ) {
			$vars['meta_key'] = '_ap2_is_agent_order';
			$vars['orderby']  = 'meta_value';
		}

		return $vars;
	}

	/**
	 * Filter orders based on agent selection (HPOS).
	 *
	 * @param array $query_args Query arguments.
	 * @return array Modified query arguments.
	 */
	public function filter_agent_orders_hpos( $query_args ) {
		if ( isset( $_GET['ap2_agent_filter'] ) && ! empty( $_GET['ap2_agent_filter'] ) ) {
			$filter = sanitize_text_field( wp_unslash( $_GET['ap2_agent_filter'] ) );

			if ( 'agent' === $filter ) {
				$query_args['meta_query'][] = array(
					'key'     => '_ap2_is_agent_order',
					'value'   => 'yes',
					'compare' => '=',
				);
			} elseif ( 'human' === $filter ) {
				$query_args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'key'     => '_ap2_is_agent_order',
						'value'   => 'yes',
						'compare' => '!=',
					),
					array(
						'key'     => '_ap2_is_agent_order',
						'compare' => 'NOT EXISTS',
					),
				);
			}
		}

		// Handle sorting by agent.
		if ( isset( $_GET['orderby'] ) && 'ap2_agent' === $_GET['orderby'] ) {
			$query_args['meta_key'] = '_ap2_is_agent_order';
			$query_args['orderby']  = 'meta_value';
		}

		return $query_args;
	}

	/**
	 * Add agent meta box to order edit screen.
	 */
	public function add_agent_meta_box() {
		add_meta_box(
			'ap2_agent_details',
			__( '🤖 AP2 Agent Details', 'ap2-gateway' ),
			array( $this, 'display_agent_meta_box' ),
			'shop_order',
			'side',
			'high'
		);
	}

	/**
	 * Display agent meta box content.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function display_agent_meta_box( $post ) {
		$order = wc_get_order( $post->ID );

		if ( ! $order ) {
			return;
		}

		$is_agent_order     = $order->get_meta( '_ap2_is_agent_order' );
		$agent_id           = $order->get_meta( '_ap2_agent_id' );
		$mandate_token      = $order->get_meta( '_ap2_mandate_token' );
		$transaction_type   = $order->get_meta( '_ap2_transaction_type' );
		$transaction_id     = $order->get_meta( '_ap2_transaction_id' );
		$detection_method   = $order->get_meta( '_ap2_agent_detection_method' );
		$audit_trail        = $order->get_meta( '_ap2_audit_trail' );
		$payment_timestamp  = $order->get_meta( '_ap2_payment_timestamp' );

		if ( 'yes' !== $is_agent_order ) {
			echo '<p>' . esc_html__( 'This is not an agent order.', 'ap2-gateway' ) . '</p>';
			return;
		}

		// Display agent information.
		?>
		<style>
			.ap2-meta-box-field {
				margin-bottom: 10px;
			}
			.ap2-meta-box-field label {
				display: block;
				font-weight: 600;
				margin-bottom: 3px;
				color: #23282d;
			}
			.ap2-meta-box-field .value {
				padding: 5px;
				background: #f1f1f1;
				border-radius: 3px;
				word-break: break-all;
				font-family: monospace;
				font-size: 12px;
			}
			.ap2-audit-trail {
				max-height: 200px;
				overflow-y: auto;
				padding: 10px;
				background: #f8f8f8;
				border: 1px solid #ddd;
				border-radius: 3px;
				font-size: 11px;
			}
			.ap2-agent-verified {
				color: #46b450;
				font-weight: bold;
			}
			.ap2-agent-test {
				color: #ff9800;
				font-weight: bold;
			}
		</style>

		<div class="ap2-agent-meta-box">
			<?php if ( $agent_id ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Agent ID:', 'ap2-gateway' ); ?></label>
					<div class="value"><?php echo esc_html( $agent_id ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $mandate_token ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Mandate Token:', 'ap2-gateway' ); ?></label>
					<div class="value"><?php echo esc_html( substr( $mandate_token, 0, 10 ) . '...' ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $transaction_type ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Transaction Type:', 'ap2-gateway' ); ?></label>
					<div class="value"><?php echo esc_html( $transaction_type ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $transaction_id ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Transaction ID:', 'ap2-gateway' ); ?></label>
					<div class="value">
						<?php
						if ( strpos( $transaction_id, 'TEST-' ) === 0 ) {
							echo '<span class="ap2-agent-test">' . esc_html( $transaction_id ) . '</span>';
						} else {
							echo '<span class="ap2-agent-verified">' . esc_html( $transaction_id ) . '</span>';
						}
						?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $detection_method ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Detection Method:', 'ap2-gateway' ); ?></label>
					<div class="value"><?php echo esc_html( $detection_method ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $payment_timestamp ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Payment Time:', 'ap2-gateway' ); ?></label>
					<div class="value"><?php echo esc_html( $payment_timestamp ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( $audit_trail ) : ?>
				<div class="ap2-meta-box-field">
					<label><?php esc_html_e( 'Audit Trail:', 'ap2-gateway' ); ?></label>
					<div class="ap2-audit-trail">
						<?php
						$trail = is_string( $audit_trail ) ? json_decode( $audit_trail, true ) : $audit_trail;
						if ( is_array( $trail ) ) {
							foreach ( $trail as $entry ) {
								if ( is_array( $entry ) ) {
									echo '<div>';
									echo '<strong>' . esc_html( $entry['timestamp'] ?? '' ) . '</strong>: ';
									echo esc_html( $entry['action'] ?? '' );
									if ( isset( $entry['details'] ) ) {
										echo ' - ' . esc_html( $entry['details'] );
									}
									echo '</div>';
								}
							}
						} else {
							echo '<pre>' . esc_html( print_r( $audit_trail, true ) ) . '</pre>';
						}
						?>
					</div>
				</div>
			<?php endif; ?>

			<div class="ap2-meta-box-field">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ap2-dashboard' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'View AP2 Dashboard', 'ap2-gateway' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Display agent info in order admin.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_agent_info_in_order( $order ) {
		$is_agent_order = $order->get_meta( '_ap2_is_agent_order' );

		if ( 'yes' === $is_agent_order ) {
			$agent_id      = $order->get_meta( '_ap2_agent_id' );
			$mandate_token = $order->get_meta( '_ap2_mandate_token' );
			?>
			<div class="ap2-agent-order-info" style="clear: both; margin-top: 15px;">
				<h3><?php esc_html_e( '🤖 Agent Payment Information', 'ap2-gateway' ); ?></h3>
				<p>
					<strong><?php esc_html_e( 'Payment Method:', 'ap2-gateway' ); ?></strong>
					<?php esc_html_e( 'AP2 Agent Payment', 'ap2-gateway' ); ?>
				</p>
				<?php if ( $agent_id ) : ?>
					<p>
						<strong><?php esc_html_e( 'Agent ID:', 'ap2-gateway' ); ?></strong>
						<code><?php echo esc_html( $agent_id ); ?></code>
					</p>
				<?php endif; ?>
				<?php if ( $mandate_token ) : ?>
					<p>
						<strong><?php esc_html_e( 'Mandate:', 'ap2-gateway' ); ?></strong>
						<code><?php echo esc_html( substr( $mandate_token, 0, 10 ) . '...' ); ?></code>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Display agent info on frontend order details.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_agent_info_frontend( $order ) {
		$is_agent_order = $order->get_meta( '_ap2_is_agent_order' );

		if ( 'yes' === $is_agent_order && is_account_page() ) {
			$agent_id = $order->get_meta( '_ap2_agent_id' );
			?>
			<div class="woocommerce-ap2-agent-info">
				<h2><?php esc_html_e( 'Agent Payment Details', 'ap2-gateway' ); ?></h2>
				<table class="woocommerce-table woocommerce-table--agent-details shop_table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Payment Type:', 'ap2-gateway' ); ?></th>
							<td><?php esc_html_e( 'AP2 Agent Payment', 'ap2-gateway' ); ?></td>
						</tr>
						<?php if ( $agent_id ) : ?>
							<tr>
								<th><?php esc_html_e( 'Agent ID:', 'ap2-gateway' ); ?></th>
								<td><?php echo esc_html( $agent_id ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}

	/**
	 * Add agent info to order preview.
	 *
	 * @param array    $details Order details.
	 * @param WC_Order $order Order object.
	 * @return array Modified details.
	 */
	public function add_agent_info_to_preview( $details, $order ) {
		$is_agent_order = $order->get_meta( '_ap2_is_agent_order' );

		if ( 'yes' === $is_agent_order ) {
			$agent_id = $order->get_meta( '_ap2_agent_id' );

			// Add to payment via string.
			if ( isset( $details['payment_via'] ) ) {
				$details['payment_via'] .= ' <span class="ap2-agent-indicator">(🤖 Agent: ' . esc_html( $agent_id ) . ')</span>';
			}

			// Add custom item to details.
			$details['agent_payment'] = array(
				'label' => __( 'Agent Payment', 'ap2-gateway' ),
				'value' => sprintf( __( 'Agent ID: %s', 'ap2-gateway' ), $agent_id ),
			);
		}

		return $details;
	}

	/**
	 * Add bulk actions for agent orders.
	 *
	 * @param array $actions Bulk actions.
	 * @return array Modified actions.
	 */
	public function add_bulk_actions( $actions ) {
		$actions['mark_as_agent'] = __( 'Mark as Agent Orders', 'ap2-gateway' );
		$actions['unmark_as_agent'] = __( 'Unmark as Agent Orders', 'ap2-gateway' );
		return $actions;
	}

	/**
	 * Handle bulk actions for agent orders.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action Action name.
	 * @param array  $post_ids Post IDs.
	 * @return string Redirect URL.
	 */
	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( 'mark_as_agent' === $action ) {
			foreach ( $post_ids as $post_id ) {
				$order = wc_get_order( $post_id );
				if ( $order ) {
					$order->update_meta_data( '_ap2_is_agent_order', 'yes' );
					$order->update_meta_data( '_ap2_agent_id', 'MANUAL-' . uniqid() );
					$order->save();
				}
			}
			$redirect_to = add_query_arg( 'ap2_bulk_action', 'marked_agent', $redirect_to );
		}

		if ( 'unmark_as_agent' === $action ) {
			foreach ( $post_ids as $post_id ) {
				$order = wc_get_order( $post_id );
				if ( $order ) {
					$order->delete_meta_data( '_ap2_is_agent_order' );
					$order->delete_meta_data( '_ap2_agent_id' );
					$order->save();
				}
			}
			$redirect_to = add_query_arg( 'ap2_bulk_action', 'unmarked_agent', $redirect_to );
		}

		return $redirect_to;
	}

	/**
	 * Add agent search fields.
	 *
	 * @param array $search_fields Search fields.
	 * @return array Modified search fields.
	 */
	public function add_agent_search_fields( $search_fields ) {
		$search_fields[] = '_ap2_agent_id';
		$search_fields[] = '_ap2_mandate_token';
		$search_fields[] = '_ap2_transaction_id';
		return $search_fields;
	}

	/**
	 * Add admin styles.
	 */
	public function add_admin_styles() {
		if ( 'shop_order' === get_post_type() ) {
			?>
			<style>
				.ap2-agent-badge {
					display: inline-block;
					background: #2271b1;
					color: white;
					padding: 2px 6px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
				}
				.ap2-agent-id {
					color: #666;
					font-family: monospace;
				}
				.column-ap2_agent {
					width: 100px;
				}
				.ap2-agent-order-info {
					background: #f0f8ff;
					padding: 10px;
					border-left: 4px solid #2271b1;
					border-radius: 3px;
				}
			</style>
			<?php
		}
	}

	/**
	 * Store agent audit trail.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Agent data.
	 */
	public static function store_agent_audit_trail( $order, $data ) {
		$audit_trail = array();

		// Add initial entry.
		$audit_trail[] = array(
			'timestamp' => current_time( 'mysql' ),
			'action'    => 'agent_payment_initiated',
			'details'   => sprintf( 'Agent %s initiated payment', $data['agent_id'] ?? 'Unknown' ),
		);

		// Add validation entry.
		$audit_trail[] = array(
			'timestamp' => current_time( 'mysql' ),
			'action'    => 'mandate_validated',
			'details'   => 'Mandate token validated successfully',
		);

		// Add processing entry.
		if ( isset( $data['test_mode'] ) && $data['test_mode'] ) {
			$audit_trail[] = array(
				'timestamp' => current_time( 'mysql' ),
				'action'    => 'test_mode_processing',
				'details'   => 'Payment processed in test mode',
			);
		} else {
			$audit_trail[] = array(
				'timestamp' => current_time( 'mysql' ),
				'action'    => 'payment_processing',
				'details'   => 'Payment sent to AP2 gateway',
			);
		}

		// Add completion entry.
		$audit_trail[] = array(
			'timestamp' => current_time( 'mysql' ),
			'action'    => 'payment_completed',
			'details'   => sprintf( 'Transaction %s completed', $data['transaction_id'] ?? 'N/A' ),
		);

		// Store audit trail as JSON.
		$order->update_meta_data( '_ap2_audit_trail', wp_json_encode( $audit_trail ) );
	}
}

// Initialize the order handler.
new AP2_Order_Handler();