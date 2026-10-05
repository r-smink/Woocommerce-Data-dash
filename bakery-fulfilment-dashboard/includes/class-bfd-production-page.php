<?php
/**
 * Adminpagina "Productie overzicht" onder het WooCommerce-menu.
 * Toont per afhaal-/bezorgdag welke producten (en hoeveel) gemaakt
 * moeten worden, plus de bijbehorende orders — met print- en CSV-export.
 */

defined( 'ABSPATH' ) || exit;

class BFD_Production_Page {

	const PAGE_SLUG = 'bfd-production';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_export_csv' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Productie overzicht', BFD_TD ),
			__( 'Productie overzicht', BFD_TD ),
			'edit_shop_orders',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function enqueue_css() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_enqueue_style( 'bfd-production', BFD_URL . 'assets/css/production.css', array(), BFD_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Filters uit de request                                              */
	/* ------------------------------------------------------------------ */

	private static function get_filters() {
		$today = current_time( 'Y-m-d' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['bfd_from'] ) && is_string( $_GET['bfd_from'] ) ? sanitize_text_field( wp_unslash( $_GET['bfd_from'] ) ) : $today;
		$to   = isset( $_GET['bfd_to'] ) && is_string( $_GET['bfd_to'] ) ? sanitize_text_field( wp_unslash( $_GET['bfd_to'] ) ) : $from;
		// phpcs:enable

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = $today;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = $from;
		}
		if ( $to < $from ) {
			$to = $from;
		}

		$type = isset( $_GET['bfd_type'] ) && is_string( $_GET['bfd_type'] ) ? sanitize_key( $_GET['bfd_type'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $type, array( 'all', 'pickup', 'delivery' ), true ) ) {
			$type = 'all';
		}

		$default_statuses = array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['bfd_status'] ) && is_array( $_GET['bfd_status'] ) ) {
			$raw      = array_filter( $_GET['bfd_status'], 'is_string' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$statuses = array_values( array_intersect( array_map( 'sanitize_key', $raw ), array_keys( wc_get_order_statuses() ) ) );
			if ( empty( $statuses ) ) {
				$statuses = $default_statuses;
			}
		} else {
			$statuses = $default_statuses;
		}

		return compact( 'from', 'to', 'type', 'statuses' );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Orders ophalen: (a) orders met pickup_date/delivery_date in het bereik,
	 * (b) fallback-orders zónder fulfilment-meta op besteldatum in het bereik.
	 *
	 * @return WC_Order[]
	 */
	private static function get_orders( $from, $to, $type, $statuses ) {
		$meta_query = array( 'relation' => 'OR' );
		if ( 'delivery' !== $type ) {
			$meta_query[] = array(
				'key'     => BFD_Util::META_PICKUP_DATE,
				'value'   => array( $from, $to ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		}
		if ( 'pickup' !== $type ) {
			$meta_query[] = array(
				'key'     => BFD_Util::META_DELIVERY_DATE,
				'value'   => array( $from, $to ),
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			);
		}

		$orders = wc_get_orders(
			array(
				'type'       => 'shop_order',
				'status'     => $statuses,
				'limit'      => -1,
				'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$fallback = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => $statuses,
				'limit'        => -1,
				'date_created' => $from . ' 00:00:00...' . $to . ' 23:59:59',
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array(
						'key'     => BFD_Util::META_PICKUP_DATE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => BFD_Util::META_DELIVERY_DATE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_merge( $orders, $fallback );
	}

	/**
	 * Organiseer orders per productiedag; aggregeer producten per
	 * categorie en verzamel orderregels.
	 *
	 * @param WC_Order[] $orders Orders.
	 * @return array [ 'Y-m-d' => [ 'orders' => [...], 'products' => [ cat => [ label => qty ] ] ] ]
	 */
	private static function organize( $orders, $from, $to, $type ) {
		$days = array();

		foreach ( $orders as $order ) {
			$day = BFD_Util::order_fulfilment_date( $order );
			if ( '' === $day || $day < $from || $day > $to ) {
				continue;
			}

			$otype = BFD_Util::order_fulfilment_type( $order );
			if ( 'all' !== $type && $otype !== $type ) {
				continue;
			}

			if ( ! isset( $days[ $day ] ) ) {
				$days[ $day ] = array(
					'orders'   => array(),
					'products' => array(),
				);
			}

			$items_html = array();
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$qty = $item->get_quantity() - abs( $order->get_qty_refunded_for_item( $item_id ) );
				if ( $qty <= 0 ) {
					continue;
				}

				$label = self::item_label( $item );
				$cat   = self::item_category( $item );

				if ( ! isset( $days[ $day ]['products'][ $cat ][ $label ] ) ) {
					$days[ $day ]['products'][ $cat ][ $label ] = 0;
				}
				$days[ $day ]['products'][ $cat ][ $label ] += $qty;

				$items_html[] = $qty . ' × ' . $label;
			}

			$days[ $day ]['orders'][] = array(
				'order'    => $order,
				'time'     => BFD_Util::order_fulfilment_time( $order ),
				'type'     => $otype,
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'items'    => $items_html,
				'note'     => $order->get_customer_note(),
			);
		}

		// Sorteer: dagen oplopend, orders op tijdslot, categorieën alfabetisch.
		ksort( $days );
		foreach ( $days as &$day ) {
			usort(
				$day['orders'],
				function ( $a, $b ) {
					$cmp = strcmp( $a['time'], $b['time'] );
					return 0 !== $cmp ? $cmp : $a['order']->get_order_number() - $b['order']->get_order_number();
				}
			);
			ksort( $day['products'] );
			foreach ( $day['products'] as &$cat_products ) {
				arsort( $cat_products );
			}
		}

		return $days;
	}

	/**
	 * Leesbaar productlabel incl. variatie-attributen.
	 *
	 * @param WC_Order_Item_Product $item Orderregel.
	 * @return string
	 */
	private static function item_label( $item ) {
		$label = $item->get_name();

		$parts = array();
		foreach ( $item->get_formatted_meta_data() as $meta ) {
			$parts[] = $meta->display_key . ': ' . wp_strip_all_tags( $meta->display_value );
		}
		if ( $parts ) {
			$label .= ' (' . implode( ', ', $parts ) . ')';
		}
		return $label;
	}

	/**
	 * Eerste productcategorie (van het hoofdproduct bij variaties).
	 *
	 * @param WC_Order_Item_Product $item Orderregel.
	 * @return string
	 */
	private static function item_category( $item ) {
		$product    = $item->get_product();
		$product_id = $item->get_product_id();
		if ( $product && $product->is_type( 'variation' ) ) {
			$product_id = $product->get_parent_id();
		}
		$terms = $product_id ? get_the_terms( $product_id, 'product_cat' ) : false;
		if ( $terms && ! is_wp_error( $terms ) ) {
			return $terms[0]->name;
		}
		return __( 'Overig', BFD_TD );
	}

	/* ------------------------------------------------------------------ */
	/* CSV-export                                                          */
	/* ------------------------------------------------------------------ */

	public static function maybe_export_csv() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || empty( $_GET['bfd_export'] ) ) {
			return;
		}
		// phpcs:enable
		if ( ! current_user_can( 'edit_shop_orders' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'bfd_export' ) ) {
			wp_die( esc_html__( 'Geen toegang.', BFD_TD ) );
		}

		$f      = self::get_filters();
		$orders = self::get_orders( $f['from'], $f['to'], $f['type'], $f['statuses'] );
		$days   = self::organize( $orders, $f['from'], $f['to'], $f['type'] );

		$filename = 'productie-' . $f['from'] . '-tot-' . $f['to'] . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		// BOM zodat Excel (NL) UTF-8 correct opent; ';' als scheidingsteken.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array( 'PRODUCTEN PER DAG' ), ';' );
		fputcsv( $out, array( 'Datum', 'Categorie', 'Product', 'Aantal' ), ';' );
		foreach ( $days as $day => $data ) {
			foreach ( $data['products'] as $cat => $products ) {
				foreach ( $products as $label => $qty ) {
					fputcsv( $out, array( $day, $cat, $label, $qty ), ';' );
				}
			}
		}

		fputcsv( $out, array(), ';' );
		fputcsv( $out, array( 'ORDERS PER DAG' ), ';' );
		fputcsv( $out, array( 'Datum', 'Order', 'Tijdslot', 'Type', 'Klant', 'Status', 'Items', 'Notitie' ), ';' );
		foreach ( $days as $day => $data ) {
			foreach ( $data['orders'] as $row ) {
				fputcsv(
					$out,
					array(
						$day,
						'#' . $row['order']->get_order_number(),
						$row['time'],
						BFD_Util::type_label( $row['type'] ),
						$row['customer'],
						wc_get_order_status_name( $row['order']->get_status() ),
						implode( ', ', $row['items'] ),
						$row['note'],
					),
					';'
				);
			}
		}

		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Geen toegang.', BFD_TD ) );
		}

		$f      = self::get_filters();
		$orders = self::get_orders( $f['from'], $f['to'], $f['type'], $f['statuses'] );
		$days   = self::organize( $orders, $f['from'], $f['to'], $f['type'] );

		$today    = current_time( 'Y-m-d' );
		$tomorrow = date( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$week_end = date( 'Y-m-d', strtotime( $today . ' +6 day' ) );

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'bfd_from'   => $f['from'],
					'bfd_to'     => $f['to'],
					'bfd_type'   => $f['type'],
					'bfd_status' => $f['statuses'],
					'bfd_export' => 'csv',
				),
				admin_url( 'admin.php' )
			),
			'bfd_export'
		);

		echo '<div class="wrap bfd-wrap">';
		echo '<h1>' . esc_html__( 'Productie overzicht', BFD_TD ) . '</h1>';

		self::render_filters( $f, $today, $tomorrow, $week_end, $export_url );

		if ( empty( $days ) ) {
			echo '<p>' . esc_html__( 'Geen orders gevonden voor deze periode.', BFD_TD ) . '</p></div>';
			return;
		}

		foreach ( $days as $day => $data ) {
			self::render_day( $day, $data );
		}

		echo '</div>';
	}

	private static function render_filters( $f, $today, $tomorrow, $week_end, $export_url ) {
		$statuses = wc_get_order_statuses();

		echo '<form method="get" class="bfd-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';

		echo '<label>' . esc_html__( 'Van', BFD_TD ) . ' <input type="date" name="bfd_from" value="' . esc_attr( $f['from'] ) . '" /></label>';
		echo '<label>' . esc_html__( 't/m', BFD_TD ) . ' <input type="date" name="bfd_to" value="' . esc_attr( $f['to'] ) . '" /></label>';

		echo '<label>' . esc_html__( 'Type', BFD_TD ) . ' <select name="bfd_type">';
		$types = array(
			'all'      => __( 'Alles', BFD_TD ),
			'pickup'   => __( 'Afhalen', BFD_TD ),
			'delivery' => __( 'Bezorgen', BFD_TD ),
		);
		foreach ( $types as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $f['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		echo '<fieldset class="bfd-statuses"><legend>' . esc_html__( 'Statussen', BFD_TD ) . '</legend>';
		foreach ( $statuses as $key => $label ) {
			$checked = in_array( $key, $f['statuses'], true ) ? ' checked' : '';
			echo '<label><input type="checkbox" name="bfd_status[]" value="' . esc_attr( $key ) . '"' . $checked . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset>';

		submit_button( __( 'Toon', BFD_TD ), 'primary', 'submit', false );

		echo '<span class="bfd-quicklinks">';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'bfd_from' => $today, 'bfd_to' => $today ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Vandaag', BFD_TD ) . '</a> · ';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'bfd_from' => $tomorrow, 'bfd_to' => $tomorrow ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Morgen', BFD_TD ) . '</a> · ';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'bfd_from' => $today, 'bfd_to' => $week_end ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Komende 7 dagen', BFD_TD ) . '</a>';
		echo '</span>';

		echo '<span class="bfd-actions">';
		echo '<button type="button" class="button" onclick="window.print()">' . esc_html__( 'Printen', BFD_TD ) . '</button> ';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'CSV-export', BFD_TD ) . '</a>';
		echo '</span>';

		echo '</form>';
	}

	private static function render_day( $day, $data ) {
		$order_count = count( $data['orders'] );
		$item_count  = 0;
		foreach ( $data['products'] as $cat_products ) {
			$item_count += array_sum( $cat_products );
		}

		$date_label = date_i18n( 'l j F Y', strtotime( $day ) );

		echo '<div class="bfd-day">';
		echo '<h2>' . esc_html( $date_label ) . ' <span class="bfd-day-meta">(' . esc_html( $order_count ) . ' ' . esc_html__( 'orders', BFD_TD ) . ' · ' . esc_html( $item_count ) . ' ' . esc_html__( 'items', BFD_TD ) . ')</span></h2>';

		// Geaggregeerde producten.
		echo '<h3>' . esc_html__( 'Te maken producten', BFD_TD ) . '</h3>';
		echo '<table class="widefat striped bfd-products"><thead><tr>';
		echo '<th class="bfd-qty">' . esc_html__( 'Aantal', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Product', BFD_TD ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['products'] as $cat => $products ) {
			echo '<tr class="bfd-cat"><th colspan="2">' . esc_html( $cat ) . '</th></tr>';
			foreach ( $products as $label => $qty ) {
				echo '<tr><td class="bfd-qty"><strong>' . esc_html( $qty ) . '</strong></td><td>' . esc_html( $label ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		// Orders van die dag.
		echo '<h3>' . esc_html__( 'Orders', BFD_TD ) . '</h3>';
		echo '<table class="widefat striped bfd-orders"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Tijdslot', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Type', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Klant', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Status', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Items', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Notitie', BFD_TD ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['orders'] as $row ) {
			$order    = $row['order'];
			$edit_url = $order->get_edit_order_url();
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $row['time'] ) . '</td>';
			echo '<td>' . esc_html( BFD_Util::type_label( $row['type'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['customer'] ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $row['items'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['note'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}
