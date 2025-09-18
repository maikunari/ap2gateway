<?php
/**
 * AP2 Agent Detector
 *
 * Detects and handles AI agent visitors to optimize their experience.
 *
 * @package AP2Gateway
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AP2 Agent Detector Class.
 *
 * Handles detection of AI agents and optimizes the checkout experience for them.
 */
class AP2_Agent_Detector {

	/**
	 * Single instance of the class.
	 *
	 * @var AP2_Agent_Detector
	 */
	protected static $instance = null;

	/**
	 * Agent detection result cache.
	 *
	 * @var bool|null
	 */
	private static $is_agent_cached = null;

	/**
	 * Statistics transient key.
	 *
	 * @var string
	 */
	const STATS_TRANSIENT_KEY = 'ap2_agent_statistics';

	/**
	 * Statistics transient expiration (24 hours).
	 *
	 * @var int
	 */
	const STATS_EXPIRATION = 86400;

	/**
	 * Main AP2_Agent_Detector Instance.
	 *
	 * Ensures only one instance of AP2_Agent_Detector is loaded.
	 *
	 * @return AP2_Agent_Detector Main instance.
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
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Initialize agent detection on init.
		add_action( 'init', array( $this, 'init' ), 5 );

		// Add body class for agent visitors.
		add_filter( 'body_class', array( $this, 'add_agent_body_class' ) );

		// Simplify checkout for agents.
		add_action( 'woocommerce_checkout_init', array( $this, 'simplify_agent_checkout' ) );

		// Remove unnecessary checkout fields for agents.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'remove_agent_checkout_fields' ), 20 );

		// Skip cart page for agents.
		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'skip_cart_for_agents' ) );

		// Add agent indicator to admin orders.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_agent_indicator' ) );

		// Enqueue agent-specific styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_agent_styles' ) );

		// Track agent statistics.
		add_action( 'wp', array( $this, 'track_agent_visit' ) );

		// Add agent detection info to checkout data.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_agent_order_meta' ), 10, 2 );
	}

	/**
	 * Initialize agent detection.
	 */
	public function init() {
		// Clear cache if needed.
		if ( isset( $_GET['ap2_clear_cache'] ) && current_user_can( 'manage_options' ) ) {
			self::$is_agent_cached = null;
			delete_transient( self::STATS_TRANSIENT_KEY );
			wp_safe_redirect( remove_query_arg( 'ap2_clear_cache' ) );
			exit;
		}
	}

	/**
	 * Check if the current request is from an AI agent.
	 *
	 * @return bool True if agent detected, false otherwise.
	 */
	public static function is_agent_request() {
		// Return cached result if available.
		if ( self::$is_agent_cached !== null ) {
			return self::$is_agent_cached;
		}

		$is_agent = false;

		// Check X-AP2-Agent header.
		if ( ! empty( $_SERVER['HTTP_X_AP2_AGENT'] ) ) {
			$is_agent = true;
		}

		// Check User-Agent string for AP2-Agent.
		if ( ! $is_agent && ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			if ( stripos( $user_agent, 'AP2-Agent' ) !== false ) {
				$is_agent = true;
			}
		}

		// Check query parameter ap2_agent=true.
		if ( ! $is_agent && isset( $_GET['ap2_agent'] ) ) {
			$param = sanitize_text_field( wp_unslash( $_GET['ap2_agent'] ) );
			if ( $param === 'true' || $param === '1' ) {
				$is_agent = true;
			}
		}

		// Allow filtering of agent detection.
		$is_agent = apply_filters( 'ap2_is_agent_request', $is_agent );

		// Cache the result.
		self::$is_agent_cached = $is_agent;

		return $is_agent;
	}

