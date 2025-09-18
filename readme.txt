=== AP2 Gateway for WooCommerce ===
Contributors: ap2gateway
Tags: payments, AI, agents, AP2, woocommerce
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 5.0
WC tested up to: 9.0

The first WordPress plugin for Google's AP2 (Agent Payments Protocol) - Accept payments from AI agents in your WooCommerce store.

== Description ==

**AP2 Gateway for WooCommerce** is the first WordPress plugin to implement Google's groundbreaking AP2 (Agent Payments Protocol), enabling your WooCommerce store to accept payments directly from AI agents. As artificial intelligence becomes increasingly integrated into commerce, AP2 provides a standardized, secure method for autonomous AI agents to make purchases on behalf of users.

= What is AP2? =

AP2 (Agent Payments Protocol) is Google's innovative payment protocol designed specifically for AI agents to conduct transactions autonomously. It allows AI assistants, shopping bots, and other intelligent agents to make purchases using pre-authorized mandates, creating a seamless bridge between AI capabilities and e-commerce.

= Key Features =

* **First-to-Market**: The first WordPress plugin supporting Google's AP2 protocol
* **Agent Authentication**: Secure Agent ID and Mandate Token validation
* **Simple Integration**: Easy setup with your existing WooCommerce store
* **Test Mode**: Safe sandbox environment for testing agent payments
* **Order Tracking**: Complete audit trail of all agent transactions
* **Security First**: Built-in nonce verification and input sanitization
* **Developer Friendly**: Extensive hooks and filters for customization
* **Translation Ready**: Full internationalization support

= How It Works =

1. AI agents authenticate using their unique Agent ID and Mandate Token
2. The plugin validates credentials against the AP2 protocol
3. Orders are processed with complete transaction logging
4. Store owners can track all agent purchases in WooCommerce orders

= Use Cases =

* AI shopping assistants making purchases for users
* Automated procurement systems
* Smart home devices ordering supplies
* Business process automation
* AI-powered personal assistants

== Installation ==

= Automatic Installation =

1. Log in to your WordPress dashboard
2. Navigate to Plugins > Add New
3. Search for "AP2 Gateway for WooCommerce"
4. Click "Install Now" and then "Activate"

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to Plugins > Add New > Upload Plugin
4. Select the ZIP file and click "Install Now"
5. Activate the plugin after installation

= Configuration =

1. Go to WooCommerce > Settings > Payments
2. Find "Agent Payment (AP2)" and click "Set up"
3. Configure these settings:
   * Enable/Disable the gateway
   * Set your payment title and description
   * Enable Test Mode for development
   * Configure API credentials when available
4. Save changes

= For Developers =

To test agent payments in Test Mode:
* Use any valid Agent ID format (alphanumeric with hyphens/underscores)
* Use any alphanumeric Mandate Token
* Transactions will be marked with a TEST- prefix

== Frequently Asked Questions ==

= What is AP2 (Agent Payments Protocol)? =

AP2 is Google's standardized protocol that enables AI agents to make secure, authorized payments on behalf of users. It provides a framework for authentication, authorization, and transaction processing specifically designed for autonomous AI systems.

= How secure are agent payments? =

The plugin implements multiple security layers:
* Nonce verification on all transactions
* Input sanitization and validation
* Secure credential storage
* Transaction logging and audit trails
* Support for test mode to safely validate integrations

= Can I use this without AP2 API credentials? =

Yes! The plugin includes a Test Mode that simulates the AP2 protocol, allowing you to develop and test agent payment flows without live credentials. Perfect for development and demonstration purposes.

= What are Agent IDs and Mandate Tokens? =

* **Agent ID**: A unique identifier for each AI agent, similar to a username
* **Mandate Token**: A pre-authorized token that allows the agent to make payments up to specified limits

= Is this officially supported by Google? =

This is an independent implementation of the AP2 protocol specification. While not officially developed by Google, it follows the AP2 protocol standards for agent payment processing.

= Can traditional customers still checkout normally? =

Absolutely! The AP2 gateway appears as an additional payment option. Traditional payment methods remain unchanged, and customers can choose their preferred payment method.

= What WooCommerce versions are supported? =

The plugin supports WooCommerce 5.0 and above, and has been tested with the latest WooCommerce 9.0 release.

= How do I get support? =

* Documentation: Visit our [GitHub repository](https://github.com/ap2gateway/ap2-gateway-woocommerce)
* Issues: Report bugs on our [GitHub Issues page](https://github.com/ap2gateway/ap2-gateway-woocommerce/issues)
* Community: Join the discussion in the WordPress.org support forums

== Screenshots ==

1. AP2 Gateway settings page in WooCommerce
2. Agent payment fields on checkout page
3. Test mode notification for development
4. Order details showing agent payment information
5. Payment method selection with AP2 option

== Changelog ==

= 1.0.0 - 2025-09-18 =
* Initial release
* First WordPress plugin to support Google's AP2 protocol
* Agent ID and Mandate Token authentication
* Test mode for safe development
* WooCommerce order integration
* Secure payment processing flow
* Input validation and sanitization
* Transaction logging system
* Internationalization support
* WordPress coding standards compliance

== Upgrade Notice ==

= 1.0.0 =
Welcome to the future of commerce! This is the first release of AP2 Gateway for WooCommerce, bringing AI agent payment capabilities to WordPress.

== Developer Notes ==

= Hooks and Filters =

The plugin provides several hooks for developers:

* `wc_ap2_gateway_icon` - Customize the payment method icon
* `ap2_payment_process` - Modify payment processing flow
* `ap2_agent_validation` - Add custom validation rules

= Contributing =

We welcome contributions! Please visit our [GitHub repository](https://github.com/ap2gateway/ap2-gateway-woocommerce) to:
* Report issues
* Submit pull requests
* Review documentation
* Join the development discussion

== Privacy Policy ==

This plugin stores transaction data including Agent IDs and order information in your WordPress database. No data is transmitted to external services unless you configure live AP2 API credentials. In Test Mode, all processing happens locally on your server.

== Credits ==

Developed by the AP2 Gateway team. Special thanks to the WooCommerce and WordPress communities for their continued support of innovative payment solutions.