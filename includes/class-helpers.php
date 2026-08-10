<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;

class Helpers
{
	public static function normalize_phone(string $s): string
	{
		$map = [
			'۰' => '0','۱' => '1','۲' => '2','۳' => '3','۴' => '4',
			'۵' => '5','۶' => '6','۷' => '7','۸' => '8','۹' => '9',
			'٠' => '0','١' => '1','٢' => '2','٣' => '3','٤' => '4',
			'٥' => '5','٦' => '6','٧' => '7','٨' => '8','٩' => '9'
		];
		$s = strtr($s, $map);
		$s = preg_replace('/\D+/', '', $s);
		if (strpos($s, '98') === 0) $s = '0' . substr($s, 2);
		return $s;
	}

	public static function parse_coords(?string $s): array
	{
		$s = trim((string) $s);
		if ($s === '') return [null, null];
		$parts = array_map('trim', explode(',', $s));
		if (count($parts) < 2) return [null, null];
		$lat = is_numeric($parts[0]) ? (float) $parts[0] : null;
		$lon = is_numeric($parts[1]) ? (float) $parts[1] : null;
		return [$lat, $lon];
	}

	public static function min0($v): float
	{
		$f = (float) $v;
		return $f > 0 ? $f : 0;
	}

	/**
	 * Convert a WooCommerce dimension value from the store unit to Bijak's cm.
	 */
	public static function dimension_to_bijak_cm($value): float
	{
		$value = self::min0($value);
		if ($value <= 0) {
			return 0;
		}

		$unit = strtolower((string) get_option('woocommerce_dimension_unit', 'cm'));
		if (function_exists('wc_get_dimension')) {
			return self::min0(wc_get_dimension($value, 'cm', $unit));
		}

		$multipliers = [
			'mm' => 0.1,
			'cm' => 1,
			'm'  => 100,
			'in' => 2.54,
			'yd' => 91.44,
		];
		return self::min0($value * ($multipliers[$unit] ?? 1));
	}

	/**
	 * Convert a WooCommerce weight value from the store unit to Bijak's kg.
	 */
	public static function weight_to_bijak_kg($value): float
	{
		$value = self::min0($value);
		if ($value <= 0) {
			return 0;
		}

		$unit = strtolower((string) get_option('woocommerce_weight_unit', 'kg'));
		if (function_exists('wc_get_weight')) {
			return self::min0(wc_get_weight($value, 'kg', $unit));
		}

		$multipliers = [
			'g'    => 0.001,
			'kg'   => 1,
			'lbs'  => 0.453592,
			'oz'   => 0.0283495,
		];
		return self::min0($value * ($multipliers[$unit] ?? 1));
	}

	/**
	 * Resolve the dimensions sent to Bijak. Product-specific values take
	 * precedence per field, with WooCommerce's standard values as fallback.
	 */
	public static function resolve_product_dimensions(\WC_Product $product): array
	{
		$fields = [
			'length' => [Config::BIJAK_LENGTH_META, 'get_length', __('Length', 'bijak')],
			'width'  => [Config::BIJAK_WIDTH_META, 'get_width', __('Width', 'bijak')],
			'height' => [Config::BIJAK_HEIGHT_META, 'get_height', __('Height', 'bijak')],
			'weight' => [Config::BIJAK_WEIGHT_META, 'get_weight', __('Weight', 'bijak')],
		];

		$dimensions = [];
		$missing = [];
		foreach ($fields as $key => [$meta_key, $getter, $label]) {
			$value = self::min0($product->get_meta($meta_key, true));
			if ($value <= 0) {
				$value = self::min0($product->{$getter}());
			}
			$dimensions[$key] = 'weight' === $key
				? self::weight_to_bijak_kg($value)
				: self::dimension_to_bijak_cm($value);
			if ($value <= 0) {
				$missing[$key] = $label;
			}
		}

		$dimensions['missing'] = $missing;
		return $dimensions;
	}

	/**
	 * Return human-readable dimension errors for the current cart.
	 */
	public static function cart_dimension_errors(): array
	{
		$errors = [];
		if (! function_exists('WC') || ! WC()->cart || WC()->cart->is_empty()) {
			return $errors;
		}

		foreach (WC()->cart->get_cart() as $item) {
			$product = $item['data'] ?? null;
			if (! $product instanceof \WC_Product) {
				$errors[] = [
					'name' => __('Unknown product', 'bijak'),
					'missing' => [__('Product details', 'bijak')],
				];
				continue;
			}

			$resolved = self::resolve_product_dimensions($product);
			if (! empty($resolved['missing'])) {
				$errors[] = [
					'name' => $product->get_name(),
					'missing' => array_values($resolved['missing']),
				];
			}
		}

		return $errors;
	}

	public static function order_dimension_errors(\WC_Order $order): array
	{
		$errors = [];
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (! $product instanceof \WC_Product) {
				$errors[] = [
					'name' => $item->get_name(),
					'missing' => [__('Product details', 'bijak')],
				];
				continue;
			}

			$resolved = self::resolve_product_dimensions($product);
			if (! empty($resolved['missing'])) {
				$errors[] = [
					'name' => $item->get_name(),
					'missing' => array_values($resolved['missing']),
				];
			}
		}

