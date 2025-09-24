<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
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
			'بیجک (ترابری هوشمند)',
			'بیجک (ارسال کالا)',
			'manage_options',
			'bijak-woo',
			[$this, 'settings_page'],
			$icon_url,
			25
		);

		add_submenu_page(
			'bijak-woo',
			'بیجک (ترابری هوشمند)',
			'بیجک (ترابری هوشمند)',
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

		add_settings_section('origin', 'تنظیمات مبدأ ارسال', '__return_false', Plugin::OPT);

		add_settings_field(
			'origin_city_id',
			'شهر مبدأ',
			[$this, 'render_origin_select'],
			Plugin::OPT,
			'origin'
		);

		add_settings_field(
			'self_delivery',
			'خودم میارم باربری؟',
			function () {
				$val = Plugin::opt('self_delivery', 'yes') === 'yes';
				printf('<input type="hidden" name="%s[self_delivery]" value="no">', esc_attr(Plugin::OPT));
				printf(
					'<label><input type="checkbox" name="%s[self_delivery]" value="yes" %s> بله</label>',
					esc_attr(Plugin::OPT),
					checked($val, true, false)
				);
			},
			Plugin::OPT,
			'origin'
		);

		$this->add_text_field('origin_address', 'آدرس دقیق مبدأ', '', 'textarea');

		add_settings_field(
			'origin_coords',
			'مختصات مبدأ (lat,lon)',
			function () {
				$val = trim((string) Plugin::opt('origin_coords', ''));
				printf(
					'<input type="text" class="regular-text" name="%s[origin_coords]" value="%s" placeholder="35.6971,51.4041" style="direction:ltr" />',
					esc_attr(Plugin::OPT),
					esc_attr($val)
				);
				echo '<p class="description">فرمت: <code>latitude,longitude</code> (مثال: <code>35.6971,51.4041</code>)</p>';
			},
			Plugin::OPT,
			'origin'
		);


		add_settings_field(
			'delivery_day',
			'روز تحویل به بیجک',
			function () {
				$val = Plugin::opt('delivery_day', 'first_working');
				$options = [
					'first_working'  => 'اولین روز کاری',
					'second_working' => 'دومین روز کاری',
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

		if (! is_wp_error($resp) && ! empty($resp['data']) && is_array($resp['data'])) {
			foreach ($resp['data'] as $c) {
				$cities[] = [
					'id'   => intval($c['city_id']),
					'name' => sanitize_text_field($c['city_name']),
					'prov' => sanitize_text_field($c['city_province_name']),
				];
			}
		}

		if (empty($cities)) {
			echo '<em style="color:#a00">خطا در واکشی شهرهای مبدأ از API.</em>';
			return;
		}

		printf('<select name="%s[origin_city_id]">', esc_attr(Plugin::OPT));
		echo '<option value="">— انتخاب —</option>';
		foreach ($cities as $c) {
			$lbl = esc_html($c['name'] . ' (' . $c['prov'] . ')');
			printf(
				'<option value="%d" %s>%s</option>',
				$c['id'],
				selected($selected, $c['id'], false),
				$lbl
			);
		}
		echo '</select>';
	}

	/* ---------- Sanitizer ---------- */

	public function sanitize_opts($in)
	{
		$old = get_option(Plugin::OPT, []);
		if (! is_array($old)) $old = [];

		$in  = is_array($in) ? $in : [];
		$out = $old;

		if (array_key_exists('api_key', $in)) {
			$out['api_key'] = sanitize_text_field($in['api_key'] ?? '');
		}

		if (array_key_exists('origin_city_id', $in)) {
			$out['origin_city_id'] = intval($in['origin_city_id'] ?? 0);
		}

		$address_included = array_key_exists('origin_address', $in);
		if ($address_included) {
			$out['origin_address'] = sanitize_textarea_field($in['origin_address'] ?? '');
		}

		if (array_key_exists('origin_coords', $in)) {
			$out['origin_coords'] = sanitize_text_field($in['origin_coords'] ?? '');
		}

		if (array_key_exists('self_delivery', $in)) {
			$out['self_delivery'] = (! empty($in['self_delivery']) && $in['self_delivery'] === 'yes') ? 'yes' : 'no';
		}

		if (array_key_exists('delivery_day', $in)) {
			$val = $in['delivery_day'] ?? '';
			$out['delivery_day'] = in_array($val, ['first_working', 'second_working'], true)
				? $val
				: ($old['delivery_day'] ?? 'first_working');
		}

		$api_key = trim($out['api_key'] ?? '');
		if ($address_included && isset($out['origin_address']) && $out['origin_address'] === '' && $api_key !== '') {
			$api = new Api();
			$res = $api->request('/application/profile');
			if (! is_wp_error($res) && ! empty($res['data'])) {
				$d = $res['data'];

				if (! empty($d['address'])) {
					$out['origin_address'] = sanitize_textarea_field((string) $d['address']);
				}

				if (isset($d['lat']) && isset($d['lng'])) {
					$lat = (float) $d['lat'];
					$lng = (float) $d['lng'];
					$out['origin_coords'] = sanitize_text_field($lat . ',' . $lng);
				}

				$cid = isset($d['city_id']) ? intval($d['city_id']) : 0;
				if ($cid > 0) {
					$out['origin_city_id'] = $cid;
				}
			}
		}

		return $out;
	}

	/* ---------- Page ---------- */

	private function refresh_profile_options(): array
	{
		$key = trim(Plugin::opt('api_key', ''));
		if ($key === '') {
			return ['ok' => false, 'msg' => 'API Key تنظیم نشده است.', 'full_name' => '', 'phone' => '', 'wallet' => 0];
		}

		$api = new Api();
		$res = $api->request('/application/profile');

		if (is_wp_error($res) || empty($res['data'])) {
			$msg = is_wp_error($res) ? $res->get_error_message() : 'پاسخ نامعتبر از API';
			return ['ok' => false, 'msg' => 'خطا در دریافت پروفایل: ' . $msg, 'full_name' => '', 'phone' => '', 'wallet' => 0];
		}

		$d = $res['data'];
		$full_name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
		$phone     = Helpers::normalize_phone($d['username'] ?? '');
		$wallet    = isset($d['inventory']) ? (int) $d['inventory'] : 0;

		$opts = get_option(Plugin::OPT, []);
		if (! is_array($opts)) $opts = [];
		$opts['supplier_full_name'] = sanitize_text_field($full_name);
		$opts['supplier_phone']     = sanitize_text_field($phone);
		$opts['wallet_inventory']   = max(0, $wallet);
		update_option(Plugin::OPT, $opts);

		return ['ok' => true, 'msg' => 'اطلاعات پروفایل از بیجک به‌روزرسانی شد.', 'full_name' => $full_name, 'phone' => $phone, 'wallet' => $wallet];
	}

	public function pre_update_options($new, $old, $option)
	{
		if ($option !== Plugin::OPT) {
			return $new;
		}
		$new_arr = is_array($new) ? $new : [];
		$old_arr = is_array($old) ? $old : [];

		$new_key = isset($new_arr['api_key']) ? trim((string) $new_arr['api_key']) : '';
		$old_key = isset($old_arr['api_key']) ? trim((string) $old_arr['api_key']) : '';

		if ($new_key !== '' && $new_key !== $old_key) {
			$api = new Api();
			$res = $api->request('/application/profile');

			if (! is_wp_error($res) && ! empty($res['data'])) {
				$d = $res['data'];

				$full_name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
				$phone     = Helpers::normalize_phone($d['username'] ?? '');
				$wallet    = isset($d['inventory']) ? (int) $d['inventory'] : 0;

				if ($full_name !== '') $new_arr['supplier_full_name'] = sanitize_text_field($full_name);
				if ($phone     !== '') $new_arr['supplier_phone']     = sanitize_text_field($phone);
				$new_arr['wallet_inventory'] = max(0, $wallet);

				$address_is_empty_now =
					empty($new_arr['origin_address']) &&
					empty($old_arr['origin_address']);

				if ($address_is_empty_now) {
					if (! empty($d['address'])) {
						$new_arr['origin_address'] = sanitize_textarea_field((string) $d['address']);
					}

					if (isset($d['lat']) && isset($d['lng'])) {
						$new_arr['origin_coords'] = sanitize_text_field(((float)$d['lat']) . ',' . ((float)$d['lng']));
					}

					$cid = isset($d['city_id']) ? intval($d['city_id']) : 0;
					if ($cid > 0) {
						$new_arr['origin_city_id'] = $cid;
					}
				}
			}
		}

		return $new_arr;
	}

	public function settings_page()
	{
		$profile = ['ok' => false, 'msg' => '', 'full_name' => '', 'phone' => '', 'wallet' => 0];
		if (trim(Plugin::opt('api_key', '')) !== '') {
			$profile = $this->refresh_profile_options();
		}

		echo '<style>
		.bijak-card{background:#fff;border:1px solid #e3e3e3;border-radius:8px;padding:16px;margin:16px 0}
		.bijak-row{display:flex;gap:12px;flex-wrap:wrap}
		.bijak-col{flex:1 1 260px;min-width:260px}
		.bijak-heading{margin:12px 0 8px;font-size:16px}
		.bijak-muted{color:#666;font-size:12px}
		.bijak-badge{display:inline-block;padding:6px 10px;border-radius:8px;background:#f5f5f5}
		.bijak-inv{font-weight:600}
		</style>';

		echo '<div class="wrap"><h1>بیجک (ترابری هوشمند)</h1>';

		if ($profile['msg'] !== '') {
			$cls = $profile['ok'] ? 'notice-info' : 'notice-error';
			echo '<div class="notice ' . esc_attr($cls) . ' is-dismissible"><p>' . esc_html($profile['msg']) . '</p></div>';
		}

		echo '<div class="bijak-card">';
		echo '<h2 class="bijak-heading">اتصال به بیجک (API Key)</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields(Plugin::OPT);
		$current_key = esc_attr(Plugin::opt('api_key', ''));
		printf(
			'<input type="text" class="regular-text" style="width:360px;direction:ltr" name="%s[api_key]" value="%s" placeholder="API Key"/>',
			esc_attr(Plugin::OPT),
			$current_key
		);
		submit_button('ذخیره API Key', 'primary', 'submit', false);
		echo '</form>';
		echo '<p class="bijak-muted">پس از ذخیره، نام/تلفن/موجودی به‌روزرسانی می‌شود. اگر «آدرس مبدأ» خالی بوده باشد، دفعهٔ اول از پروفایل بیجک پر می‌شود.</p>';
		echo '</div>';

		$full_name = $profile['ok'] ? $profile['full_name'] : sanitize_text_field(Plugin::opt('supplier_full_name', ''));
		$phone     = $profile['ok'] ? $profile['phone']     : sanitize_text_field(Plugin::opt('supplier_phone', ''));
		$wallet    = $profile['ok'] ? (int)$profile['wallet'] : (int) Plugin::opt('wallet_inventory', 0);

		echo '<div class="bijak-card">';
		echo '<h2 class="bijak-heading">اطلاعات حساب بیجک</h2>';
		echo '<div class="bijak-row">';
		echo '<div class="bijak-col"><label>نام مالک حساب</label><br/>';
		printf('<input type="text" class="regular-text" value="%s" readonly>', esc_attr($full_name));
		echo '<p class="bijak-muted">نام در پروفایل کاربر بیجک</p></div>';

		echo '<div class="bijak-col"><label>شماره موبایل</label><br/>';
		printf('<input type="text" class="regular-text" value="%s" readonly>', esc_attr($phone));
		echo '<p class="bijak-muted">تلفن پروفایل بیجک</p></div>';

		echo '<div class="bijak-col"><label>موجودی کیف پول</label><br/>';
		printf('<div class="bijak-badge bijak-inv">%s تومان</div>', number_format_i18n($wallet));
		echo '<p class="bijak-muted">کیف پول بیجک</p></div>';

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
		if (! current_user_can('manage_options')) {
			return;
		}
		$key = trim(Plugin::opt('api_key', ''));
		if ($key !== '') {
			return;
		}
		$url = admin_url('admin.php?page=bijak-woo');
		echo '<div class="notice notice-warning is-dismissible">';
		echo '<p><strong>بیجک:</strong> لطفاً <a href="' . esc_url($url) . '">API Key</a> خود را وارد کنید.</p>';
		echo '</div>';
	}
}
