/**
 * Voegt een "Datumtype"-filter toe aan de WooCommerce Analytics-rapporten.
 * Bij "Afhaal-/bezorgdatum" wordt de REST-param date_basis=fulfilment
 * meegestuurd; de PHP-kant herschrijft dan de datumvergelijkingen.
 *
 * Vanilla JS via wp.hooks — geen build-stap nodig.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.hooks.addFilter ) {
		return;
	}

	var __ = wp.i18n.__;

	var dateBasisFilter = {
		label: __( 'Datumtype', 'bakery-fulfilment-dashboard' ),
		staticParams: [],
		param: 'date_basis',
		showFilters: function () {
			return true;
		},
		defaultValue: 'order',
		filters: [
			{
				label: __( 'Besteldatum', 'bakery-fulfilment-dashboard' ),
				value: 'order',
			},
			{
				label: __( 'Afhaal-/bezorgdatum', 'bakery-fulfilment-dashboard' ),
				value: 'fulfilment',
			},
		],
	};

	// Alleen rapporten die op ordergebonden lookup-tabellen draaien.
	var reports = [
		'orders',
		'products',
		'revenue',
		'categories',
		'variations',
		'coupons',
		'taxes',
	];

	reports.forEach( function ( report ) {
		wp.hooks.addFilter(
			'woocommerce_admin_' + report + '_report_filters',
			'bakery-fulfilment-dashboard/date-basis',
			function ( filters ) {
				return [ dateBasisFilter ].concat( filters || [] );
			}
		);
	} );
} )( window.wp );
