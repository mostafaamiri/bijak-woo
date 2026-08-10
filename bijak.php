<?php
/**
 * Plugin Name: Bijak
 * Plugin URI: https://wordpress.org/plugins/bijak/
 * Description: Smart freight shipping for WooCommerce via Bijak. Adds prepay/postpay shipping, live price estimates, and order submission to Bijak.
 * Version: 1.3.23
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

define('BIJAK_WOO_VER', '1.3.23');
define('BIJAK_WOO_PATH', plugin_dir_path(__FILE__));
define('BIJAK_WOO_URL',  plugin_dir_url(__FILE__));

/**
 * Prefer WordPress language packs and use the bundled catalogue only when the
 * installed pack is missing strings from the current plugin release.
 */
function bijak_translation_file($path, $domain)
{
	if ('bijak' !== $domain || ! is_string($path) || ! defined('WP_LANG_DIR')) {
		return $path;
	}

	$global_dir = trailingslashit(wp_normalize_path(WP_LANG_DIR . '/plugins'));
	$normalized = wp_normalize_path($path);
	if (0 !== strpos($normalized, $global_dir) || '.mo' !== substr($normalized, -3)) {
		return $path;
	}

	$filename = basename($normalized);
	$bundled_mo = BIJAK_WOO_PATH . 'languages/' . $filename;
	if (! is_readable($bundled_mo)) {
		return $path;
	}

	$global_php = substr($path, 0, -3) . '.l10n.php';
	$global_file = is_readable($global_php) ? $global_php : $path;
	$bundled_php = substr($bundled_mo, 0, -3) . '.l10n.php';
	$bundled_file = is_readable($bundled_php) ? $bundled_php : $bundled_mo;
	$bundled_entries = bijak_translation_entries($bundled_file);
	$global_entries = bijak_translation_entries($global_file);

	if (empty($bundled_entries) || ! array_diff_key($bundled_entries, $global_entries)) {
		return $path;
	}

	return $bundled_mo;
}

/**
 * Read translation keys across WordPress 5.8+ and the newer PHP catalogue format.
 */
function bijak_translation_entries($path)
{
	if (! is_readable($path)) {
		return [];
	}

	if (class_exists('WP_Translation_File')) {
		$file = WP_Translation_File::create($path);
		return $file ? $file->entries() : [];
	}

	if (! class_exists('MO')) {
		require_once ABSPATH . WPINC . '/pomo/mo.php';
	}
	$translations = new MO();
	return $translations->import_from_file($path) ? $translations->entries : [];
}

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
	load_plugin_textdomain('bijak', false, dirname(plugin_basename(__FILE__)) . '/languages');
	add_filter('load_textdomain_mofile', 'bijak_translation_file', 10, 2);

	if ( ! class_exists('WooCommerce') ) {
		return;
	}
	BIJAK\BijakWoo\Plugin::instance()->boot();
});
