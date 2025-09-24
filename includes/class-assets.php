<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Assets
{

	public function register()
	{
		add_action('wp_enqueue_scripts', [$this, 'enqueue']);
	}

	public function enqueue()
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
		if (wp_script_is('selectWoo',   'registered')) {
			$deps[] = 'selectWoo';
		}

		wp_register_script(
			'bijak-woo',
			BIJAK_WOO_URL . 'assets/js/checkout.js',
			$deps,
			BIJAK_WOO_VER,
			true
		);

		wp_localize_script(
			'bijak-woo',
			'BIJAK',
			[
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('bijak_nonce'),
				'origin_city_id' => (int) Plugin::opt('origin_city_id', 0),
			]
		);

		wp_enqueue_script('bijak-woo');
	}
}
