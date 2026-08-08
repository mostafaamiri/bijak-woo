<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Ajax
{
	/** @var Api */
	private $api;

	public function __construct(Api $api)
	{
		$this->api = $api;
	}

	public function register(): void
	{
		add_action('wp_ajax_bijak_get_profile', [$this, 'get_profile']);
		add_action('wp_ajax_nopriv_bijak_get_profile', [$this, 'get_profile']);

		add_action('wp_ajax_bijak_get_destinations', [$this, 'get_destinations']);
		add_action('wp_ajax_nopriv_bijak_get_destinations', [$this, 'get_destinations']);

		add_action('wp_ajax_bijak_price_estimate', [$this, 'price_estimate']);
		add_action('wp_ajax_nopriv_bijak_price_estimate', [$this, 'price_estimate']);
		add_action('wp_ajax_bijak_create_location_picker_session', [$this, 'create_location_picker_session']);
		add_action('wp_ajax_nopriv_bijak_create_location_picker_session', [$this, 'create_location_picker_session']);
		add_action('wp_ajax_bijak_save_destination_location', [$this, 'save_destination_location']);
		add_action('wp_ajax_nopriv_bijak_save_destination_location', [$this, 'save_destination_location']);
		add_action('wp_ajax_bijak_clear_destination_location', [$this, 'clear_destination_location']);
		add_action('wp_ajax_nopriv_bijak_clear_destination_location', [$this, 'clear_destination_location']);
	}

	private function session(): ?object
	{
		return function_exists('WC') && WC() && WC()->session ? WC()->session : null;
	}

	private function clear_location(): void
	{
		$session = $this->session();
		if (!$session) {
			return;
		}
		foreach (['bijak_destination_lat', 'bijak_destination_lng', 'bijak_destination_address', 'bijak_destination_city_id', 'bijak_location_picker_state'] as $key) {
			$session->__unset($key);
		}
	}

	private function valid_coordinate($value, float $min, float $max): ?float
	{
		if (is_array($value) || is_object($value) || $value === '' || !is_scalar($value) || !is_numeric($value)) {
			return null;
		}
		$number = (float) $value;
		if (!is_finite($number) || $number < $min || $number > $max) {
			return null;
		}
		return $number;
	}

	private function picker_origin(string $url): string
	{
		$parts = wp_parse_url($url);
		if (empty($parts['scheme']) || empty($parts['host'])) {
			return '';
		}
		$scheme = strtolower($parts['scheme']);
		if (!in_array($scheme, ['http', 'https'], true)) {
			return '';
		}
		$origin = $scheme . '://' . strtolower($parts['host']);
		if (!empty($parts['port']) && !(($scheme === 'https' && (int) $parts['port'] === 443) || ($scheme === 'http' && (int) $parts['port'] === 80))) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	public function create_location_picker_session(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');
		$session = $this->session();
		if (!$session) {
			wp_send_json_error(['message' => __('WooCommerce session is not available.', 'bijak')], 400);
		}

		$city_raw = isset($_POST['destination_city_id']) ? wp_unslash($_POST['destination_city_id']) : '';
		$city_id = is_scalar($city_raw) && $city_raw !== '' ? absint($city_raw) : (int) $session->get('bijak_dest_city_id', 0);
		$picker_value = Plugin::opt('map_picker_url', '');
		$picker_url = is_scalar($picker_value) ? esc_url_raw((string) $picker_value) : '';
		$origin = $this->picker_origin($picker_url);
		if (!$city_id || !$picker_url || !$origin) {
			wp_send_json_error(['message' => __('Select a destination city and configure a valid location picker URL.', 'bijak')], 400);
		}
		$previous_city = (int) $session->get('bijak_dest_city_id', 0);
		if ($previous_city && $previous_city !== $city_id) {
			$this->clear_location();
		}
		$session->set('bijak_dest_city_id', $city_id);

		try {
			$state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		} catch (\Throwable $e) {
			wp_send_json_error(['message' => __('Unable to create a secure picker session.', 'bijak')], 500);
		}

		$session->set('bijak_location_picker_state', [
			'state' => $state,
			'created_at' => time(),
			'destination_city_id' => $city_id,
			'consumed' => false,
		]);

		$parent_origin = $this->picker_origin(home_url('/'));
		$url = add_query_arg(['state' => $state, 'parent_origin' => $parent_origin, 'destination_city_id' => $city_id], $picker_url);
		wp_send_json_success(['url' => esc_url_raw($url), 'state' => $state, 'origin' => $origin]);
	}

	public function save_destination_location(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');
		$session = $this->session();
		if (!$session) {
			wp_send_json_error(['message' => __('WooCommerce session is not available.', 'bijak')], 400);
		}

		$pending = $session->get('bijak_location_picker_state', []);
		$state_raw = isset($_POST['state']) ? wp_unslash($_POST['state']) : '';
		$state = is_scalar($state_raw) ? sanitize_text_field($state_raw) : '';
		if (!is_array($pending) || empty($pending['state']) || empty($state) || !hash_equals((string) $pending['state'], $state)) {
			wp_send_json_error(['message' => __('This location picker session is invalid.', 'bijak')], 400);
		}
		if (!empty($pending['consumed']) || empty($pending['created_at']) || (time() - (int) $pending['created_at']) > 600) {
			$session->__unset('bijak_location_picker_state');
			wp_send_json_error(['message' => __('This location picker session has expired.', 'bijak')], 400);
		}

		$current_city = (int) $session->get('bijak_dest_city_id', 0);
		if (!$current_city || $current_city !== (int) ($pending['destination_city_id'] ?? 0)) {
			$this->clear_location();
			wp_send_json_error(['message' => __('The destination city changed. Please select the location again.', 'bijak')], 400);
		}

		$lat = $this->valid_coordinate(isset($_POST['lat']) ? wp_unslash($_POST['lat']) : '', -90.0, 90.0);
		$lng = $this->valid_coordinate(isset($_POST['lng']) ? wp_unslash($_POST['lng']) : '', -180.0, 180.0);
		if (is_null($lat) || is_null($lng)) {
			wp_send_json_error(['message' => __('The selected coordinates are invalid.', 'bijak')], 400);
		}

		$address = isset($_POST['address']) && is_scalar($_POST['address']) ? sanitize_textarea_field(wp_unslash($_POST['address'])) : '';
		$address = function_exists('mb_substr') ? mb_substr($address, 0, 1000, 'UTF-8') : substr($address, 0, 1000);
		$session->set('bijak_destination_lat', $lat);
		$session->set('bijak_destination_lng', $lng);
		$session->set('bijak_destination_address', $address);
		$session->set('bijak_destination_city_id', $current_city);
		$session->__unset('bijak_location_picker_state');

		wp_send_json_success(['lat' => $lat, 'lng' => $lng, 'address' => $address, 'city_id' => $current_city]);
	}

	public function clear_destination_location(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');
		if (!$this->session()) {
			wp_send_json_error(['message' => __('WooCommerce session is not available.', 'bijak')], 400);
		}
		$this->clear_location();
		wp_send_json_success();
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

		$r = $this->api->request('/application/terminals?type=destination&city_id=' . $origin_city_id);

		is_wp_error($r)
			? wp_send_json_error(['message' => $r->get_error_message()])
			: wp_send_json_success($r);
	}

	/**
	 * Extract route line IDs from /application/routes response.
	 *
	 * Each top-level item in `routes` is one route option.
	 * For each segment inside that route, we take the first line ID.
	 *
	 * Output example:
	 * [
	 *   [110],
	 *   [113, 320],
	 * ]
	 */
	private function extract_route_line_id_groups(array $routes_response): array
	{
		$routes = [];

		if (! empty($routes_response['routes']) && is_array($routes_response['routes'])) {
			$routes = $routes_response['routes'];
		} elseif (! empty($routes_response['data']['routes']) && is_array($routes_response['data']['routes'])) {
			$routes = $routes_response['data']['routes'];
		}

		$groups = [];
		foreach ($routes as $route) {
			if (! is_array($route) || empty($route)) {
				continue;
			}

			$segment_first_line_ids = [];
			foreach ($route as $segment) {
				if (! is_array($segment) || empty($segment['lines']) || ! is_array($segment['lines'])) {
					$segment_first_line_ids = [];
					break;
				}

				$first_line    = $segment['lines'][0] ?? null;
				$first_line_id = (is_array($first_line) && ! empty($first_line['id'])) ? intval($first_line['id']) : 0;

				if ($first_line_id <= 0) {
					$segment_first_line_ids = [];
					break;
				}

				$segment_first_line_ids[] = $first_line_id;
			}

			if (! empty($segment_first_line_ids)) {
				$groups[] = $segment_first_line_ids;
			}
		}

		return $groups;
	}

	public function price_estimate(): void
	{
		check_ajax_referer('bijak_nonce', 'nonce');

		$dest_raw = isset($_POST['dest_city_id']) ? wp_unslash($_POST['dest_city_id']) : '';
		$dest_city_id = is_scalar($dest_raw) && $dest_raw !== '' ? absint($dest_raw) : 0;
		$door_raw = isset($_POST['is_door_delivery']) ? wp_unslash($_POST['is_door_delivery']) : '';
		$is_door = is_scalar($door_raw) && (string) $door_raw === '1';

		$session = $this->session();
		if ($session) {
			$previous_city = (int) $session->get('bijak_dest_city_id', 0);
			if ($previous_city && $dest_city_id && $previous_city !== $dest_city_id) {
				$this->clear_location();
			}
			$session->set('bijak_dest_city_id', $dest_city_id);
			$session->set('bijak_is_door_delivery', $is_door ? '1' : '0');
		}

		if (!$dest_city_id) {
			wp_send_json_error(['message' => __('Please select a destination city.', 'bijak')], 400);
		}

		$destination_src = [
			'location_longitude' => 0,
			'location_latitude' => 0,
		];
		if ($is_door) {
			$lat = $session ? $this->valid_coordinate($session->get('bijak_destination_lat', ''), -90.0, 90.0) : null;
			$lng = $session ? $this->valid_coordinate($session->get('bijak_destination_lng', ''), -180.0, 180.0) : null;
			$location_city = $session ? (int) $session->get('bijak_destination_city_id', 0) : 0;
			if (is_null($lat) || is_null($lng) || $location_city !== $dest_city_id) {
				wp_send_json_error(['message' => __('Please select the delivery location on the map first.', 'bijak')], 400);
			}
			$destination_src = [
				'location_longitude' => $lng,
				'location_latitude' => $lat,
			];
		}

		$set_session_cost = function (float $toman) {
			$store_cost = Helpers::toman_to_store_currency($toman);
			if (function_exists('WC') && WC()->session) {
				WC()->session->set('bijak_estimate_cost', $store_cost);
			}
		};

		$origin_city_id = intval(Plugin::opt('origin_city_id', 0));
		if (! $origin_city_id) {
			wp_send_json_error(['message' => __('Origin city is not configured.', 'bijak')]);
		}

		$routes_res = $this->api->request(
			"/application/routes?origin_city_id={$origin_city_id}&destination_city_id={$dest_city_id}"
		);

		if (is_wp_error($routes_res)) {
			wp_send_json_error(['message' => __('Shipping route not found.', 'bijak')]);
		}

		$route_line_id_groups = $this->extract_route_line_id_groups($routes_res);
		if (empty($route_line_id_groups)) {
			wp_send_json_error(['message' => __('Shipping route not found.', 'bijak')]);
		}

		$selected_route_line_ids = array_values(
			array_filter(
				array_map('intval', $route_line_id_groups[0]),
				static function ($id) {
					return $id > 0;
				}
			)
		);

		if (empty($selected_route_line_ids)) {
			wp_send_json_error(['message' => __('Shipping route not found.', 'bijak')]);
		}

		if (function_exists('WC') && WC()->session) {
			WC()->session->set('bijak_route_line_ids', $selected_route_line_ids);
		}

		$goods_details = [];
		if (function_exists('WC') && WC()->cart && ! WC()->cart->is_empty()) {
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
		if (function_exists('WC') && WC()->cart) {
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
					'local_transport_cost' => max(0, (int) Plugin::opt('local_transport_cost', 0)),
				],
				'destination_info' => [
					'is_door_delivery' => $is_door,
					'src' => $destination_src,
				],
				'line_ids' => $selected_route_line_ids,
			],
		];

		$res = $this->api->request('/application/price-estimate', 'POST', $body);

		if (is_wp_error($res)) {
			if (function_exists('WC') && WC()->session) {
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
