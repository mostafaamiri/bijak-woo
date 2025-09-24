<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;

class Thankyou
{
	public function register()
	{
		add_action('woocommerce_thankyou', [$this, 'render'], 20);
	}

	public function render($order_id)
	{
		if (! $order_id) return;
		$order = wc_get_order($order_id);
		if (! $order) return;

		$status = get_post_meta($order_id, '_bijak_status', true);
		$error  = get_post_meta($order_id, '_bijak_error_message', true);

		if ($status === '') return;

		echo '<div class="woocommerce-message" style="margin-top:16px;">';
		if ($status === 'success') {
			echo '<strong>ثبت سفارش در بیجک موفق بود. | بیجک ترابری هوشمند</strong>';
		} else {
			echo '<strong>ثبت سفارش در بیجک ناموفق بود.</strong><br/>';
			if ($error) {
				echo 'پیام: ' . esc_html($error);
			} else {
				echo 'لطفاً با پشتیبانی تماس بگیرید.';
			}
		}
		echo '</div>';
	}
}
