(function () {
	'use strict';

	var config = window.EtchpointBachsWooReturn;

	if (!config || !config.ajaxUrl || !config.orderId || !config.orderKey || !config.nonce) {
		return;
	}

	var attempts = 0;
	var message = document.getElementById(config.messageElementId || 'etchpoint-bachs-confirmation-message');
	var maxAttempts = parseInt(config.maxAttempts, 10) || 24;
	var delay = parseInt(config.delay, 10) || 2500;
	var storageKey = config.storageKey || '';
	var messages = config.messages || {};

	try {
		attempts = parseInt(window.sessionStorage.getItem(storageKey) || '0', 10);
	} catch (error) {
		attempts = 0;
	}

	function setAttempts(value) {
		attempts = value;
		try {
			window.sessionStorage.setItem(storageKey, String(value));
		} catch (error) {
			// Session storage is optional.
		}
	}

	function stopWaiting() {
		if (message && messages.timeout) {
			message.textContent = messages.timeout;
		}
	}

	function scheduleNext() {
		if (attempts >= maxAttempts) {
			stopWaiting();
			return;
		}

		window.setTimeout(checkStatus, delay);
	}

	function checkStatus() {
		var body = new URLSearchParams();
		body.set('action', config.action);
		body.set('order_id', String(config.orderId));
		body.set('order_key', config.orderKey);
		body.set('nonce', config.nonce);

		setAttempts(attempts + 1);

		window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data) {
				scheduleNext();
				return;
			}

			if ('paid' === payload.data.state && payload.data.redirect) {
				try {
					window.sessionStorage.removeItem(storageKey);
				} catch (error) {
					// Session storage is optional.
				}

				if (message && messages.paid) {
					message.textContent = messages.paid;
				}

				window.location.replace(payload.data.redirect);
				return;
			}

			if ('failed' === payload.data.state) {
				if (message && messages.failed) {
					message.textContent = messages.failed;
				}
				return;
			}

			scheduleNext();
		}).catch(function () {
			scheduleNext();
		});
	}

	if (attempts >= maxAttempts) {
		stopWaiting();
		return;
	}

	checkStatus();
}());
