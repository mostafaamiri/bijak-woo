<?php

namespace BIJAK\BijakWoo;

if ( ! defined('ABSPATH') ) {
	exit;
}

class Admin
{
	public function register()
	{
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_notices', [$this, 'maybe_notice_api_key']);
		add_filter('pre_update_option_' . Plugin::OPT, [$this, 'pre_update_options'], 10, 3);
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
			[$this, 'settings_page'],
			$icon_url,
			25
		);

		add_submenu_page(
			'bijak-woo',
			__('Bijak (Smart Freight)', 'bijak'),
			__('Bijak (Smart Freight)', 'bijak'),
			'manage_options',
			'bijak-woo',
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
				$val = trim((string) Plugin::opt('origin_coords', ''));
				printf(
					'<input type="text" class="regular-text" name="%s[origin_coords]" value="%s" placeholder="35.6971,51.4041" style="direction:ltr" />',
					esc_attr(Plugin::OPT),
					esc_attr($val)
				);
				echo '<p class="description">' . esc_html__('Format: latitude,longitude (e.g. 35.6971,51.4041)', 'bijak') . '</p>';
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
						'<textarea class="large-text" rows="3" name="%s[%s]">%s</textarea>',
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
		$resp  = $api->request('/application/terminals/?type=origin');
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
		if ( $address_included ) {
			$out['origin_address'] = sanitize_textarea_field($in['origin_address'] ?? '');
		}

		if ( array_key_exists('origin_coords', $in) ) {
			$out['origin_coords'] = sanitize_text_field($in['origin_coords'] ?? '');
		}

		if ( array_key_exists('self_delivery', $in) ) {
			$out['self_delivery'] = ( ! empty($in['self_delivery']) && $in['self_delivery'] === 'yes' ) ? 'yes' : 'no';
		}

		if ( array_key_exists('delivery_day', $in) ) {
			$val = $in['delivery_day'] ?? '';
			$out['delivery_day'] = in_array($val, ['first_working', 'second_working'], true)
				? $val
				: ($old['delivery_day'] ?? 'first_working');
		}

		$api_key = trim($out['api_key'] ?? '');

		if ( $address_included && isset($out['origin_address']) && $out['origin_address'] === '' && $api_key !== '' ) {
			$api = new Api();
			$res = $api->request('/application/profile');
			if ( ! is_wp_error($res) && ! empty($res['data']) ) {
				$d = $res['data'];

				if ( ! empty($d['address']) ) {
					$out['origin_address'] = sanitize_textarea_field((string) $d['address']);
				}

				if ( isset($d['lat']) && isset($d['lng']) ) {
					$lat = (float) $d['lat'];
					$lng = (float) $d['lng'];
					$out['origin_coords'] = sanitize_text_field($lat . ',' . $lng);
				}

				$cid = isset($d['city_id']) ? intval($d['city_id']) : 0;
				if ( $cid > 0 ) {
					$out['origin_city_id'] = $cid;
				}
			}
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
		$wallet    = isset($d['inventory']) ? (int) $d['inventory'] : 0;

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
				$wallet    = isset($d['inventory']) ? (int) $d['inventory'] : 0;

				if ( $full_name !== '' ) {
					$new_arr['supplier_full_name'] = sanitize_text_field($full_name);
				}
				if ( $phone !== '' ) {
					$new_arr['supplier_phone'] = sanitize_text_field($phone);
				}
				$new_arr['wallet_inventory'] = max(0, $wallet);

				$address_is_empty_now =
					empty($new_arr['origin_address']) &&
					empty($old_arr['origin_address']);

				if ( $address_is_empty_now ) {
					if ( ! empty($d['address']) ) {
						$new_arr['origin_address'] = sanitize_textarea_field((string) $d['address']);
					}

					if ( isset($d['lat']) && isset($d['lng']) ) {
						$new_arr['origin_coords'] = sanitize_text_field(((float) $d['lat']) . ',' . ((float) $d['lng']));
					}

					$cid = isset($d['city_id']) ? intval($d['city_id']) : 0;
					if ( $cid > 0 ) {
						$new_arr['origin_city_id'] = $cid;
					}
				}
			}
		}

		if ( $new_key === '' && $old_key !== '' ) {
			unset($new_arr['supplier_full_name'], $new_arr['supplier_phone']);
			$new_arr['wallet_inventory'] = 0;
		}

