/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 */
(function ($, OC) {
	$(document).ready(function () {
		var $section = $('#openidconnect-admin');
		if ($section.length === 0) {
			return;
		}

		var $form = $('#openidconnect-admin-form');
		var $message = $('#openidconnect-message');
		var $source = $('#openidconnect-config-source');
		var $raw = $('#openidconnect-raw-config');
		var boolFields = [
			'autoRedirectOnLoginPage',
			'insecure',
			'use-access-token-payload-for-user-info',
			'use-access-token-introspection-for-user-info',
			'jwt-self-signed-jwk-header-supported',
			'auto-provision-enabled',
			'auto-provision-update-enabled'
		];

		function showMessage(text, isError) {
			$message
				.text(text)
				.toggleClass('error', isError)
				.toggleClass('success', !isError);
		}

		function setBusy(isBusy) {
			$form.find('input, select, textarea, button').prop('disabled', isBusy);
			$raw.prop('disabled', false);
		}

		function fieldSelector(name) {
			return '[name="' + name.replace(/"/g, '\\"') + '"]';
		}

		function setField(name, value) {
			var $field = $form.find(fieldSelector(name));
			if ($field.attr('type') === 'checkbox') {
				$field.prop('checked', !!value);
				return;
			}
			$field.val(value === null || value === undefined ? '' : value);
		}

		function renderConfig(response) {
			var config = response.config || {};
			$source.text(response.source || 'empty');
			$raw.val(response.raw || '');

			$.each(config, function (name, value) {
				setField(name, value);
			});
		}

		function loadConfig() {
			setBusy(true);
			$.ajax({
				type: 'GET',
				url: OC.generateUrl('/apps/openidconnect/settings/config')
			}).done(function (response) {
				renderConfig(response);
				showMessage(t('openidconnect', 'Configuration loaded.'), false);
			}).fail(function (xhr) {
				var message = t('openidconnect', 'Could not load OpenID Connect configuration.');
				if (xhr.responseJSON && xhr.responseJSON.message) {
					message = xhr.responseJSON.message;
				}
				showMessage(message, true);
			}).always(function () {
				setBusy(false);
			});
		}

		function collectConfig() {
			var data = {};
			$.each($form.serializeArray(), function (_, item) {
				data[item.name] = item.value;
			});
			$.each(boolFields, function (_, name) {
				data[name] = $form.find(fieldSelector(name)).is(':checked') ? '1' : '0';
			});
			return data;
		}

		$form.on('submit', function (event) {
			event.preventDefault();
			var configData = collectConfig();
			setBusy(true);
			showMessage(t('openidconnect', 'Saving configuration...'), false);

			$.ajax({
				type: 'POST',
				url: OC.generateUrl('/apps/openidconnect/settings/config'),
				data: configData
			}).done(function (response) {
				renderConfig(response);
				showMessage(t('openidconnect', 'OpenID Connect configuration saved.'), false);
			}).fail(function (xhr) {
				var message = t('openidconnect', 'Could not save OpenID Connect configuration.');
				if (xhr.responseJSON && xhr.responseJSON.message) {
					message = xhr.responseJSON.message;
				}
				showMessage(message, true);
			}).always(function () {
				setBusy(false);
			});
		});

		$('#openidconnect-reset-appconfig').on('click', function () {
			if (!window.confirm(t('openidconnect', 'Remove the OpenID Connect app configuration override and use config.php again?'))) {
				return;
			}

			setBusy(true);
			$.ajax({
				type: 'POST',
				url: OC.generateUrl('/apps/openidconnect/settings/reset')
			}).done(function (response) {
				renderConfig(response);
				showMessage(t('openidconnect', 'OpenID Connect app configuration override removed.'), false);
			}).fail(function (xhr) {
				var message = t('openidconnect', 'Could not reset OpenID Connect app configuration.');
				if (xhr.responseJSON && xhr.responseJSON.message) {
					message = xhr.responseJSON.message;
				}
				showMessage(message, true);
			}).always(function () {
				setBusy(false);
			});
		});

		loadConfig();
	});
})(jQuery, OC);
