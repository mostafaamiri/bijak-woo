<?php

namespace BIJAK\BijakWoo;

if ( ! defined('ABSPATH') ) {
	exit;
}

class Admin
{
	public function register()
	{
		add_action('wp_ajax_bijak_admin_get_profile', [$this, 'ajax_get_profile']);
		add_action('wp_ajax_bijak_admin_create_origin_picker_session', [$this, 'ajax_create_origin_picker_session']);
		add_action('wp_ajax_bijak_admin_save_origin_location', [$this, 'ajax_save_origin_location']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_notices', [$this, 'maybe_notice_api_key']);
		add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'render_order_shipping_filter'], 20, 2);
		add_filter('woocommerce_order_list_table_prepare_items_query_args', [$this, 'filter_hpos_orders'], 20);
		add_action('restrict_manage_posts', [$this, 'render_legacy_order_shipping_filter'], 20);
		add_action('pre_get_posts', [$this, 'filter_legacy_orders'], 20);
		add_filter('pre_update_option_' . Plugin::OPT, [$this, 'pre_update_options'], 10, 3);
		add_action('woocommerce_product_options_shipping', [$this, 'render_product_bijak_dimensions']);
		add_action('woocommerce_admin_process_product_object', [$this, 'save_product_bijak_dimensions']);
		add_action('woocommerce_variation_options_dimensions', [$this, 'render_variation_bijak_dimensions'], 10, 3);
		add_action('woocommerce_save_product_variation', [$this, 'save_variation_bijak_dimensions'], 10, 2);
	}

	/* ---------- Product shipping dimensions ---------- */

	private function bijak_dimension_fields(): array
	{
		return [
			'length' => [
				'label' => __('Length', 'bijak'),
				'meta'  => Config::BIJAK_LENGTH_META,
				'unit'  => get_option('woocommerce_dimension_unit', 'cm'),
			],
			'width' => [
				'label' => __('Width', 'bijak'),
				'meta'  => Config::BIJAK_WIDTH_META,
				'unit'  => get_option('woocommerce_dimension_unit', 'cm'),
			],
			'height' => [
				'label' => __('Height', 'bijak'),
				'meta'  => Config::BIJAK_HEIGHT_META,
				'unit'  => get_option('woocommerce_dimension_unit', 'cm'),
			],
			'weight' => [
				'label' => __('Weight', 'bijak'),
				'meta'  => Config::BIJAK_WEIGHT_META,
				'unit'  => get_option('woocommerce_weight_unit', 'kg'),
			],
		];
	}

	private function product_dimension_value(\WC_Product $product, string $meta_key): string
	{
		$value = $product->get_meta($meta_key, true);
		return is_scalar($value) ? (string) $value : '';
	}

	public function render_product_bijak_dimensions(): void
	{
		global $post;
		if (! function_exists('woocommerce_wp_text_input') && defined('WC_ABSPATH')) {
			require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		}
		$product = ($post && ! empty($post->ID)) ? wc_get_product($post->ID) : new \WC_Product_Simple();
		if (! $product instanceof \WC_Product) {
			return;
		}

		echo '<div class="options_group bijak-product-dimensions">';
		echo '<h4>' . esc_html__('Bijak Shipping Dimensions', 'bijak') . '</h4>';
		echo '<p class="form-field bijak-dimensions-description"><span class="description">';
		echo esc_html__('Optional overrides for Bijak shipping. Empty fields use the standard WooCommerce product values.', 'bijak');
		echo '</span></p>';

		foreach ($this->bijak_dimension_fields() as $key => $field) {
			\woocommerce_wp_text_input([
				'id'                => 'bijak_shipping_' . $key,
				'label'             => $field['label'] . ' (' . $field['unit'] . ')',
				'value'             => $this->product_dimension_value($product, $field['meta']),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __('Used for Bijak when filled.', 'bijak'),
				'custom_attributes' => [
					'min'  => '0',
					'step' => 'any',
				],
			]);
		}
		echo '</div>';
	}

	public function save_product_bijak_dimensions(\WC_Product $product): void
	{
		foreach ($this->bijak_dimension_fields() as $key => $field) {
			if (! isset($_POST['bijak_shipping_' . $key])) {
				continue;
			}
			$raw = isset($_POST['bijak_shipping_' . $key]) ? wp_unslash($_POST['bijak_shipping_' . $key]) : '';
			$value = is_scalar($raw) ? wc_format_decimal($raw) : '';
			if ($value !== '' && (float) $value > 0) {
				$product->update_meta_data($field['meta'], $value);
			} else {
				$product->delete_meta_data($field['meta']);
			}
		}
	}

	public function render_variation_bijak_dimensions(int $loop, array $variation_data, $variation): void
	{
		if (! function_exists('woocommerce_wp_text_input') && defined('WC_ABSPATH')) {
			require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		}
		$variation_id = $variation instanceof \WP_Post ? $variation->ID : $variation;
		$variation_product = wc_get_product($variation_id);
		if (! $variation_product instanceof \WC_Product_Variation) {
			return;
		}
		echo '<div class="bijak-variation-dimensions">';
		echo '<p><strong>' . esc_html__('Bijak Shipping Dimensions', 'bijak') . '</strong></p>';
		echo '<p class="form-row form-row-full"><span class="description">' . esc_html__('Optional overrides for Bijak shipping. Empty fields use the standard WooCommerce variation values.', 'bijak') . '</span></p>';

		foreach ($this->bijak_dimension_fields() as $key => $field) {
			\woocommerce_wp_text_input([
				'id'                => 'bijak_shipping_' . $key . '_' . $loop,
				'name'              => 'bijak_shipping_' . $key . '[' . $loop . ']',
				'label'             => $field['label'] . ' (' . $field['unit'] . ')',
				'value'             => $this->product_dimension_value($variation_product, $field['meta']),
				'wrapper_class'     => 'form-row form-row-first',
				'type'              => 'number',
				'custom_attributes' => [
					'min'  => '0',
					'step' => 'any',
				],
			]);
		}
		echo '</div>';
	}

	public function save_variation_bijak_dimensions(int $variation_id, int $loop): void
	{
		$variation = wc_get_product($variation_id);
		if (! $variation instanceof \WC_Product_Variation) {
			return;
		}

		foreach ($this->bijak_dimension_fields() as $key => $field) {
			if (! isset($_POST['bijak_shipping_' . $key]) || ! is_array($_POST['bijak_shipping_' . $key]) || ! array_key_exists($loop, $_POST['bijak_shipping_' . $key])) {
				continue;
			}
			$raw = isset($_POST['bijak_shipping_' . $key][$loop]) ? wp_unslash($_POST['bijak_shipping_' . $key][$loop]) : '';
			$value = is_scalar($raw) ? wc_format_decimal($raw) : '';
			if ($value !== '' && (float) $value > 0) {
				$variation->update_meta_data($field['meta'], $value);
			} else {
				$variation->delete_meta_data($field['meta']);
			}
		}
		$variation->save();
	}

	/* ---------- Menu ---------- */

	public function admin_menu()
	{
		$icon_file = BIJAK_WOO_PATH . 'assets/icon.png';
		$icon_url  = file_exists($icon_file) ? BIJAK_WOO_URL . 'assets/icon.png' : 'dashicons-admin-generic';

		add_menu_page(
			__('Bijak (Smart Freight)', 'bijak'),
			__('Bijak (Shipping)', 'bijak'),
			'manage_options',
			'bijak-woo',
			[$this, 'dashboard_page'],
			$icon_url,
			25
		);

		add_submenu_page(
			'bijak-woo',
			__('Bijak (Smart Freight)', 'bijak'),
			__('Dashboard', 'bijak'),
			'manage_options',
			'bijak-woo',
			[$this, 'dashboard_page']
		);

		add_submenu_page(
			'bijak-woo',
			__('Bijak Setup & Settings', 'bijak'),
			__('Setup & Settings', 'bijak'),
			'manage_options',
			'bijak-woo-settings',
			[$this, 'settings_page']
		);
	}

	/* ---------- Settings: register ---------- */

	public function register_settings()
	{
		register_setting(
			Plugin::OPT,
			Plugin::OPT,
			['sanitize_callback' => [$this, 'sanitize_opts']]
		);

		add_settings_section('origin', __('Origin settings', 'bijak'), '__return_false', Plugin::OPT);

		add_settings_field(
			'origin_city_id',
			__('Origin city', 'bijak'),
			[$this, 'render_origin_select'],
			Plugin::OPT,
			'origin'
		);

		add_settings_field(
			'self_delivery',
			__('Will you deliver to terminal yourself?', 'bijak'),
			function () {
				$val = Plugin::opt('self_delivery', 'yes') === 'yes';
				printf('<input type="hidden" name="%s[self_delivery]" value="no">', esc_attr(Plugin::OPT));
				printf(
					'<label><input type="checkbox" name="%s[self_delivery]" value="yes" %s> %s</label>',
					esc_attr(Plugin::OPT),
					checked($val, true, false),
					esc_html__('Yes', 'bijak')
				);
			},
			Plugin::OPT,
			'origin'
		);

		$this->add_text_field('origin_address', __('Origin address (detailed)', 'bijak'), '', 'textarea');

		add_settings_field(
			'origin_coords',
			__('Origin coordinates (lat,lon)', 'bijak'),
			function () {
				[$lat, $lng] = $this->origin_coordinates();
				$has_location = ! is_null($lat) && ! is_null($lng);
				echo '<div class="bijak-origin-location">';
				printf('<input type="hidden" id="bijak-origin-coords" name="%s[origin_coords]" value="%s" />', esc_attr(Plugin::OPT), esc_attr($has_location ? $lat . ',' . $lng : ''));
				echo '<div class="bijak-origin-location__actions">';
				echo '<button type="button" class="button button-secondary" id="bijak-origin-profile">' . esc_html__('Fill address from Bijak profile', 'bijak') . '</button>';
				echo '<button type="button" class="button" id="bijak-origin-map">' . esc_html__('Choose origin on map', 'bijak') . '</button>';
				echo '</div>';
				echo '<p id="bijak-origin-coords-status" class="description ' . ($has_location ? 'is-set' : 'is-missing') . '">';
				if ($has_location) {
					printf('<a id="bijak-origin-map-link" href="%s" target="_blank" rel="noopener">%s</a>', esc_url(Config::neshan_map_url($lat, $lng)), esc_html__('View origin on Neshan map', 'bijak'));
				} else {
					echo esc_html__('Origin location is not set.', 'bijak');
				}
				echo '</p>';
				echo '<input type="hidden" id="bijak-origin-location-source" name="' . esc_attr(Plugin::OPT) . '[origin_location_source]" value="" />';
				echo '</div>';
			},
			Plugin::OPT,
			'origin'
		);

		add_settings_field(
			'delivery_day',
			__('Pickup day for Bijak', 'bijak'),
			function () {
				$val = Plugin::opt('delivery_day', 'first_working');
				$options = [
					'first_working'  => __('First working day', 'bijak'),
					'second_working' => __('Second working day', 'bijak'),
				];
				printf('<select name="%s[delivery_day]">', esc_attr(Plugin::OPT));
				foreach ($options as $k => $lbl) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr($k),
						selected($val, $k, false),
						esc_html($lbl)
					);
				}
				echo '</select>';
			},
			Plugin::OPT,
			'origin'
		);
		add_settings_field(
			'local_transport_cost',
			__('Origin local transport cost', 'bijak'),
			function () {
				$val = max(0, intval(Plugin::opt('local_transport_cost', 0)));
				printf(
					'<input type="number" class="regular-text" min="0" step="1" name="%s[local_transport_cost]" value="%s" style="direction:ltr;max-width:180px" />',
					esc_attr(Plugin::OPT),
					esc_attr((string) $val)
				);
				echo '<p class="description">' . esc_html__('Enter a non-negative number (Toman).', 'bijak') . '</p>';
			},
			Plugin::OPT,
			'origin'
		);
	}

	private function origin_coordinates(): array
	{
		[$lat, $lng] = Helpers::parse_coords((string) Plugin::opt('origin_coords', ''));
		if (is_null($lat) || is_null($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return [null, null];
		return [(float) $lat, (float) $lng];
	}

	private function ajax_guard(): void
	{
		check_ajax_referer('bijak_admin_nonce', 'nonce');
		if (! current_user_can('manage_options')) wp_send_json_error(['message' => __('You are not allowed to manage Bijak settings.', 'bijak')], 403);
	}

	public function ajax_get_profile(): void
	{
		$this->ajax_guard();
		$response = (new Api())->request('/application/profile');
		if (is_wp_error($response) || empty($response['data']) || ! is_array($response['data'])) {
			wp_send_json_error(['message' => __('Unable to read the Bijak profile.', 'bijak')], 502);
		}
		$data = $response['data'];
		$lat = isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null;
		$lng = isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null;
		wp_send_json_success([
			'address' => isset($data['address']) ? sanitize_textarea_field((string) $data['address']) : '',
			'lat' => $lat, 'lng' => $lng,
			'city_id' => isset($data['city_id']) ? absint($data['city_id']) : 0,
		]);
	}

	public function ajax_create_origin_picker_session(): void
	{
		$this->ajax_guard();
		$parent_origin = Config::origin_from_url(home_url('/'));
		$picker_url = esc_url_raw(Config::MAP_PICKER_URL);
		if (!$parent_origin || !$picker_url) wp_send_json_error(['message' => __('The location picker configuration is invalid.', 'bijak')], 400);
		$authorization = (new Api())->request('/application/location-picker/authorize', 'POST', ['origin' => $parent_origin]);
		if (is_wp_error($authorization) || empty($authorization['grant'])) wp_send_json_error(['message' => __('The Bijak API key is inactive or this domain is not authorized to use the location picker.', 'bijak')], 403);
		try { $state = rtrim(strtr(base64_encode(random_bytes(Config::LOCATION_PICKER_STATE_BYTES)), '+/', '-_'), '='); } catch (\Throwable $e) { wp_send_json_error(['message' => __('Unable to create a secure picker session.', 'bijak')], 500); }
		set_transient('bijak_origin_picker_' . get_current_user_id(), ['state' => $state, 'created_at' => time()], Config::LOCATION_PICKER_STATE_TTL);
		$url = add_query_arg(['state' => $state, 'parent_origin' => $parent_origin, 'picker_grant' => (string) $authorization['grant']], $picker_url);
		wp_send_json_success(['url' => esc_url_raw($url), 'state' => $state, 'origin' => Config::map_picker_origin()]);
	}

	public function ajax_save_origin_location(): void
	{
		$this->ajax_guard();
		$state = isset($_POST['state']) && is_scalar($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
		$pending = get_transient('bijak_origin_picker_' . get_current_user_id());
		if (! is_array($pending) || empty($pending['state']) || !$state || ! hash_equals((string) $pending['state'], $state)) wp_send_json_error(['message' => __('This location picker session is invalid or expired.', 'bijak')], 400);
		$lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float) wp_unslash($_POST['lat']) : null;
		$lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float) wp_unslash($_POST['lng']) : null;
		if (is_null($lat) || is_null($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) wp_send_json_error(['message' => __('The selected coordinates are invalid.', 'bijak')], 400);
		delete_transient('bijak_origin_picker_' . get_current_user_id());
		wp_send_json_success(['coords' => $lat . ',' . $lng, 'lat' => $lat, 'lng' => $lng]);
	}

	/* ---------- Field helpers ---------- */

	private function add_text_field($key, $label, $default = '', $type = 'text')
	{
		add_settings_field(
			$key,
			esc_html($label),
			function () use ($key, $default, $type) {
				$val = Plugin::opt($key, $default);
				if ($type === 'textarea') {
					printf(
						'<textarea id="bijak-%s" class="large-text" rows="3" name="%s[%s]">%s</textarea>',
							esc_attr(str_replace('_', '-', $key)),
						esc_attr(Plugin::OPT),
						esc_attr($key),
						esc_textarea($val)
					);
				} else {
					printf(
						'<input type="%s" class="regular-text" name="%s[%s]" value="%s" placeholder="%s"/>',
						esc_attr($type),
						esc_attr(Plugin::OPT),
						esc_attr($key),
						esc_attr($val),
						esc_attr($default)
					);
				}
			},
			Plugin::OPT,
			($key === 'api_key') ? 'api' : 'origin'
		);
	}

	/* ---------- Origin city select ---------- */

	public function render_origin_select()
	{
		$selected = intval(Plugin::opt('origin_city_id', 0));

		$api   = new Api();
		$resp  = $api->request('/application/terminals?type=origin');
		$cities = [];

		if ( ! is_wp_error($resp) && ! empty($resp['data']) && is_array($resp['data']) ) {
			foreach ($resp['data'] as $c) {
				$cities[] = [
					'id'   => intval($c['city_id']),
					'name' => sanitize_text_field($c['city_name']),
					'prov' => sanitize_text_field($c['city_province_name']),
				];
			}
		}

		if ( empty($cities) ) {
			echo '<em style="color:#a00">' . esc_html__('Failed to fetch origin cities from API.', 'bijak') . '</em>';
			return;
		}

		printf('<select name="%s[origin_city_id]">', esc_attr(Plugin::OPT));
		echo '<option value="">' . esc_html__('— Select —', 'bijak') . '</option>';
		foreach ($cities as $c) {
			$val = (string) $c['id'];
			$lbl = $c['name'] . ' (' . $c['prov'] . ')';
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr($val),
				selected($selected, (int) $c['id'], false),
				esc_html($lbl)
			);
		}
		echo '</select>';
	}

	/* ---------- Sanitizer ---------- */

	public function sanitize_opts($in)
	{
		$old = get_option(Plugin::OPT, []);
		if ( ! is_array($old) ) {
			$old = [];
		}

		$in  = is_array($in) ? $in : [];
		$out = $old;

		if ( array_key_exists('api_key', $in) ) {
			$out['api_key'] = sanitize_text_field($in['api_key'] ?? '');
		}

		if ( array_key_exists('origin_city_id', $in) ) {
			$out['origin_city_id'] = intval($in['origin_city_id'] ?? 0);
		}

		$address_included = array_key_exists('origin_address', $in);
		$location_source = isset($in['origin_location_source']) && is_scalar($in['origin_location_source']) ? sanitize_key($in['origin_location_source']) : '';
		if ( $address_included ) {
			$out['origin_address'] = sanitize_textarea_field($in['origin_address'] ?? '');
		}

		if ( array_key_exists('origin_coords', $in) ) {
			$raw_coords = is_scalar($in['origin_coords'] ?? null) ? sanitize_text_field($in['origin_coords']) : '';
			[$lat, $lng] = Helpers::parse_coords($raw_coords);
			$out['origin_coords'] = (! is_null($lat) && ! is_null($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180)
				? ((float) $lat . ',' . (float) $lng)
				: '';
		}
		// This endpoint is deployment configuration, not a merchant setting.
		unset($out['map_picker_url']);

		if ( array_key_exists('self_delivery', $in) ) {
			$out['self_delivery'] = ( ! empty($in['self_delivery']) && $in['self_delivery'] === 'yes' ) ? 'yes' : 'no';
		}

		if ( array_key_exists('delivery_day', $in) ) {
			$val = $in['delivery_day'] ?? '';
			$out['delivery_day'] = in_array($val, ['first_working', 'second_working'], true)
				? $val
				: ($old['delivery_day'] ?? 'first_working');
		}

		if ( array_key_exists('local_transport_cost', $in) ) {
			$raw = $in['local_transport_cost'] ?? 0;
			$num = is_numeric($raw) ? (int) $raw : 0;
			$out['local_transport_cost'] = max(0, $num);
		}
		$api_key = trim($out['api_key'] ?? '');

		$address_changed = $address_included && trim((string) ($old['origin_address'] ?? '')) !== trim((string) ($out['origin_address'] ?? ''));
		if ( $address_changed && $location_source !== 'profile' ) {
			$out['origin_coords'] = '';
			$out['origin_location_needs_selection'] = 1;
		} elseif ($location_source === 'profile') {
			$out['origin_location_needs_selection'] = empty($out['origin_coords']) ? 1 : 0;
		} elseif (!empty($out['origin_coords'])) {
			$out['origin_location_needs_selection'] = 0;
		}

		return $out;
	}

	/* ---------- Page / Options sync ---------- */

	private function refresh_profile_options(): array
	{
		$key = trim(Plugin::opt('api_key', ''));
		if ( $key === '' ) {
			return ['ok' => false, 'msg' => __('API Key is not set.', 'bijak'), 'full_name' => '', 'phone' => '', 'wallet' => 0];
		}

		$api = new Api();
		$res = $api->request('/application/profile');

		if ( is_wp_error($res) || empty($res['data']) ) {
			$msg = is_wp_error($res) ? $res->get_error_message() : 'Invalid API response';
			return ['ok' => false, 'msg' => __('Failed to fetch profile: ', 'bijak') . $msg, 'full_name' => '', 'phone' => '', 'wallet' => 0];
		}

		$d = $res['data'];
		$full_name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
		$phone     = Helpers::normalize_phone($d['username'] ?? '');
		$wallet    = Helpers::fetch_wallet_inventory($api, (int) Plugin::opt("wallet_inventory", 0));

		$opts = get_option(Plugin::OPT, []);
		if ( ! is_array($opts) ) {
			$opts = [];
		}
		$opts['supplier_full_name'] = sanitize_text_field($full_name);
		$opts['supplier_phone']     = sanitize_text_field($phone);
		$opts['wallet_inventory']   = max(0, $wallet);
		update_option(Plugin::OPT, $opts);

		return ['ok' => true, 'msg' => __('Profile info synced from Bijak.', 'bijak'), 'full_name' => $full_name, 'phone' => $phone, 'wallet' => $wallet];
	}

	public function pre_update_options($new, $old, $option)
	{
		if ( $option !== Plugin::OPT ) {
			return $new;
		}
		$new_arr = is_array($new) ? $new : [];
		$old_arr = is_array($old) ? $old : [];

		$new_key = isset($new_arr['api_key']) ? trim((string) $new_arr['api_key']) : '';
		$old_key = isset($old_arr['api_key']) ? trim((string) $old_arr['api_key']) : '';

		if ( $new_key !== '' && $new_key !== $old_key ) {
			$api = new Api();
			$res = $api->request('/application/profile');

			if ( ! is_wp_error($res) && ! empty($res['data']) ) {
				$d = $res['data'];

				$full_name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
				$phone     = Helpers::normalize_phone($d['username'] ?? '');
				$wallet    = Helpers::fetch_wallet_inventory($api, (int) Plugin::opt("wallet_inventory", 0));

				if ( $full_name !== '' ) {
					$new_arr['supplier_full_name'] = sanitize_text_field($full_name);
				}
				if ( $phone !== '' ) {
					$new_arr['supplier_phone'] = sanitize_text_field($phone);
				}
				$new_arr['wallet_inventory'] = max(0, $wallet);

			}
		}

		if ( $new_key === '' && $old_key !== '' ) {
			unset($new_arr['supplier_full_name'], $new_arr['supplier_phone']);
			$new_arr['wallet_inventory'] = 0;
		}

		unset($new_arr['origin_location_source']);

		return $new_arr;
	}

	public function settings_page()
	{
		if (! current_user_can('manage_options')) return;
		$api_key = trim(Plugin::opt('api_key', ''));
		$profile = ['ok' => false, 'msg' => ''];
		if ($api_key !== '') $profile = $this->refresh_profile_options();

		$logo = '<span class="bijak-brand-mark"><img src="' . esc_url(BIJAK_WOO_URL . 'assets/dashboard-logo.jpeg') . '" alt="' . esc_attr__('Bijak', 'bijak') . '" /></span>';
		echo '<div class="wrap bijak-admin"><div class="bijak-page-header"><div><span class="bijak-eyebrow">BIJAK / CONFIGURATION</span><h1>' . esc_html__('Bijak Setup & Settings', 'bijak') . '</h1><p>' . esc_html__('Connect your store and manage shipping settings in one place.', 'bijak') . '</p></div>' . $logo . '</div>';
		if ($profile['msg'] !== '') echo '<div class="notice ' . esc_attr($profile['ok'] ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html($profile['msg']) . '</p></div>';
		if (Plugin::opt('origin_location_needs_selection', 0)) echo '<div class="notice notice-warning"><p>' . esc_html__('The origin address changed. Select its point on the map and save the settings.', 'bijak') . '</p></div>';

		echo '<section class="bijak-panel bijak-setup-connect"><div class="bijak-panel-heading"><div><span class="bijak-step">01</span><h2>' . esc_html__('Connect Your Bijak Account', 'bijak') . '</h2></div><span class="dashicons dashicons-admin-network"></span></div><p class="bijak-muted">' . esc_html__('Create an API key in the Bijak panel and enter it here.', 'bijak') . '</p><form method="post" action="options.php" class="bijak-inline-form">';
		settings_fields(Plugin::OPT);
		printf('<input type="password" autocomplete="off" class="regular-text bijak-api-input" name="%s[api_key]" value="%s" placeholder="API Key"/>', esc_attr(Plugin::OPT), esc_attr($api_key));
		submit_button(esc_html__('Save & Sync', 'bijak'), 'primary', 'submit', false);
		echo '<a class="button button-secondary" href="' . esc_url(Config::PANEL_API_KEYS_URL) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> ' . esc_html__('Create API Key', 'bijak') . '</a></form></section>';

		echo '<section class="bijak-panel bijak-setup-origin"><div class="bijak-panel-heading"><div><span class="bijak-step">02</span><h2>' . esc_html__('Shipping Origin', 'bijak') . '</h2></div><span class="dashicons dashicons-location"></span></div><p class="bijak-muted">' . esc_html__('Set the pickup city, address, and exact map location used for Bijak shipments.', 'bijak') . '</p><form method="post" action="options.php" class="bijak-origin-form">';
		settings_fields(Plugin::OPT);
		do_settings_sections(Plugin::OPT);
		echo '<div class="bijak-origin-form__footer">';
		submit_button(esc_html__('Save Settings', 'bijak'), 'primary', 'submit', false);
		echo '</div>';
		echo '</form></section></div>';
	}

	public function dashboard_page()
	{
		if (! current_user_can('manage_options')) return;
		$api_key = trim(Plugin::opt('api_key', ''));
		$profile = $api_key !== '' ? $this->refresh_profile_options() : ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
		$orders = $this->fetch_orders();
		$full = $profile['ok'] ? $profile['full_name'] : (string) Plugin::opt('supplier_full_name', '');
		$phone = $profile['ok'] ? $profile['phone'] : (string) Plugin::opt('supplier_phone', '');
		$wallet = $profile['ok'] ? (int) $profile['wallet'] : (int) Plugin::opt('wallet_inventory', 0);

		$logo = '<span class="bijak-brand-mark"><img src="' . esc_url(BIJAK_WOO_URL . 'assets/dashboard-logo.jpeg') . '" alt="' . esc_attr__('Bijak', 'bijak') . '" /></span>';
		echo '<div class="wrap bijak-admin"><div class="bijak-page-header"><div><span class="bijak-eyebrow">BIJAK / CONTROL CENTER</span><h1>' . esc_html__('Bijak Dashboard', 'bijak') . '</h1><p>' . esc_html__('Review your account, wallet balance, and shipping orders at a glance.', 'bijak') . '</p></div>' . $logo . '</div>';
		echo '<aside class="bijak-setup-reminder"><span class="dashicons dashicons-admin-settings"></span><p>' . esc_html__('Make sure your API key and shipping origin settings are correct.', 'bijak') . '</p><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=bijak-woo-settings')) . '">' . esc_html__('Review Setup & Settings', 'bijak') . ' <span class="dashicons dashicons-arrow-left-alt2"></span></a></aside>';
		if ($api_key === '') {
			echo '<div class="bijak-empty-state"><span class="dashicons dashicons-admin-network"></span><h2>' . esc_html__('Your Bijak account is not connected yet', 'bijak') . '</h2><p>' . esc_html__('Save an API key first to view your wallet balance and orders.', 'bijak') . '</p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=bijak-woo-settings')) . '">' . esc_html__('Start Setup', 'bijak') . '</a></div></div>';
			return;
		}

		echo '<div class="bijak-summary-grid"><div class="bijak-summary-card bijak-summary-wallet"><span class="dashicons dashicons-money-alt"></span><div><span class="bijak-summary-label">' . esc_html__('Wallet Balance', 'bijak') . '</span><strong>' . esc_html(number_format_i18n($wallet)) . ' <small>' . esc_html__('Toman', 'bijak') . '</small></strong><a href="' . esc_url(Config::PANEL_WALLET_URL) . '" target="_blank" rel="noopener">' . esc_html__('Manage Wallet', 'bijak') . ' <span class="dashicons dashicons-arrow-left-alt2"></span></a></div></div><div class="bijak-summary-card"><span class="dashicons dashicons-admin-users"></span><div><span class="bijak-summary-label">' . esc_html__('Connected Account', 'bijak') . '</span><strong>' . esc_html($full ?: '-') . '</strong><small dir="ltr">' . esc_html($phone ?: '-') . '</small></div></div><div class="bijak-summary-card"><span class="dashicons dashicons-list-view"></span><div><span class="bijak-summary-label">' . esc_html__('Total Orders', 'bijak') . '</span><strong>' . esc_html($orders['total_orders'] === null ? '-' : number_format_i18n($orders['total_orders'])) . '</strong><small>' . esc_html__('Based on the active filters', 'bijak') . '</small></div></div></div>';
		echo '<aside class="bijak-web-app-callout"><span class="dashicons dashicons-external"></span><p>' . esc_html__('For viewing, payments, and other activities, use the Bijak web app.', 'bijak') . '</p><a class="button button-secondary" href="' . esc_url(Config::WEB_APP_URL) . '" target="_blank" rel="noopener">' . esc_html__('Open Bijak Web App', 'bijak') . ' <span class="dashicons dashicons-external"></span></a></aside>';
		$this->render_orders($orders);
		echo '</div>';
	}

	private function order_shipping_filter_value(): string
	{
		$value = isset($_GET['bijak_shipping']) && is_scalar($_GET['bijak_shipping']) ? sanitize_key(wp_unslash($_GET['bijak_shipping'])) : '';
		return $value === 'bijak' ? $value : '';
	}

	private function bijak_order_ids(): array
	{
		global $wpdb;
		$items_table = $wpdb->prefix . 'woocommerce_order_items';
		$meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT items.order_id FROM {$items_table} AS items INNER JOIN {$meta_table} AS itemmeta ON itemmeta.order_item_id = items.order_item_id WHERE items.order_item_type = %s AND itemmeta.meta_key = %s AND itemmeta.meta_value = %s",
			'shipping',
			'method_id',
			Config::SHIPPING_METHOD_ID
		));
		return array_values(array_filter(array_map('absint', is_array($ids) ? $ids : [])));
	}

	public function render_order_shipping_filter($order_type = '', $which = 'top'): void
	{
		if ($which !== 'top' || $order_type !== 'shop_order') return;
		echo '<select name="bijak_shipping" class="bijak-order-method-filter"><option value="">' . esc_html__('All shipping methods', 'bijak') . '</option><option value="bijak" ' . selected($this->order_shipping_filter_value(), 'bijak', false) . '>' . esc_html__('Shipping with Bijak', 'bijak') . '</option></select>';
	}

	public function render_legacy_order_shipping_filter(): void
	{
		global $typenow;
		if ($typenow !== 'shop_order') return;
		$this->render_order_shipping_filter('shop_order', 'top');
	}

	public function filter_hpos_orders(array $args): array
	{
		if ($this->order_shipping_filter_value() === '' || ! isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'wc-orders') return $args;
		$ids = $this->bijak_order_ids();
		$args['post__in'] = isset($args['post__in']) && is_array($args['post__in']) ? array_values(array_intersect(array_map('absint', $args['post__in']), $ids)) : $ids;
		return $args;
	}

	public function filter_legacy_orders(\WP_Query $query): void
	{
		global $pagenow;
		if ($this->order_shipping_filter_value() === '' || ! is_admin() || ! $query->is_main_query() || $pagenow !== 'edit.php' || $query->get('post_type') !== 'shop_order') return;
		$ids = $this->bijak_order_ids();
		$existing = $query->get('post__in');
		$query->set('post__in', is_array($existing) && $existing ? array_values(array_intersect(array_map('absint', $existing), $ids)) : $ids);
	}

	private function order_filters(): array
	{
		$allowed = ['state' => ['all', 'active', 'previous']];
		$out = [];
		foreach ($allowed as $key => $values) { $value = isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : ''; $out[$key] = in_array($value, $values, true) ? $value : 'all'; }
		foreach (['tracking_number', 'phone_number', 'search'] as $key) $out[$key] = isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
		foreach (['start_date', 'end_date'] as $key) $out[$key] = isset($_GET[$key]) && is_scalar($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
		$paged = isset($_GET['paged']) && is_scalar($_GET['paged']) ? absint($_GET['paged']) : 0;
		$page_num = isset($_GET['page_num']) && is_scalar($_GET['page_num']) ? absint($_GET['page_num']) : 0;
		$out['page'] = max(1, $paged ?: $page_num);
		return $out;
	}

	private function fetch_orders(): array
	{
		$filters = $this->order_filters();
		if (trim(Plugin::opt('api_key', '')) === '') return ['orders' => [], 'total' => 0, 'total_orders' => 0, 'pages' => 0, 'page' => 1, 'error' => ''];
		$query = $filters; $query['page'] = $filters['page']; $api = new Api();
		$response = $api->get('/application/orders', $query);
		if (is_wp_error($response)) return ['orders' => [], 'total' => 0, 'total_orders' => null, 'pages' => 0, 'page' => $filters['page'], 'error' => $response->get_error_message()];
		$orders = isset($response['orders']) && is_array($response['orders']) ? $response['orders'] : (isset($response['data']['orders']) && is_array($response['data']['orders']) ? $response['data']['orders'] : []);
		$pages = max(1, absint($response['total_pages'] ?? $response['data']['total_pages'] ?? 1));
		$page = max(1, absint($response['current_page'] ?? $response['data']['current_page'] ?? $filters['page']));
		$total_orders = $pages === 1 ? count($orders) : null;
		if ($pages > 1) {
			$last_orders = $page === $pages ? $orders : null;
			if ($last_orders === null) {
				$last_query = $query; $last_query['page'] = $pages; $last_response = $api->get('/application/orders', $last_query);
				if (! is_wp_error($last_response)) $last_orders = isset($last_response['orders']) && is_array($last_response['orders']) ? $last_response['orders'] : (isset($last_response['data']['orders']) && is_array($last_response['data']['orders']) ? $last_response['data']['orders'] : null);
			}
			if (is_array($last_orders)) $total_orders = (($pages - 1) * Config::ORDERS_PER_PAGE) + count($last_orders);
		}
		return ['orders' => $orders, 'total' => count($orders), 'total_orders' => $total_orders, 'pages' => $pages, 'page' => $page, 'error' => ''];
	}

	private function render_orders(array $result): void
	{
		$f = $this->order_filters();
		echo '<section class="bijak-orders"><div class="bijak-orders-heading"><div><span class="bijak-eyebrow">ORDERS</span><h2>' . esc_html__('Bijak Orders', 'bijak') . '</h2></div><a class="button button-secondary" href="' . esc_url(add_query_arg(['page' => 'bijak-woo'], admin_url('admin.php'))) . '"><span class="dashicons dashicons-update"></span> ' . esc_html__('Refresh', 'bijak') . '</a></div>';
		echo '<form method="get" class="bijak-order-filters"><input type="hidden" name="page" value="bijak-woo"/><input type="search" name="search" value="' . esc_attr($f['search']) . '" placeholder="' . esc_attr__('Search orders', 'bijak') . '"/><input type="text" name="tracking_number" value="' . esc_attr($f['tracking_number']) . '" placeholder="' . esc_attr__('Tracking number', 'bijak') . '"/><input type="text" name="phone_number" value="' . esc_attr($f['phone_number']) . '" placeholder="' . esc_attr__('Phone number', 'bijak') . '"/><select name="state"><option value="all" ' . selected($f['state'], 'all', false) . '>' . esc_html__('Shipping status', 'bijak') . '</option><option value="active" ' . selected($f['state'], 'active', false) . '>' . esc_html__('Active', 'bijak') . '</option><option value="previous" ' . selected($f['state'], 'previous', false) . '>' . esc_html__('Previous', 'bijak') . '</option></select><input type="text" class="bijak-jalali-date" data-jdp name="start_date" value="' . esc_attr($f['start_date']) . '" placeholder="' . esc_attr__('Start date', 'bijak') . '"/><input type="text" class="bijak-jalali-date" data-jdp name="end_date" value="' . esc_attr($f['end_date']) . '" placeholder="' . esc_attr__('End date', 'bijak') . '"/><button class="button button-primary bijak-filter-submit" type="submit"><span class="dashicons dashicons-search" aria-hidden="true"></span><span>' . esc_html__('Filter', 'bijak') . '</span></button><a class="button" href="' . esc_url(admin_url('admin.php?page=bijak-woo')) . '">' . esc_html__('Clear', 'bijak') . '</a></form>';
		if ($result['error']) echo '<div class="notice notice-error inline"><p>' . esc_html($result['error']) . '</p></div>';
		if (empty($result['orders'])) { echo '<div class="bijak-orders-empty"><span class="dashicons dashicons-clipboard"></span><p>' . esc_html__('No orders found for the selected filters.', 'bijak') . '</p></div></section>'; return; }
		echo '<div class="bijak-table-wrap"><table class="widefat bijak-orders-table"><thead><tr><th>' . esc_html__('Order', 'bijak') . '</th><th>' . esc_html__('Counterparty', 'bijak') . '</th><th>' . esc_html__('Destination', 'bijak') . '</th><th>' . esc_html__('Status', 'bijak') . '</th><th>' . esc_html__('Tracking', 'bijak') . '</th><th>' . esc_html__('Created', 'bijak') . '</th><th>' . esc_html__('Updated', 'bijak') . '</th><th>' . esc_html__('Actions', 'bijak') . '</th></tr></thead><tbody>';
		foreach ($result['orders'] as $order) { $title = sanitize_text_field($order['title'] ?? '-'); $status = sanitize_text_field($order['order_status_titles'] ?? $order['order_status'] ?? '-'); $tracking = sanitize_text_field($order['tracking_number'] ?? ''); $uuid = isset($order['uuid']) && is_scalar($order['uuid']) ? sanitize_text_field($order['uuid']) : ''; $details_url = $uuid ? esc_url(Config::PANEL_ORDER_DETAILS_URL . rawurlencode($uuid)) : ''; $tracking_html = $tracking ? '<button type="button" class="bijak-tracking-copy" data-tracking="' . esc_attr($tracking) . '" aria-label="' . esc_attr__('Copy tracking number', 'bijak') . '" title="' . esc_attr__('Copy tracking number', 'bijak') . '">' . esc_html($tracking) . '</button>' : '-'; echo '<tr><td><strong>' . esc_html($title) . '</strong><small dir="ltr">' . esc_html($uuid) . '</small></td><td>' . esc_html($order['counterparty_name'] ?? '-') . '</td><td>' . esc_html($order['destination_city'] ?? '-') . '</td><td><span class="bijak-status">' . esc_html($status) . '</span></td><td dir="ltr">' . $tracking_html . '</td><td dir="ltr">' . esc_html($order['created_at'] ?? '-') . '</td><td dir="ltr">' . esc_html($order['updated_at'] ?? '-') . '</td><td>' . ($details_url ? '<a class="button button-small bijak-order-action" href="' . $details_url . '" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> ' . esc_html__('View in Bijak', 'bijak') . '</a>' : '-') . '</td></tr>'; }
		echo '</tbody></table></div>';
		if ($result['pages'] > 1) $this->render_orders_pagination($result, $f);
		echo '</section>';
	}

	private function render_orders_pagination(array $result, array $filters): void
	{
		$current = (int) $result['page']; $total = (int) $result['pages'];
		$pages = array_unique(array_filter([1, 2, $current - 1, $current, $current + 1, $total - 1, $total], static function ($page) use ($total) { return $page >= 1 && $page <= $total; }));
		sort($pages, SORT_NUMERIC);
		$url = static function (int $page) use ($filters): string { $args = ['page' => 'bijak-woo', 'paged' => $page]; foreach ($filters as $key => $value) if ($value !== '' && $key !== 'page') $args[$key] = $value; return esc_url(add_query_arg($args, admin_url('admin.php'))); };

		echo '<nav class="bijak-pagination" aria-label="' . esc_attr__('Orders pagination', 'bijak') . '">';
		if ($current > 1) echo '<a class="bijak-pagination__nav" href="' . $url($current - 1) . '" aria-label="' . esc_attr__('Previous page', 'bijak') . '" title="' . esc_attr__('Previous page', 'bijak') . '"><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
		else echo '<span class="bijak-pagination__nav is-disabled" aria-hidden="true"><span class="dashicons dashicons-arrow-right-alt2"></span></span>';

		$last = 0;
		foreach ($pages as $page) { if ($last && $page > $last + 1) echo '<span class="bijak-pagination__ellipsis" aria-hidden="true">&hellip;</span>'; echo '<a class="' . ($page === $current ? 'is-current' : '') . '" href="' . $url($page) . '"' . ($page === $current ? ' aria-current="page"' : '') . '>' . esc_html($page) . '</a>'; $last = $page; }

		if ($current < $total) echo '<a class="bijak-pagination__nav" href="' . $url($current + 1) . '" aria-label="' . esc_attr__('Next page', 'bijak') . '" title="' . esc_attr__('Next page', 'bijak') . '"><span class="dashicons dashicons-arrow-left-alt2"></span></a>';
		else echo '<span class="bijak-pagination__nav is-disabled" aria-hidden="true"><span class="dashicons dashicons-arrow-left-alt2"></span></span>';
		echo '</nav>';
	}

	/* ---------- Admin notice ---------- */

	public function maybe_notice_api_key()
	{
		if ( ! current_user_can('manage_options') ) {
			return;
		}
		$key = trim(Plugin::opt('api_key', ''));
		if ( $key !== '' ) {
			return;
		}
		$url = admin_url('admin.php?page=bijak-woo-settings');
		echo '<div class="notice notice-warning is-dismissible">';
		echo '<p><strong>' . esc_html__('Bijak:', 'bijak') . '</strong> ';
		echo wp_kses_post(sprintf(
			/* translators: %s: settings url */
			__('Please enter your <a href="%s">API Key</a>.', 'bijak'),
			esc_url($url)
		));
		echo '</p></div>';
	}
}