		return $new_arr;
	}

	public function settings_page()
	{
		// Extra safety: ensure only admins can view this page directly.
		if ( ! current_user_can('manage_options') ) {
			return;
		}

		$api_key = trim(Plugin::opt('api_key', ''));
		$profile = ['ok' => false, 'msg' => '', 'full_name' => '', 'phone' => '', 'wallet' => 0];

		if ( $api_key !== '' ) {
			$profile = $this->refresh_profile_options();
		}

		echo '<div class="wrap"><h1>' . esc_html__('Bijak (Smart Freight)', 'bijak') . '</h1>';

		if ( $profile['msg'] !== '' ) {
			$cls = $profile['ok'] ? 'notice-info' : 'notice-error';
			echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html($profile['msg']) . '</p></div>';
		}

		echo '<div class="bijak-card">';
		echo '<h2 class="bijak-heading">' . esc_html__('Connect to Bijak (API Key)', 'bijak') . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields(Plugin::OPT);

		printf(
			'<input type="text" class="regular-text" style="width:360px;direction:ltr" name="%s[api_key]" value="%s" placeholder="API Key"/>',
			esc_attr(Plugin::OPT),
			esc_attr($api_key)
		);

		submit_button(esc_html__('Save API Key', 'bijak'), 'primary', 'submit', false);
		$api_url = 'https://my.bijak.ir/panel/organizational/apiKeys';
		echo '<span>&nbsp;&nbsp;</span>';
		echo '<a class="button button-secondary" href="' . esc_url($api_url) . '" target="_blank" rel="noopener">' . esc_html__('Create API key', 'bijak') . '</a>';
		echo '</form>';
		echo '<p class="bijak-muted">' . esc_html__('After saving, name/phone/wallet will be synced. If "Origin address" is empty, it will be filled from your Bijak profile on first sync.', 'bijak') . '</p>';
		echo '</div>';

		if ( $api_key === '' ) {
			$full_name = '';
			$phone     = '';
			$wallet    = 0;
		} else {
			$full_name = $profile['ok'] ? $profile['full_name'] : sanitize_text_field(Plugin::opt('supplier_full_name', ''));
			$phone     = $profile['ok'] ? $profile['phone']     : sanitize_text_field(Plugin::opt('supplier_phone', ''));
			$wallet    = $profile['ok'] ? (int) $profile['wallet'] : (int) Plugin::opt('wallet_inventory', 0);
		}

		echo '<div class="bijak-card">';
		echo '<h2 class="bijak-heading">' . esc_html__('Bijak account info', 'bijak') . '</h2>';
		echo '<div class="bijak-row">';
		echo '<div class="bijak-col"><label>' . esc_html__('Account holder name', 'bijak') . '</label><br/>';
		printf('<input type="text" class="regular-text" value="%s" readonly>', esc_attr($full_name ?: ''));
		echo '<p class="bijak-muted">' . esc_html__('Name in your Bijak profile', 'bijak') . '</p></div>';

		echo '<div class="bijak-col"><label>' . esc_html__('Phone number', 'bijak') . '</label><br/>';
		printf('<input type="text" class="regular-text" value="%s" readonly>', esc_attr($phone ?: ''));
		echo '<p class="bijak-muted">' . esc_html__('Phone in Bijak profile', 'bijak') . '</p></div>';

		echo '<div class="bijak-col"><label>' . esc_html__('Wallet balance', 'bijak') . '</label><br/>';
		if ( $api_key === '' ) {
			echo '<div class="bijak-badge bijak-inv">—</div>';
		} else {
			$wallet_url = 'https://my.bijak.ir/panel/profile/wallet';
			echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
			echo '<div class="bijak-badge bijak-inv">' . esc_html(number_format_i18n($wallet) . ' ' . __('Toman', 'bijak')) . '</div>';
			echo '<a class="button button-secondary" href="' . esc_url($wallet_url) . '" target="_blank" rel="noopener">' . esc_html__('Top up wallet', 'bijak') . '</a>';
			echo '</div>';
		}
		echo '<p class="bijak-muted">' . esc_html__('Bijak wallet', 'bijak') . '</p></div>';

		echo '</div></div>';

		echo '<div class="bijak-card">';
		echo '<form method="post" action="options.php">';
		settings_fields(Plugin::OPT);
		do_settings_sections(Plugin::OPT);
		submit_button();
		echo '</form></div>';

		echo '</div>';
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
		$url = admin_url('admin.php?page=bijak-woo');
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
