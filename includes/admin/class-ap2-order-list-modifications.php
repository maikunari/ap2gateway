<?php
/**
 * AP2 Order List Modifications
 *
 * Adds agent indicators and filters to WooCommerce Orders screen.
 *
 * @package AP2_Gateway
 * @subpackage Admin
 * @since 1.0.0
 */

namespace AP2_Gateway\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Order List Modifications class.
 */
class AP2_Order_List_Modifications {

	/**
	 * Single instance.
	 *
	 * @var AP2_Order_List_Modifications
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AP2_Order_List_Modifications
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
		// Add agent indicator column.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_type_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_type_column' ), 20, 2 );

		// HPOS compatibility.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_order_type_column_hpos' ), 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_order_type_column_hpos' ), 20, 2 );

		// Add filter dropdown.
		add_action( 'restrict_manage_posts', array( $this, 'add_order_type_filter' ), 20 );
		add_filter( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'add_order_type_filter_hpos' ), 20 );

		// Apply filter to query.
		add_filter( 'request', array( $this, 'filter_orders_query' ) );
		add_filter( 'woocommerce_orders_table_query_clauses', array( $this, 'filter_orders_query_hpos' ), 10, 3 );

		// Add row classes for styling.
		add_filter( 'post_class', array( $this, 'add_order_row_class' ), 10, 3 );

		// Quick edit compatibility.
		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'add_agent_info_to_order' ) );
	}

	/**
	 * Add order type column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_type_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $column ) {
			$new_columns[ $key ] = $column;

			// Add after order number.
			if ( 'order_number' === $key ) {
				$new_columns['order_type'] = '<span class="ap2-column-header" title="' . esc_attr__( 'Order Type', 'ap2-gateway' ) . '">👤</span>';
			}
		}

		return $new_columns;
	}

	/**
	 * Add order type column for HPOS.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_order_type_column_hpos( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $column ) {
			// Add after order number.
			if ( 'order_number' === $key ) {
				$new_columns[ $key ] = $column;
				$new_columns['order_type'] = '<span class="ap2-column-header" title="' . esc_attr__( 'Order Type', 'ap2-gateway' ) . '">👤</span>';
			} else {
				$new_columns[ $key ] = $column;
			}
		}

		return $new_columns;
	}

	/**
	 * Render order type column.
	 *
	 * @param string $column Column ID.
	 * @param int $order_id Order ID.
	 */
	public function render_order_type_column( $column, $order_id ) {
		if ( 'order_type' !== $column ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->display_order_type_badge( $order );
	}

	/**
	 * Render order type column for HPOS.
	 *
	 * @param string $column Column ID.
	 * @param \WC_Order $order Order object.
	 */
	public function render_order_type_column_hpos( $column, $order ) {
		if ( 'order_type' !== $column ) {
			return;
		}

		$this->display_order_type_badge( $order );
	}

	/**
	 * Display order type badge.
	 *
	 * @param \WC_Order $order Order object.
	 */
	private function display_order_type_badge( $order ) {
		$agent_id = $order->get_meta( '_ap2_agent_id' );
		$is_agent = ! empty( $agent_id ) || 'ap2_gateway' === $order->get_payment_method();

		if ( $is_agent ) {
			$transaction_id = $order->get_meta( '_ap2_transaction_id' );
			$is_test = strpos( $transaction_id, 'TEST-' ) === 0;

			?>
			<div class="ap2-gateway-order-type">
				<span class="ap2-gateway-badge ap2-gateway-agent" title="<?php echo esc_attr( sprintf( __( 'Agent: %s', 'ap2-gateway' ), $agent_id ) ); ?>">
					🤖
				</span>
				<?php if ( $is_test ) : ?>
					<span class="ap2-gateway-badge ap2-gateway-test"><?php esc_html_e( 'TEST', 'ap2-gateway' ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		} else {
			?>
			<div class="ap2-gateway-order-type">
				<span class="ap2-gateway-badge" style="background-color: #e0e0e0; color: #50575e;">
					👤
				</span>
			</div>
			<?php
		}
	}

	/**
	 * Add order type filter dropdown.
	 *
	 * @param string $post_type Post type.
	 */
	public function add_order_type_filter( $post_type ) {
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET['order_type'] ) ? sanitize_text_field( wp_unslash( $_GET['order_type'] ) ) : '';
		?>
		<select name="order_type" id="dropdown_order_type" class="wc-enhanced-select ap2-gateway-filter">
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
	 * Add order type filter for HPOS.
	 */
	public function add_order_type_filter_hpos() {
		$selected = isset( $_GET['order_type'] ) ? sanitize_text_field( wp_unslash( $_GET['order_type'] ) ) : '';
		?>
		<select name="order_type" id="dropdown_order_type">
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
	 * Filter orders query.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function filter_orders_query( $vars ) {
		global $typenow;

		if ( 'shop_order' !== $typenow || ! isset( $_GET['order_type'] ) ) {
			return $vars;
		}

		$order_type = sanitize_text_field( wp_unslash( $_GET['order_type'] ) );

		if ( 'agent' === $order_type ) {
			$vars['meta_query'][] = array(
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
			$vars['meta_query'][] = array(
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

		return $vars;
	}

	/**
	 * Filter orders query for HPOS.
	 *
	 * @param array $clauses SQL clauses.
	 * @param object $query Query object.
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function filter_orders_query_hpos( $clauses, $query, $args ) {
		if ( ! isset( $_GET['order_type'] ) ) {
			return $clauses;
		}

		global $wpdb;
		$order_type = sanitize_text_field( wp_unslash( $_GET['order_type'] ) );

		if ( 'agent' === $order_type ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_orders_meta ap2m ON {$wpdb->prefix}wc_orders.id = ap2m.order_id";
			$clauses['where'] .= " AND (ap2m.meta_key = '_ap2_agent_id' OR {$wpdb->prefix}wc_orders.payment_method = 'ap2_gateway')";
			$clauses['groupby'] = "{$wpdb->prefix}wc_orders.id";
		} elseif ( 'human' === $order_type ) {
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
	 * Add order row class.
	 *
	 * @param array $classes Classes.
	 * @param string $class Class.
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function add_order_row_class( $classes, $class, $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			$order = wc_get_order( $post_id );
			if ( $order && ( $order->get_meta( '_ap2_agent_id' ) || 'ap2_gateway' === $order->get_payment_method() ) ) {
				$classes[] = 'ap2-agent-order-row';
			}
		}

		return $classes;
	}

	/**
	 * Add agent info to order edit screen.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public function add_agent_info_to_order( $order ) {
		$agent_id = $order->get_meta( '_ap2_agent_id' );
		if ( ! $agent_id ) {
			return;
		}

		$mandate_token = $order->get_meta( '_ap2_mandate_token' );
		$transaction_id = $order->get_meta( '_ap2_transaction_id' );
		?>
		<div class="ap2-agent-info" style="margin: 10px 0; padding: 10px; background: #f0f8ff; border-left: 4px solid #0073aa;">
			<h4 style="margin: 0 0 10px;">🤖 <?php esc_html_e( 'Agent Payment Details', 'ap2-gateway' ); ?></h4>
			<p><strong><?php esc_html_e( 'Agent ID:', 'ap2-gateway' ); ?></strong> <?php echo esc_html( $agent_id ); ?></p>
			<?php if ( $mandate_token ) : ?>
				<p><strong><?php esc_html_e( 'Mandate Token:', 'ap2-gateway' ); ?></strong> <?php echo esc_html( $mandate_token ); ?></p>
			<?php endif; ?>
			<?php if ( $transaction_id ) : ?>
				<p><strong><?php esc_html_e( 'Transaction ID:', 'ap2-gateway' ); ?></strong> <?php echo esc_html( $transaction_id ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

}

// Initialize only if in admin.
if ( is_admin() ) {
	AP2_Order_List_Modifications::instance();
}