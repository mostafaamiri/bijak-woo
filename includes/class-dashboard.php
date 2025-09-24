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
        wp_add_dashboard_widget(
            'bijak_wallet_widget',
            'بیجک ترابری هوشمند',
            [$this, 'render_widget']
        );
    }

    public function render_widget(): void
    {
        $api_key = trim(Plugin::opt('api_key', ''));
        $has_key = ($api_key !== '');

        $profile = ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
        if ($has_key) {
            $admin = new Admin();
            $profile = $this->refresh_profile_like_admin($admin);
        }

        $wallet = $has_key ? (int) $profile['wallet']    : 0;
        $full   = $has_key ? (string) $profile['full_name'] : '';
        $phone  = $has_key ? (string) $profile['phone']  : '';

        $wallet_url = 'https://my.bijak.ir/panel/profile/wallet';
        $orders_url = 'https://my.bijak.ir/panel/myOrders';

        echo '<style>
            .bijak-dash {display:flex;flex-direction:column;gap:10px}
            .bijak-dash .row{display:flex;gap:12px;flex-wrap:wrap}
            .bijak-dash .item{flex:1 1 220px;min-width:220px}
            .bijak-dash .label{color:#555;font-size:.92em}
            .bijak-dash .val{display:block;margin-top:4px;font-weight:600}
            .bijak-dash .badge{display:inline-block;background:#f6f7f7;border:1px solid #e5e5e5;border-radius:999px;padding:6px 10px}
            .bijak-dash .actions{margin-top:6px;display:flex;gap:8px;flex-wrap:wrap}
            .bijak-dash .btn{display:inline-block;background:#2271b1;color:#fff !important;border-radius:6px;padding:8px 12px;text-decoration:none}
            .bijak-dash .btn:hover{background:#135e96;color:#fff !important}
            .bijak-dash small.muted{color:#666}
        </style>';

        echo '<div class="bijak-dash">';

        if (! $has_key) {
            echo '<p><strong>API Key تنظیم نشده است.</strong> ';
            echo 'برای مشاهده موجودی کیف پول و سفارش‌ها، لطفاً از ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=bijak-woo')) . '">تنظیمات بیجک</a> API Key را وارد کنید.</p>';
        }

        echo '<div class="row">';
        echo '<div class="item"><span class="label">نام مالک حساب</span><span class="val">'
            . esc_html($full ?: '—') . '</span></div>';
        echo '<div class="item"><span class="label">شماره موبایل</span><span class="val" style="direction:ltr">'
            . esc_html($phone ?: '—') . '</span></div>';
        echo '<div class="item"><span class="label">موجودی کیف پول</span><span class="val"><span class="badge">';
        echo $has_key ? (number_format_i18n($wallet) . ' تومان') : '—';
        echo '</span></span></div>';
        echo '</div>';

        echo '<div class="actions">';
        echo '<a class="btn" href="' . esc_url($wallet_url) . '" target="_blank" rel="noopener">افزایش کیف پول</a>';
        echo '<a class="btn" href="' . esc_url($orders_url) . '" target="_blank" rel="noopener">مشاهده سفارش‌ها</a>';
        echo '</div>';

        echo '</div>';
    }

    private function refresh_profile_like_admin(Admin $admin): array
    {
        $key = trim(Plugin::opt('api_key', ''));
        if ($key === '') {
            return ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
        }

        $api = new Api();
        $res = $api->request('/application/profile');

        if (is_wp_error($res) || empty($res['data'])) {
            return ['ok' => false, 'full_name' => '', 'phone' => '', 'wallet' => 0];
        }

        $d = $res['data'];
        $full_name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        $phone     = Helpers::normalize_phone($d['username'] ?? '');
        $wallet    = isset($d['inventory']) ? (int) $d['inventory'] : 0;

        $opts = get_option(Plugin::OPT, []);
        if (! is_array($opts)) $opts = [];
        $opts['supplier_full_name'] = sanitize_text_field($full_name);
        $opts['supplier_phone']     = sanitize_text_field($phone);
        $opts['wallet_inventory']   = max(0, $wallet);
        update_option(Plugin::OPT, $opts);

        return ['ok' => true, 'full_name' => $full_name, 'phone' => $phone, 'wallet' => $wallet];
    }
}
