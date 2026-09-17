/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * Modified by BW-Tech GmbH on 2026-09-17.
 * Changes:
 *   - configuration source as readable, translated text
 *   - no permanent "Configuration loaded." status after opening the page;
 *     the status region only speaks when something happened
 *   - reset asks with the core dialog instead of window.confirm
 *   - keyboard focus returns to the triggering button after saving or
 *     resetting (disabling the focused button dropped it onto <body>)
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
		var sourceTexts = {
			appconfig: t('openidconnect', 'App configuration (set on this page)'),
			system: t('openidconnect', 'config.php'),
			empty: t('openidconnect', 'Not configured')
		};

		function showMessage(text, isError) {
			$message
				.text(text)
				.toggleClass('error', isError)
				.toggleClass('success', !isError)
				.attr('role', isError ? 'alert' : 'status')
				.attr('aria-live', isError ? 'assertive' : 'polite');
		}

		var focusBeforeBusy = null;

		function setBusy(isBusy) {
			if (isBusy && focusBeforeBusy === null) {
				focusBeforeBusy = document.activeElement;
			}
			$form.find('input, select, textarea, button').prop('disabled', isBusy);
			$raw.prop('disabled', false);
			$form.attr('aria-busy', isBusy ? 'true' : 'false');
			if (!isBusy) {
				var ziel = focusBeforeBusy;
				focusBeforeBusy = null;
				if (ziel && $.contains($form[0], ziel)
					&& (document.activeElement === null || document.activeElement === document.body)) {
					ziel.focus();
				}
			}
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
			$source.text(sourceTexts[response.source] || sourceTexts.empty);
			$raw.val(response.raw || '');

			$.each(config, function (name, value) {
				setField(name, value);
			});
		}

		function errorText(xhr, fallback) {
			return xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : fallback;
		}

		function loadConfig() {
			setBusy(true);
			$.ajax({
				type: 'GET',
				url: OC.generateUrl('/apps/openidconnect/settings/config')
			}).done(function (response) {
				renderConfig(response);
			}).fail(function (xhr) {
				showMessage(errorText(xhr, t('openidconnect', 'Could not load OpenID Connect configuration.')), true);
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
				showMessage(errorText(xhr, t('openidconnect', 'Could not save OpenID Connect configuration.')), true);
			}).always(function () {
				setBusy(false);
			});
		});

		function resetConfig() {
			setBusy(true);
			$.ajax({
				type: 'POST',
				url: OC.generateUrl('/apps/openidconnect/settings/reset')
			}).done(function (response) {
				renderConfig(response);
				showMessage(t('openidconnect', 'OpenID Connect app configuration override removed.'), false);
			}).fail(function (xhr) {
				showMessage(errorText(xhr, t('openidconnect', 'Could not reset OpenID Connect app configuration.')), true);
			}).always(function () {
				setBusy(false);
			});
		}

		$('#openidconnect-reset-appconfig').on('click', function () {
			var knopf = this;
			var frage = t('openidconnect', 'Remove the OpenID Connect app configuration override and use config.php again?');
			if (!OC.dialogs || typeof OC.dialogs.confirm !== 'function') {
				if (window.confirm(frage)) {
					resetConfig();
				}
				return;
			}
			OC.dialogs.confirm(frage, t('openidconnect', 'Use system config'), function (bestaetigt) {
				// Der Dialog hat den Fokus genommen; danach gehört er zurück auf den Knopf.
				if (bestaetigt) {
					focusBeforeBusy = knopf;
					resetConfig();
				} else {
					knopf.focus();
				}
			}, true, t('openidconnect', 'Cancel'), t('openidconnect', 'Remove app configuration'));
		});

		loadConfig();
	});
})(jQuery, OC);
