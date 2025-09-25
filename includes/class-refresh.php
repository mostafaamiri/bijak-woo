<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;


class Refresh_Shipping
{

	public static function init()
	{
		add_action('woocommerce_checkout_update_order_review', [__CLASS__, 'refresh_rates'], 9);
		add_action('woocommerce_thankyou',                     [__CLASS__, 'clear_session']);
		add_action('woocommerce_cart_emptied',                 [__CLASS__, 'clear_session']);
	}

	public static function refresh_rates($post_data)
	{
		if (isset(WC()->session) && WC()->session->get('bijak_estimate_cost') > 0) {
			foreach (WC()->cart->get_shipping_packages() as $key => $pkg) {
				WC()->session->set('shipping_for_package_' . $key, false);
			}
			WC()->cart->calculate_shipping();
		}
	}

	public static function clear_session()
	{
		if (isset(WC()->session)) {
			WC()->session->__unset('bijak_estimate_cost');
		}
	}
}
