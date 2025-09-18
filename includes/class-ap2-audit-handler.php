<?php
/**
 * AP2 Audit Handler
 *
 * Handles audit trail storage for agent orders.
 *
 * @package AP2_Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Audit Handler Class.
 *
 * This is a simplified version that maintains compatibility with the payment gateway.
 */
class AP2_Order_Handler {

	/**
	 * Store audit trail for agent orders.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data Audit data.
	 */
	public static function store_agent_audit_trail( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Store audit data as order meta.
		$timestamp = current_time( 'Y-m-d H:i:s' );

		// Get existing audit trail.
		$audit_trail = $order->get_meta( '_ap2_audit_trail' );
		if ( ! is_array( $audit_trail ) ) {
			$audit_trail = array();
		}

		// Add new audit entry.
		$audit_trail[] = array(
			'timestamp' => $timestamp,
			'data'      => $data,
		);

		// Limit audit trail to last 50 entries.
		if ( count( $audit_trail ) > 50 ) {
			$audit_trail = array_slice( $audit_trail, -50 );
		}

		// Save audit trail.
		$order->update_meta_data( '_ap2_audit_trail', $audit_trail );

		// Also store key fields directly for easier access.
		if ( isset( $data['agent_id'] ) ) {
			$order->update_meta_data( '_ap2_agent_id', sanitize_text_field( $data['agent_id'] ) );
		}

		if ( isset( $data['mandate_token'] ) ) {
			$order->update_meta_data( '_ap2_mandate_token', sanitize_text_field( $data['mandate_token'] ) );
		}

		if ( isset( $data['transaction_id'] ) ) {
			$order->update_meta_data( '_ap2_transaction_id', sanitize_text_field( $data['transaction_id'] ) );
		}

		if ( isset( $data['transaction_type'] ) ) {
			$order->update_meta_data( '_ap2_transaction_type', sanitize_text_field( $data['transaction_type'] ) );
		}

		// Save all meta.
		$order->save();

		// Add order note.
		if ( isset( $data['note'] ) ) {
			$order->add_order_note(
				sprintf(
				/* translators: %s: audit note */
					__( 'AP2 Agent: %s', 'ap2-gateway' ),
					$data['note']
				)
			);
		}

		// Update HPOS index if available.
		if ( class_exists( 'AP2_HPOS_Optimizer' ) && method_exists( 'AP2_HPOS_Optimizer', 'update_order_index' ) ) {
			$optimizer = new AP2_HPOS_Optimizer();
			$optimizer->update_order_index( $order->get_id() );
		}
	}

	/**
	 * Get audit trail for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array Audit trail.
	 */
	public static function get_audit_trail( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array();
		}

		$audit_trail = $order->get_meta( '_ap2_audit_trail' );
		return is_array( $audit_trail ) ? $audit_trail : array();
	}
}