		return $errors;
	}

	public static function build_goods_details_from_cart(): array
	{
		$details = [];
		$is_goods_have_problem = false;

		if (function_exists('WC') && WC()->cart && ! WC()->cart->is_empty()) {
			foreach (WC()->cart->get_cart() as $item) {
				$p = isset($item['data']) ? $item['data'] : null;
				if (! $p instanceof \WC_Product) {
					$is_goods_have_problem = true;
					continue;
				}

				$dimensions = self::resolve_product_dimensions($p);
				$pr_length = $dimensions['length'];
				$pr_width  = $dimensions['width'];
				$pr_height = $dimensions['height'];
				$pr_weight = $dimensions['weight'];

				if ($pr_length == 0 || $pr_width == 0 || $pr_height == 0 || $pr_weight == 0) {
					$is_goods_have_problem = true;
				}
				$details[] = [
					'title'                 => (string) $p->get_name(),
					'length'                => $pr_length,
					'width'                 => $pr_width,
					'height'                => $pr_height,
					'weight'                => $pr_weight,
					'goods_count'           => (int) $item['quantity'],
					'needs_packaging'       => false,
					'goods_packaging_type_id' => 1,
					'file_paths'            => [],
				];
			}
		}

		if ($is_goods_have_problem) {
			return [];
		} else {
			return $details;
		}
	}

	public static function build_goods_details_from_order(\WC_Order $order): array
	{
		$details = [];
		$is_goods_have_problem = false;

		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (! $product instanceof \WC_Product) {
				$is_goods_have_problem = true;
				continue;
			}

			$dimensions = self::resolve_product_dimensions($product);
			$pr_length = $dimensions['length'];
			$pr_width  = $dimensions['width'];
			$pr_height = $dimensions['height'];
			$pr_weight = $dimensions['weight'];

			if ($pr_length == 0 || $pr_width == 0 || $pr_height == 0 || $pr_weight == 0) {
				$is_goods_have_problem = true;
			}
			$details[] = [
				'title'                 => $item->get_name(),
				'length'                => $pr_length,
				'width'                 => $pr_width,
				'height'                => $pr_height,
				'weight'                => $pr_weight,
				'goods_count'           => (int) $item->get_quantity(),
				'needs_packaging'       => false,
				'goods_packaging_type_id' => 1,
				'file_paths'            => [],
			];
		}

		if ($is_goods_have_problem) {
			return [];
		} else {
			return $details;
		}
	}

	/**
	 * Output example:
	 * [
	 *   [110],
	 *   [113, 320],
	 * ]
	 */
	public static function extract_route_line_id_groups(array $routes_response): array
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
				$groups[] = array_values($segment_first_line_ids);
			}
		}

		return $groups;
	}

	/**
	 * Returns first route option line IDs, using first line of each segment.
	 */
	public static function resolve_line_ids(Api $api, int $origin_city_id, int $dest_city_id): array
	{
		$routes_response = $api->request("/application/routes?origin_city_id={$origin_city_id}&destination_city_id={$dest_city_id}");
		if (is_wp_error($routes_response)) {
			return [];
		}

		$groups = self::extract_route_line_id_groups(is_array($routes_response) ? $routes_response : []);
		if (empty($groups) || empty($groups[0]) || ! is_array($groups[0])) {
			return [];
		}

		return array_values(
			array_filter(
				array_map('intval', $groups[0]),
				static function ($id) {
					return $id > 0;
				}
			)
		);
	}


	public static function fetch_wallet_inventory(Api $api, int $default = 0): int
	{
		$fallback = max(0, $default);
		$res = $api->request("/application/inventory");

		if (is_wp_error($res) || ! is_array($res)) {
			return $fallback;
		}

		if (isset($res["inventory"])) {
			return max(0, (int) $res["inventory"]);
		}

		if (! empty($res["data"]) && is_array($res["data"]) && isset($res["data"]["inventory"])) {
			return max(0, (int) $res["data"]["inventory"]);
		}

		return $fallback;
	}

	// Backward-compatible wrapper.
	public static function resolve_line_id(Api $api, int $origin_city_id, int $dest_city_id): ?int
	{
		$line_ids = self::resolve_line_ids($api, $origin_city_id, $dest_city_id);
		return ! empty($line_ids) ? (int) $line_ids[0] : null;
	}

	/** Convert Toman to store currency (IRR=×10) */
	public static function toman_to_store_currency(float $toman): float
	{
		$currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'IRT';
		return (strtoupper($currency) === 'IRR') ? ($toman * 10.0) : $toman;
	}

	/** Convert store currency to Toman before sending monetary values to Bijak. */
	public static function store_currency_to_toman(float $amount): float
	{
		$currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'IRT';
		return (strtoupper($currency) === 'IRR') ? ($amount / 10.0) : $amount;
	}

	/**
	 * Safe accessor for checkout fields posted via WooCommerce AJAX.
	 * - Sanitizes early, validates type, and escapes at output time elsewhere.
	 */
	public static function checkout_field(string $key, $default = '')
	{
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Handle serialized post_data (AJAX updates)
		if (! empty($_POST['post_data'])) {
			$raw_pd = (string) wp_unslash($_POST['post_data']);
			$pd = [];
			parse_str($raw_pd, $pd);

			if (isset($pd[$key])) {
				$val = $pd[$key];

				if (is_array($val)) {
					// Deep sanitize arrays
					$sanitize = function ($v) use (&$sanitize) {
						if (is_array($v)) {
							return array_map($sanitize, $v);
						}
						return is_string($v) ? sanitize_text_field($v) : '';
					};
					return $sanitize($val);
				}

				return is_string($val) ? sanitize_text_field($val) : $default;
			}
		}

		// Fallback to direct POST in non-AJAX submits
		if (isset($_POST[$key])) {
			$val = wp_unslash($_POST[$key]);

			if (is_array($val)) {
				$sanitize = function ($v) use (&$sanitize) {
					if (is_array($v)) {
						return array_map($sanitize, $v);
					}
					return is_string($v) ? sanitize_text_field($v) : '';
				};
				return $sanitize($val);
			}

			return is_string($val) ? sanitize_text_field($val) : $default;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return $default;
	}
}
