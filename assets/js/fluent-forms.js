(function ($) {
	'use strict';

	var eventName = 'fluentform_next_action_payment';

	function isBachsRedirect(payload) {
		var response = payload && payload.response ? payload.response : null;
		var data = response && response.data ? response.data : null;

		return !!(
			data &&
			data.nextAction === 'payment' &&
			data.actionName === 'normalRedirect' &&
			data.payment_method === 'bachs' &&
			data.redirect_url
		);
	}

	function rememberButton($button) {
		if ($button.data('etchpointBachsStored')) {
			return;
		}

		$button.data('etchpointBachsStored', true);
		$button.data('etchpointBachsOriginalDisabled', $button.prop('disabled'));
		$button.data('etchpointBachsOriginalAriaBusy', $button.attr('aria-busy'));

		if ($button.is('input')) {
			$button.data('etchpointBachsOriginalValue', $button.val());
		} else {
			$button.data('etchpointBachsOriginalHtml', $button.html());
		}
	}

	function applyRedirectingState($form, label) {
		var $button = $form.find('.ff-btn-submit').first();

		if (!$button.length) {
			return;
		}

		rememberButton($button);

		$button
			.addClass('etchpoint-bachs-redirecting')
			.prop('disabled', true)
			.attr('aria-busy', 'true');

		if ($button.is('input')) {
			$button.val(label);
			return;
		}

		$button.empty();
		$('<span/>', {
			'class': 'etchpoint-bachs-spinner',
			'aria-hidden': 'true'
		}).appendTo($button);
		$('<span/>', {
			'class': 'etchpoint-bachs-redirect-label',
			text: label
		}).appendTo($button);
	}

	function restoreButton($button) {
		if (!$button.data('etchpointBachsStored')) {
			return;
		}

		if ($button.is('input')) {
			$button.val($button.data('etchpointBachsOriginalValue'));
		} else {
			$button.html($button.data('etchpointBachsOriginalHtml'));
		}

		$button.removeClass('etchpoint-bachs-redirecting');
		$button.prop('disabled', !!$button.data('etchpointBachsOriginalDisabled'));

		var originalAriaBusy = $button.data('etchpointBachsOriginalAriaBusy');
		if (typeof originalAriaBusy === 'undefined') {
			$button.removeAttr('aria-busy');
		} else {
			$button.attr('aria-busy', originalAriaBusy);
		}

		$button.removeData('etchpointBachsStored');
		$button.removeData('etchpointBachsOriginalDisabled');
		$button.removeData('etchpointBachsOriginalAriaBusy');
		$button.removeData('etchpointBachsOriginalValue');
		$button.removeData('etchpointBachsOriginalHtml');
	}

	$(document).on(eventName, 'form', function (event, payload) {
		if (!isBachsRedirect(payload)) {
			return;
		}

		var $form = payload.form && payload.form.jquery ? payload.form : $(event.currentTarget);
		var data = payload.response.data;
		var label = data.bachs_redirect_label || 'Redirecting to Bachs checkout...';

		applyRedirectingState($form, label);

		// Fluent Forms removes its own loading state in the request's always()
		// callback. Re-apply ours immediately afterwards while the browser leaves.
		window.setTimeout(function () {
			applyRedirectingState($form, label);
		}, 0);
	});

	$(window).on('pageshow', function () {
		$('.ff-btn-submit.etchpoint-bachs-redirecting').each(function () {
			restoreButton($(this));
		});
	});
})(jQuery);
