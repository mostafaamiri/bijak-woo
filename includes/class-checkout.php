<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) {
	exit;
}

class Checkout
{
	private static bool $printed = false;

	public function register(): void
	{
		add_action('woocommerce_review_order_after_shipping', [$this, 'render_box'], 10);
		add_action('woocommerce_checkout_process',           [$this, 'validate_required']);
		add_action('woocommerce_checkout_update_order_meta', [$this, 'save_meta']);
	}

	private function is_bijak_chosen(): bool
	{
		$raw_methods = isset($_POST['shipping_method']) ? wp_unslash($_POST['shipping_method']) : [];
		$methods = is_array($raw_methods) ? $raw_methods : [$raw_methods];
		foreach ($methods as $method) {
			if (is_scalar($method) && strpos(sanitize_text_field((string) $method), 'bijak_pay_at_dest') === 0) {
				return true;
			}
		}

		// Some checkout integrations omit shipping_method from the final POST.
		// Use WooCommerce's chosen method only when the request contained no method.
		if (empty($methods) && function_exists('WC') && WC()->session) {
			$session_methods = WC()->session->get('chosen_shipping_methods', []);
			$session_methods = is_array($session_methods) ? $session_methods : [$session_methods];
			foreach ($session_methods as $method) {
				if (is_scalar($method) && strpos(sanitize_text_field((string) $method), 'bijak_pay_at_dest') === 0) {
					return true;
				}
			}
		}

		return false;
	}

	public function render_box(): void
	{
		if (self::$printed) {
			return;
		}
		self::$printed = true;

		$session = function_exists('WC') && WC() ? WC()->session : null;
		$session_city = $session ? (int) $session->get('bijak_dest_city_id', 0) : 0;
		$location_city = $session ? (int) $session->get('bijak_destination_city_id', 0) : 0;
		$lat = $session ? $session->get('bijak_destination_lat', '') : '';
		$lng = $session ? $session->get('bijak_destination_lng', '') : '';
		$has_location = $this->valid_location($lat, $lng) && $session_city > 0 && $location_city === $session_city;
		$door_checked = !$session || (string) $session->get('bijak_is_door_delivery', '1') === '1';
		?>
		<div class="bijak-box" style="display:none;">
			<div class="bijak-box__header">
				<div>
					<h2 class="bijak-box__title"><?php esc_html_e('Shipping with Bijak', 'bijak'); ?></h2>
					<p class="bijak-box__intro"><?php esc_html_e('Your order will be shipped via Bijak.', 'bijak'); ?></p>
				</div>
				<a class="bijak-box__website" href="<?php echo esc_url(Config::WEBSITE_URL); ?>" target="_blank" rel="noopener">
					<?php esc_html_e('Visit Bijak Website', 'bijak'); ?>
				</a>
			</div>

			<div class="bijak-box__content">
				<div class="form-row form-row-wide bijak-destination-field">
					<label for="bijak_dest_city">
						<?php esc_html_e('Destination city', 'bijak'); ?> <abbr class="required">*</abbr>
					</label>
					<select id="bijak_dest_city"
						name="bijak_dest_city"
						class="input-select wc-enhanced-select address-field update_totals_on_change"
						data-placeholder="<?php esc_attr_e('— Select —', 'bijak'); ?>">
						<option value=""></option>
					</select>
					<p class="bijak-destination-city-notice" role="alert" aria-live="assertive" style="display:none"></p>
				</div>

				<div class="bijak-delivery-mode">
					<label class="bijak-delivery-mode__toggle" for="bijak_is_door_delivery">
						<input type="hidden" name="bijak_is_door_delivery" value="0">
						<input type="checkbox"
							id="bijak_is_door_delivery"
							name="bijak_is_door_delivery"
							value="1"
							class="input-checkbox"
							<?php checked($door_checked, true); ?>>
						<span><?php esc_html_e('Door-to-door delivery', 'bijak'); ?></span>
					</label>
					<div class="bijak-delivery-mode__details">
						<p><?php esc_html_e('If door-to-door delivery is unchecked, your shipment will be delivered to the destination city cargo terminal.', 'bijak'); ?></p>
						<a href="<?php echo esc_url(Config::DESTINATION_GUIDE_URL); ?>" target="_blank" rel="noopener">
							<?php esc_html_e('Guide to destination city cargo terminals', 'bijak'); ?>
						</a>
					</div>
				</div>

				<div class="bijak-location-picker" <?php echo $has_location ? 'data-location-selected="1"' : 'data-location-selected="0"'; ?>>
					<div>
						<button type="button" class="button bijak-location-picker__open">
							<?php esc_html_e('Select delivery location on map', 'bijak'); ?>
						</button>
						<p class="bijak-location-picker__status" aria-live="polite">
							<?php if ($has_location) : ?>
								<strong><?php esc_html_e('Location selected', 'bijak'); ?></strong>
							<?php else : ?>
								<?php esc_html_e('Location not selected', 'bijak'); ?>
							<?php endif; ?>
						</p>
					</div>
				</div>

				<div class="bijak-estimate">
					<div id="bijak_estimate_result" class="bijak-estimate__result" aria-live="polite"></div>
				</div>
			</div>
		</div>
		<?php
	}

