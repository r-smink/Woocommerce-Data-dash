<?php
/**
 * Helpers rond order-meta van de CodeRockz "Delivery & Pickup Date Time"-plugin
 * en HPOS/postmeta detectie.
 */

defined( 'ABSPATH' ) || exit;

class BFD_Util {

	// Meta-keys zoals opgeslagen door de CodeRockz-plugin (free + Pro).
	const META_PICKUP_DATE   = 'pickup_date';
	const META_DELIVERY_DATE = 'delivery_date';
	const META_PICKUP_TIME   = 'pickup_time';
	const META_DELIVERY_TIME = 'delivery_time';
	const META_DELIVERY_TYPE = 'delivery_type'; // 'pickup' | 'delivery'

	/**
	 * Slaat de shop HPOS (custom order tables) op?
	 */
	public static function using_hpos() {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Tabel waarin order-meta staat: wc_orders_meta (HPOS) of postmeta (klassiek).
	 */
	public static function meta_table() {
		global $wpdb;
		return self::using_hpos() ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
	}

	/**
	 * Kolom in de meta-tabel die naar het order verwijst.
	 */
	public static function meta_order_column() {
		return self::using_hpos() ? 'order_id' : 'post_id';
	}

	/**
	 * SQL-expressie voor de "productiedag" van een orderregel in een
	 * analytics lookup/stats-tabel.
	 *
	 * Voorbeeld $base_ref: "wp_wc_order_stats.`date_created`".
	 * Resultaat:
	 *   COALESCE(
	 *     (SELECT meta pickup_date ...),
	 *     (SELECT meta delivery_date ...),
	 *     DATE(wp_wc_order_stats.`date_created`)
	 *   )
	 *
	 * Geeft een datumstring 'Y-m-d' terug.
	 *
	 * @param string $base_ref Tabel-gekwalificeerde datumkolom (bv. `tabel`.`kolom`).
	 * @return string SQL-expressie.
	 */
	public static function fulfilment_expr( $base_ref ) {
		$meta_table = self::meta_table();
		$meta_col   = self::meta_order_column();

		// Van "tabel.kolom" (eventueel met backticks) naar "tabel.order_id".
		$order_ref = preg_replace( '/\.\s*`?[a-z_]+`?$/i', '.order_id', $base_ref );
		$table_ref = preg_replace( '/\.\s*`?[a-z_]+`?$/i', '', $base_ref );

		$pickup_sub   = "(SELECT pm.meta_value FROM {$meta_table} pm WHERE pm.{$meta_col} = {$order_ref} AND pm.meta_key = '" . self::META_PICKUP_DATE . "' AND pm.meta_value <> '' LIMIT 1)";
		$delivery_sub = "(SELECT pm.meta_value FROM {$meta_table} pm WHERE pm.{$meta_col} = {$order_ref} AND pm.meta_key = '" . self::META_DELIVERY_DATE . "' AND pm.meta_value <> '' LIMIT 1)";

		// Fallback: de oorspronkelijke datumkolom, en (indien anders, bv.
		// date_paid die NULL kan zijn) daarna alsnog date_created.
		$fallback = "DATE({$base_ref})";
		if ( ! preg_match( '/date_created`?$/i', $base_ref ) ) {
			$fallback .= ", DATE({$table_ref}.`date_created`)";
		}

		return "COALESCE({$pickup_sub}, {$delivery_sub}, {$fallback})";
	}

	/**
	 * Lees de productiedag van een WC_Order uit.
	 *
	 * @param WC_Order $order Order-object.
	 * @return string 'Y-m-d'
	 */
	public static function order_fulfilment_date( $order ) {
		$pickup   = $order->get_meta( self::META_PICKUP_DATE, true );
		$delivery = $order->get_meta( self::META_DELIVERY_DATE, true );

		if ( ! empty( $pickup ) ) {
			return date( 'Y-m-d', strtotime( $pickup ) );
		}
		if ( ! empty( $delivery ) ) {
			return date( 'Y-m-d', strtotime( $delivery ) );
		}
		$created = $order->get_date_created();
		return $created ? $created->date( 'Y-m-d' ) : '';
	}

	/**
	 * Ordertype: 'pickup', 'delivery' of 'order' (geen afhaal/bezorg-info).
	 *
	 * @param WC_Order $order Order-object.
	 * @return string
	 */
	public static function order_fulfilment_type( $order ) {
		$type = $order->get_meta( self::META_DELIVERY_TYPE, true );
		if ( 'pickup' === $type || 'delivery' === $type ) {
			return $type;
		}
		if ( $order->get_meta( self::META_PICKUP_DATE, true ) ) {
			return 'pickup';
		}
		if ( $order->get_meta( self::META_DELIVERY_DATE, true ) ) {
			return 'delivery';
		}
		return 'order';
	}

	/**
	 * Tijdslot-label van een order ('pickup_time' of 'delivery_time' meta).
	 *
	 * @param WC_Order $order Order-object.
	 * @return string
	 */
	public static function order_fulfilment_time( $order ) {
		$type = self::order_fulfilment_type( $order );
		if ( 'pickup' === $type ) {
			return (string) $order->get_meta( self::META_PICKUP_TIME, true );
		}
		if ( 'delivery' === $type ) {
			return (string) $order->get_meta( self::META_DELIVERY_TIME, true );
		}
		return '';
	}

	/**
	 * NL-label voor een ordertype.
	 *
	 * @param string $type pickup|delivery|order
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'pickup'   => __( 'Afhalen', BFD_TD ),
			'delivery' => __( 'Bezorgen', BFD_TD ),
			'order'    => __( 'Besteldatum', BFD_TD ),
		);
		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}
}
