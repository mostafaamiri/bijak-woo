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
Stable tag: 1.3.23
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
* Supports optional Bijak-specific product and variation shipping dimensions with WooCommerce fallback
* Automatically registers orders in Bijak after WooCommerce checkout
* Displays shipment tracking status and code in both user and admin panels

== Installation ==

1. Download and upload the `bijak` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Go to **Bijak Settings** under the WordPress admin menu
4. Enter your **API Key** obtained from your Bijak account
5. Configure the **Origin city** and other settings
6. In WooCommerce → Shipping → Zones, enable the method **Bijak Shipping**

== Developer configuration ==

Integration endpoints are centralized in `includes/class-config.php`. Update `Config::MAP_PICKER_URL` there when deploying the location picker; it is intentionally not exposed as a merchant setting.

The plugin requests a short-lived picker authorization from the Bijak API server-side. The API Key is never placed in JavaScript, the iframe, or the browser URL. Revoking the key invalidates new picker sessions and location saves.

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

= 1.3.23 =
* Convert store values in Iranian Rial to Toman before sending goods values to Bijak.

= 1.3.22 =
* Convert WooCommerce product dimensions and weights to Bijak's cm/kg units before rate estimates and order submission.

= 1.3.21 =
* Hide Bijak shipment-status panels for orders that use another shipping method.

= 1.3.20 =
* Improved the Bijak shipment-status panel on the customer view-order page.

= 1.3.19 =
* Redesigned the Bijak shipment-status panel on the order-received page.
* Corrected the front-end Bijak button to open the corresponding shipment details page.

= 1.3.18 =
* Redesigned the Bijak checkout panel for clearer destination, delivery, location, and price-estimate controls.

= 1.3.17 =
* Added optional Bijak shipping dimensions for products and variations.
* Bijak shipping now uses product-specific dimensions first, then falls back to WooCommerce values.
* Added clear validation errors when length, width, height, or weight is missing.

= 1.3.16 =
* Added a "Shipping with Bijak" filter to both legacy and HPOS WooCommerce order lists.

= 1.3.15 =
* Redesigned the Bijak shipping-status panel in the WooCommerce order editor.
* Added a direct link from each submitted WooCommerce order to its Bijak order details page.

= 1.3.14 =
* Renamed the default order-state filter label and corrected the filter icon position for RTL layouts.

= 1.3.13 =
* Fixed the order filter button icon contrast and RTL icon placement.

= 1.3.12 =
* Added a dashboard reminder linking administrators to Bijak Setup & Settings for API key and shipping-origin checks.
* Refined the shipping-origin settings layout with clearer grouping, controls, status styling, and responsive mobile behavior.

= 1.3.11 =
* Added a redesigned Bijak dashboard with account, wallet, and filtered order data from the Bijak API.
* Added compact RTL order pagination, total-order calculation, copyable tracking numbers, and direct links to order details.
* Improved the WordPress dashboard widget and added direct access to the in-plugin Bijak dashboard.
* Added a web-app shortcut for viewing, payments, and other Bijak account activities.
* Improved checkout destination validation without relying on third-party Persian WooCommerce localization plugins.
* Made map location confirmation save coordinates immediately without reverse-geocoding delays.

= 1.3.10 =
* Added the bundled Persian Jalali date picker for order date filters in the Bijak dashboard.
* Improved the Bijak admin setup screens and origin location picker experience.

= 1.3.8 =
* Added explicit admin controls to fill the origin from the Bijak profile or choose it securely on the map.
* Made origin coordinates readonly and added a Neshan map link.
* Clear origin coordinates when the origin address is changed manually.

= 1.3.7 =
* Added server-side Bijak API authorization for location-picker sessions.
* Picker grants are short-lived and revalidated before saving coordinates; revoked API keys are rejected.
* Added admin origin profile/map controls with readonly coordinates and Neshan map links.
* Clear origin coordinates when the administrator changes the origin address manually.

= 1.3.6 =
* Show destination options as province followed by city.
* Pre-fill the opened destination search with the checkout province and city.

= 1.3.5 =
* Match Persian city spelling variants and multi-part city names more reliably.
* Require the checkout province when resolving duplicate city names.

= 1.3.4 =
* Send checkout address fields to Bijak when registering a door-to-door order.
* Limit location-picker data to the selected latitude and longitude.

= 1.3.3 =
* Fixed the location-picker loading message remaining visible after the iframe loads.
* Delegated browser geolocation permission to the secure location-picker iframe.
* Corrected the map center pin direction.

= 1.3.2 =
* Match the standard WooCommerce billing/shipping city and province against Bijak destinations.
* Prompt customers to choose a Bijak city when their checkout city is unavailable.

= 1.3.1 =
* Show the destination province beside each city and open the picker at the city's API coordinates when available.
* Fall back to searching by city and province when city coordinates are unavailable.

= 1.3.0 =
* Removed the location-picker URL from merchant settings.
* Centralized API, picker, panel URLs, timeout, and picker security values in `includes/class-config.php`.

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

= 1.3.16 =
Adds a direct shipping-method filter for Bijak orders in the WooCommerce orders list.

= 1.3.15 =
Adds a clearer Bijak status panel and direct order-details link in WooCommerce order editing.

= 1.3.14 =
Improves the order filter label and RTL button icon placement.

= 1.3.13 =
Fixes the filter button icon visibility in the Bijak order dashboard.

= 1.3.12 =
Improves the setup experience with a clearer shipping-origin form and a direct configuration reminder in the dashboard.

= 1.3.11 =
Adds the Bijak administration dashboard, order tools, improved location selection, and checkout compatibility fixes.

= 1.3.10 =
Adds Jalali order date filters and improves the Bijak admin setup experience.

= 1.3.7 =
* Added server-side Bijak API authorization for location-picker sessions.
* Picker grants are short-lived and revalidated before saving coordinates; revoked API keys are rejected.

= 1.3.6 =
The destination selector now searches by province and city when an automatic match is unavailable.

= 1.3.5 =
Improves city matching for spelling variants and prevents selecting a same-named city from the wrong province.

= 1.3.4 =
Door-to-door orders now use the WooCommerce checkout address instead of the picker address.

= 1.3.3 =
Fixes the picker loading indicator, center marker, and browser location permission in the checkout iframe.

= 1.3.2 =
Checkout now synchronizes the customer's selected city with the Bijak destination list.

= 1.3.1 =
The checkout city list now includes province names and provides a city-centered map default.

= 1.3.0 =
Integration endpoints are now maintained in `includes/class-config.php` instead of the admin settings page.

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
