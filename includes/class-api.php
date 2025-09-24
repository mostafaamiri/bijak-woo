<?php

namespace BIJAK\BijakWoo;

use WP_Error;

if (! defined('ABSPATH')) exit;

class Api
{
	private const API_BASE = 'https://testapi.bijak.ir';
	private const TIMEOUT = 15;

	public function request($endpoint, $method = 'GET', $body = null)
	{
		$base = rtrim(self::API_BASE, '/');
		$url = $base . $endpoint;

		$headers = ['Content-Type' => 'application/json; charset=utf-8'];
		$key = trim(Plugin::opt('api_key', ''));
		if ($key !== '') $headers['Authorization'] = 'Bearer ' . $key;

		$args = [
			'method' => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		];
		if ($body !== null) $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

		$res = wp_remote_request($url, $args);
		if (is_wp_error($res)) return $res;

		$code = wp_remote_retrieve_response_code($res);
		$raw  = wp_remote_retrieve_body($res);
		$json = json_decode($raw, true);

		if ($code >= 200 && $code < 300) return $json;

		$msg = 'HTTP ' . $code;
		if (is_array($json) && ! empty($json['message'])) $msg .= ': ' . $json['message'];
		return new WP_Error('http', $msg, $json ?: $raw);
	}
}
