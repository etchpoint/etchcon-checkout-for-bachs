(() => {
	let settings = {};

	function BachsGatewayFields() {
		return window.wp.element.createElement(
			'div',
			{ className: 'etchpoint-bachs-givewp-help' },
			window.wp.element.createElement(
				'p',
				{ style: { marginBottom: 0 } },
				settings.message
			)
		);
	}

	const BachsGateway = {
		id: 'bachs',
		initialize() {
			settings = this.settings || {};
		},
		Fields() {
			return window.wp.element.createElement(BachsGatewayFields);
		},
	};

	window.givewp.gateways.register(BachsGateway);
})();
