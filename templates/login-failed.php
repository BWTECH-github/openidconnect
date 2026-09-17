<?php
/**
 * Anmeldung über OpenID Connect ohne passendes Konto (Gastansicht).
 *
 * Gleiches Markup wie die Fehlerseiten des Kerns (core/templates/error.php),
 * damit die Anmeldekarte es gestaltet.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0
 */
?>
<ul class="error-wide">
	<li class="error">
		<?php p($l->t('The login with your external account did not work.')); ?><br>
		<p class="hint"><?php p($l->t('There is no account on this server for the identity you signed in with, or it may not log in this way. Please contact your administrator.')); ?></p>
		<?php if ($_['providerLogoutUrl'] !== ''): ?>
		<p class="hint"><?php p($l->t('Signed in with a different account at the provider? Sign out there and try again.')); ?></p>
		<?php endif; ?>
	</li>
</ul>
<?php if ($_['loginUrl'] !== '' || $_['providerLogoutUrl'] !== ''): ?>
<p class="openidconnect-login-failed-back">
	<?php if ($_['providerLogoutUrl'] !== ''): ?>
	<a class="button" id="openidconnect-provider-logout" href="<?php p($_['providerLogoutUrl']); ?>"><?php p($l->t('Sign out at the provider')); ?></a>
	<?php endif; ?>
	<?php if ($_['loginUrl'] !== ''): ?>
	<a class="button" id="openidconnect-back-to-login" href="<?php p($_['loginUrl']); ?>"><?php p($l->t('Back to login')); ?></a>
	<?php endif; ?>
</p>
<?php endif; ?>
