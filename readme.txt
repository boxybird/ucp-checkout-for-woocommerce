=== UCP Checkout for WooCommerce ===
Contributors: andrewrhyand
Tags: ai, woocommerce, checkout, chatgpt, claude
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable AI agents like ChatGPT, Gemini, and Claude to purchase products from your WooCommerce store using the Universal Commerce Protocol (UCP).

== Description ==

**UCP Checkout for WooCommerce** implements the [Universal Commerce Protocol (UCP)](https://ucp.dev), enabling AI assistants to discover, browse, and complete purchases on your WooCommerce store.

= What is UCP? =

The Universal Commerce Protocol is an open standard that allows AI agents to interact with e-commerce stores programmatically. When a customer asks an AI assistant like ChatGPT, Claude, or Gemini to "buy running shoes," the AI can discover UCP-enabled stores and complete the purchase on behalf of the user.

= Features =

* **AI-Discoverable Store** - Your store becomes visible to AI shopping agents via the `/.well-known/ucp` manifest
* **Checkout Sessions** - Full checkout flow support (create, update, complete, cancel)
* **WooCommerce Integration** - Works with your existing products, inventory, and payment methods
* **Secure by Design** - Built following UCP security specifications

= Use Cases =

* Voice commerce through AI assistants
* Conversational shopping experiences
* Automated B2B purchasing
* AI-powered price comparison and shopping

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and configured
4. Verify your manifest at `https://yoursite.com/.well-known/ucp`

== Frequently Asked Questions ==

= What AI assistants are supported? =

Any AI assistant that implements the UCP protocol, including ChatGPT, Claude, Gemini, and others.

= Do I need to configure anything? =

The plugin works out of the box with your existing WooCommerce setup. No additional configuration required.

= Is this secure? =

Yes. UCP Checkout implements the security specifications defined in the UCP protocol, including secure session handling and payment data protection.

= What WooCommerce version is required? =

WooCommerce 8.0 or higher is required.

== Screenshots ==

1. The UCP manifest at /.well-known/ucp

== Changelog ==

= 1.0.0 =
* Initial release
* UCP manifest endpoint (/.well-known/ucp)
* Checkout session management (create, get, update, complete, cancel)
* WooCommerce product and order integration

== Upgrade Notice ==

= 1.0.0 =
Initial release of UCP Checkout for WooCommerce.
