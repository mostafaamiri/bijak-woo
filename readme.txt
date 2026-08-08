=== Bijak ===
Contributors: bijak
Plugin URI: https://wordpress.org/plugins/bijak/
Donate link: https://bijak.ir
Tags: shipping, woocommerce, logistics, delivery, iran
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 5.5
WC tested up to: 10.7
Stable tag: 1.2.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add smart freight shipping to WooCommerce with live rate estimates and order integration via the Bijak API.

== Description ==

**Bijak Smart Freight** integrates directly with WooCommerce to enable nationwide freight shipping across Iran.  
It allows store owners to automatically calculate delivery costs, choose between prepaid and postpaid shipping, and register shipments in Bijak’s logistics system.

### Main Features:
* Adds a new shipping method for **Bijak Freight**
* Supports both **Prepaid** and **Postpaid (Cash on Delivery)** modes
* Fetches live shipping rates from the Bijak API
* Supports **Door-to-door delivery** or **Pickup at freight terminal**
* Provides secure map-based destination selection for door-to-door delivery
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
- Terms of Service: https://bijak.ir/privacy-policy/
- Privacy Policy: https://bijak.ir/privacy-policy/

== Screenshots ==

1. Bijak settings page with API key and account info
2. Shipping configuration in WooCommerce
3. Checkout page with Bijak shipping method
4. Order details with Bijak tracking code
5. Secure map-based delivery location picker

== Changelog ==

= 1.2.5 =
* Fixed loading of the bundled Persian translation catalogue.

= 1.2.4 =
* Load the bundled Persian translations for checkout and location-picker text.

= 1.2.3 =
* Fixed the location-picker loading state after the iframe has loaded.

= 1.2.2 =
* Fixed destination city option values so WooCommerce receives the selected Bijak city ID

= 1.2.1 =
* Fixed checkout fragment handling so the selected destination city and door-delivery state are read from the active Bijak checkout box

= 1.2.0 =
* Added a secure map-based destination picker for door-to-door delivery
* Added session-backed destination coordinates to rate estimates, checkout validation, order metadata, and Bijak order submission
* Added destination-location invalidation when the customer changes the destination city

= 1.1.0 =
* Added origin city selection in plugin settings and checkout flow improvements for destination handling
* Updated Bijak API integrations to newer endpoints for order status retrieval and related requests
* Fixed multiple checkout and shipping-rate refresh bugs for more reliable rate calculation

= 1.0.1 =
* Added WooCommerce HPOS compatibility declaration and order-meta handling through `WC_Order` APIs
* Added support for HPOS order editor metabox
* Replaced PHP 8-only syntax so plugin stays compatible with PHP 7.4+
* Updated compatibility metadata for latest WordPress and WooCommerce releases

= 1.0.0 =
* Initial public release
* Integration with Bijak API for real-time rate estimation
* Automatic order registration with Bijak
* Shipment status display for users and admins

== Upgrade Notice ==

= 1.2.5 =
Fixes loading the bundled Persian translations.

= 1.2.4 =
Loads the bundled Persian checkout translations.

= 1.2.3 =
Fixes the loading state after the map-picker iframe loads.

= 1.2.2 =
Fixes selecting a destination city before opening the map picker.

= 1.2.1 =
Fixes destination city handling after WooCommerce checkout fragments refresh.

= 1.2.0 =
Door-to-door delivery now requires a destination location selected through the configured map picker.

= 1.1.0 =
This release adds origin-city support, updates key Bijak API endpoints, and includes important bug fixes for checkout reliability.

= 1.0.1 =
Compatibility update for latest WooCommerce/WordPress while preserving backward support.
