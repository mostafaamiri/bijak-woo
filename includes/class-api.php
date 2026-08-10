<?php

namespace BIJAK\BijakWoo;

use WP_Error;

if (! defined('ABSPATH')) exit;

class Api
{
	/**
	 * Make a GET request with a safely encoded query string.
	 *
	 * @return array|WP_Error
	 */
	public function get(string $endpoint, array $query = [])
	{
		$query = array_filter($query, static function ($value) {
			return $value !== '' && $value !== null;
		});

		if (! empty($query)) {
			$endpoint = add_query_arg($query, $endpoint);
		}

		return $this->request($endpoint);
	}

	/**
	 * Make a request to the Bijak API.
	 *
	 * @param string $endpoint API endpoint (e.g. /application/profile)
	 * @param string $method   HTTP method (GET, POST, etc.)
	 * @param mixed  $body     Request body data
	 * @return array|WP_Error  API response array or WP_Error object on failure
	 */
	public function request($endpoint, $method = 'GET', $body = null)
	{
		$base = rtrim(Config::API_BASE, '/');
		$url  = $base . $endpoint;

		$headers = ['Content-Type' => 'application/json; charset=utf-8'];

		$key = trim(Plugin::opt('api_key', ''));
		if ($key !== '') {
			$headers['X-API-Key'] = $key;
		}

		$args = [
			'method'  => $method,
			'timeout' => Config::API_TIMEOUT,
			'headers' => $headers,
		];

		if ($body !== null) {
			$args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		$code     = wp_remote_retrieve_response_code($response);
		$raw_body = wp_remote_retrieve_body($response);
		$json     = json_decode($raw_body, true);

		// Success (2xx)
		if ($code >= 200 && $code < 300) {
			return is_array($json) ? $json : [];
		}

		// translators: %d is the HTTP response code returned by the Bijak API.
		$msg = sprintf(__('HTTP error %d', 'bijak'), $code);

		if (is_array($json) && ! empty($json['message'])) {
			$msg .= ': ' . $json['message'];
		}

		return new WP_Error('http', $msg, is_array($json) ? $json : $raw_body);
	}
}
