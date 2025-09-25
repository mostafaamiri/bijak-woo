<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Status_Display
{
	public function __construct(private Api $api) {}

	public function register()
	{
		add_action('woocommerce_order_details_after_order_table', [$this, 'render_after_table'], 20, 1);

		add_action('add_meta_boxes_shop_order', [$this, 'add_metabox']);
	}

	/* ------------------------------------------------------------------ */

	public function add_metabox()
	{
		add_meta_box(
			'bijak_status',
			'وضعیت ارسال با بیجک',
			[$this, 'render_admin_box'],
			'shop_order',
			'side',
			'high'
		);
	}

	public function render_admin_box($post)
	{
		$order_id = is_numeric($post) ? (int) $post : (int) $post->ID;
		echo wp_kses_post($this->get_status_html($order_id, 'admin'));
	}

	public function render_after_table($order)
	{
		if (! $order) {
			return;
		}
		$order_id = is_object($order) ? (int) $order->get_id() : (int) $order;
		echo wp_kses_post($this->get_status_html($order_id, 'front'));
	}

	/* ------------------------------------------------------------------ */

	private function get_status_html(int $order_id, string $context = 'front'): string
	{
		$uuid = get_post_meta($order_id, '_bijak_order_uuid', true);

		/* ---------- Admin view (metabox) ---------- */
		if ($context === 'admin') {
			$wrap_open  = '<div class="inside" style="padding:8px 0;">';
			$wrap_close = '</div>';
			$h_open = '<strong>';
			$h_close = '</strong>';

			if (empty($uuid)) {
				return $wrap_open . $h_open . 'در انتظار ثبت سفارش در بیجک.' . $h_close . $wrap_close;
			}

			$res = $this->api->request('/application/order_data', 'POST', ['order_uuid' => $uuid]);

			if (is_wp_error($res)) {
				$msg = esc_html($res->get_error_message());
				$html  = $wrap_open . $h_open . 'خطا در دریافت وضعیت از بیجک' . $h_close . '<br/>';
				$html .= 'جزئیات: ' . $msg . '<br/>';
				$html .= '<small>UUID: <code style="direction:ltr">' . esc_html($uuid) . '</code></small>';
				return $html . $wrap_close;
			}
			if (empty($res['success'])) {
				$html  = $wrap_open . $h_open . 'پاسخ نامعتبر از بیجک.' . $h_close . '<br/>';
				$html .= '<small>UUID: <code style="direction:ltr">' . esc_html($uuid) . '</code></small>';
				return $html . $wrap_close;
			}

			$status_title   = $res['order_status_title'] ?? '';
			$tracking_num   = $res['tracking_number']    ?? null;
			$dest_city_name = $res['demand_info']['destination_city']['city_name'] ?? '';

			$html  = $wrap_open;
			$html .= '<div><span>' . $h_open . 'وضعیت: ' . $h_close . '</span>' . esc_html($status_title ?: 'نامشخص') . '</div>';
			$html .= '<div><span>' . $h_open . 'مقصد: ' . $h_close . '</span>' . esc_html($dest_city_name ?: '—') . '</div>';
			$html .= '<div><span>' . $h_open . 'کد پیگیری بیجک: ' . $h_close . '</span>';
			$html .= $tracking_num ? '<code style="direction:ltr">' . esc_html($tracking_num) . '</code>' : 'هنوز صادر نشده';
			$html .= '</div>';
			$html .= '<div><small>UUID: <code style="direction:ltr">' . esc_html($uuid) . '</code></small></div>';
			return $html . $wrap_close;
		}

		$section_open  = '<section class="woocommerce-order-details" style="margin-top:18px">';
		$section_open .= '<h2 class="woocommerce-order-details__title">وضعیت ارسال با بیجک</h2>';
		$table_open    = '<div class="responsive-table"><table class="woocommerce-table woocommerce-table--order-details shop_table order_details"><tbody>';
		$table_close   = '</tbody></table></div>';
		$section_close = '</section>';

		if (empty($uuid)) {
			$html  = $section_open . $table_open;
			$html .= '<tr><th scope="row" class="product-name">وضعیت</th><td class="product-total"><span>در انتظار ثبت در بیجک</span></td></tr>';
			$html .= $table_close;

			$html .= '<p><a class="button" href="' . esc_url('https://my.bijak.ir/panel/myOrders') . '" target="_blank" rel="noopener">مشاهده سفارش در بیجک</a></p>';
			return $html . $section_close;
		}

		$res = $this->api->request('/application/order_data', 'POST', ['order_uuid' => $uuid]);

		if (is_wp_error($res)) {
			$msg  = esc_html($res->get_error_message());
			$html = $section_open . $table_open;
			$html .= '<tr><th scope="row" class="product-name">وضعیت</th><td class="product-total"><span>خطا در دریافت وضعیت</span></td></tr>';
			$html .= '<tr><th scope="row" class="product-name">جزئیات</th><td class="product-total"><span>' . $msg . '</span></td></tr>';
			$html .= $table_close;
			$html .= '<p><a class="button" href="' . esc_url('https://my.bijak.ir/panel/myOrders') . '" target="_blank" rel="noopener">مشاهده سفارش در بیجک</a></p>';
			return $html . $section_close;
		}

		if (empty($res['success'])) {
			$html = $section_open . $table_open;
			$html .= '<tr><th scope="row" class="product-name">وضعیت</th><td class="product-total"><span>پاسخ نامعتبر از بیجک</span></td></tr>';
			$html .= $table_close;
			$html .= '<p><a class="button" href="' . esc_url('https://my.bijak.ir/panel/myOrders') . '" target="_blank" rel="noopener">مشاهده سفارش در بیجک</a></p>';
			return $html . $section_close;
		}

		$status_title   = isset($res['order_status_title']) ? (string) $res['order_status_title'] : '';
		$tracking_num   = $res['tracking_number'] ?? null;
		$dest_city_name = $res['demand_info']['destination_city']['city_name'] ?? '';
		$order_sum = $res['payment_info']['sum'];
		$is_door_delivery = $res['demand_info']['is_door_delivery'];

		if ($is_door_delivery) {
			$is_door_delivery = "تحویل درب منزل";
		} else {
			$is_door_delivery = "تحویل به باربری " . $dest_city_name;
		}

		$html  = $section_open . $table_open;


		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">وضعیت</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html($status_title ?: 'نامشخص') . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">مقصد</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html($dest_city_name ?: '—') . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">محل تحویل</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html($is_door_delivery) . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">هزینه ارسال</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html($order_sum) . ' تومان</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">کد پیگیری بیجک</th>';
		$html .= '<td class="woocommerce-table__product-total product-total">';
		$html .= $tracking_num
			? '<span class="bijak-code" style="direction:ltr;display:inline-block">' . esc_html((string) $tracking_num) . '</span>'
			: '<span>هنوز صادر نشده</span>';
		$html .= '</td></tr>';

		$html .= $table_close;

		$html .= '<p><a class="button" href="' . esc_url('https://my.bijak.ir/panel/myOrders') . '" target="_blank" rel="noopener">مشاهده سفارش در بیجک</a></p>';

		return $html . $section_close;
	}
}
