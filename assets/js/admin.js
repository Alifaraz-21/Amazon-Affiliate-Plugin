(function () {
	function toNumber(value) {
		var number = parseFloat(String(value || '').replace(/[^0-9.-]/g, ''));
		return Number.isFinite(number) ? number : 0;
	}

	function updateTotals(scope) {
		var quantity = Math.max(1, toNumber((scope.querySelector('[data-cdac-quantity]') || {}).value));
		var unit = toNumber((scope.querySelector('[data-cdac-cost="unit"]') || {}).value);
		var service = toNumber((scope.querySelector('[data-cdac-cost="service"]') || {}).value);
		var tax = toNumber((scope.querySelector('[data-cdac-cost="tax"]') || {}).value);
		var total = quantity * unit + service + shipping + tax;
		var output = scope.querySelector('[data-cdac-total]');

		if (output) {
			output.textContent = total.toFixed(2);
		}
	}

	function bindCalculator(scope) {
		var fields = scope.querySelectorAll('[data-cdac-quantity], [data-cdac-cost]');

		if (!fields.length) {
			return;
		}

		fields.forEach(function (field) {
			field.addEventListener('input', function () {
				updateTotals(scope);
			});
		});

		updateTotals(scope);
	}

	function fillField(scope, name, value) {
		var field = scope.querySelector('[name="' + name + '"]');

		if (field && value !== undefined && value !== null) {
			field.value = value;
			field.dispatchEvent(new Event('input', { bubbles: true }));
		}
	}

	function buildAffiliateUrl(rawUrl, asin, tag, marketplace) {
		var url = rawUrl || '';
		var cleanTag = tag || '';
		var baseMarketplace = (marketplace || 'https://www.amazon.com').replace(/\/+$/, '');

		if (!cleanTag) {
			return '';
		}

		if ((!url || url.indexOf('amazon.') === -1) && asin) {
			url = baseMarketplace + '/dp/' + encodeURIComponent(asin);
		}

		if (!url || url.indexOf('amazon.') === -1) {
			return '';
		}

		try {
			var built = new URL(url, window.location.origin);
			built.searchParams.set('tag', cleanTag);
			return built.toString();
		} catch (error) {
			return '';
		}
	}

	function bindProductSelector(scope) {
		var selector = scope.querySelector('[data-cdac-product-select]');

		if (!selector) {
			return;
		}

		selector.addEventListener('change', function () {
			var selected = selector.options[selector.selectedIndex];

			if (!selected || !selected.value) {
				return;
			}

			fillField(scope, 'product_title', selected.dataset.title);
			fillField(scope, 'product_url', selected.dataset.url);
			fillField(scope, 'asin', selected.dataset.asin);
			fillField(scope, 'unit_cost', selected.dataset.unitCost);
			fillField(scope, 'affiliate_url', selected.dataset.affiliate);

			var source = scope.querySelector('[name="product_source"]');
			if (source) {
				source.value = selected.dataset.amazonUrl ? 'amazon' : 'ctrldeals';
			}

			updateTotals(scope);
		});
	}

	function bindAffiliateGenerator(scope) {
		var button = scope.querySelector('[data-cdac-generate-affiliate]');

		if (!button) {
			return;
		}

		button.addEventListener('click', function () {
			var selector = scope.querySelector('[data-cdac-product-select]');
			var selected = selector && selector.value ? selector.options[selector.selectedIndex] : null;
			var productUrl = (scope.querySelector('[name="product_url"]') || {}).value || '';
			var amazonUrl = selected ? selected.dataset.amazonUrl : '';
			var asin = (scope.querySelector('[name="asin"]') || {}).value || '';
			var output = scope.querySelector('[name="affiliate_url"]');
			var affiliateUrl = buildAffiliateUrl(amazonUrl || productUrl, asin, button.dataset.cdacTag, button.dataset.cdacMarketplace);

			if (output && affiliateUrl) {
				output.value = affiliateUrl;
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.cdac-form, .cdac-front-form').forEach(function (scope) {
			bindCalculator(scope);
			bindProductSelector(scope);
			bindAffiliateGenerator(scope);
		});

		document.querySelectorAll('[data-cdac-copy-url]').forEach(function (button) {
			button.addEventListener('click', function () {
				var input = button.parentElement ? button.parentElement.querySelector('input') : null;

				if (!input) {
					return;
				}

				input.select();
				document.execCommand('copy');
				button.textContent = 'Copied';
			});
		});
	});
})();
