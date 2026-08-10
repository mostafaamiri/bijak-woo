<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Assets
{
	public function register(): void
	{
		add_action('wp_enqueue_scripts',    [$this, 'enqueue_front']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
	}

	/* ---------- Frontend (Checkout and order details) ---------- */

	public function enqueue_front(): void
	{
		$is_checkout = function_exists('is_checkout') && is_checkout();
		$is_view_order = function_exists('is_view_order_page') && is_view_order_page();
		if (! $is_checkout && ! $is_view_order) {
			return;
		}

		wp_enqueue_style(
			'bijak-woo',
			BIJAK_WOO_URL . 'assets/css/checkout.css',
			[],
			BIJAK_WOO_VER
		);

		if (! $is_checkout) {
			return;
		}

		$deps = ['jquery', 'wp-i18n'];
		if (wp_script_is('wc-checkout', 'registered')) {
			$deps[] = 'wc-checkout';
		}
		if (wp_script_is('selectWoo', 'registered')) {
			$deps[] = 'selectWoo';
		}

		wp_register_script(
			'bijak-woo',
			BIJAK_WOO_URL . 'assets/js/checkout.js',
			$deps,
			BIJAK_WOO_VER,
			true
		);

		// Enable JS translation
		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('bijak-woo', 'bijak', BIJAK_WOO_PATH . 'languages');
		}

		wp_localize_script(
			'bijak-woo',
			'BIJAK',
				[
					'ajax_url'       => admin_url('admin-ajax.php'),
					'nonce'          => wp_create_nonce('bijak_nonce'),
					'origin_city_id' => (int) Plugin::opt('origin_city_id', 0),
					'saved_city_id' => (function_exists('WC') && WC() && WC()->session) ? (int) WC()->session->get('bijak_dest_city_id', 0) : 0,
					'map_picker_url' => esc_url(Config::MAP_PICKER_URL),
					'map_picker_origin' => Config::map_picker_origin(),
				]
		);

		wp_enqueue_script('bijak-woo');
	}

	/* ---------- Admin (Dashboard & Settings) ---------- */

	public function enqueue_admin(string $hook): void
	{
		$is_dashboard = $hook === 'index.php';
		$page = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		$is_bijak_page = in_array($page, ['bijak-woo', 'bijak-woo-settings'], true);
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		$is_order_screen = $screen && in_array($screen->id, ['edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders'], true);

		if (! $is_dashboard && ! $is_bijak_page && ! $is_order_screen) {
			return;
		}

		if ($is_dashboard || $is_bijak_page) {
			wp_enqueue_style(
				'bijak-jalali-datepicker',
				BIJAK_WOO_URL . 'assets/css/jalalidatepicker.min.css',
				[],
				BIJAK_WOO_VER
			);
			wp_enqueue_script(
				'bijak-jalali-datepicker',
				BIJAK_WOO_URL . 'assets/js/jalalidatepicker.min.js',
				[],
				BIJAK_WOO_VER,
				true
			);

			wp_enqueue_script(
				'bijak-admin',
				BIJAK_WOO_URL . 'assets/js/admin.js',
				['jquery', 'wp-i18n', 'bijak-jalali-datepicker'],
				BIJAK_WOO_VER,
				true
			);
			wp_localize_script('bijak-admin', 'BIJAK_ADMIN', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('bijak_admin_nonce'),
				'picker_origin' => Config::map_picker_origin(),
			]);
			if (function_exists('wp_set_script_translations')) {
				wp_set_script_translations('bijak-admin', 'bijak', BIJAK_WOO_PATH . 'languages');
			}
		}

		wp_register_style(
			'bijak-woo-admin',
			BIJAK_WOO_URL . 'assets/css/admin.css',
			[],
			BIJAK_WOO_VER
		);

		wp_enqueue_style('bijak-woo-admin');
	}
}
