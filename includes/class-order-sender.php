<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Order_Sender
{
	public function __construct(private Api $api) {}

	public function register()
	{
		add_action('woocommerce_checkout_order_processed', [$this, 'queue_send_order'], 15, 3);
		add_action('woocommerce_thankyou', [$this, 'maybe_send_on_thankyou'], 1);
		add_action('bijak_send_order', [$this, 'send_to_bijak']);

		// 1) پس از تکمیل پرداخت (برخی درگاه‌ها این را صدا می‌زنند)
		add_action('woocommerce_payment_complete', [$this, 'maybe_pay_from_wallet']);

		// 2) پوشش سناریوهایی که payment_complete فراخوانی نمی‌شود:
		//    وقتی وضعیت به processing/completed رسید هم اقدام کن.
		add_action('woocommerce_order_status_processing', [$this, 'maybe_pay_from_wallet_by_status'], 10, 1);
		add_action('woocommerce_order_status_completed',  [$this, 'maybe_pay_from_wallet_by_status'], 10, 1);
	}

	private function order_uses_bijak(\WC_Order $order): bool
	{
		foreach ($order->get_shipping_methods() as $sm) {
			if ($sm->get_method_id() === 'bijak_pay_at_dest') {
				return true;
			}
		}
		return false;
	}

	private function get_bijak_mode(\WC_Order $order): string
	{
		foreach ($order->get_shipping_methods() as $sm) {
			$mode = $sm->get_meta('bijak_mode');
			if ($mode) {
				return (string) $mode; // 'prepay' | 'postpay'
			}
		}
		return 'postpay';
	}

	private function ensure_supplier_from_options_or_api(): array
	{
		$full_name  = sanitize_text_field(Plugin::opt('supplier_full_name', ''));
		$phone_norm = Helpers::normalize_phone(Plugin::opt('supplier_phone', ''));

		if ($full_name !== '' && $phone_norm !== '') {
			return ['full_name' => $full_name, 'phone' => $phone_norm];
		}

		$res = $this->api->request('/application/profile');
		if (! is_wp_error($res) && ! empty($res['data'])) {
			$d = $res['data'];
			$full_name  = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
			$phone_norm = Helpers::normalize_phone($d['username'] ?? '');

			if ($full_name !== '' && $phone_norm !== '') {
				$opts = get_option(Plugin::OPT, []);
				if (! is_array($opts)) $opts = [];
				$opts['supplier_full_name'] = sanitize_text_field($full_name);
				$opts['supplier_phone']     = sanitize_text_field($phone_norm);

				if (isset($d['inventory'])) {
					$opts['wallet_inventory'] = max(0, (int) $d['inventory']);
				}
				update_option(Plugin::OPT, $opts);
				return ['full_name' => $full_name, 'phone' => $phone_norm];
			}
		}

		return [
			'full_name' => sanitize_text_field(Plugin::opt('supplier_full_name', '')),
			'phone'     => Helpers::normalize_phone(Plugin::opt('supplier_phone', ''))
		];
	}

	public function queue_send_order($order_id, $posted_data, $order)
	{
		if (get_post_meta($order_id, '_bijak_order_uuid', true)) {
			return;
		}
		if (! $order instanceof \WC_Order) {
			$order = wc_get_order($order_id);
		}
		if (! $order || ! $this->order_uses_bijak($order)) {
			return;
		}

		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action('bijak_send_order', ['order_id' => $order_id], 'bijak');
		} else {
			$this->send_to_bijak(['order_id' => $order_id]);
		}
	}

	public function maybe_send_on_thankyou($order_id)
	{
		if (! $order_id || get_post_meta($order_id, '_bijak_order_uuid', true)) {
			return;
		}
		$order = wc_get_order($order_id);
		if (! $order || ! $this->order_uses_bijak($order)) {
			return;
		}
		$this->send_to_bijak(['order_id' => $order_id]);
	}

	public function send_to_bijak($args)
	{
		$order_id = intval($args['order_id'] ?? 0);
		$order = wc_get_order($order_id);
		if (! $order || ! $this->order_uses_bijak($order)) {
			return;
		}

		$logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
		$log = function ($m) use ($logger) {
			if ($logger) {
				$logger->info($m, ['source' => 'bijak-woo']);
			}
		};


		$supplier = $this->ensure_supplier_from_options_or_api();
		$supplier_name  = $supplier['full_name'];
		$supplier_phone = $supplier['phone'];

		if ($supplier_name === '' || $supplier_phone === '') {
			$supplier_name  = sanitize_text_field(get_bloginfo('name'));
			$supplier_phone = Helpers::normalize_phone(get_option('woocommerce_store_phone', ''));
			if ($supplier_name === '' || $supplier_phone === '') {
				$order->add_order_note('Bijak: نام یا تلفن فرستنده مشخص نیست');
				update_post_meta($order_id, '_bijak_status', 'error');
				update_post_meta($order_id, '_bijak_error_message', 'نام یا تلفن فرستنده مشخص نیست');
				return;
			}
		}

		$rec_name  = trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
		if ($rec_name === '') {
			$rec_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}
		$rec_phone = Helpers::normalize_phone($order->get_billing_phone());
		if ($rec_name === '' || $rec_phone === '') {
			$order->add_order_note('Bijak: نام/تلفن گیرنده در فاکتور موجود نیست');
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', 'نام/تلفن گیرنده در فاکتور موجود نیست');
			return;
		}

		$origin_city_id = intval(Plugin::opt('origin_city_id', 0));
		if (! $origin_city_id) {
			$order->add_order_note('Bijak: شهر مبدأ تنظیم نشده است');
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', 'شهر مبدأ تنظیم نشده است');
			return;
		}

		$dest_city_id = intval(get_post_meta($order_id, '_bijak_dest_city', true));
		if (! $dest_city_id) {
			$order->add_order_note('Bijak: مقصد انتخاب نشده است');
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', 'مقصد انتخاب نشده است');
			return;
		}

		$line_id = Helpers::resolve_line_id($this->api, $origin_city_id, $dest_city_id);
		if (! $line_id) {
			$order->add_order_note('Bijak: خط حمل یافت نشد');
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', 'خط حمل یافت نشد');
			return;
		}

		/* ---------- goods ---------- */
		$goods_details = Helpers::build_goods_details_from_order($order);
		if (empty($goods_details)) {
			$order->add_order_note('Bijak: هیچ آیتمی با ابعاد معتبر یافت نشد');
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', 'هیچ آیتمی با ابعاد معتبر یافت نشد');
			return;
		}

		$self_delivery = Plugin::opt('self_delivery', 'yes') === 'yes';

		$is_door      = get_post_meta($order_id, '_bijak_is_door_delivery', true) === '1';
		$goods_value  = max(1000, (int) round($order->get_total()));

		$shipping_a1 = trim((string) $order->get_shipping_address_1());
		$shipping_a2 = trim((string) $order->get_shipping_address_2());
		$billing_a1  = trim((string) $order->get_billing_address_1());
		$billing_a2  = trim((string) $order->get_billing_address_2());
		$addr1 = $shipping_a1 !== '' ? $shipping_a1 : $billing_a1;
		$addr2 = $shipping_a1 !== '' ? $shipping_a2 : $billing_a2;
		$address_concat = trim($addr1 . ($addr2 !== '' ? ' - ' . $addr2 : ''));

		$origin_src    = null;
		$delivery_time = null;
		if (! $self_delivery) {
			$sendData = $this->api->request("/application/send_data/{$origin_city_id}");
			if (is_wp_error($sendData) || empty($sendData['data'])) {
				$order->add_order_note('Bijak: خطا در دریافت روزهای کاری/بازه زمانی.');
				update_post_meta($order_id, '_bijak_status', 'error');
				update_post_meta($order_id, '_bijak_error_message', 'ناتوان در دریافت send_data');
				return;
			}
			$data = $sendData['data'];

			$interval_id = 0;
			if (!empty($data['send_interval_list']) && is_array($data['send_interval_list'])) {
				$firstInterval = reset($data['send_interval_list']);
				if (is_array($firstInterval) && !empty($firstInterval['id'])) {
					$interval_id = (int) $firstInterval['id'];
				}
			}

			$day_id = 0;
			if (!empty($data['working_days']) && is_array($data['working_days'])) {
				$mode = Plugin::opt('delivery_day', 'first_working');
				$index = ($mode === 'second_working') ? 1 : 0;
				$chosen = $data['working_days'][$index] ?? $data['working_days'][0] ?? null;
				if (is_array($chosen) && !empty($chosen['id'])) {
					$day_id = (int) $chosen['id'];
				}
			}

			if ($interval_id === 0 || $day_id === 0) {
				$order->add_order_note('Bijak: روز کاری یا بازه زمانی معتبر یافت نشد.');
				update_post_meta($order_id, '_bijak_status', 'error');
				update_post_meta($order_id, '_bijak_error_message', 'day/interval نامعتبر');
				return;
			}

			$origin_address = trim((string) Plugin::opt('origin_address', ''));
			[$lat, $lon] = Helpers::parse_coords(Plugin::opt('origin_coords', ''));
			$origin_src = [
				'location_longitude' => is_null($lon) ? 0 : $lon,
				'location_latitude'  => is_null($lat) ? 0 : $lat,
				'address'            => $origin_address,
				'address_detail'     => '',
			];
			$delivery_time = [
				'calendar_day_id' => $day_id,
				'send_interval_id' => $interval_id,
			];
		}

		$destination_src = $is_door ? [
			'location_longitude' => 0,
			'location_latitude'  => 0,
			'address'            => $address_concat,
			'address_detail'     => '',
		] : null;

		// postpay => is_cod=true | prepay => is_cod=false
		$mode   = $this->get_bijak_mode($order);
		$is_cod = ($mode !== 'prepay');

		$body = [
			'supplier_info' => [
				'supplier_full_name'   => $supplier_name,
				'supplier_phonenumber' => $supplier_phone,
			],
			'demand_info' => [
				'demand_full_name'     => $rec_name,
				'demand_phonenumber'   => $rec_phone,
			],
			'role' => 'SUPPLIER',
			'payment_info' => [
				'is_cod' => $is_cod,
				'has_secure_payment' => false,
				'secure_payment' => [
					'account_number' => '',
					'account_name' => '',
					'secure_payment_amount' => 0,
				],
			],
			'goods_info' => [
				'goods_value'   => $goods_value,
				'goods_details' => $goods_details,
			],
			'shipment_info' => [
				'origin_info' => [
					'self_delivery'        => $self_delivery,
					'src'                   => $origin_src,
					'local_transport_cost'  => 0,
					'delivery_time'         => $delivery_time,
				],
				'destination_info' => [
					'is_door_delivery' => $is_door,
					'src'              => $destination_src,
				],
				'line_id' => $line_id,
			],
		];

		$res = $this->api->request('/application/orders', 'POST', $body);

		if (is_wp_error($res)) {
			$err = $res->get_error_message();
			$api = $res->get_error_data();
			$log("Bijak order failed #{$order_id} | {$err} | api=" . (is_string($api) ? $api : wp_json_encode($api, JSON_UNESCAPED_UNICODE)));
			$order->add_order_note('Bijak: ارسال ناموفق. ' . $err);
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', $err);
			return;
		}

		if (! empty($res['success']) && ! empty($res['order_uuid'])) {
			update_post_meta($order_id, '_bijak_order_uuid', sanitize_text_field($res['order_uuid']));
			update_post_meta($order_id, '_bijak_status', 'success');
			update_post_meta($order_id, '_bijak_error_message', '');
			$order->add_order_note('Bijak: سفارش ثبت شد. UUID: ' . $res['order_uuid']);
		} else {
			$order->add_order_note('Bijak: پاسخ نامعتبر. ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
			update_post_meta($order_id, '_bijak_status', 'error');
			update_post_meta($order_id, '_bijak_error_message', 'پاسخ نامعتبر از بیجک');
		}
	}

	/** یک هلسپر مرکزی برای زدن پرداخت کیف‌پول (تا دوبار صدا نشود) */
	private function pay_wallet_core(\WC_Order $order): void
	{
		if (! $this->order_uses_bijak($order)) return;
		if ($this->get_bijak_mode($order) !== 'prepay') return;

		// جلوگیری از تکرار
		if (get_post_meta($order->get_id(), '_bijak_wallet_paid_state', true) === 'Paid') {
			return;
		}
		if (get_post_meta($order->get_id(), '_bijak_wallet_pay_attempted', true)) {
			// قبلاً تلاش شده؛ اگر نیاز داری دوباره بزنی این guard رو بردار
			return;
		}

		$uuid = get_post_meta($order->get_id(), '_bijak_order_uuid', true);
		if (empty($uuid)) {
			// اگر هنوز در بیجک ثبت نشده، همین الآن ثبت کن
			$this->maybe_send_on_thankyou($order->get_id());
			$uuid = get_post_meta($order->get_id(), '_bijak_order_uuid', true);
			if (empty($uuid)) {
				$order->add_order_note('Bijak Wallet Pay: UUID موجود نیست، پرداخت کیف‌پول ممکن نشد.');
				return;
			}
		}

		update_post_meta($order->get_id(), '_bijak_wallet_pay_attempted', 1);

		$res = $this->api->request('/application/pay_order', 'POST', [
			'order_uuid' => $uuid,
			'use_wallet' => true,
		]);

		if (is_wp_error($res)) {
			$order->add_order_note('Bijak Wallet Pay: خطا - ' . $res->get_error_message());
			return;
		}

		$state   = (string) ($res['state'] ?? '');
		$success = ! empty($res['success']);

		if ($success && $state === 'Paid') {
			update_post_meta($order->get_id(), '_bijak_wallet_paid_state', 'Paid');
			$order->add_order_note('Bijak Wallet Pay: سفارش با موفقیت از کیف‌پول پرداخت شد.');
			return;
		}

		if ($success && $state === 'Paying' && !empty($res['data']['payment_link'])) {
			$link = (string) $res['data']['payment_link'];
			update_post_meta($order->get_id(), '_bijak_wallet_paid_state', 'Paying');
			$order->add_order_note('Bijak Wallet Pay: موجودی کافی نیست. لینک پرداخت: ' . $link);
			return;
		}

		$msg = (string) ($res['message'] ?? 'پرداخت ناموفق/نامشخص.');
		$order->add_order_note('Bijak Wallet Pay: ' . $msg);
	}

	public function maybe_pay_from_wallet($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) return;
		$this->pay_wallet_core($order);
	}

	public function maybe_pay_from_wallet_by_status($order_id)
	{
		$order = wc_get_order($order_id);
		if (! $order) return;
		$this->pay_wallet_core($order);
	}
}
