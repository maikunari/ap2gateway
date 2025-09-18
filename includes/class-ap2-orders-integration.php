<?php
/**
 * AP2 Orders Integration
 *
 * Adds agent indicators and filters to WooCommerce Orders screen.
 *
 * @package AP2_Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Orders Integration Class.
 */
class AP2_Orders_Integration {

	/**
	 * Single instance.
	 *
	 * @var AP2_Orders_Integration
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AP2_Orders_Integration
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
		// Add agent indicator to orders list.
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'add_agent_indicator' ), 20, 2 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'add_agent_indicator_hpos' ), 20, 2 );

		// Add filter dropdown to orders screen.
		add_action( 'restrict_manage_posts', array( $this, 'add_agent_filter_dropdown' ), 20 );
		add_filter( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_agent_filter_dropdown_hpos' ), 20 );

		// Filter orders query.
		add_filter( 'request', array( $this, 'filter_orders_by_agent' ) );
		add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'filter_orders_by_agent_hpos' ), 10, 3 );

		// Add agent column to orders list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_agent_column' ), 20 );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_agent_column_hpos' ), 20 );

		// Add styles for agent badges.
		add_action( 'admin_head', array( $this, 'add_agent_styles' ) );

		// Add bulk actions.
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_actions' ) );
	}

	/**
	 * Add agent column to orders list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_agent_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;

			// Add agent column after order number.
			if ( 'order_number' === $key ) {
				$new_columns['ap2_agent'] = __( 'Type', 'ap2-gateway' );
			}
		}

		return $new_columns;
	}

	/**
	 * Add agent column for HPOS.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_agent_column_hpos( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;

			// Add agent column after order number.
			if ( 'order_number' === $key ) {
				$new_columns['ap2_agent'] = __( 'Type', 'ap2-gateway' );
			}
		}

		return $new_columns;
	}

	/**
	 * Add agent indicator to order row.
	 *
	 * @param string $column Column name.
	 * @param int    $order_id Order ID.
	 */
	public function add_agent_indicator( $column, $order_id ) {
		if ( 'ap2_agent' !== $column ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->display_order_badge( $order );
	}

	/**
	 * Add agent indicator for HPOS.
	 *
	 * @param string   $column Column name.
	 * @param WC_Order $order Order object.
	 */
	public function add_agent_indicator_hpos( $column, $order ) {
		if ( 'ap2_agent' !== $column ) {
			return;
		}

		$this->display_order_badge( $order );
	}

	/**
	 * Display order type badge.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function display_order_badge( $order ) {
		$agent_id = $order->get_meta( '_ap2_agent_id' );
		$is_agent = $order->get_payment_method() === 'ap2_gateway' || ! empty( $agent_id );

		if ( $is_agent ) {
			$test_mode = strpos( $order->get_meta( '_ap2_transaction_id' ), 'TEST-' ) === 0;
			?>
			<span class="ap2-order-badge ap2-agent-order <?php echo $test_mode ? 'ap2-test-mode' : ''; ?>">
				🤖 <?php esc_html_e( 'Agent', 'ap2-gateway' ); ?>
				<?php if ( $test_mode ) : ?>
					<span class="ap2-test-indicator"><?php esc_html_e( 'TEST', 'ap2-gateway' ); ?></span>
				<?php endif; ?>
			</span>
			<?php
			if ( $agent_id ) {
				?>
				<div class="ap2-agent-id" title="<?php esc_attr_e( 'Agent ID', 'ap2-gateway' ); ?>">
					<small><?php echo esc_html( $agent_id ); ?></small>
				</div>
				<?php
			}
		} else {
			?>
			<span class="ap2-order-badge ap2-human-order">
				👤 <?php esc_html_e( 'Human', 'ap2-gateway' ); ?>
			</span>
			<?php
		}
	}

	/**
	 * Add agent filter dropdown to orders screen.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_agent_filter_dropdown( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET['ap2_order_type'] ) ? sanitize_text_field( wp_unslash( $_GET['ap2_order_type'] ) ) : '';
		?>
		<select name="ap2_order_type" id="ap2_order_type">
			<option value=""><?php esc_html_e( 'All order types', 'ap2-gateway' ); ?></option>
			<option value="agent" <?php selected( $selected, 'agent' ); ?>>
				🤖 <?php esc_html_e( 'Agent Orders', 'ap2-gateway' ); ?>
			</option>
			<option value="human" <?php selected( $selected, 'human' ); ?>>
				👤 <?php esc_html_e( 'Human Orders', 'ap2-gateway' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Add agent filter dropdown for HPOS.
	 */
	public function add_agent_filter_dropdown_hpos() {
		$selected = isset( $_GET['ap2_order_type'] ) ? sanitize_text_field( wp_unslash( $_GET['ap2_order_type'] ) ) : '';
		?>
		<select name="ap2_order_type" id="ap2_order_type">
			<option value=""><?php esc_html_e( 'All order types', 'ap2-gateway' ); ?></option>
			<option value="agent" <?php selected( $selected, 'agent' ); ?>>
				🤖 <?php esc_html_e( 'Agent Orders', 'ap2-gateway' ); ?>
			</option>
			<option value="human" <?php selected( $selected, 'human' ); ?>>
				👤 <?php esc_html_e( 'Human Orders', 'ap2-gateway' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Filter orders query by agent type.
	 *
	 * @param array $query_vars Query variables.
	 * @return array Modified query variables.
	 */
	public function filter_orders_by_agent( $query_vars ) {
		global $typenow;

		if ( 'shop_order' !== $typenow ) {
			return $query_vars;
		}

		if ( ! isset( $_GET['ap2_order_type'] ) || empty( $_GET['ap2_order_type'] ) ) {
			return $query_vars;
		}

		$order_type = sanitize_text_field( wp_unslash( $_GET['ap2_order_type'] ) );

		if ( 'agent' === $order_type ) {
			// Show only agent orders.
			$query_vars['meta_query'][] = array(
				'relation' => 'OR',
				array(
					'key'     => '_ap2_agent_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'   => '_payment_method',
					'value' => 'ap2_gateway',
				),
			);
		} elseif ( 'human' === $order_type ) {
			// Show only human orders.
			$query_vars['meta_query'][] = array(
				'relation' => 'AND',
				array(
					'key'     => '_ap2_agent_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_payment_method',
					'value'   => 'ap2_gateway',
					'compare' => '!=',
				),
			);
		}

		return $query_vars;
	}

	/**
	 * Filter orders query for HPOS.
	 *
	 * @param array  $clauses SQL clauses.
	 * @param object $query Query object.
	 * @param array  $args Query arguments.
	 * @return array Modified clauses.
	 */
	public function filter_orders_by_agent_hpos( $clauses, $query, $args ) {
		if ( ! isset( $_GET['ap2_order_type'] ) || empty( $_GET['ap2_order_type'] ) ) {
			return $clauses;
		}

		global $wpdb;
		$order_type = sanitize_text_field( wp_unslash( $_GET['ap2_order_type'] ) );

		if ( 'agent' === $order_type ) {
			// Join with meta table to filter agent orders.
			$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_orders_meta ap2m ON {$wpdb->prefix}wc_orders.id = ap2m.order_id";
			$clauses['where'] .= " AND (ap2m.meta_key = '_ap2_agent_id' OR {$wpdb->prefix}wc_orders.payment_method = 'ap2_gateway')";
			$clauses['groupby'] = "{$wpdb->prefix}wc_orders.id";
		} elseif ( 'human' === $order_type ) {
			// Exclude agent orders.
			$clauses['where'] .= " AND {$wpdb->prefix}wc_orders.payment_method != 'ap2_gateway'";
			$clauses['where'] .= " AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->prefix}wc_orders_meta ap2m
				WHERE ap2m.order_id = {$wpdb->prefix}wc_orders.id
				AND ap2m.meta_key = '_ap2_agent_id'
			)";
		}

		return $clauses;
	}

	/**
	 * Add agent-related bulk actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array Modified actions.
	 */
	public function add_bulk_actions( $actions ) {
		$actions['ap2_export_agent_data'] = __( 'Export Agent Data', 'ap2-gateway' );
		return $actions;
	}

	/**
	 * Add styles for agent badges.
	 */
	public function add_agent_styles() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		?>
		<style>
			.ap2-order-badge {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				line-height: 1;
			}

			.ap2-agent-order {
				background-color: #e8f4fd;
				color: #0073aa;
				border: 1px solid #b5d9f1;
			}

			.ap2-agent-order.ap2-test-mode {
				background-color: #fff8e5;
				color: #94660c;
				border: 1px solid #ffb900;
			}

			.ap2-test-indicator {
				background: #ffb900;
				color: white;
				padding: 1px 4px;
				border-radius: 2px;
				margin-left: 4px;
				font-size: 9px;
			}

			.ap2-human-order {
				background-color: #f0f0f1;
				color: #50575e;
				border: 1px solid #c3c4c7;
			}

			.ap2-agent-id {
				margin-top: 4px;
				color: #666;
				font-size: 11px;
			}

			.column-ap2_agent {
				width: 120px;
			}

			#ap2_order_type {
				margin-right: 8px;
			}

			/* Highlight agent rows */
			.type-shop_order.ap2-is-agent-order {
				background-color: #f9fcfe !important;
			}

			.type-shop_order.ap2-is-agent-order.alternate {
				background-color: #f4f9fc !important;
			}

			/* HPOS compatibility */
			.woocommerce-orders-table .ap2-order-badge {
				margin-bottom: 4px;
			}
		</style>
		<?php
	}
}

// Initialize.
AP2_Orders_Integration::instance();