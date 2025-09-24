<?php

/**
 * Plugin Name: بیجک (ترابری هوشمند)
 * Plugin URI: https://github.com/mostafaamiri/bijak_wordpress_plugin
 * Description: ارسال کالا به صورت هوشمند به سرتاسر ایران به کمک باربری ها
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: بیجک
 * Author URI: https://bijak.ir
 * Text Domain: bijak-woo
 *
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.5
 * WC tested up to: 8.9
 */


if (! defined('ABSPATH')) exit;

define('BIJAK_WOO_VER', '1.0.0');
define('BIJAK_WOO_PATH', plugin_dir_path(__FILE__));
define('BIJAK_WOO_URL',  plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function () {
	add_option('bijak_woo_do_activation_redirect', true);
});

add_action('admin_init', function () {
	if (get_option('bijak_woo_do_activation_redirect', false)) {
		delete_option('bijak_woo_do_activation_redirect');

		if (! isset($_GET['activate-multi'])) {
			wp_safe_redirect(admin_url('admin.php?page=bijak-woo'));
			exit;
		}
	}
});

require_once BIJAK_WOO_PATH . 'includes/class-plugin.php';

add_action('plugins_loaded', function () {
	if (! class_exists('WooCommerce')) return;
	BIJAK\BijakWoo\Plugin::instance()->boot();
});