	/**
	 * Get agent identifier for tracking.
	 *
	 * @return string Agent identifier.
	 */
	private static function get_agent_identifier() {
		if ( ! empty( $_SERVER['HTTP_X_AP2_AGENT'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AP2_AGENT'] ) );
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
			if ( stripos( $user_agent, 'AP2-Agent' ) !== false ) {
				// Extract agent ID from user agent string if present.
				preg_match( '/AP2-Agent\/([^\s;]+)/', $user_agent, $matches );
				if ( ! empty( $matches[1] ) ) {
					return $matches[1];
				}
			}
		}

		return 'unknown-agent';
	}

	/**
	 * Add body class for agent visitors.
	 *
	 * @param array $classes Body classes.
	 * @return array Modified body classes.
	 */
	public function add_agent_body_class( $classes ) {
		if ( self::is_agent_request() ) {
			$classes[] = 'ap2-agent-visitor';
			$classes[] = 'simplified-checkout';
		}
		return $classes;
	}

	/**
	 * Simplify checkout process for agents.
	 *
	 * @param WC_Checkout $checkout Checkout object.
	 */
	public function simplify_agent_checkout( $checkout ) {
		if ( ! self::is_agent_request() ) {
			return;
		}

		// Disable account creation prompts.
		add_filter( 'woocommerce_enable_signup_and_login_from_checkout', '__return_false' );

		// Skip terms and conditions for agents.
		add_filter( 'woocommerce_checkout_show_terms', '__return_false' );

		// Disable order notes.
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );

		// Pre-select AP2 payment method if available.
		add_filter( 'woocommerce_default_payment_method', array( $this, 'set_default_payment_method' ) );
	}

	/**
	 * Remove unnecessary checkout fields for agents.
	 *
	 * @param array $fields Checkout fields.
	 * @return array Modified checkout fields.
	 */
	public function remove_agent_checkout_fields( $fields ) {
		if ( ! self::is_agent_request() ) {
			return $fields;
		}

		// Remove marketing fields.
		if ( isset( $fields['billing']['billing_company'] ) ) {
			$fields['billing']['billing_company']['required'] = false;
		}

		// Remove order notes.
		if ( isset( $fields['order'] ) ) {
			unset( $fields['order']['order_comments'] );
		}

		// Simplify phone field (not required).
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['required'] = false;
		}

		// Apply additional agent-specific field modifications.
		return apply_filters( 'ap2_agent_checkout_fields', $fields );
	}

	/**
	 * Skip cart page for agents and go directly to checkout.
	 *
	 * @param string $url Redirect URL.
	 * @return string Modified redirect URL.
	 */
	public function skip_cart_for_agents( $url ) {
		if ( self::is_agent_request() && ! is_checkout() ) {
			return wc_get_checkout_url();
		}
		return $url;
	}

	/**
	 * Set default payment method for agents.
	 *
	 * @return string Payment method ID.
	 */
	public function set_default_payment_method() {
		return 'ap2_agent_payments';
	}

	/**
	 * Track agent visits in statistics.
	 */
	public function track_agent_visit() {
		if ( ! self::is_agent_request() ) {
			return;
		}

		// Get current statistics.
		$stats = get_transient( self::STATS_TRANSIENT_KEY );

		if ( ! is_array( $stats ) ) {
			$stats = array(
				'total_visits'    => 0,
				'unique_agents'   => array(),
				'hourly_visits'   => array(),
				'page_views'      => array(),
				'last_visit'      => '',
				'daily_visits'    => array(),
			);
		}

		$agent_id = self::get_agent_identifier();
		$current_hour = gmdate( 'Y-m-d H:00:00' );
		$current_date = gmdate( 'Y-m-d' );
		$current_page = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		// Update total visits.
		$stats['total_visits']++;

		// Track unique agents.
		if ( ! in_array( $agent_id, $stats['unique_agents'], true ) ) {
			$stats['unique_agents'][] = $agent_id;
		}

		// Track hourly visits.
		if ( ! isset( $stats['hourly_visits'][ $current_hour ] ) ) {
			$stats['hourly_visits'][ $current_hour ] = 0;
		}
		$stats['hourly_visits'][ $current_hour ]++;

		// Track daily visits.
		if ( ! isset( $stats['daily_visits'][ $current_date ] ) ) {
			$stats['daily_visits'][ $current_date ] = 0;
		}
		$stats['daily_visits'][ $current_date ]++;

		// Track page views.
		if ( ! isset( $stats['page_views'][ $current_page ] ) ) {
			$stats['page_views'][ $current_page ] = 0;
		}
		$stats['page_views'][ $current_page ]++;

		// Update last visit.
		$stats['last_visit'] = current_time( 'mysql' );

		// Keep only last 24 hours of hourly data.
		$cutoff_time = gmdate( 'Y-m-d H:00:00', strtotime( '-24 hours' ) );
		foreach ( $stats['hourly_visits'] as $hour => $count ) {
			if ( $hour < $cutoff_time ) {
				unset( $stats['hourly_visits'][ $hour ] );
			}
		}

		// Keep only last 30 days of daily data.
		$cutoff_date = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		foreach ( $stats['daily_visits'] as $date => $count ) {
			if ( $date < $cutoff_date ) {
				unset( $stats['daily_visits'][ $date ] );
			}
		}

		// Save updated statistics.
		set_transient( self::STATS_TRANSIENT_KEY, $stats, self::STATS_EXPIRATION );
	}

