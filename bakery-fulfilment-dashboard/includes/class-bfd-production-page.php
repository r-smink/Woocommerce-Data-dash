<?php
/**
 * Adminpagina "Productie overzicht" onder het WooCommerce-menu.
 * Toont per afhaal-/bezorgdag welke producten (en hoeveel) gemaakt
 * moeten worden, plus de bijbehorende orders — met print- en CSV-export.
 */

defined( 'ABSPATH' ) || exit;

class BFD_Production_Page {

	const PAGE_SLUG = 'bfd-production';

	/** Standaard aangevinkte statussen (ingeplande orders die gemaakt moeten worden). */
	const DEFAULT_STATUSES = array( 'wc-ideal', 'wc-afhalen', 'wc-zakelijke-rekenin' );

	/** Statussen die in de inklapbare dropdown "Overige statussen" staan. */
	const SECONDARY_STATUSES = array(
		'wc-pending',
		'wc-wacht-op-betaling',
		'wc-processing',
		'wc-on-hold',
		'wc-cancelled',
		'wc-refunded',
		'wc-failed',
		'wc-checkout-draft',
	);

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

		$default_statuses = self::DEFAULT_STATUSES;
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

		// Ontdubbelen op order-ID: een meta_query met OR (of dubbel opgeslagen
		// datum-meta / HPOS-joins) kan dezelfde order meerdere keren teruggeven.
		$unique = array();
		foreach ( array_merge( $orders, $fallback ) as $order ) {
			if ( $order instanceof WC_Order ) {
				$unique[ $order->get_id() ] = $order;
			}
		}

