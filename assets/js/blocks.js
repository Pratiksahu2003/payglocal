(function () {
	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wc.wcSettings || !window.wp || !window.wp.element) {
		return;
	}

	var settings = window.wc.wcSettings.getSetting('payglocal_data', {});
	var decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
		? window.wp.htmlEntities.decodeEntities
		: function (value) { return value; };
	var createElement = window.wp.element.createElement;
	var title = decode(settings.title || 'PayGlocal');

	var Content = function () {
		return createElement('div', { className: 'payglocal-block-description' }, decode(settings.description || ''));
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod({
		name: 'payglocal',
		label: title,
		content: createElement(Content, null),
		edit: createElement(Content, null),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: {
			features: settings.supports || ['products']
		}
	});
}());
