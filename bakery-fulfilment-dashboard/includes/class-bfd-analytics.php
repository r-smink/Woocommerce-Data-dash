<?php
/**
 * Schakelt WooCommerce Analytics over van besteldatum naar
 * afhaal-/bezorgdatum wanneer de gebruiker "Datumtype =
 * Afhaal-/bezorgdatum" kiest (REST-param `date_basis=fulfilment`).
 *
 * Werking: alle SQL-clauses van de rapporten lopen door
 * `woocommerce_analytics_clauses_{type}_{context}`. Wij herschrijven
 * elke referentie naar `tabel`.`datumkolom` in where_time / select /
 * where / order_by naar een COALESCE die eerst pickup_date, dan
 * delivery_date, dan de oorspronkelijke datumkolom pakt.
 */

defined( 'ABSPATH' ) || exit;

class BFD_Analytics {

	/**
	 * Matcht `tabel`.`datumkolom` voor alle order-lookup/stats-tabellen die
	 * een order_id-kolom hebben (ook met afwijkend $wpdb-prefix).
	 */
	const DATE_COL_PATTERN = '/`?[a-zA-Z0-9_]*wc_order(?:_stats|_product_lookup|_coupon_lookup|_tax_lookup)`?\.`?(?:date_created_gmt|date_created|date_paid|date_completed)`?/i';

	/**
	 * Clause-contexts van de rapporten die op ordergebonden tabellen draaien.
	 * Onbekende/niet-bestaande contexts zijn onschuldig (de filter wordt
	 * dan simpelweg nooit aangeroepen).
	 */
	const CONTEXTS = array(
		// Orders + Revenue (= orders_stats) rapporten.
		'orders',
		'orders_subquery',
		'orders_stats',
		'orders_stats_subquery',
		'orders_stats_total',
		'orders_stats_interval',
		// Producten.
		'products',
		'products_subquery',
		'products_stats',
		'products_stats_subquery',
		'products_stats_total',
		'products_stats_interval',
		// Categorieën en variaties (draaien op wc_order_product_lookup).
		'categories',
		'categories_subquery',
		'variations',
		'variations_subquery',
		'variations_stats',
		'variations_stats_subquery',
		'variations_stats_total',
		'variations_stats_interval',
		// Coupons (wc_order_coupon_lookup) en belastingen (wc_order_tax_lookup).
		'coupons',
		'coupons_subquery',
		'coupons_stats',
		'coupons_stats_subquery',
		'coupons_stats_total',
		'coupons_stats_interval',
		'taxes',
		'taxes_subquery',
		'taxes_stats',
		'taxes_stats_subquery',
		'taxes_stats_total',
		'taxes_stats_interval',
	);

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_js' ) );

		// `date_basis` wordt door de REST-controllers uit query_args gefilterd
		// en komt dus niet in de rapport-cache-sleutel terecht. Schakel de
		// cache uit zolang op afhaal-/bezorgdatum gefilterd wordt.
		add_filter( 'woocommerce_analytics_report_should_use_cache', array( __CLASS__, 'maybe_disable_cache' ), 10, 2 );

		foreach ( self::CONTEXTS as $context ) {
			add_filter( "woocommerce_analytics_clauses_where_time_{$context}", array( __CLASS__, 'rewrite_clauses' ) );
			add_filter( "woocommerce_analytics_clauses_where_{$context}", array( __CLASS__, 'rewrite_clauses' ) );
			add_filter( "woocommerce_analytics_clauses_select_{$context}", array( __CLASS__, 'rewrite_clauses' ) );
			add_filter( "woocommerce_analytics_clauses_order_by_{$context}", array( __CLASS__, 'rewrite_clauses' ) );
			add_filter( "woocommerce_analytics_clauses_group_by_{$context}", array( __CLASS__, 'rewrite_clauses' ) );
		}
	}

	/**
	 * Is de huidige request een analytics-request met datumtype "fulfilment"?
	 */
	public static function is_fulfilment_basis() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['date_basis'] ) && is_string( $_GET['date_basis'] ) && 'fulfilment' === sanitize_key( wp_unslash( $_GET['date_basis'] ) );
	}

	/**
	 * Cache alleen uitschakelen bij fulfilment-basis.
	 *
	 * @param bool   $use_cache Of rapportcaching aan staat.
	 * @param string $cache_key Cache-sleutel van het rapport.
	 * @return bool
	 */
	public static function maybe_disable_cache( $use_cache, $cache_key ) {
		if ( self::is_fulfilment_basis() ) {
			return false;
		}
		return $use_cache;
	}

	/**
	 * Herschrijf datumkolom-referenties in een clause-array.
	 *
	 * Elke `{order_tabel}.{datumkolom}` wordt vervangen door de
	 * fulfilment-expressie (pickup_date → delivery_date → datumkolom).
	 * Datetime-literals ('Y-m-d H:i:s') worden in DATE() gewikkeld zodat
	 * de vergelijking op dagniveau gebeurt.
	 *
	 * @param array $clauses SQL-clause-strings.
	 * @return array
	 */
	public static function rewrite_clauses( $clauses ) {
		if ( ! self::is_fulfilment_basis() || ! is_array( $clauses ) ) {
			return $clauses;
		}

		foreach ( $clauses as $i => $clause ) {
			if ( ! is_string( $clause ) || '' === $clause ) {
				continue;
			}

			$new = preg_replace_callback(
				self::DATE_COL_PATTERN,
				function ( $m ) {
					return BFD_Util::fulfilment_expr( $m[0] );
				},
				$clause
			);

			// Datetime-literals naar DATE() zodat '2026-10-03 23:59:59' als
			// de dag '2026-10-03' vergeleken wordt met de Y-m-d fulfil-datum.
			$new = preg_replace(
				"/'(\\d{4}-\\d{2}-\\d{2})[ T]\\d{2}:\\d{2}:\\d{2}'/",
				"DATE('$1')",
				$new
			);

			$clauses[ $i ] = $new;
		}

		return $clauses;
	}

	/**
	 * Laad de filtercontrol-JS op wc-admin/analytics-schermen.
	 */
	public static function enqueue_js() {
		// Alleen laden op wc-admin pagina's.
		if ( ! isset( $_GET['page'] ) || 'wc-admin' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_enqueue_script(
			'bfd-analytics-date-basis',
			BFD_URL . 'assets/js/analytics-date-basis.js',
			array( 'wp-hooks', 'wp-i18n' ),
			BFD_VERSION,
			true
		);
	}
}
