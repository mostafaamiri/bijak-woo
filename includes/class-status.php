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
		$order_id = $this->order_id_from_context($post, $post_type);
		if ($order_id > 0 && ! $this->order_uses_bijak($order_id)) {
			return;
		}

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
		$order_id = $this->order_id_from_context($post);

		if ($order_id <= 0) {
			echo wp_kses_post('<p>' . esc_html__('Order not found.', 'bijak') . '</p>');
			return;
		}
		if (! $this->order_uses_bijak($order_id)) {
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

	private function order_id_from_context($primary, $fallback = null): int
	{
		foreach ([$primary, $fallback] as $candidate) {
			if ($candidate instanceof \WC_Order) {
				return (int) $candidate->get_id();
			}
			if (is_numeric($candidate)) {
				return (int) $candidate;
			}
			if (is_object($candidate) && isset($candidate->ID)) {
				return (int) $candidate->ID;
			}
		}

		return 0;
	}

	private function order_uses_bijak(int $order_id): bool
	{
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		if (! $order instanceof \WC_Order) {
			return false;
		}

		foreach ($order->get_shipping_methods() as $shipping_method) {
			if ($shipping_method->get_method_id() === Config::SHIPPING_METHOD_ID) {
				return true;
			}
		}

		return false;
	}

	private function admin_view_link(string $uuid): string
	{
		$url = Config::PANEL_ORDER_DETAILS_URL . rawurlencode($uuid);
		return '<a class="button button-primary bijak-admin-status__view" href="' . esc_url($url) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-external" aria-hidden="true"></span> ' . esc_html__('View in Bijak', 'bijak') . '</a>';
	}

	private function front_view_url(string $uuid): string
	{
		return Config::PANEL_ORDER_DETAILS_URL . rawurlencode($uuid);
	}

	private function get_status_html(int $order_id, string $context = 'front'): string
	{
		if (! $this->order_uses_bijak($order_id)) {
			return '';
		}

		$uuid = (string) $this->order_meta($order_id, '_bijak_order_uuid', '');

		if ($context === 'admin') {
			$wrap_open  = '<div class="inside bijak-admin-status">';
			$wrap_close = '</div>';

			if (empty($uuid)) {
				return $wrap_open . '<div class="bijak-admin-status__empty"><span class="dashicons dashicons-clock" aria-hidden="true"></span><strong>' . esc_html__('Waiting to be submitted to Bijak.', 'bijak') . '</strong></div>' . $wrap_close;
			}

			$res = $this->api->request('/application/orders/' . $uuid);

			if (is_wp_error($res)) {
				$msg = esc_html($res->get_error_message());
				$html  = $wrap_open . '<div class="bijak-admin-status__notice is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong>' . esc_html__('Failed to fetch status from Bijak', 'bijak') . '</strong><p>' . esc_html__('Details:', 'bijak') . ' ' . $msg . '</p></div>';
				$html .= '<div class="bijak-admin-status__uuid"><span>UUID</span><code dir="ltr">' . esc_html($uuid) . '</code></div>' . $this->admin_view_link($uuid);
				return $html . $wrap_close;
			}
			if (empty($res['success'])) {
				$html  = $wrap_open . '<div class="bijak-admin-status__notice is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong>' . esc_html__('Invalid response from Bijak.', 'bijak') . '</strong></div>';
				$html .= '<div class="bijak-admin-status__uuid"><span>UUID</span><code dir="ltr">' . esc_html($uuid) . '</code></div>' . $this->admin_view_link($uuid);
				return $html . $wrap_close;
			}

			$status_title   = $res['order_status_title'] ?? '';
			$tracking_num   = $res['tracking_number']    ?? null;
			$dest_city_name = $res['demand_info']['destination_city']['city_name'] ?? '';

			$html  = $wrap_open . '<div class="bijak-admin-status__rows">';
			$html .= '<div class="bijak-admin-status__row"><span>' . esc_html__('Status: ', 'bijak') . '</span><strong>' . esc_html($status_title ?: esc_html__('Unknown', 'bijak')) . '</strong></div>';
			$html .= '<div class="bijak-admin-status__row"><span>' . esc_html__('Destination: ', 'bijak') . '</span><strong>' . esc_html($dest_city_name ?: '—') . '</strong></div>';
			$html .= '<div class="bijak-admin-status__row"><span>' . esc_html__('Bijak Tracking Code: ', 'bijak') . '</span>';
			$html .= $tracking_num ? '<code dir="ltr">' . esc_html((string) $tracking_num) . '</code>' : '<strong>' . esc_html__('Not issued yet', 'bijak') . '</strong>';
			$html .= '</div></div>';
			$html .= '<div class="bijak-admin-status__uuid"><span>UUID</span><code dir="ltr">' . esc_html($uuid) . '</code></div>';
			$html .= $this->admin_view_link($uuid);
			return $html . $wrap_close;
		}

		$section_open  = '<section class="woocommerce-order-details bijak-front-status" style="margin-top:18px">';
		$section_open .= '<div class="bijak-front-status__header"><h2 class="woocommerce-order-details__title">' . esc_html__('Bijak Shipping Status', 'bijak') . '</h2></div>';
		$section_open .= '<div class="bijak-front-status__body">';
		$section_close = '</div></section>';

		if (empty($uuid)) {
			$html  = $section_open . '<div class="bijak-front-status__empty"><span>' . esc_html__('Status', 'bijak') . '</span><strong>' . esc_html__('Waiting for submission to Bijak', 'bijak') . '</strong></div>';
			return $html . $section_close;
		}

		$view_url = $this->front_view_url($uuid);

		$res = $this->api->request('/application/orders/' . $uuid);

		if (is_wp_error($res)) {
			$msg  = esc_html($res->get_error_message());
			$html = $section_open . '<div class="bijak-front-status__grid">';
			$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Status', 'bijak') . '</span><strong>' . esc_html__('Failed to fetch status', 'bijak') . '</strong></div>';
			$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Details', 'bijak') . '</span><strong>' . $msg . '</strong></div></div>';
			$html .= '<div class="bijak-front-status__actions"><a class="button" href="' . esc_url($view_url) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></div>';
			return $html . $section_close;
		}

		if (empty($res['success'])) {
			$html = $section_open . '<div class="bijak-front-status__grid"><div class="bijak-front-status__row"><span>' . esc_html__('Status', 'bijak') . '</span><strong>' . esc_html__('Invalid response from Bijak', 'bijak') . '</strong></div></div>';
			$html .= '<div class="bijak-front-status__actions"><a class="button" href="' . esc_url($view_url) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></div>';
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

		$html  = $section_open . '<div class="bijak-front-status__grid">';
		$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Status', 'bijak') . '</span><strong>' . esc_html($status_title ?: __('Unknown', 'bijak')) . '</strong></div>';
		$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Destination', 'bijak') . '</span><strong>' . esc_html($dest_city_name ?: '—') . '</strong></div>';
		$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Delivery place', 'bijak') . '</span><strong>' . $place_label . '</strong></div>';
		$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Shipping cost', 'bijak') . '</span><strong>' . esc_html(number_format_i18n((float) $order_sum)) . ' ' . esc_html__('Toman', 'bijak') . '</strong></div>';
		$html .= '<div class="bijak-front-status__row"><span>' . esc_html__('Bijak tracking code', 'bijak') . '</span><strong>';
		$html .= $tracking_num
			? '<span class="bijak-code" style="direction:ltr;display:inline-block">' . esc_html((string) $tracking_num) . '</span>'
			: esc_html__('Not issued yet', 'bijak');
		$html .= '</strong></div></div>';
		$html .= '<div class="bijak-front-status__actions"><a class="button" href="' . esc_url($view_url) . '" target="_blank" rel="noopener">' . esc_html__('View in Bijak', 'bijak') . '</a></div>';

		return $html . $section_close;
	}
}
