<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Central configuration for Bijak service integrations.
 *
 * Keep environment-specific endpoints in this class so a deployment only
 * needs one code change when an integration host changes.
 */
final class Config
{
	public const API_BASE = 'https://api.bijak.ir/';
	public const API_TIMEOUT = 15;

	public const WEBSITE_URL = 'https://bijak.ir';
	public const WEB_APP_URL = 'https://my.bijak.ir/';
	public const DESTINATION_GUIDE_URL = 'https://bijak.ir/destination-guide/';
	public const PANEL_API_KEYS_URL = 'https://my.bijak.ir/panel/organizational/apiKeys';
	public const PANEL_WALLET_URL = 'https://my.bijak.ir/panel/profile/wallet';
	public const PANEL_ORDERS_URL = 'https://my.bijak.ir/panel/myOrders';
	public const PANEL_ORDER_DETAILS_URL = 'https://my.bijak.ir/panel/orderDetails/';
	public const SHIPPING_METHOD_ID = 'bijak_pay_at_dest';
	public const ORDERS_PER_PAGE = 10;
	public const BIJAK_LENGTH_META = '_bijak_shipping_length';
	public const BIJAK_WIDTH_META  = '_bijak_shipping_width';
	public const BIJAK_HEIGHT_META = '_bijak_shipping_height';
	public const BIJAK_WEIGHT_META = '_bijak_shipping_weight';

	// Use the production picker URL in released builds. The local URL is for this test installation.
	public const MAP_PICKER_URL = 'https://location-picker.bijak.ir/picker';
	public const LOCATION_PICKER_STATE_BYTES = 32;
	public const LOCATION_PICKER_STATE_TTL = 600;

	public static function map_picker_origin(): string
	{
		return self::origin_from_url(self::MAP_PICKER_URL);
	}

	public static function origin_from_url(string $url): string
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

	public static function neshan_map_url(float $lat, float $lng, int $zoom = 16): string
	{
		return add_query_arg([
			'lat' => rtrim(rtrim(number_format($lat, 7, '.', ''), '0'), '.'),
			'lng' => rtrim(rtrim(number_format($lng, 7, '.', ''), '0'), '.'),
		], 'https://nshn.ir/');
	}
}
