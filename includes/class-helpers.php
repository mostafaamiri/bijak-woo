<?php

namespace BIJAK\BijakWoo;

if (! defined('ABSPATH')) exit;

class Helpers
{
    public static function normalize_phone(string $s): string
    {
        $map = [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9'
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\D+/', '', $s);
        if (strpos($s, '98') === 0) $s = '0' . substr($s, 2);
        return $s;
    }

    public static function parse_coords(?string $s): array
    {
        $s = trim((string) $s);
        if ($s === '') return [null, null];
        $parts = array_map('trim', explode(',', $s));
        if (count($parts) < 2) return [null, null];
        $lat = is_numeric($parts[0]) ? (float) $parts[0] : null;
        $lon = is_numeric($parts[1]) ? (float) $parts[1] : null;
        return [$lat, $lon];
    }

    public static function min0($v): float
    {
        $f = (float) $v;
        return $f > 0 ? $f : 0;
    }

    public static function build_goods_details_from_cart(): array
    {
        $details = [];
        $is_goods_have_problem = false;

        if (function_exists('WC') && WC()->cart && ! WC()->cart->is_empty()) {
            foreach (WC()->cart->get_cart() as $item) {
                $p = $item['data'];
                if (! $p) {
                    $is_goods_have_problem = true;
                }
                $pr_length = self::min0($p->get_length());
                $pr_width = self::min0($p->get_width());
                $pr_height = self::min0($p->get_height());
                $pr_weight = self::min0($p->get_weight());

                if ($pr_length == 0 or $pr_width == 0 or $pr_height == 0 or $pr_weight == 0) {
                    $is_goods_have_problem = true;
                }
                $details[] = [
                    'title' => (string) $p->get_name(),
                    'length' => $pr_length,
                    'width' => $pr_width,
                    'height' => $pr_height,
                    'weight' => $pr_weight,
                    'goods_count' => (int) $item['quantity'],
                    'needs_packaging' => false,
                    'goods_packaging_type_id' => 1,
                    'file_paths' => [],
                ];
            }
        }

        if ($is_goods_have_problem) {
            return [];
        } else {
            return $details;
        }
    }

    public static function build_goods_details_from_order(\WC_Order $order): array
    {
        $details = [];
        $is_goods_have_problem = false;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (! $product) {
                $is_goods_have_problem = true;
            }

            $pr_length = self::min0($product->get_length());
            $pr_width = self::min0($product->get_width());
            $pr_height = self::min0($product->get_height());
            $pr_weight = self::min0($product->get_weight());

            if ($pr_length == 0 or $pr_width == 0 or $pr_height == 0 or $pr_weight == 0) {
                $is_goods_have_problem = true;
            }
            $details[] = [
                'title' => $item->get_name(),
                'length' => $pr_length,
                'width' => $pr_width,
                'height' => $pr_height,
                'weight' => $pr_weight,
                'goods_count' => (int) $item->get_quantity(),
                'needs_packaging' => false,
                'goods_packaging_type_id' => 1,
                'file_paths' => [],
            ];
        }

        if ($is_goods_have_problem) {
            return [];
        } else {
            return $details;
        }
    }

    public static function resolve_line_id(Api $api, int $origin_city_id, int $dest_city_id): ?int
    {
        $freights = $api->request("/application/freights/?origin_city_id={$origin_city_id}&destination_city_id={$dest_city_id}");
        if (is_wp_error($freights) || empty($freights['data'][0]['line_id'])) {
            return null;
        }
        return (int) $freights['data'][0]['line_id'];
    }

    /** تبدیل تومان به ارز فروشگاه (IRR=×10) */
    public static function toman_to_store_currency(float $toman): float
    {
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'IRT';
        return (strtoupper($currency) === 'IRR') ? ($toman * 10.0) : $toman;
    }

    public static function checkout_field(string $key, $default = '')
    {
        if (! empty($_POST['post_data'])) {
            $pd = [];
            parse_str((string) $_POST['post_data'], $pd);
            if (isset($pd[$key])) {
                return is_string($pd[$key]) ? trim($pd[$key]) : $pd[$key];
            }
        }
        if (isset($_POST[$key])) {
            return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
        }
        return $default;
    }
}
