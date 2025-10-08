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

	/* ---------- Frontend (Checkout) ---------- */

	public function enqueue_front(): void
	{
		if (! function_exists('is_checkout') || ! is_checkout()) {
			return;
		}

		wp_enqueue_style(
			'bijak-woo',
			BIJAK_WOO_URL . 'assets/css/checkout.css',
			[],
			BIJAK_WOO_VER
		);

		$deps = ['jquery'];
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
			]
		);

		wp_enqueue_script('bijak-woo');
	}

	/* ---------- Admin (Dashboard & Settings) ---------- */

	public function enqueue_admin(string $hook): void
	{
		// Load our admin styles on Dashboard and on our settings page.
		$should_enqueue = false;

		// WP Dashboard
		if ($hook === 'index.php') {
			$should_enqueue = true;
		}

		// Our settings page
		if ($hook === 'toplevel_page_bijak-woo' || $hook === 'bijak-woo_page_bijak-woo') {
			$should_enqueue = true;
		}

		if (! $should_enqueue) {
			return;
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
