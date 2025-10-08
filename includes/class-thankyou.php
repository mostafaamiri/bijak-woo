<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;

class Thankyou
{
	public function register(): void
	{
		add_action('woocommerce_thankyou', [$this, 'render'], 20);
	}

	public function render($order_id): void
	{
		if (! $order_id) return;

		$order = wc_get_order($order_id);
		if (! $order) return;

		$status = get_post_meta($order_id, '_bijak_status', true);
		$error  = get_post_meta($order_id, '_bijak_error_message', true);

		if ($status === '') return;

		echo '<div class="woocommerce-message" style="margin-top:16px;">';

		if ($status === 'success') {
			echo '<strong>' . esc_html__('Your order was successfully registered in Bijak.', 'bijak') . '</strong>';
		} else {
			echo '<strong>' . esc_html__('Failed to register order in Bijak.', 'bijak') . '</strong><br/>';

			if ($error) {
				echo esc_html__('Error:', 'bijak') . ' ' . esc_html($error);
			} else {
				echo esc_html__('Please contact support.', 'bijak');
			}
		}

		echo '</div>';
	}
}
