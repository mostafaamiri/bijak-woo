<?php

namespace BIJAK\BijakWoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-helpers.php';
require_once __DIR__ . '/class-admin.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-api.php';
require_once __DIR__ . '/class-ajax.php';
require_once __DIR__ . '/class-checkout.php';
require_once __DIR__ . '/class-order-sender.php';
require_once __DIR__ . '/class-status.php';
require_once __DIR__ . '/class-thankyou.php';
require_once __DIR__ . '/class-refresh.php';
require_once __DIR__ . '/class-dashboard.php';

final class Plugin {

	private static $instance;

	public static function instance() {
		return self::$instance ?: self::$instance = new self();
	}

	public const OPT = 'bijak_woo_options';

	public function boot(): void {
		// Make translations available. This looks for:
		//  - WP_LANG_DIR/plugins/bijak-*.mo
		//  - wp-content/plugins/bijak/languages/bijak-*.mo
		$rel_lang_dir = dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages';
		load_plugin_textdomain( 'bijak', false, $rel_lang_dir );

		( new Admin() )->register();
		( new Assets() )->register();

		$api = new Api();

		( new Ajax( $api ) )->register();
		( new Checkout() )->register();
		( new Order_Sender( $api ) )->register();
		( new Status_Display( $api ) )->register();
		( new Thankyou() )->register();
		( new Refresh_Shipping() )->init(); // class has a static init(), calling via instance is acceptable.

		( new Dashboard( $api ) )->register();

		add_action(
			'woocommerce_shipping_init',
			function () {
				require_once BIJAK_WOO_PATH . 'includes/class-shipping-method.php';
			}
		);

		add_filter(
			'woocommerce_shipping_methods',
			function ( $methods ) {
				$methods['bijak_pay_at_dest'] = __NAMESPACE__ . '\\Shipping_Method';
				return $methods;
			}
		);
	}

	public static function opt( $k, $d = '' ) {
		$o = get_option( self::OPT, [] );
		return array_key_exists( $k, $o ) ? $o[ $k ] : $d;
	}
}
