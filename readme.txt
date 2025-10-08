=== Bijak – Smart Freight Shipping for WooCommerce ===
Contributors: bijak
Plugin URI: https://github.com/mostafaamiri/bijak_wordpress_plugin
Donate link: https://bijak.ir
Tags: shipping, woocommerce, logistics, delivery, iran
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 5.5
WC tested up to: 8.9
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bijak adds a smart freight shipping method to WooCommerce, enabling merchants in Iran to calculate shipping rates and automatically register orders through the Bijak logistics system.

== Description ==

**Bijak Smart Freight** integrates directly with WooCommerce to enable nationwide freight shipping across Iran.  
It allows store owners to automatically calculate delivery costs, choose between prepaid and postpaid shipping, and register shipments in Bijak’s logistics system.

### Main Features:
* Adds a new shipping method for **Bijak Freight**
* Supports both **Prepaid** and **Postpaid (Cash on Delivery)** modes
* Fetches live shipping rates from the Bijak API
* Supports **Door-to-door delivery** or **Pickup at freight terminal**
* Automatically registers orders in Bijak after WooCommerce checkout
* Displays shipment tracking status and code in both user and admin panels

== Installation ==

1. Download and upload the `bijak` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Go to **Bijak Settings** under the WordPress admin menu
4. Enter your **API Key** obtained from your Bijak account
5. Configure the **Origin city** and other settings
6. In WooCommerce → Shipping → Zones, enable the method **Bijak Shipping**

== Frequently Asked Questions ==

= Do I need a Bijak account? =
Yes. You need an active Bijak account to obtain an API key.

= How are shipping costs calculated? =
The plugin requests real-time rate estimates from the Bijak API based on product weight, size, and destination.

= Can I use this plugin alongside other shipping methods? =
Yes. It registers as a new WooCommerce shipping method, and you can keep other methods active.

== External Services ==

This plugin connects to the **Bijak Smart Freight API** to calculate shipping rates and register orders.

**What data is sent**
- Origin and destination city IDs  
- Product dimensions (length, width, height, weight)  
- Customer’s contact data for shipment registration  

**When data is sent**
- During checkout (for rate estimation)
- When an order is created (for shipment registration)

**Service Provider**
- Bijak (https://bijak.ir)  
- Terms of Service: https://bijak.ir/%D9%82%D9%88%D8%A7%D9%86%DB%8C%D9%86-%D8%B3%D8%A7%D9%85%D8%A7%D9%86%D9%87-%D8%A8%DB%8C%D8%AC%DA%A9/
- Privacy Policy: https://bijak.ir/%D9%82%D9%88%D8%A7%D9%86%DB%8C%D9%86-%D8%B3%D8%A7%D9%85%D8%A7%D9%86%D9%87-%D8%A8%DB%8C%D8%AC%DA%A9/

== Screenshots ==

1. Bijak settings page with API key and account info
2. Shipping configuration in WooCommerce
3. Checkout page with Bijak shipping method
4. Order details with Bijak tracking code

== Changelog ==

= 1.0.0 =
* Initial public release
* Integration with Bijak API for real-time rate estimation
* Automatic order registration with Bijak
* Shipment status display for users and admins

== Upgrade Notice ==

= 1.0.0 =
First official release of the Bijak Smart Freight plugin for WooCommerce.
