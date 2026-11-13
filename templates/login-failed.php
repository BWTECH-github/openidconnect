<?php
/**
 * Anmeldung über OpenID Connect gescheitert (Gastansicht): kein passendes
 * Konto (reason "account") oder Anbieter nicht erreichbar bzw. Antwort
 * unbrauchbar (reason "provider").
 *
 * Gleiches Markup wie die Fehlerseiten des Kerns (core/templates/error.php),
 * damit die Anmeldekarte es gestaltet. Der Rückweg ist der Hauptknopf der
 * Karte (Klasse des Anmeldeknopfs), die Abmeldung beim Anbieter ein
 * Textverweis darunter.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0
 */
style('openidconnect', 'login-failed');
?>
<ul class="error-wide">
	<li class="error">
		<?php if ($_['reason'] === 'provider'): ?>
		<?php p($l->t('Sign-in with the identity provider failed.')); ?><br>
		<p class="hint"><?php p($l->t('The identity provider could not be reached or rejected the request. Please try again later or contact your administrator.')); ?></p>
		<?php else: ?>
		<?php p($l->t('The login with your external account did not work.')); ?><br>
		<p class="hint"><?php p($l->t('There is no account on this server for the identity you signed in with, or it may not log in this way. Please contact your administrator.')); ?></p>
		<?php if ($_['providerLogoutUrl'] !== ''): ?>
		<p class="hint"><?php p($l->t('Signed in with a different account at the provider? Sign out there and try again.')); ?></p>
		<?php endif; ?>
		<?php endif; ?>
	</li>
</ul>
<a class="login-button" id="openidconnect-back-to-login" href="<?php p($_['loginUrl']); ?>"><?php p($l->t('Back to login')); ?></a>
<?php if ($_['providerLogoutUrl'] !== ''): ?>
<p class="openidconnect-login-failed-logout">
	<a class="lost-password" id="openidconnect-provider-logout" href="<?php p($_['providerLogoutUrl']); ?>"><?php p($l->t('Sign out at the provider')); ?></a>
</p>
<?php endif; ?>