		return array_values( $unique );
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
					'orders'        => array(),
					'products'      => array(), // Productie: alle orders (ook COD), alleen aantallen.
					'revenue'       => array(), // Omzet: [cat][label] => [ qty, amount ], zonder COD.
					'revenue_total' => 0.0,
					'cod_count'     => 0,
				);
			}

			$is_cod = self::is_cod( $order );
			if ( $is_cod ) {
				$days[ $day ]['cod_count']++;
			}

			$items_html = array();
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				$orig_qty = (int) $item->get_quantity();
				$qty      = $orig_qty - abs( $order->get_qty_refunded_for_item( $item_id ) );
				if ( $qty <= 0 ) {
					continue;
				}

				$label = self::item_label( $item );
				$cat   = self::item_category( $item );

				if ( ! isset( $days[ $day ]['products'][ $cat ][ $label ] ) ) {
					$days[ $day ]['products'][ $cat ][ $label ] = 0;
				}
				$days[ $day ]['products'][ $cat ][ $label ] += $qty;

				// Omzet: na korting, incl. btw, naar rato van niet-terugbetaalde stuks. COD telt niet mee.
				if ( ! $is_cod ) {
					$line_incl = (float) $item->get_total() + (float) $item->get_total_tax();
					$amount    = $orig_qty > 0 ? $line_incl * ( $qty / $orig_qty ) : 0.0;

					if ( ! isset( $days[ $day ]['revenue'][ $cat ][ $label ] ) ) {
						$days[ $day ]['revenue'][ $cat ][ $label ] = array( 'qty' => 0, 'amount' => 0.0 );
					}
					$days[ $day ]['revenue'][ $cat ][ $label ]['qty']    += $qty;
					$days[ $day ]['revenue'][ $cat ][ $label ]['amount'] += $amount;
				}

				$items_html[] = $qty . ' × ' . $label;
			}

			$order_total = (float) $order->get_total() - (float) $order->get_total_refunded();
			if ( ! $is_cod ) {
				$days[ $day ]['revenue_total'] += $order_total;
			}

			$days[ $day ]['orders'][] = array(
				'order'    => $order,
				'time'     => BFD_Util::order_fulfilment_time( $order ),
				'type'     => $otype,
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'items'    => $items_html,
				'note'     => $order->get_customer_note(),
				'is_cod'   => $is_cod,
				'payment'  => $order->get_payment_method_title(),
				'total'    => $order_total,
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
			unset( $cat_products );

			ksort( $day['revenue'] );
			foreach ( $day['revenue'] as &$cat_revenue ) {
				uasort(
					$cat_revenue,
					function ( $a, $b ) {
						return $b['amount'] <=> $a['amount'];
					}
				);
			}
			unset( $cat_revenue );
		}
		unset( $day );

		return $days;
	}

	/**
	 * Betalen bij afhalen (COD): afgerekend in de winkel, telt niet mee voor omzet.
	 */
	private static function is_cod( $order ) {
		return 'cod' === $order->get_payment_method();
	}

	/**
	 * Huidige tab: 'production' (standaard) of 'revenue'.
	 */
	private static function get_view() {
		$view = isset( $_GET['bfd_view'] ) && is_string( $_GET['bfd_view'] ) ? sanitize_key( $_GET['bfd_view'] ) : 'production'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $view, array( 'production', 'revenue' ), true ) ? $view : 'production';
	}

	/**
	 * Bedrag als platte tekst (voor CSV), bijv. 12,50.
	 */
	private static function csv_amount( $amount ) {
		return number_format( (float) $amount, 2, ',', '' );
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

		$view     = self::get_view();
		$filename = ( 'revenue' === $view ? 'omzet-' : 'productie-' ) . $f['from'] . '-tot-' . $f['to'] . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		// BOM zodat Excel (NL) UTF-8 correct opent; ';' als scheidingsteken.
		fwrite( $out, "\xEF\xBB\xBF" );

		if ( 'revenue' === $view ) {
			fputcsv( $out, array( 'OMZET PER PRODUCT (excl. betalen bij afhalen)' ), ';' );
			fputcsv( $out, array( 'Datum', 'Categorie', 'Product', 'Aantal', 'Omzet incl. btw' ), ';' );
			foreach ( $days as $day => $data ) {
				foreach ( $data['revenue'] as $cat => $products ) {
					foreach ( $products as $label => $row ) {
						fputcsv( $out, array( $day, $cat, $label, $row['qty'], self::csv_amount( $row['amount'] ) ), ';' );
					}
				}
			}

			fputcsv( $out, array(), ';' );
			fputcsv( $out, array( 'ORDERS (excl. betalen bij afhalen)' ), ';' );
			fputcsv( $out, array( 'Datum', 'Order', 'Klant', 'Betaalmethode', 'Status', 'Totaal incl. btw' ), ';' );
			foreach ( $days as $day => $data ) {
				foreach ( $data['orders'] as $row ) {
					if ( $row['is_cod'] ) {
						continue;
					}
					fputcsv(
						$out,
						array(
							$day,
							'#' . $row['order']->get_order_number(),
							$row['customer'],
							$row['payment'],
							wc_get_order_status_name( $row['order']->get_status() ),
							self::csv_amount( $row['total'] ),
						),
						';'
					);
				}
				fputcsv( $out, array( $day, 'Totaal dag', '', '', '', self::csv_amount( $data['revenue_total'] ) ), ';' );
			}

			fclose( $out );
			exit;
		}

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
		$view   = self::get_view();
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
					'bfd_view'   => $view,
					'bfd_export' => 'csv',
				),
				admin_url( 'admin.php' )
			),
			'bfd_export'
		);

		echo '<div class="wrap bfd-wrap">';
		echo '<h1>' . esc_html__( 'Productie overzicht', BFD_TD ) . '</h1>';

		self::render_filters( $f, $today, $tomorrow, $week_end, $export_url, $view );
		self::render_tabs( $f, $view );

		if ( empty( $days ) ) {
			echo '<p>' . esc_html__( 'Geen orders gevonden voor deze periode.', BFD_TD ) . '</p></div>';
			return;
		}

		if ( 'revenue' === $view ) {
			self::render_revenue_summary( $days );
		}

		foreach ( $days as $day => $data ) {
			if ( 'revenue' === $view ) {
				self::render_revenue_day( $day, $data );
			} else {
				self::render_day( $day, $data );
			}
		}

		echo '</div>';
	}

	/**
	 * Tabs Productie / Omzet; behoudt de huidige filters.
	 */
	private static function render_tabs( $f, $view ) {
		$base = array(
			'page'       => self::PAGE_SLUG,
			'bfd_from'   => $f['from'],
			'bfd_to'     => $f['to'],
			'bfd_type'   => $f['type'],
			'bfd_status' => $f['statuses'],
		);
		$tabs = array(
			'production' => __( 'Productie', BFD_TD ),
			'revenue'    => __( 'Omzet', BFD_TD ),
		);

		echo '<nav class="nav-tab-wrapper bfd-tabs" style="margin:16px 0;">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg( array_merge( $base, array( 'bfd_view' => $key ) ), admin_url( 'admin.php' ) );
			$class = 'nav-tab' . ( $view === $key ? ' nav-tab-active' : '' );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Totaaloverzicht omzet over de hele geselecteerde periode.
	 */
	private static function render_revenue_summary( $days ) {
		$total     = 0.0;
		$orders    = 0;
		$cod_count = 0;
		foreach ( $days as $data ) {
			$total     += $data['revenue_total'];
			$orders    += count( $data['orders'] ) - $data['cod_count'];
			$cod_count += $data['cod_count'];
		}

		echo '<div class="bfd-revenue-summary" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin-bottom:16px;">';
		echo '<strong style="font-size:16px;">' . esc_html__( 'Omzet periode', BFD_TD ) . ': ' . wp_kses_post( wc_price( $total ) ) . '</strong>';
		echo ' <span style="color:#646970;">(' . esc_html( $orders ) . ' ' . esc_html__( 'orders, incl. btw', BFD_TD ) . ')</span>';
		if ( $cod_count ) {
			echo '<br><small style="color:#646970;">' . esc_html(
				sprintf(
					/* translators: %d: aantal orders */
					_n( '%d order met betalen bij afhalen is niet meegeteld (afgerekend in de winkel).', '%d orders met betalen bij afhalen zijn niet meegeteld (afgerekend in de winkel).', $cod_count, BFD_TD ),
					$cod_count
				)
			) . '</small>';
		}
		echo '</div>';
	}

	/**
	 * Omzet per dag: producten met bedragen en orders, zonder COD-orders.
	 */
	private static function render_revenue_day( $day, $data ) {
		$date_label = date_i18n( 'l j F Y', strtotime( $day ) );
		$paid_count = count( $data['orders'] ) - $data['cod_count'];

		echo '<div class="bfd-day">';
		echo '<h2>' . esc_html( $date_label ) . ' <span class="bfd-day-meta">(' . esc_html( $paid_count ) . ' ' . esc_html__( 'orders', BFD_TD ) . ' · ' . wp_kses_post( wc_price( $data['revenue_total'] ) ) . ')</span></h2>';

		if ( $data['cod_count'] ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: aantal orders */
					_n( '%d order met betalen bij afhalen niet meegeteld.', '%d orders met betalen bij afhalen niet meegeteld.', $data['cod_count'], BFD_TD ),
					$data['cod_count']
				)
			) . '</p>';
		}

		if ( empty( $data['revenue'] ) ) {
			echo '<p>' . esc_html__( 'Geen omzet voor deze dag (alleen betalen bij afhalen).', BFD_TD ) . '</p></div>';
			return;
		}

		echo '<h3>' . esc_html__( 'Omzet per product', BFD_TD ) . '</h3>';
		echo '<table class="widefat striped bfd-products"><thead><tr>';
		echo '<th class="bfd-qty">' . esc_html__( 'Aantal', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Product', BFD_TD ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Omzet', BFD_TD ) . '</th>';
		echo '</tr></thead><tbody>';

		$products_total = 0.0;
		foreach ( $data['revenue'] as $cat => $products ) {
			echo '<tr class="bfd-cat"><th colspan="3">' . esc_html( $cat ) . '</th></tr>';
			foreach ( $products as $label => $row ) {
				$products_total += $row['amount'];
				echo '<tr><td class="bfd-qty"><strong>' . esc_html( $row['qty'] ) . '</strong></td><td>' . esc_html( $label ) . '</td><td style="text-align:right;">' . wp_kses_post( wc_price( $row['amount'] ) ) . '</td></tr>';
			}
		}
		echo '</tbody><tfoot><tr><th colspan="2">' . esc_html__( 'Totaal producten', BFD_TD ) . '</th><th style="text-align:right;">' . wp_kses_post( wc_price( $products_total ) ) . '</th></tr></tfoot></table>';

		echo '<h3>' . esc_html__( 'Orders', BFD_TD ) . '</h3>';
		echo '<table class="widefat striped bfd-orders"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Klant', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Betaalmethode', BFD_TD ) . '</th>';
		echo '<th>' . esc_html__( 'Status', BFD_TD ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Totaal', BFD_TD ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $data['orders'] as $row ) {
			if ( $row['is_cod'] ) {
				continue;
			}
			$order = $row['order'];
			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $row['customer'] ) . '</td>';
			echo '<td>' . esc_html( $row['payment'] ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
			echo '<td style="text-align:right;">' . wp_kses_post( wc_price( $row['total'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody><tfoot><tr><th colspan="4">' . esc_html__( 'Totaal orders (incl. verzendkosten)', BFD_TD ) . '</th><th style="text-align:right;">' . wp_kses_post( wc_price( $data['revenue_total'] ) ) . '</th></tr></tfoot></table></div>';
	}

	private static function render_filters( $f, $today, $tomorrow, $week_end, $export_url, $view = 'production' ) {
		$statuses = wc_get_order_statuses();

		echo '<form method="get" class="bfd-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="bfd_view" value="' . esc_attr( $view ) . '" />';

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

		$primary   = array();
		$secondary = array();
		foreach ( $statuses as $key => $label ) {
			if ( in_array( $key, self::SECONDARY_STATUSES, true ) ) {
				$secondary[ $key ] = $label;
			} else {
				$primary[ $key ] = $label;
			}
		}

		$render_checkbox = function ( $key, $label ) use ( $f ) {
			$checked = in_array( $key, $f['statuses'], true ) ? ' checked' : '';
			echo '<label><input type="checkbox" name="bfd_status[]" value="' . esc_attr( $key ) . '"' . $checked . ' /> ' . esc_html( $label ) . '</label>';
		};

		echo '<fieldset class="bfd-statuses"><legend>' . esc_html__( 'Statussen', BFD_TD ) . '</legend>';
		foreach ( $primary as $key => $label ) {
			$render_checkbox( $key, $label );
		}

		if ( ! empty( $secondary ) ) {
			// Openklappen als er een overige status is aangevinkt, zodat de selectie zichtbaar blijft.
			$open = array_intersect( array_keys( $secondary ), $f['statuses'] ) ? ' open' : '';
			echo '<details class="bfd-statuses-more"' . $open . ' style="margin-top:6px;">';
			echo '<summary style="cursor:pointer;">' . esc_html__( 'Overige statussen', BFD_TD ) . '</summary>';
			echo '<div style="margin-top:4px;">';
			foreach ( $secondary as $key => $label ) {
				$render_checkbox( $key, $label );
			}
			echo '</div></details>';
		}
		echo '</fieldset>';

		submit_button( __( 'Toon', BFD_TD ), 'primary', 'submit', false );

		echo '<span class="bfd-quicklinks">';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'bfd_from' => $today, 'bfd_to' => $today, 'bfd_view' => $view ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Vandaag', BFD_TD ) . '</a> · ';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'bfd_from' => $tomorrow, 'bfd_to' => $tomorrow, 'bfd_view' => $view ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Morgen', BFD_TD ) . '</a> · ';
		echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'bfd_from' => $today, 'bfd_to' => $week_end, 'bfd_view' => $view ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Komende 7 dagen', BFD_TD ) . '</a>';
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