	public function validate_required(): void
	{
		if (! $this->is_bijak_chosen()) {
			return;
		}

		foreach (Helpers::cart_dimension_errors() as $dimension_error) {
			// translators: %1$s is the product name, %2$s is a comma-separated list of missing dimensions.
			wc_add_notice(
				sprintf(
					__('Bijak shipping dimensions are incomplete for "%1$s": %2$s.', 'bijak'),
					$dimension_error['name'],
					implode(', ', $dimension_error['missing'])
				),
				'error'
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$has_destination_field = array_key_exists('bijak_dest_city', $_POST);
		$dest_raw = $has_destination_field ? wp_unslash($_POST['bijak_dest_city']) : '';
		$dest = is_scalar($dest_raw) && $dest_raw !== '' ? absint($dest_raw) : 0;
		// phpcs:enable

		// Some checkout templates submit this custom field as an empty value even
		// though its selected value was already persisted by the price AJAX call.
		if (!$dest && function_exists('WC') && WC()->session) {
			$dest = (int) WC()->session->get('bijak_dest_city_id', 0);
		}

		if ($dest > 0 && function_exists('WC') && WC()->session) {
			$session_dest = (int) WC()->session->get('bijak_dest_city_id', 0);
			if ($session_dest > 0 && $session_dest !== (int) $dest) {
				foreach (['bijak_destination_lat', 'bijak_destination_lng', 'bijak_destination_address', 'bijak_destination_city_id', 'bijak_location_picker_state'] as $key) {
					WC()->session->__unset($key);
				}
				WC()->session->set('bijak_dest_city_id', (int) $dest);
			}
		}

		if (!$dest) {
			wc_add_notice(__('Please select a destination city for Bijak shipping.', 'bijak'), 'error');
		}

		$door_raw = isset($_POST['bijak_is_door_delivery']) ? wp_unslash($_POST['bijak_is_door_delivery']) : '';
		$door_values = is_array($door_raw) ? $door_raw : [$door_raw];
		$door = '';
		foreach ($door_values as $door_value) {
			if (is_scalar($door_value)) {
				$door = sanitize_text_field((string) $door_value);
				if ($door === '1') {
					break;
				}
			}
		}
		if ($door === '' && function_exists('WC') && WC()->session) {
			$door = (string) WC()->session->get('bijak_is_door_delivery', '1');
		}
		if ($door === '1' && $dest > 0 && function_exists('WC') && WC()->session) {
			$session = WC()->session;
			$location_city = (int) $session->get('bijak_destination_city_id', 0);
			$lat = $session->get('bijak_destination_lat', '');
			$lng = $session->get('bijak_destination_lng', '');
			if (!$this->valid_location($lat, $lng) || $location_city !== (int) $dest) {
				wc_add_notice(__('Please select the delivery location on the map.', 'bijak'), 'error');
			}
		}

		if (Plugin::opt('self_delivery', 'yes') !== 'yes') {
			$origin_address = trim((string) Plugin::opt('origin_address', ''));
			if ($origin_address === '') {
				wc_add_notice(__('Origin address is required in Bijak settings for pickup.', 'bijak'), 'error');
			}
		}
	}

	public function save_meta(int $order_id): void
	{
		if (! $this->is_bijak_chosen()) {
			return;
		}

		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;

		foreach (['bijak_dest_city', 'bijak_is_door_delivery'] as $key) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if (isset($_POST[$key])) {
				$raw = wp_unslash($_POST[$key]);
				$val = is_array($raw)
					? array_map('sanitize_text_field', $raw)
					: sanitize_text_field($raw);
				// phpcs:enable

				if ($order instanceof \WC_Order) {
					$order->update_meta_data('_' . $key, $val);
				} else {
					update_post_meta($order_id, '_' . $key, $val);
				}
			}
		}

		// Fallback: if checkout POST missed custom fields, persist session values.
		if (function_exists('WC') && WC()->session) {
			$session_dest = (string) WC()->session->get('bijak_dest_city_id', '');
			$session_door = (string) WC()->session->get('bijak_is_door_delivery', '');

			if ($session_dest !== '') {
				if ($order instanceof \WC_Order) {
					$order->update_meta_data('_bijak_dest_city', $session_dest);
				} else {
					update_post_meta($order_id, '_bijak_dest_city', $session_dest);
				}
			}

			if ($session_door !== '') {
				if ($order instanceof \WC_Order) {
					$order->update_meta_data('_bijak_is_door_delivery', $session_door);
				} else {
					update_post_meta($order_id, '_bijak_is_door_delivery', $session_door);
				}
			}

			$session_city = (int) WC()->session->get('bijak_dest_city_id', 0);
			$location_city = (int) WC()->session->get('bijak_destination_city_id', 0);
			$lat = WC()->session->get('bijak_destination_lat', '');
			$lng = WC()->session->get('bijak_destination_lng', '');
			if ($session_city > 0 && $location_city === $session_city && $this->valid_location($lat, $lng)) {
				$location_meta = [
					'_bijak_destination_lat' => (float) $lat,
					'_bijak_destination_lng' => (float) $lng,
					'_bijak_destination_city_id' => $location_city,
				];
				foreach ($location_meta as $key => $value) {
					if ($order instanceof \WC_Order) {
						$order->update_meta_data($key, $value);
					} else {
						update_post_meta($order_id, $key, $value);
					}
				}
			}
		}

		if ($order instanceof \WC_Order) {
			$order->save();
			$order->add_order_note(__('Bijak: Shipping via Bijak.', 'bijak'));
		} else {
			$legacy_order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
			if ($legacy_order instanceof \WC_Order) {
				$legacy_order->add_order_note(__('Bijak: Shipping via Bijak.', 'bijak'));
			}
		}
	}

	private function valid_location($lat, $lng): bool
	{
		if (!is_scalar($lat) || !is_scalar($lng) || $lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
			return false;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		return is_finite($lat) && is_finite($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
	}
}
