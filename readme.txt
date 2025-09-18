=== AP2 Gateway for WooCommerce ===
Contributors: yourwordpressusername
Tags: woocommerce, payment gateway, ap2, ecommerce, payments
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure payment gateway for WooCommerce that integrates with the AP2 payment system.

== Description ==

AP2 Gateway for WooCommerce provides a seamless integration between your WooCommerce store and the AP2 payment platform. Accept payments securely with support for both live and test environments.

= Features =

* Easy setup and configuration
* Secure payment processing
* Support for test/sandbox mode
* Full refund support
* Detailed logging for debugging
* Webhook support for payment notifications
* WordPress coding standards compliant
* Translation ready

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* SSL certificate for production use

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/ap2-gateway` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce > Settings > Payments
4. Click on 'AP2 Gateway' to configure the payment method
5. Enter your API credentials (both test and live)
6. Enable the payment method and save changes

= Configuration =

After installation, configure the following settings:

* **Title** - The payment method title displayed to customers
* **Description** - Payment method description shown during checkout
* **Test Mode** - Enable to use sandbox environment for testing
* **API Key** - Your AP2 API key (separate fields for test and live)
* **API Secret** - Your AP2 API secret (separate fields for test and live)
* **Debug Log** - Enable to log API requests and responses

== Frequently Asked Questions ==

= How do I get AP2 API credentials? =

Contact AP2 support or visit their developer portal to obtain your API credentials.

= Is test mode available? =

Yes, the plugin supports both test (sandbox) and live environments. Enable test mode in the settings to use sandbox credentials.

= Does this plugin support refunds? =

Yes, full and partial refunds are supported directly from the WooCommerce order management interface.

= Is the plugin secure? =

Yes, the plugin follows WordPress coding standards, uses nonces for security, escapes all output, and validates all input data.

= Can I translate the plugin? =

Yes, the plugin is fully translation-ready with a POT file included in the languages directory.

== Screenshots ==

1. Payment gateway settings page
2. Checkout page with AP2 payment option
3. Order details with AP2 transaction information

== Changelog ==

= 1.0.0 =
* Initial release
* Basic payment processing functionality
* Refund support
* Webhook handling
* Test mode support

== Upgrade Notice ==

= 1.0.0 =
Initial release of AP2 Gateway for WooCommerce.