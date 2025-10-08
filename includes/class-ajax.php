<?php

namespace BIJAK\BijakWoo;

use WP_Error;

if (! defined('ABSPATH')) {
	exit;
}

class Ajax
{
	public function __construct(private Api $api) {}

	public function register(): void
	{
		add_action('wp_ajax_bijak_get_profile', [$this, 'get_profile']);
		add_action('wp_ajax_nopriv_bijak_get_profile', [$this, 'get_profile']);

		add_action('wp_ajax_bijak_get_destinations', [$this, 'get_destinations']);
		add_action('wp_ajax_nopriv_bijak_get_destinations', [$this, 'get_destinations']);

		add_action('wp_ajax_bijak_price_estimate', [$this, 'price_estimate']);
		add_action('wp_ajax_nopriv_bijak_price_estimate', [$this, 'price_estimate']);
	}

	public function get_profile(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');
		$r = $this->api->request('/application/profile');
		is_wp_error($r)
			? wp_send_json_error(['message' => $r->get_error_message()])
			: wp_send_json_success($r);
	}

	public function get_destinations(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');

		$origin_city_id = isset($_POST['origin_city_id']) ? intval($_POST['origin_city_id']) : 0;
		if (! $origin_city_id) {
			wp_send_json_error(['message' => __('origin_city_id is required.', 'bijak')]);
		}

		$r = $this->api->request('/application/terminals/?type=destination&city_id=' . $origin_city_id);

		is_wp_error($r)
			? wp_send_json_error(['message' => $r->get_error_message()])
			: wp_send_json_success($r);
	}

	public function price_estimate(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');

		$dest_city_id = isset($_POST['dest_city_id']) ? intval($_POST['dest_city_id']) : 0;
		$is_door      = ! empty($_POST['is_door_delivery']);

		if (function_exists('WC') && WC()->session) {
			WC()->session->set('bijak_dest_city_id', $dest_city_id);
			WC()->session->set('bijak_is_door_delivery', $is_door ? '1' : '0');
		}

		$set_session_cost = function (float $toman) {
			$store_cost = Helpers::toman_to_store_currency($toman);
			if (WC()->session) {
				WC()->session->set('bijak_estimate_cost', $store_cost);
			}
		};

		$origin_city_id = intval(Plugin::opt('origin_city_id', 0));
		if (! $origin_city_id) {
			wp_send_json_error(['message' => __('Origin city is not configured.', 'bijak')]);
		}

		$freights = $this->api->request(
			"/application/freights/?origin_city_id={$origin_city_id}&destination_city_id={$dest_city_id}"
		);

		if (is_wp_error($freights) || empty($freights['data'][0]['line_id'])) {
			wp_send_json_error(['message' => __('Shipping route not found.', 'bijak')]);
		}
		$line_id = intval($freights['data'][0]['line_id']);

		$goods_details = [];
		if (WC()->cart && ! WC()->cart->is_empty()) {
			foreach (WC()->cart->get_cart() as $item) {
				$p = $item['data'];
				if (! $p) {
					wp_send_json_error(['message' => __('Product details not available in cart.', 'bijak')]);
				}
				if (! $p->get_name()) {
					wp_send_json_error(['message' => __('Product name is missing.', 'bijak')]);
				}
				if (! $p->get_length()) {
					wp_send_json_error(['message' => __('Product length is missing.', 'bijak')]);
				}
				if (! $p->get_width()) {
					wp_send_json_error(['message' => __('Product width is missing.', 'bijak')]);
				}
				if (! $p->get_height()) {
					wp_send_json_error(['message' => __('Product height is missing.', 'bijak')]);
				}
				if (! $p->get_weight()) {
					wp_send_json_error(['message' => __('Product weight is missing.', 'bijak')]);
				}

				$goods_details[] = [
					'title'                   => $p->get_name(),
					'length'                  => (float) $p->get_length(),
					'width'                   => (float) $p->get_width(),
					'height'                  => (float) $p->get_height(),
					'weight'                  => (float) $p->get_weight(),
					'goods_count'             => (int) $item['quantity'],
					'needs_packaging'         => false,
					'goods_packaging_type_id' => 1,
					'file_paths'              => [],
				];
			}
		}

		if (empty($goods_details)) {
			wp_send_json_error(['message' => __('Cart is empty or product dimensions are zero.', 'bijak')]);
		}

		$goods_value = 0;
		if (WC()->cart) {
			$totals = WC()->cart->get_totals();
			$goods_value = isset($totals['total']) ? (int) round($totals['total']) : 0;
		}

		$self_delivery = Plugin::opt('self_delivery', 'yes') === 'yes';
		$self_delivery_src = null;

		if (! $self_delivery) {
			[$lat, $lon] = Helpers::parse_coords(Plugin::opt('origin_coords', ''));
			$self_delivery_src = [
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
					'src'                  => $self_delivery_src,
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

		$res = $this->api->request('/application/price-estimate', 'POST', $body);

		if (is_wp_error($res)) {
			if (WC()->session) {
				WC()->session->__unset('bijak_estimate_cost');
			}
			wp_send_json_error(['message' => __('Price estimate failed: ', 'bijak') . $res->get_error_message()]);
		}

		if (! empty($res['data']['sum'])) {
			$set_session_cost((float) $res['data']['sum']);
		}

		wp_send_json_success($res);
	}
}
