<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;

class Shipping_Method extends \WC_Shipping_Method
{
	public static function register() {}

	public function __construct($instance_id = 0)
	{
		$this->id                 = 'bijak_pay_at_dest';
		$this->instance_id        = absint($instance_id);
		$this->method_title       = __('Bijak Shipping', 'bijak');
		$this->method_description = __('Enable prepay/postpay shipping via Bijak with optional free shipping threshold.', 'bijak');
		$this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal'];

		$this->init();
	}

	private function init()
	{
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option('title', __('Bijak Shipping', 'bijak'));
		$this->enabled = $this->get_option('enabled', 'yes');

		add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
	}

	public function init_form_fields()
	{
		$this->instance_form_fields = [
			'enabled' => [
				'title'       => __('Enable/Disable', 'bijak'),
				'type'        => 'checkbox',
				'label'       => __('Enable this shipping method', 'bijak'),
				'default'     => 'yes',
			],
			'title' => [
				'title'       => __('Method Title', 'bijak'),
				'type'        => 'text',
				'default'     => __('Bijak Shipping', 'bijak'),
				'desc_tip'    => true,
				'description' => __('This controls the title shown to the customer during checkout.', 'bijak'),
			],
			'mode' => [
				'title'       => __('Shipping Payment Mode', 'bijak'),
				'type'        => 'select',
				'default'     => 'postpay',
				'options'     => [
					'postpay' => __('Postpay (Customer pays at delivery)', 'bijak'),
					'prepay'  => __('Prepay (Pay in checkout)', 'bijak'),
				],
				'description' => __('In prepay mode, shipping cost is added to cart. If threshold is reached, it becomes free. Paid from your Bijak wallet.', 'bijak'),
			],
			'free_threshold' => [
				'title'       => __('Free Shipping Threshold (Prepay Only)', 'bijak'),
				'type'        => 'price',
				'default'     => '',
				'desc_tip'    => true,
				'description' => __('Cart subtotal (excluding shipping) above this amount makes shipping free. Currency is store currency.', 'bijak'),
			],
		];
	}

	public function is_available($package)
	{
		return ($this->enabled === 'yes') ? parent::is_available($package) : false;
	}

	public static function maybe_session_cost(float $orig_cost): float
	{
		$stored = (function_exists('WC') && WC()->session) ? (float) WC()->session->get('bijak_estimate_cost', 0) : 0;
		return $stored > 0 ? $stored : $orig_cost;
	}

	public function calculate_shipping($package = [])
	{
		$mode           = $this->get_option('mode', 'postpay');
		$free_threshold = (float) $this->get_option('free_threshold', 0.0);

		$label = $this->title . (($mode === 'prepay') ? ' - ' . __('Prepay', 'bijak') : ' - ' . __('Postpay', 'bijak'));

		if ($mode !== 'prepay') {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label,
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'postpay',
				],
			]);
			return;
		}

		$origin_city_id = (int) Plugin::opt('origin_city_id', 0);
		$dest_city_id   = (int) Helpers::checkout_field('bijak_dest_city', 0);

		$door_raw = Helpers::checkout_field('bijak_is_door_delivery', '__missing__');
		if ($door_raw === '__missing__' && function_exists('WC') && WC()->session) {
			$is_door = ((string) WC()->session->get('bijak_is_door_delivery', '1')) === '1';
		} else {
			$is_door = ((string) $door_raw) === '1';
		}

		if (! $dest_city_id && function_exists('WC') && WC()->session) {
			$dest_city_id = (int) WC()->session->get('bijak_dest_city_id', 0);
		}

		if (! $origin_city_id || ! $dest_city_id) {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label . ' (' . __('Please select destination city', 'bijak') . ')',
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'prepay',
				],
			]);
			return;
		}

		$api     = new Api();
		$line_id = Helpers::resolve_line_id($api, $origin_city_id, $dest_city_id);
		if (! $line_id) {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label . ' (' . __('No shipping route found', 'bijak') . ')',
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'prepay',
					'bijak_note' => __('No freight line found', 'bijak'),
				],
			]);
			return;
		}

		$goods_details = Helpers::build_goods_details_from_cart();
		if (empty($goods_details)) {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label . ' (' . __('Invalid dimensions/items', 'bijak') . ')',
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'prepay',
					'bijak_note' => __('No valid items in cart', 'bijak'),
				],
			]);
			return;
		}

		$goods_value = 0;
		if (function_exists('WC') && WC()->cart) {
			$totals      = WC()->cart->get_totals();
			$goods_value = isset($totals['total']) ? (int) round($totals['total']) : 0;
		}

		$self_delivery = Plugin::opt('self_delivery', 'yes') === 'yes';
		$origin_src    = null;
		if (! $self_delivery) {
			[$lat, $lon] = Helpers::parse_coords(Plugin::opt('origin_coords', ''));
			$origin_src = [
				'location_longitude' => is_null($lon) ? 0 : $lon,
				'location_latitude'  => is_null($lat) ? 0 : $lat,
			];
		}

		$body = [
			'goods_info' => [
				'goods_value'   => $goods_value,
				'goods_details' => $goods_details,
			],
			'shipment_info' => [
				'origin_info' => [
					'self_delivery'        => $self_delivery,
					'src'                  => $origin_src,
					'local_transport_cost' => 0,
				],
				'destination_info' => [
					'is_door_delivery' => $is_door,
					'src' => [
						'location_longitude' => 0,
						'location_latitude'  => 0,
					],
				],
				'line_id' => $line_id,
			],
		];

		$estimate    = $api->request('/application/price-estimate', 'POST', $body);
		$sum_toman   = (! is_wp_error($estimate) && ! empty($estimate['data'])) ? (float) ($estimate['data']['sum'] ?? 0) : 0;
		$cost_raw    = Helpers::toman_to_store_currency($sum_toman);
		$final_cost  = self::maybe_session_cost($cost_raw);
		$cart_total  = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->get_subtotal() : 0.0;
		$is_free     = ($free_threshold > 0 && $cart_total >= $free_threshold);

		$this->add_rate([
			'id'        => $this->get_rate_id(),
			'label'     => $label . ($is_free ? ' (' . __('Free Shipping', 'bijak') . ')' : ''),
			'cost'      => $is_free ? 0 : max(0, $final_cost),
			'taxes'     => false,
			'meta_data' => [
				'bijak_mode'         => 'prepay',
				'bijak_prepaid_free' => $is_free ? '1' : '0',
				'bijak_estimate_sum' => $sum_toman,
			],
		]);
	}
}
