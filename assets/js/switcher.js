(function () {
	function onReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	onReady(function () {
		document.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || !target.matches) {
				return;
			}
			var isCountry = target.matches('[data-payglocal-country]');
			var isCurrency = target.matches('[data-payglocal-currency]');
			if (!isCountry && !isCurrency) {
				return;
			}
			if (!target.value || !window.PayGlocalCurrency) {
				return;
			}
			updateCurrency(
				isCountry ? 'payglocal_set_country' : 'payglocal_set_currency',
				isCountry ? 'country' : 'currency',
				target.value,
				target
			);
		});
	});

	function updateCurrency(action, field, value, control) {
		var switcher = control.closest('[data-payglocal-switcher]');
		var status = switcher ? switcher.querySelector('[data-payglocal-status]') : null;
		var controls = switcher ? switcher.querySelectorAll('select') : [];
		Array.prototype.forEach.call(controls, function (select) {
			select.disabled = true;
		});
		if (status) {
			status.textContent = '';
		}

		var body = new FormData();
		body.append('action', action);
		body.append('nonce', window.PayGlocalCurrency.nonce);
		body.append(field, value);

		window.fetch(window.PayGlocalCurrency.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json();
		}).then(function (result) {
			if (!result || !result.success) {
				if (status) {
					status.textContent = (result && result.data && result.data.message) || 'Currency could not be updated.';
				}
				Array.prototype.forEach.call(controls, function (select) {
					select.disabled = false;
				});
				return;
			}

			applyFragments(result.data && result.data.fragments);
			if (window.jQuery) {
				window.jQuery(document.body).trigger('payglocal_currency_changed', [result.data]);
				window.jQuery(document.body).trigger('wc_fragment_refresh');
			}

			if (needsReload()) {
				var url = new URL(window.location.href);
				url.searchParams.delete('min_price');
				url.searchParams.delete('max_price');
				window.location.assign(url.toString());
				return;
			}

			Array.prototype.forEach.call(controls, function (select) {
				select.disabled = false;
			});
		}).catch(function () {
			if (status) {
				status.textContent = 'Currency could not be updated.';
			}
			Array.prototype.forEach.call(controls, function (select) {
				select.disabled = false;
			});
		});
	}

	function applyFragments(fragments) {
		if (!fragments || !window.jQuery) {
			return;
		}
		window.jQuery.each(fragments, function (selector, html) {
			window.jQuery(selector).replaceWith(html);
		});
	}

	function needsReload() {
		var body = document.body;
		if (!body) {
			return true;
		}
		if (body.classList.contains('woocommerce-cart') || body.classList.contains('woocommerce-checkout')) {
			return true;
		}
		return !!document.querySelector('.price, .wc-block-components-product-price, .wc-block-components-totals-item, .wp-block-woocommerce-product-price');
	}
}());
