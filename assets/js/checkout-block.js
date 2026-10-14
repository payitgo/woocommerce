/**
 * payitgo in the block checkout. No build step: it uses the globals the
 * checkout loads (wc.wcBlocksRegistry, wc.wcSettings, wp.element,
 * wp.htmlEntities). Paying sends the customer to payitgo's hosted checkout.
 */
( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting( 'payitgo_data', {} );
	if ( ! registry || ! settings ) {
		return;
	}
	var el = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;
	var title = decode( settings.title || 'Crypto' );

	function Description() {
		return el( 'p', null, decode( settings.description || '' ) );
	}

	registry.registerPaymentMethod( {
		name: 'payitgo',
		label: el( 'span', null, title ),
		ariaLabel: title,
		placeOrderButtonLabel: settings.button || undefined,
		content: el( Description ),
		edit: el( Description ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )();
