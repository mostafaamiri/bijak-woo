<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;

class Dashboard
{
    public function __construct(private Api $api) {}

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'add_widget']);
    }

    public function add_widget(): void
    {
        // Title in English to satisfy Plugin Check warnings; fully translatable.
        wp_add_dashboard_widget(
            'bijak_wallet_widget',
            __('Bijak — Smart Freight', 'bijak'),
            [$this, 'render_widget']
        );
    }

    public function render_widget(): void
    {
        $api_key = trim(Plugin::opt('api_key', ''));
        $has_key = ($api_key !== '');

        $profile = ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
        if ($has_key) {
            $profile = $this->refresh_profile();
        }

        $wallet = $has_key ? (int) $profile['wallet']       : 0;
        $full   = $has_key ? (string) $profile['full_name'] : '';
        $phone  = $has_key ? (string) $profile['phone']     : '';

        $wallet_url = 'https://my.bijak.ir/panel/profile/wallet';
        $orders_url = 'https://my.bijak.ir/panel/myOrders';

        echo '<div class="bijak-dash">';

        if (! $has_key) {
            echo '<p><strong>' . esc_html__('API Key is not set.', 'bijak') . '</strong> ';
            echo esc_html__('To view your wallet balance and orders, please enter your API Key on', 'bijak') . ' ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=bijak-woo')) . '">'
                . esc_html__('Bijak Settings', 'bijak') . '</a>.';
            echo '</p>';
        }

        echo '<div class="row">';
        echo '<div class="item"><span class="label">' . esc_html__('Account Holder Name', 'bijak') . '</span><span class="val">'
            . esc_html($full !== '' ? $full : '—') . '</span></div>';
        echo '<div class="item"><span class="label">' . esc_html__('Phone Number', 'bijak') . '</span><span class="val" style="direction:ltr">'
            . esc_html($phone !== '' ? $phone : '—') . '</span></div>';
        echo '<div class="item"><span class="label">' . esc_html__('Wallet Balance', 'bijak') . '</span><span class="val"><span class="badge">';
        if ($has_key) {
            echo esc_html(number_format_i18n($wallet) . ' ' . __('Toman', 'bijak'));
        } else {
            echo esc_html('—');
        }
        echo '</span></span></div>';
        echo '</div>';

        echo '<div class="actions">';
        echo '<a class="btn" href="' . esc_url($wallet_url) . '" target="_blank" rel="noopener">' . esc_html__('Top up Wallet', 'bijak') . '</a>';
        echo '<a class="btn" href="' . esc_url($orders_url) . '" target="_blank" rel="noopener">' . esc_html__('View Orders', 'bijak') . '</a>';
        echo '</div>';

        echo '</div>';
    }

    private function refresh_profile(): array
    {
        $key = trim(Plugin::opt('api_key', ''));
        if ($key === '') {
            return ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
        }

        $res = $this->api->request('/application/profile');

        if (is_wp_error($res) || empty($res['data'])) {
            return ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
        }

        $d = $res['data'];
        $full_name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        $phone     = Helpers::normalize_phone($d['username'] ?? '');
        $wallet    = isset($d['inventory']) ? (int) $d['inventory'] : 0;

        // persist to options (mirrors Admin behavior)
        $opts = get_option(Plugin::OPT, []);
        if (! is_array($opts)) $opts = [];
        $opts['supplier_full_name'] = sanitize_text_field($full_name);
        $opts['supplier_phone']     = sanitize_text_field($phone);
        $opts['wallet_inventory']   = max(0, $wallet);
        update_option(Plugin::OPT, $opts);

        return ['ok' => true, 'full_name' => $full_name, 'phone' => $phone, 'wallet' => $wallet];
    }
}
