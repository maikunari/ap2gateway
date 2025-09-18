<?php
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Main plugin file.
/**
 * Plugin Name:       AP2 Gateway for WooCommerce
 * Plugin URI:        https://ap2gateway.com
 * Description:       Accept payments from AI agents using Google's AP2 (Agent Payments Protocol). The first WordPress plugin for agent commerce.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            AP2 Gateway
 * Author URI:        https://ap2gateway.com
 * Text Domain:       ap2-gateway
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 7.1
 * WC tested up to:   9.0
 *
 * @package AP2_Gateway
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AP2_GATEWAY_VERSION', '1.0.0' );
define( 'AP2_GATEWAY_PLUGIN_FILE', __FILE__ );
define( 'AP2_GATEWAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AP2_GATEWAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AP2_GATEWAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main AP2 Gateway class.
 */
class AP2_Gateway {

	/**
	 * Single instance of the class.
	 *
	 * @var AP2_Gateway
	 */
	protected static $instance = null;

	/**
	 * Main AP2_Gateway Instance.
	 *
	 * Ensures only one instance of AP2_Gateway is loaded or can be loaded.
	 *
	 * @return AP2_Gateway - Main instance.
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
		// Check if WooCommerce is active.
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		// Initialize the plugin.
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );

		// Add gateway to WooCommerce.
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . AP2_GATEWAY_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );

		// Enqueue admin styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
			return true;
		}

		// Check for network activated.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . '/wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( 'woocommerce/woocommerce.php' );
	}

	/**
	 * WooCommerce missing notice.
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$nonce = wp_create_nonce( 'ap2_gateway_dismiss_notice' );
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s: WooCommerce plugin URL */
					esc_html__( 'AP2 Gateway for WooCommerce requires WooCommerce to be installed and activated. Please %s to use this plugin.', 'ap2-gateway' ),
					'<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">' . esc_html__( 'install WooCommerce', 'ap2-gateway' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Initialize plugin.
	 */
	public function init() {
		// Load plugin textdomain.
		load_plugin_textdomain( 'ap2-gateway', false, dirname( AP2_GATEWAY_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Plugins loaded hook.
	 */
	public function plugins_loaded() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		// Include required classes.
		require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-wc-gateway-ap2.php';
		require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-ap2-agent-detector.php';
		require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-ap2-audit-handler.php';

		// Initialize agent detector.
		AP2_Agent_Detector::instance();

		// Load admin features.
		if ( is_admin() ) {
			// WooCommerce Analytics integration.
			require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/admin/class-ap2-analytics.php';

			// Order list modifications.
			require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/admin/class-ap2-order-list-modifications.php';

			// Keep simple analytics as fallback.
			require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/admin/class-ap2-analytics-simple.php';
		}

		// Load HPOS features if WooCommerce supports it.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-ap2-hpos-optimizer.php';
			require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-ap2-datastore.php';
			require_once AP2_GATEWAY_PLUGIN_DIR . 'includes/class-ap2-migration-handler.php';

			// Register DataStore.
			add_filter( 'woocommerce_data_stores', array( $this, 'register_data_stores' ) );
		}
	}

	/**
	 * Add the gateway to WooCommerce.
	 *
	 * @param array $gateways Payment gateways.
	 * @return array
	 */
	public function add_gateway( $gateways ) {
		$gateways[] = 'WC_Gateway_AP2';
		return $gateways;
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ap2_gateway' ) ) . '">' . esc_html__( 'Settings', 'ap2-gateway' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_admin_styles( $hook_suffix ) {
		// Only load on WooCommerce pages.
		$wc_pages = array(
			'woocommerce_page_wc-orders',
			'edit-shop_order',
			'shop_order',
			'woocommerce_page_wc-admin',
			'woocommerce_page_ap2-analytics',
			'toplevel_page_woocommerce',
		);

		if ( in_array( $hook_suffix, $wc_pages, true ) || strpos( $hook_suffix, 'woocommerce' ) !== false ) {
			wp_enqueue_style(
				'ap2-admin',
				AP2_GATEWAY_PLUGIN_URL . 'assets/css/admin/ap2-admin.css',
				array( 'woocommerce_admin_styles' ),
				AP2_GATEWAY_VERSION,
				'all'
			);
		}
	}

	/**
	 * Plugin activation hook.
	 */
	public static function activate() {
		// Create HPOS optimizer tables.
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		// Create agent order index table for HPOS optimization.
		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ap2_agent_order_index (
			order_id bigint(20) unsigned NOT NULL,
			agent_id varchar(100) NOT NULL,
			mandate_token varchar(100),
			transaction_type varchar(50),
			transaction_id varchar(100),
			payment_timestamp datetime DEFAULT NULL,
			total_amount decimal(10,2) DEFAULT 0,
			processing_time int(11) DEFAULT 0,
			PRIMARY KEY (order_id),
			KEY idx_agent_id (agent_id),
			KEY idx_transaction_type (transaction_type),
			KEY idx_payment_timestamp (payment_timestamp),
			KEY idx_mandate_token (mandate_token)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Flush rewrite rules.
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation hook.
	 */
	public static function deactivate() {
		// Deactivation tasks if needed.
		flush_rewrite_rules();
	}

	/**
	 * Register custom data stores.
	 *
	 * @param array $stores Data stores.
	 * @return array Modified stores.
	 */
	public function register_data_stores( $stores ) {
		$stores['report-agent-orders'] = 'AP2_Agent_Orders_DataStore';
		return $stores;
	}
}

// Activation and deactivation hooks.
register_activation_hook( __FILE__, array( 'AP2_Gateway', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AP2_Gateway', 'deactivate' ) );

// Declare HPOS compatibility before WooCommerce initialization.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);

			// Also declare compatibility with other WooCommerce features.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

// Initialize the plugin.
add_action( 'plugins_loaded', array( 'AP2_Gateway', 'instance' ), 0 );