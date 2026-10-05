<?php
/**
 * Plugin Name:       Bakkerij Productie & Analytics
 * Plugin URI:        https://devin.ai
 * Description:       Filtert WooCommerce Analytics op afhaal-/bezorgdatum (i.p.v. besteldatum) via de order-meta van "WooCommerce Delivery & Pickup Date Time" (CodeRockz), en voegt een "Productie overzicht"-pagina toe die per dag toont welke producten gemaakt moeten worden.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Devin
 * License:           GPL-2.0+
 * Text Domain:       bakery-fulfilment-dashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'BFD_VERSION', '1.0.0' );
define( 'BFD_PATH', plugin_dir_path( __FILE__ ) );
define( 'BFD_URL', plugin_dir_url( __FILE__ ) );
define( 'BFD_TD', 'bakery-fulfilment-dashboard' );

// HPOS-compatibiliteit: deze plugin schrijft zelf geen orderdata en leest meta
// via WC_Order/ wc_get_orders of via de juiste meta-tabel (HPOS-aware).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

require_once BFD_PATH . 'includes/class-bfd-util.php';
require_once BFD_PATH . 'includes/class-bfd-analytics.php';
require_once BFD_PATH . 'includes/class-bfd-production-page.php';

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		BFD_Analytics::init();
		BFD_Production_Page::init();
	}
);
