<?php
/**
 * AP2 Payment Gateway
 *
 * Provides an AP2 Payment Gateway for WooCommerce.
 *
 * @class       WC_Gateway_AP2
 * @extends     WC_Payment_Gateway
 * @package     AP2Gateway
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Gateway Class.
 */
class WC_Gateway_AP2 extends WC_Payment_Gateway {

	/**
	 * API endpoint URL.
	 *
	 * @var string
	 */
	private $api_endpoint;

	/**
	 * API Key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * API Secret.
	 *
	 * @var string
	 */
	private $api_secret;

	/**
	 * Test mode.
	 *
	 * @var bool
	 */
	private $testmode;

	/**
	 * Debug mode.
	 *
	 * @var bool
	 */
	private $debug;

	/**
	 * Logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		$this->id                 = 'ap2_agent_payments';
		$this->icon               = apply_filters( 'wc_ap2_gateway_icon', '' );
		$this->has_fields         = true;
		$this->method_title       = __( 'Agent Payment (AP2)', 'ap2-gateway' );
		$this->method_description = __( 'Accept payments from AI agents using Google\'s AP2 (Agent Payments Protocol).', 'ap2-gateway' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user settings.
		$this->title        = $this->get_option( 'title' );
		$this->description  = $this->get_option( 'description' );
		$this->testmode     = 'yes' === $this->get_option( 'testmode', 'no' );
		$this->debug        = 'yes' === $this->get_option( 'debug', 'no' );
		$this->api_key      = $this->testmode ? $this->get_option( 'test_api_key' ) : $this->get_option( 'api_key' );
		$this->api_secret   = $this->testmode ? $this->get_option( 'test_api_secret' ) : $this->get_option( 'api_secret' );
		$this->api_endpoint = $this->testmode ? 'https://sandbox.ap2gateway.com/api/v1/' : 'https://api.ap2gateway.com/api/v1/';

		// Initialize logger.
		if ( $this->debug ) {
			$this->logger = wc_get_logger();
		}

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_wc_gateway_ap2', array( $this, 'check_response' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	}

	/**
	 * Initialize Gateway Settings Form Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'ap2-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable AP2 Gateway', 'ap2-gateway' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'ap2-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'ap2-gateway' ),
				'default'     => __( 'Agent Payment (AP2)', 'ap2-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'ap2-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'ap2-gateway' ),
				'default'     => __( 'Pay using your AI agent credentials via Google\'s AP2 protocol.', 'ap2-gateway' ),
				'desc_tip'    => true,
			),
			'testmode' => array(
				'title'       => __( 'Test Mode', 'ap2-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'ap2-gateway' ),
				'default'     => 'yes',
				'description' => __( 'Enable this to test payments using the sandbox environment.', 'ap2-gateway' ),
			),
			'debug' => array(
				'title'       => __( 'Debug Log', 'ap2-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'ap2-gateway' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: log file path */
					__( 'Log AP2 Gateway events, such as API requests. You can check the logs in %s', 'ap2-gateway' ),
					'<code>' . WC_Log_Handler_File::get_log_file_path( 'ap2-gateway' ) . '</code>'
				),
			),
			'api_details' => array(
				'title'       => __( 'API Credentials', 'ap2-gateway' ),
				'type'        => 'title',
				'description' => __( 'Enter your AP2 API credentials to process payments.', 'ap2-gateway' ),
			),
			'api_key' => array(
				'title'       => __( 'Live API Key', 'ap2-gateway' ),
				'type'        => 'text',
				'description' => __( 'Enter your AP2 Live API Key.', 'ap2-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'api_secret' => array(
				'title'       => __( 'Live API Secret', 'ap2-gateway' ),
				'type'        => 'password',
				'description' => __( 'Enter your AP2 Live API Secret.', 'ap2-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_api_key' => array(
				'title'       => __( 'Test API Key', 'ap2-gateway' ),
				'type'        => 'text',
				'description' => __( 'Enter your AP2 Test API Key.', 'ap2-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_api_secret' => array(
				'title'       => __( 'Test API Secret', 'ap2-gateway' ),
				'type'        => 'password',
				'description' => __( 'Enter your AP2 Test API Secret.', 'ap2-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Check if the gateway is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! $this->api_key || ! $this->api_secret ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Check if gateway is in test mode.
	 *
	 * @return bool True if test mode is enabled.
	 */
	public function is_test_mode() {
		return $this->testmode;
	}

	/**
	 * Admin Panel Options.
	 */
	public function admin_options() {
		?>
		<h2><?php esc_html_e( 'AP2 Gateway', 'ap2-gateway' ); ?></h2>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	/**
	 * Output payment fields for AP2 agent payments.
	 */
	public function payment_fields() {
		// Display description if available.
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		// Add nonce for security.
		wp_nonce_field( 'ap2_payment_process', 'ap2_payment_nonce' );

		// Display test mode notice.
		if ( $this->testmode ) {
			echo '<p class="ap2-test-mode">' . esc_html__( 'TEST MODE ENABLED: Use test agent credentials.', 'ap2-gateway' ) . '</p>';
		}

		?>
		<fieldset id="ap2-payment-fields">
			<p class="form-row form-row-wide">
				<label for="ap2_agent_id">
					<?php esc_html_e( 'Agent ID', 'ap2-gateway' ); ?>
					<span class="required">*</span>
				</label>
				<input
					id="ap2_agent_id"
					name="ap2_agent_id"
					type="text"
					class="input-text"
					placeholder="<?php esc_attr_e( 'Enter your Agent ID', 'ap2-gateway' ); ?>"
					required
				/>
			</p>
			<p class="form-row form-row-wide">
				<label for="ap2_mandate_token">
					<?php esc_html_e( 'Mandate Token', 'ap2-gateway' ); ?>
					<span class="required">*</span>
				</label>
				<input
					id="ap2_mandate_token"
					name="ap2_mandate_token"
					type="text"
					class="input-text"
					placeholder="<?php esc_attr_e( 'Enter your Mandate Token', 'ap2-gateway' ); ?>"
					required
				/>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Validate payment fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		// Verify nonce.
		if ( ! isset( $_POST['ap2_payment_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ap2_payment_nonce'] ) ), 'ap2_payment_process' ) ) {
			wc_add_notice( __( 'Payment verification failed. Please try again.', 'ap2-gateway' ), 'error' );
			return false;
		}

		// Check Agent ID.
		if ( empty( $_POST['ap2_agent_id'] ) ) {
			wc_add_notice( __( 'Please enter your Agent ID.', 'ap2-gateway' ), 'error' );
			return false;
		}

		// Check Mandate Token.
		if ( empty( $_POST['ap2_mandate_token'] ) ) {
			wc_add_notice( __( 'Please enter your Mandate Token.', 'ap2-gateway' ), 'error' );
			return false;
		}

		// Validate Agent ID format (alphanumeric and hyphens).
		$agent_id = sanitize_text_field( wp_unslash( $_POST['ap2_agent_id'] ) );
		if ( ! preg_match( '/^[a-zA-Z0-9\-_]+$/', $agent_id ) ) {
			wc_add_notice( __( 'Invalid Agent ID format.', 'ap2-gateway' ), 'error' );
			return false;
		}

		// Validate Mandate Token format (alphanumeric).
		$mandate_token = sanitize_text_field( wp_unslash( $_POST['ap2_mandate_token'] ) );
		if ( ! preg_match( '/^[a-zA-Z0-9]+$/', $mandate_token ) ) {
			wc_add_notice( __( 'Invalid Mandate Token format.', 'ap2-gateway' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Payment form scripts.
	 */
	public function payment_scripts() {
		if ( ! is_checkout() || ! $this->is_available() ) {
			return;
		}

		wp_enqueue_script(
			'ap2-gateway',
			AP2_GATEWAY_PLUGIN_URL . 'assets/js/ap2-checkout.js',
			array( 'jquery' ),
			AP2_GATEWAY_VERSION,
			true
		);

		wp_localize_script(
			'ap2-gateway',
			'ap2_params',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ap2-gateway-nonce' ),
			)
		);
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'ap2-gateway' ), 'error' );
			return array(
				'result' => 'fail',
			);
		}

		// Validate fields first.
		if ( ! $this->validate_fields() ) {
			return array(
				'result' => 'fail',
			);
		}

		// Get and sanitize agent credentials.
		$agent_id      = isset( $_POST['ap2_agent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ap2_agent_id'] ) ) : '';
		$mandate_token = isset( $_POST['ap2_mandate_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ap2_mandate_token'] ) ) : '';

		// Log payment attempt.
		$this->log( 'Processing AP2 agent payment for order #' . $order_id );
		$this->log( 'Agent ID: ' . $agent_id );

		// Store comprehensive agent data as order meta.
		$order->update_meta_data( '_ap2_is_agent_order', 'yes' );
		$order->update_meta_data( '_ap2_agent_id', $agent_id );
		$order->update_meta_data( '_ap2_mandate_token', $mandate_token );
		$order->update_meta_data( '_ap2_transaction_type', 'agent_payment' );
		$order->update_meta_data( '_ap2_payment_method', 'agent_payment' );
		$order->update_meta_data( '_ap2_payment_timestamp', current_time( 'mysql' ) );

		// Store audit trail.
		if ( class_exists( 'AP2_Order_Handler' ) ) {
			AP2_Order_Handler::store_agent_audit_trail( $order, array(
				'agent_id'       => $agent_id,
				'mandate_token'  => $mandate_token,
				'test_mode'      => $this->testmode,
				'transaction_id' => '',
			) );
		}

		// If in test mode, simulate successful payment.
		if ( $this->testmode ) {
			// Generate test transaction ID.
			$transaction_id = 'TEST-' . strtoupper( uniqid() );
			$order->update_meta_data( '_ap2_transaction_id', $transaction_id );

			// Update audit trail with transaction ID.
			if ( class_exists( 'AP2_Order_Handler' ) ) {
				$audit_data = array(
					'agent_id'       => $agent_id,
					'mandate_token'  => $mandate_token,
					'test_mode'      => true,
					'transaction_id' => $transaction_id,
				);
				AP2_Order_Handler::store_agent_audit_trail( $order, $audit_data );
			}

			// Mark order as processing.
			$order->update_status( 'processing', __( 'AP2 agent payment completed (Test Mode)', 'ap2-gateway' ) );

			// Add order note.
			$order->add_order_note(
				sprintf(
					/* translators: 1: Agent ID, 2: Transaction ID */
					__( 'AP2 agent payment successful. Agent ID: %1$s, Transaction: %2$s', 'ap2-gateway' ),
					$agent_id,
					$transaction_id
				)
			);

			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );

			// Remove cart.
			WC()->cart->empty_cart();

			// Save order.
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Production mode - make actual API request.
		$request_data = array(
			'amount'        => $order->get_total(),
			'currency'      => get_woocommerce_currency(),
			'order_id'      => $order_id,
			'agent_id'      => $agent_id,
			'mandate_token' => $mandate_token,
			'description'   => sprintf( __( 'Order #%s', 'ap2-gateway' ), $order_id ),
			'return_url'    => $this->get_return_url( $order ),
			'cancel_url'    => wc_get_checkout_url(),
			'webhook_url'   => home_url( '/wc-api/wc_gateway_ap2/' ),
			'customer'      => array(
				'email'      => $order->get_billing_email(),
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'address'    => $order->get_billing_address_1(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'zip'        => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			),
		);

		// Make API request.
		$response = $this->api_request( 'agent/payment', $request_data );

		if ( is_wp_error( $response ) ) {
			wc_add_notice( $response->get_error_message(), 'error' );
			return array(
				'result' => 'fail',
			);
		}

		// Check for successful response.
		if ( ! empty( $response['success'] ) && ! empty( $response['transaction_id'] ) ) {
			// Save transaction ID.
			$order->update_meta_data( '_ap2_transaction_id', sanitize_text_field( $response['transaction_id'] ) );

			// Update audit trail with real transaction.
			if ( class_exists( 'AP2_Order_Handler' ) ) {
				$audit_data = array(
					'agent_id'       => $agent_id,
					'mandate_token'  => $mandate_token,
					'test_mode'      => false,
					'transaction_id' => $response['transaction_id'],
				);
				AP2_Order_Handler::store_agent_audit_trail( $order, $audit_data );
			}

			// Mark order as processing.
			$order->update_status( 'processing', __( 'AP2 agent payment completed', 'ap2-gateway' ) );

			// Add order note.
			$order->add_order_note(
				sprintf(
					/* translators: 1: Agent ID, 2: Transaction ID */
					__( 'AP2 agent payment successful. Agent ID: %1$s, Transaction: %2$s', 'ap2-gateway' ),
					$agent_id,
					$response['transaction_id']
				)
			);

			// Reduce stock levels.
			wc_reduce_stock_levels( $order_id );

			// Remove cart.
			WC()->cart->empty_cart();

			// Save order.
			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Check for redirect URL (3D Secure or additional verification).
		if ( ! empty( $response['redirect_url'] ) ) {
			// Mark order as pending.
			$order->update_status( 'pending', __( 'Awaiting AP2 payment verification', 'ap2-gateway' ) );

			// Save transaction ID if provided.
			if ( ! empty( $response['transaction_id'] ) ) {
				$order->update_meta_data( '_ap2_transaction_id', sanitize_text_field( $response['transaction_id'] ) );
			}

			$order->save();

			return array(
				'result'   => 'success',
				'redirect' => esc_url_raw( $response['redirect_url'] ),
			);
		}

		wc_add_notice( __( 'Agent payment processing failed. Please verify your credentials and try again.', 'ap2-gateway' ), 'error' );
		return array(
			'result' => 'fail',
		);
	}

	/**
	 * Process refund.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount Refund amount.
	 * @param string $reason Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'error', __( 'Order not found.', 'ap2-gateway' ) );
		}

		$transaction_id = $order->get_meta( '_ap2_transaction_id' );

		if ( ! $transaction_id ) {
			return new WP_Error( 'error', __( 'Transaction ID not found.', 'ap2-gateway' ) );
		}

		// Log refund attempt.
		$this->log( 'Processing refund for order #' . $order_id );

		// Prepare refund request.
		$request_data = array(
			'transaction_id' => $transaction_id,
			'amount'         => $amount,
			'reason'         => $reason,
		);

		// Make API request.
		$response = $this->api_request( 'payments/refund', $request_data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response['success'] ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Refund amount, 2: Refund ID */
					__( 'Refunded %1$s - Refund ID: %2$s', 'ap2-gateway' ),
					wc_price( $amount ),
					$response['refund_id']
				)
			);
			return true;
		}

		return new WP_Error( 'error', __( 'Refund failed.', 'ap2-gateway' ) );
	}

	/**
	 * Check for AP2 Response.
	 */
	public function check_response() {
		// Verify nonce for security.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ap2_gateway_response' ) ) {
			$this->log( 'Invalid nonce in webhook response' );
			wp_die( esc_html__( 'Invalid request', 'ap2-gateway' ), '', array( 'response' => 403 ) );
		}

		// Get posted data.
		$body = file_get_contents( 'php://input' );
		$data = json_decode( $body, true );

		if ( empty( $data ) ) {
			$this->log( 'Empty webhook response' );
			wp_die( esc_html__( 'Invalid request', 'ap2-gateway' ), '', array( 'response' => 400 ) );
		}

		// Verify signature.
		if ( ! $this->verify_signature( $body ) ) {
			$this->log( 'Invalid signature in webhook response' );
			wp_die( esc_html__( 'Invalid signature', 'ap2-gateway' ), '', array( 'response' => 403 ) );
		}

		// Process the webhook.
		$this->process_webhook( $data );

		// Send success response.
		wp_die( 'OK', '', array( 'response' => 200 ) );
	}

	/**
	 * Process webhook data.
	 *
	 * @param array $data Webhook data.
	 */
	private function process_webhook( $data ) {
		if ( empty( $data['order_id'] ) || empty( $data['status'] ) ) {
			$this->log( 'Missing order_id or status in webhook data' );
			return;
		}

		$order_id = absint( $data['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log( 'Order not found: ' . $order_id );
			return;
		}

		// Update transaction ID if provided.
		if ( ! empty( $data['transaction_id'] ) ) {
			$order->update_meta_data( '_ap2_transaction_id', sanitize_text_field( $data['transaction_id'] ) );
		}

		// Process based on status.
		switch ( $data['status'] ) {
			case 'completed':
				$order->payment_complete( $data['transaction_id'] );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Transaction ID */
						__( 'AP2 payment completed. Transaction ID: %s', 'ap2-gateway' ),
						$data['transaction_id']
					)
				);
				break;

			case 'failed':
				$order->update_status( 'failed', __( 'AP2 payment failed', 'ap2-gateway' ) );
				break;

			case 'cancelled':
				$order->update_status( 'cancelled', __( 'AP2 payment cancelled', 'ap2-gateway' ) );
				break;
		}

		$order->save();
	}

	/**
	 * Make API request.
	 *
	 * @param string $endpoint API endpoint.
	 * @param array  $data Request data.
	 * @return array|WP_Error
	 */
	private function api_request( $endpoint, $data ) {
		$url = $this->api_endpoint . $endpoint;

		// Add authentication.
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
			'X-API-Secret'  => $this->api_secret,
		);

		$args = array(
			'method'  => 'POST',
			'headers' => $headers,
			'body'    => wp_json_encode( $data ),
			'timeout' => 30,
		);

		$this->log( 'API Request to ' . $endpoint );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'API Error: ' . $response->get_error_message() );
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data ) ) {
			$this->log( 'Invalid API response' );
			return new WP_Error( 'api_error', __( 'Invalid response from payment gateway.', 'ap2-gateway' ) );
		}

		return $data;
	}

	/**
	 * Verify webhook signature.
	 *
	 * @param string $body Request body.
	 * @return bool
	 */
	private function verify_signature( $body ) {
		if ( empty( $_SERVER['HTTP_X_AP2_SIGNATURE'] ) ) {
			return false;
		}

		$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AP2_SIGNATURE'] ) );
		$expected  = hash_hmac( 'sha256', $body, $this->api_secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Log messages.
	 *
	 * @param string $message Log message.
	 */
	private function log( $message ) {
		if ( $this->debug && $this->logger ) {
			$this->logger->add( 'ap2-gateway', $message );
		}
	}
}