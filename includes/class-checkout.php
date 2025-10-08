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
		$chosen = '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if (isset($_POST['shipping_method'][0])) {
			$chosen = sanitize_text_field(wp_unslash($_POST['shipping_method'][0]));
		}
		// phpcs:enable
		return ($chosen !== '') && str_starts_with($chosen, 'bijak_pay_at_dest');
	}

	public function render_box(): void
	{
		if (self::$printed) {
			return;
		}
		self::$printed = true;

		?>
		<div class="bijak-box" style="display:none;">
			<h2 class="bijak-box__title"><?php esc_html_e('Shipping with Bijak', 'bijak'); ?></h2>

			<p class="description">
				<?php esc_html_e('Your order will be shipped via Bijak.', 'bijak'); ?><br>
				<a href="https://bijak.ir" target="_blank" rel="noopener">
					<button type="button" class="button button-primary">
						<?php esc_html_e('Visit Bijak Website', 'bijak'); ?>
					</button>
				</a>
			</p>

			<p class="form-row form-row-wide">
				<label for="bijak_dest_city">
					<?php esc_html_e('Destination city', 'bijak'); ?> <abbr class="required">*</abbr>
				</label>
				<select id="bijak_dest_city"
					name="bijak_dest_city"
					class="input-select wc-enhanced-select address-field update_totals_on_change"
					data-placeholder="<?php esc_attr_e('— Select —', 'bijak'); ?>">
					<option value=""></option>
				</select>
			</p>

			<p class="form-row form-row-first">
				<label for="bijak_is_door_delivery">
					<input type="hidden" name="bijak_is_door_delivery" value="0">
					<input type="checkbox"
						id="bijak_is_door_delivery"
						name="bijak_is_door_delivery"
						value="1"
						class="input-checkbox"
						checked>
					<?php esc_html_e('Door-to-door delivery', 'bijak'); ?>
				</label>
			</p>

			<div class="bijak-estimate">
				<div id="bijak_estimate_result" class="bijak-estimate__result"></div>
			</div>
		</div>
		<?php
	}

	public function validate_required(): void
	{
		if (! $this->is_bijak_chosen()) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$dest = isset($_POST['bijak_dest_city']) ? sanitize_text_field(wp_unslash($_POST['bijak_dest_city'])) : '';
		// phpcs:enable

		if ($dest === '') {
			wc_add_notice(__('Please select a destination city for Bijak shipping.', 'bijak'), 'error');
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

		foreach (['bijak_dest_city', 'bijak_is_door_delivery'] as $key) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if (isset($_POST[$key])) {
				$raw = wp_unslash($_POST[$key]);
				$val = is_array($raw)
					? array_map('sanitize_text_field', $raw)
					: sanitize_text_field($raw);
				// phpcs:enable
				update_post_meta($order_id, '_' . $key, $val);
			}
		}

		wc_get_order($order_id)?->add_order_note(__('Bijak: Shipping via Bijak.', 'bijak'));
	}
}