	/**
	 * Get agent visit statistics.
	 *
	 * @return array Statistics array.
	 */
	public static function get_statistics() {
		$stats = get_transient( self::STATS_TRANSIENT_KEY );

		if ( ! is_array( $stats ) ) {
			return array(
				'total_visits'    => 0,
				'unique_agents'   => array(),
				'unique_agents_count' => 0,
				'hourly_visits'   => array(),
				'page_views'      => array(),
				'last_visit'      => __( 'Never', 'ap2-gateway' ),
				'daily_visits'    => array(),
			);
		}

		// Convert unique agents array to count.
		if ( isset( $stats['unique_agents'] ) && is_array( $stats['unique_agents'] ) ) {
			$stats['unique_agents_count'] = count( $stats['unique_agents'] );
		} else {
			$stats['unique_agents_count'] = 0;
		}

		return $stats;
	}

	/**
	 * Save agent information to order meta.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Order data.
	 */
	public function save_agent_order_meta( $order, $data ) {
		if ( self::is_agent_request() ) {
			$order->update_meta_data( '_ap2_is_agent_order', 'yes' );
			$order->update_meta_data( '_ap2_agent_identifier', self::get_agent_identifier() );
			$order->update_meta_data( '_ap2_agent_detection_method', $this->get_detection_method() );
		}
	}

	/**
	 * Get the method used to detect the agent.
	 *
	 * @return string Detection method.
	 */
	private function get_detection_method() {
		if ( ! empty( $_SERVER['HTTP_X_AP2_AGENT'] ) ) {
			return 'header';
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && stripos( $_SERVER['HTTP_USER_AGENT'], 'AP2-Agent' ) !== false ) {
			return 'user-agent';
		}

		if ( isset( $_GET['ap2_agent'] ) ) {
			return 'query-parameter';
		}

		return 'unknown';
	}

	/**
	 * Display agent indicator in admin order page.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_agent_indicator( $order ) {
		$is_agent_order = $order->get_meta( '_ap2_is_agent_order' );

		if ( 'yes' === $is_agent_order ) {
			$agent_id = $order->get_meta( '_ap2_agent_identifier' );
			$detection_method = $order->get_meta( '_ap2_agent_detection_method' );
			?>
			<div class="ap2-agent-order-indicator" style="margin-top: 10px; padding: 10px; background: #f0f8ff; border-left: 4px solid #0073aa;">
				<strong><?php esc_html_e( '🤖 AI Agent Order', 'ap2-gateway' ); ?></strong><br>
				<?php if ( $agent_id ) : ?>
					<?php
					printf(
						/* translators: %s: Agent ID */
						esc_html__( 'Agent ID: %s', 'ap2-gateway' ),
						esc_html( $agent_id )
					);
					?>
					<br>
				<?php endif; ?>
				<?php if ( $detection_method ) : ?>
					<?php
					printf(
						/* translators: %s: Detection method */
						esc_html__( 'Detection: %s', 'ap2-gateway' ),
						esc_html( $detection_method )
					);
					?>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Clear agent statistics (admin use).
	 */
	public static function clear_statistics() {
		delete_transient( self::STATS_TRANSIENT_KEY );
	}

	/**
	 * Check if checkout should be simplified.
	 *
	 * @return bool True if checkout should be simplified.
	 */
	public static function should_simplify_checkout() {
		return self::is_agent_request() && apply_filters( 'ap2_simplify_agent_checkout', true );
	}

	/**
	 * Enqueue agent-specific styles.
	 */
	public function enqueue_agent_styles() {
		if ( self::is_agent_request() ) {
			wp_enqueue_style(
				'ap2-agent-styles',
				AP2_GATEWAY_PLUGIN_URL . 'assets/css/ap2-agent.css',
				array(),
				AP2_GATEWAY_VERSION,
				'all'
			);
		}
	}

	/**
	 * Get formatted statistics for display.
	 *
	 * @return array Formatted statistics.
	 */
	public static function get_formatted_statistics() {
		$stats = self::get_statistics();

		return array(
			'total_visits'    => number_format_i18n( $stats['total_visits'] ),
			'unique_agents'   => number_format_i18n( $stats['unique_agents_count'] ),
			'last_visit'      => $stats['last_visit'],
			'top_pages'       => array_slice( $stats['page_views'], 0, 5, true ),
			'visits_today'    => isset( $stats['daily_visits'][ gmdate( 'Y-m-d' ) ] ) ? $stats['daily_visits'][ gmdate( 'Y-m-d' ) ] : 0,
			'visits_24h'      => array_sum( $stats['hourly_visits'] ),
		);
	}
}