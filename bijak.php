<?php
/**
 * Plugin Name: Bijak
 * Plugin URI: https://github.com/mostafaamiri/bijak_wordpress_plugin
 * Description: Smart freight shipping for WooCommerce via Bijak. Adds prepay/postpay shipping, live price estimates, and order submission to Bijak.
 * Version: 1.0.1
 * Requires Plugins: woocommerce
 * Author: بیجک
 * Author URI: https://bijak.ir
 * Text Domain: bijak
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.5
 * WC tested up to: 10.7
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

define('BIJAK_WOO_VER', '1.0.1');
define('BIJAK_WOO_PATH', plugin_dir_path(__FILE__));
define('BIJAK_WOO_URL',  plugin_dir_url(__FILE__));

add_action('before_woocommerce_init', function () {
	if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
	}
});

register_activation_hook(__FILE__, function () {
	add_option('bijak_woo_do_activation_redirect', true);
});

add_action('admin_init', function () {
	if (get_option('bijak_woo_do_activation_redirect', false)) {
		delete_option('bijak_woo_do_activation_redirect');

		$is_multi = (bool) filter_input(INPUT_GET, 'activate-multi', FILTER_VALIDATE_BOOLEAN);
		if ( ! $is_multi ) {
			wp_safe_redirect(admin_url('admin.php?page=bijak-woo'));
			exit;
		}
	}
});

require_once BIJAK_WOO_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', function () {
	if ( ! class_exists('WooCommerce') ) {
		return;
	}
	BIJAK\BijakWoo\Plugin::instance()->boot();
});
