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
		$this->method_title       = 'ارسال کالا با بیجک';
		$this->method_description = 'امکان انتخاب پیش‌کرایه یا پس‌کرایه و (در حالت پیش‌کرایه) تعریف آستانهٔ ارسال رایگان.';
		$this->supports           = ['shipping-zones', 'instance-settings'];

		$this->init();
	}

	private function init()
	{
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option('title', 'ارسال کالا با بیجک');
		$this->enabled = $this->get_option('enabled', 'yes');

		add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
	}

	public function init_form_fields()
	{
		$this->instance_form_fields = [
			'enabled' => [
				'title'       => 'فعال/غیرفعال',
				'type'        => 'checkbox',
				'label'       => 'فعال باشد',
				'default'     => 'yes',
			],
			'title' => [
				'title'       => 'عنوان نمایشی روش حمل',
				'type'        => 'text',
				'default'     => 'ارسال کالا با بیجک',
				'desc_tip'    => true,
				'description' => 'این عنوان به مشتری نمایش داده می‌شود.',
			],
			'mode' => [
				'title'       => 'نوع پرداخت کرایه',
				'type'        => 'select',
				'default'     => 'postpay',
				'options'     => [
					'postpay' => 'پس‌کرایه (پرداخت توسط گیرنده در زمان تحویل)',
					'prepay'  => 'پیش‌کرایه',
				],
				'description' => 'در حالت پیش‌کرایه، هزینهٔ حمل روی فاکتور فروشگاه می‌آید و در صورت عبور از آستانه، برای کاربر رایگان می‌شود ولی از کیف‌پول بیجک شما کسر خواهد شد.',
			],
			'free_threshold' => [
				'title'       => 'آستانهٔ ارسال رایگان (فقط در حالت پیش‌کرایه)',
				'type'        => 'price',
				'default'     => '',
				'desc_tip'    => true,
				'description' => 'اگر جمع سبد (بدون هزینهٔ حمل) به این مقدار برسد، کرایه برای کاربر رایگان می‌شود. واحد بر اساس ارز فروشگاه است.',
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
		$mode           = $this->get_option('mode', 'postpay'); // postpay | prepay
		$free_threshold = (float) $this->get_option('free_threshold', 0.0);

		$label = $this->title . (($mode === 'prepay') ? ' - پیش‌کرایه' : ' - پس‌کرایه');

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
		$is_door        = (string) Helpers::checkout_field('bijak_is_door_delivery', '1') === '1';

		if (! $origin_city_id || ! $dest_city_id) {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label . ' (لطفاً شهر مقصد را انتخاب کنید)',
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'prepay',
				],
			]);
			return;
		}

		$api = new Api();
		$line_id = Helpers::resolve_line_id($api, $origin_city_id, $dest_city_id);
		if (! $line_id) {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label . ' (مسیر حمل یافت نشد)',
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'prepay',
					'bijak_note' => 'خط حمل یافت نشد',
				],
			]);
			return;
		}

		$goods_details = Helpers::build_goods_details_from_cart();
		if (empty($goods_details)) {
			$this->add_rate([
				'id'        => $this->get_rate_id(),
				'label'     => $label . ' (ابعاد/اقلام نامعتبر)',
				'cost'      => 0,
				'taxes'     => false,
				'meta_data' => [
					'bijak_mode' => 'prepay',
					'bijak_note' => 'هیچ آیتمی با ابعاد معتبر در سبد نیست',
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

		$api  = new Api();
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
					// فعلا تا بعدا داخل خود بیجک عوض شود
					'src' => [
						'location_longitude' => 0,
						'location_latitude'  => 0,
					],
				],
				'line_id' => $line_id,
			],
		];

		$estimate = $api->request('/application/price-estimate', 'POST', $body);
		$sum_toman = 0.0;

		if (! is_wp_error($estimate) && ! empty($estimate['data'])) {
			$sum_toman = (float) ($estimate['data']['sum'] ?? 0);
		}

		$shipping_cost_raw = Helpers::toman_to_store_currency($sum_toman);
		$shipping_cost     = self::maybe_session_cost($shipping_cost_raw);

		$cart_subtotal = (function_exists('WC') && WC()->cart) ? (float) WC()->cart->get_subtotal() : 0.0;
		$is_free = ($free_threshold > 0 && $cart_subtotal >= $free_threshold);

		$this->add_rate([
			'id'        => $this->get_rate_id(),
			'label'     => $label . ($is_free ? ' (ارسال رایگان)' : ''),
			'cost'      => $is_free ? 0 : max(0, $shipping_cost),
			'taxes'     => false,
			'meta_data' => [
				'bijak_mode'         => 'prepay',
				'bijak_prepaid_free' => $is_free ? '1' : '0',
				'bijak_estimate_sum' => $sum_toman,
			],
		]);
	}
}
