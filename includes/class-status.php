<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Status_Display
{
	/** @var Api */
	private $api;

	public function __construct(Api $api)
	{
		$this->api = $api;
	}

	public function register()
	{
		add_action('woocommerce_order_details_after_order_table', [$this, 'render_after_table'], 20, 1);

		// Legacy order editor and HPOS order editor.
		add_action('add_meta_boxes', [$this, 'add_metabox']);
		add_action('add_meta_boxes_shop_order', [$this, 'add_metabox']);
		add_action('add_meta_boxes_woocommerce_page_wc-orders', [$this, 'add_metabox']);
	}

	public function add_metabox($post_type = '', $post = null)
	{
		$hpos_screen = function_exists('wc_get_page_screen_id')
			? wc_get_page_screen_id('shop-order')
			: 'woocommerce_page_wc-orders';

		add_meta_box(
			'bijak_status',
			__('Bijak Shipping Status', 'bijak'),
			[$this, 'render_admin_box'],
			'shop_order',
			'side',
			'high'
		);

		if ($hpos_screen && $hpos_screen !== 'shop_order') {
			add_meta_box(
				'bijak_status',
				__('Bijak Shipping Status', 'bijak'),
				[$this, 'render_admin_box'],
				$hpos_screen,
				'side',
				'high'
			);
		}
	}

	public function render_admin_box($post)
	{
		if ($post instanceof \WC_Order) {
			$order_id = (int) $post->get_id();
		} elseif (is_numeric($post)) {
			$order_id = (int) $post;
		} elseif (is_object($post) && isset($post->ID)) {
			$order_id = (int) $post->ID;
		} else {
			$order_id = 0;
		}

		if ($order_id <= 0) {
			echo wp_kses_post('<p>' . esc_html__('Order not found.', 'bijak') . '</p>');
			return;
		}

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

	private function order_meta(int $order_id, string $key, $default = '')
	{
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		if ($order instanceof \WC_Order) {
			$value = $order->get_meta($key, true);
			return $value === '' ? $default : $value;
		}

		$value = get_post_meta($order_id, $key, true);
		return $value === '' ? $default : $value;
	}

	private function get_status_html(int $order_id, string $context = 'front'): string
	{
		$uuid = (string) $this->order_meta($order_id, '_bijak_order_uuid', '');

		if ($context === 'admin') {
			$wrap_open  = '<div class="inside" style="padding:8px 0;">';
			$wrap_close = '</div>';
			$h_open = '<strong>';
			$h_close = '</strong>';

			if (empty($uuid)) {
				return $wrap_open . $h_open . esc_html__('Waiting to be submitted to Bijak.', 'bijak') . $h_close . $wrap_close;
			}

			$res = $this->api->request('/application/orders/' . $uuid);

			if (is_wp_error($res)) {
				$msg = esc_html($res->get_error_message());
				$html  = $wrap_open . $h_open . esc_html__('Failed to fetch status from Bijak', 'bijak') . $h_close . '<br/>';
				$html .= esc_html__('Details:', 'bijak') . ' ' . $msg . '<br/>';
				$html .= '<small>UUID: <code style="direction:ltr">' . esc_html($uuid) . '</code></small>';
				return $html . $wrap_close;
			}
			if (empty($res['success'])) {
				$html  = $wrap_open . $h_open . esc_html__('Invalid response from Bijak.', 'bijak') . $h_close . '<br/>';
				$html .= '<small>UUID: <code style="direction:ltr">' . esc_html($uuid) . '</code></small>';
				return $html . $wrap_close;
			}

			$status_title   = $res['order_status_title'] ?? '';
			$tracking_num   = $res['tracking_number']    ?? null;
			$dest_city_name = $res['demand_info']['destination_city']['city_name'] ?? '';

			$html  = $wrap_open;
			$html .= '<div><span>' . $h_open . esc_html__('Status: ', 'bijak') . $h_close . '</span>' . esc_html($status_title ?: esc_html__('Unknown', 'bijak')) . '</div>';
			$html .= '<div><span>' . $h_open . esc_html__('Destination: ', 'bijak') . $h_close . '</span>' . esc_html($dest_city_name ?: '—') . '</div>';
			$html .= '<div><span>' . $h_open . esc_html__('Bijak Tracking Code: ', 'bijak') . $h_close . '</span>';
			$html .= $tracking_num ? '<code style="direction:ltr">' . esc_html((string) $tracking_num) . '</code>' : esc_html__('Not issued yet', 'bijak');
			$html .= '</div>';
			$html .= '<div><small>UUID: <code style="direction:ltr">' . esc_html($uuid) . '</code></small></div>';
			return $html . $wrap_close;
		}

		$section_open  = '<section class="woocommerce-order-details" style="margin-top:18px">';
		$section_open .= '<h2 class="woocommerce-order-details__title">' . esc_html__('Bijak Shipping Status', 'bijak') . '</h2>';
		$table_open    = '<div class="responsive-table"><table class="woocommerce-table woocommerce-table--order-details shop_table order_details"><tbody>';
		$table_close   = '</tbody></table></div>';
		$section_close = '</section>';

		if (empty($uuid)) {
			$html  = $section_open . $table_open;
			$html .= '<tr><th scope="row" class="product-name">' . esc_html__('Status', 'bijak') . '</th><td class="product-total"><span>' . esc_html__('Waiting for submission to Bijak', 'bijak') . '</span></td></tr>';
			$html .= $table_close;

			$html .= '<p><a class="button" href="' . esc_url(Config::PANEL_ORDERS_URL) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></p>';
			return $html . $section_close;
		}

		$res = $this->api->request('/application/orders/' . $uuid);

		if (is_wp_error($res)) {
			$msg  = esc_html($res->get_error_message());
			$html = $section_open . $table_open;
			$html .= '<tr><th scope="row" class="product-name">' . esc_html__('Status', 'bijak') . '</th><td class="product-total"><span>' . esc_html__('Failed to fetch status', 'bijak') . '</span></td></tr>';
			$html .= '<tr><th scope="row" class="product-name">' . esc_html__('Details', 'bijak') . '</th><td class="product-total"><span>' . $msg . '</span></td></tr>';
			$html .= $table_close;
			$html .= '<p><a class="button" href="' . esc_url(Config::PANEL_ORDERS_URL) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></p>';
			return $html . $section_close;
		}

		if (empty($res['success'])) {
			$html = $section_open . $table_open;
			$html .= '<tr><th scope="row" class="product-name">' . esc_html__('Status', 'bijak') . '</th><td class="product-total"><span>' . esc_html__('Invalid response from Bijak', 'bijak') . '</span></td></tr>';
			$html .= $table_close;
			$html .= '<p><a class="button" href="' . esc_url(Config::PANEL_ORDERS_URL) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></p>';
			return $html . $section_close;
		}

		$status_title     = isset($res['order_status_title']) ? (string) $res['order_status_title'] : '';
		$tracking_num     = $res['tracking_number'] ?? null;
		$dest_city_name   = $res['demand_info']['destination_city']['city_name'] ?? '';
		$order_sum        = $res['payment_info']['sum'] ?? 0;
		$is_door_delivery = !empty($res['demand_info']['is_door_delivery']);

		$place_label = $is_door_delivery
			? esc_html__('Door-to-door delivery', 'bijak')
			: sprintf(
				/* translators: %s: destination city */
				esc_html__('Delivery to terminal in %s', 'bijak'),
				esc_html($dest_city_name ?: '')
			);

		$html  = $section_open . $table_open;

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">' . esc_html__('Status', 'bijak') . '</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html($status_title ?: esc_html__('Unknown', 'bijak')) . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">' . esc_html__('Destination', 'bijak') . '</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html($dest_city_name ?: '—') . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">' . esc_html__('Delivery place', 'bijak') . '</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . $place_label . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">' . esc_html__('Shipping cost', 'bijak') . '</th>';
		$html .= '<td class="woocommerce-table__product-total product-total"><span>' . esc_html(number_format_i18n((float) $order_sum)) . ' ' . esc_html__('Toman', 'bijak') . '</span></td>';
		$html .= '</tr>';

		$html .= '<tr class="woocommerce-table__line-item order_item">';
		$html .= '<th scope="row" class="woocommerce-table__product-name product-name">' . esc_html__('Bijak tracking code', 'bijak') . '</th>';
		$html .= '<td class="woocommerce-table__product-total product-total">';
		$html .= $tracking_num
			? '<span class="bijak-code" style="direction:ltr;display:inline-block">' . esc_html((string) $tracking_num) . '</span>'
			: '<span>' . esc_html__('Not issued yet', 'bijak') . '</span>';
		$html .= '</td></tr>';

		$html .= $table_close;

		$html .= '<p><a class="button" href="' . esc_url(Config::PANEL_ORDERS_URL) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></p>';

		return $html . $section_close;
	}
}
