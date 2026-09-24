( function () {
	'use strict';

	if (
		! window.wc ||
		! window.wc.wcBlocksRegistry ||
		! window.wc.wcSettings ||
		! window.wp ||
		! window.wp.element ||
		! window.wp.i18n
	) {
		return;
	}

	const settings = window.wc.wcSettings.getSetting( 'bachs_data', {} );
	const createElement = window.wp.element.createElement;
	const registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	const title = settings.title || window.wp.i18n.__( 'Bachs', 'etchcon-checkout-for-bachs' );
	const description = settings.description || window.wp.i18n.__(
		'Pay securely using Bachs.',
		'etchcon-checkout-for-bachs'
	);
	const content = createElement( 'div', null, description );

	registerPaymentMethod( {
		name: 'bachs',
		label: createElement( 'span', null, title ),
		content,
		edit: content,
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [],
		},
	} );
}() );
