/**
 * AP2 Gateway Checkout Script
 */

(function( $ ) {
	'use strict';

	// Wait for document ready
	$( document ).ready( function() {

		// Handle payment method selection
		$( 'body' ).on( 'change', 'input[name="payment_method"]', function() {
			var selectedMethod = $( 'input[name="payment_method"]:checked' ).val();

			if ( selectedMethod === 'ap2_agent_payments' ) {
				// Show AP2 payment description
				$( '#payment_method_ap2_agent_payments' ).show();
			}
		});

		// Validate AP2 payment before submission
		$( 'form.woocommerce-checkout' ).on( 'checkout_place_order_ap2_agent_payments', function() {
			var agentId = $( '#ap2_agent_id' ).val();
			var mandateToken = $( '#ap2_mandate_token' ).val();

			// Validate Agent ID
			if ( ! agentId || agentId.length === 0 ) {
				alert( 'Please enter your Agent ID.' );
				$( '#ap2_agent_id' ).focus();
				return false;
			}

			// Validate Mandate Token
			if ( ! mandateToken || mandateToken.length === 0 ) {
				alert( 'Please enter your Mandate Token.' );
				$( '#ap2_mandate_token' ).focus();
				return false;
			}

			// Validate Agent ID format
			if ( ! /^[a-zA-Z0-9\-_]+$/.test( agentId ) ) {
				alert( 'Invalid Agent ID format. Only alphanumeric characters, hyphens, and underscores are allowed.' );
				$( '#ap2_agent_id' ).focus();
				return false;
			}

			// Validate Mandate Token format
			if ( ! /^[a-zA-Z0-9]+$/.test( mandateToken ) ) {
				alert( 'Invalid Mandate Token format. Only alphanumeric characters are allowed.' );
				$( '#ap2_mandate_token' ).focus();
				return false;
			}

			return true;
		});

		// Handle errors
		$( document.body ).on( 'checkout_error', function( event, error_message ) {
			if ( error_message && error_message.indexOf( 'AP2' ) !== -1 ) {
				console.error( 'AP2 Gateway Error:', error_message );
			}
		});

	});

})( jQuery );