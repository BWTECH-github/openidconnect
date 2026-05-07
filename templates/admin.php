<?php
/**
 * Modified by BW-Tech GmbH for owncloud.online (PHP 8.4).
 *
 * @license GPL-2.0
 */
?>
<div class="section" id="openidconnect-admin">
	<h2><?php p($l->t('OpenID Connect')); ?></h2>
	<p class="openidconnect-config-source">
		<?php p($l->t('Configuration source')); ?>:
		<strong id="openidconnect-config-source">-</strong>
	</p>
	<p class="openidconnect-required-note">
		<span class="openidconnect-required-marker">*</span>
		<?php p($l->t('Required fields. The configuration is saved as app configuration and overrides config.php.')); ?>
	</p>

	<form id="openidconnect-admin-form">
		<fieldset>
			<legend><?php p($l->t('Identity Provider')); ?></legend>
			<p>
				<label for="openidconnect-provider-url"><?php p($l->t('Provider URL')); ?> <span class="openidconnect-required-marker">*</span></label>
				<input type="url" id="openidconnect-provider-url" name="provider-url" required="required" placeholder="https://idp.example.com" />
			</p>
			<p>
				<label for="openidconnect-client-id"><?php p($l->t('Client ID')); ?> <span class="openidconnect-required-marker">*</span></label>
				<input type="text" id="openidconnect-client-id" name="client-id" required="required" autocomplete="off" />
			</p>
			<p>
				<label for="openidconnect-client-secret"><?php p($l->t('Client secret')); ?> <span class="openidconnect-required-marker">*</span></label>
				<input type="password" id="openidconnect-client-secret" name="client-secret" required="required" autocomplete="new-password" />
			</p>
			<p>
				<label for="openidconnect-scopes"><?php p($l->t('Scopes')); ?></label>
				<textarea id="openidconnect-scopes" name="scopes" rows="3"></textarea>
			</p>
			<p>
				<label for="openidconnect-login-button-name"><?php p($l->t('Login button text')); ?></label>
				<input type="text" id="openidconnect-login-button-name" name="loginButtonName" />
			</p>
		</fieldset>

		<fieldset>
			<legend><?php p($l->t('User mapping')); ?></legend>
			<p>
				<label for="openidconnect-mode"><?php p($l->t('Lookup mode')); ?></label>
				<select id="openidconnect-mode" name="mode">
					<option value="userid"><?php p($l->t('User ID')); ?></option>
					<option value="email"><?php p($l->t('Email')); ?></option>
				</select>
			</p>
			<p>
				<label for="openidconnect-search-attribute"><?php p($l->t('Search claim')); ?></label>
				<input type="text" id="openidconnect-search-attribute" name="search-attribute" placeholder="email" />
			</p>
			<p>
				<label for="openidconnect-allowed-user-backends"><?php p($l->t('Allowed user backends')); ?></label>
				<textarea id="openidconnect-allowed-user-backends" name="allowed-user-backends" rows="3"></textarea>
			</p>
		</fieldset>

		<fieldset>
			<legend><?php p($l->t('Login and logout')); ?></legend>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-auto-redirect" name="autoRedirectOnLoginPage" value="1" />
				<label for="openidconnect-auto-redirect"><?php p($l->t('Redirect login page automatically')); ?></label>
			</p>
			<p>
				<label for="openidconnect-redirect-url"><?php p($l->t('Redirect URL override')); ?></label>
				<input type="url" id="openidconnect-redirect-url" name="redirect-url" />
			</p>
			<p>
				<label for="openidconnect-post-logout-redirect-uri"><?php p($l->t('Post logout redirect URI')); ?></label>
				<input type="url" id="openidconnect-post-logout-redirect-uri" name="post_logout_redirect_uri" />
			</p>
		</fieldset>

		<fieldset>
			<legend><?php p($l->t('Auto provisioning')); ?></legend>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-auto-provision-enabled" name="auto-provision-enabled" value="1" />
				<label for="openidconnect-auto-provision-enabled"><?php p($l->t('Create missing users on first login')); ?></label>
			</p>
			<p>
				<label for="openidconnect-auto-provision-groups"><?php p($l->t('Groups')); ?></label>
				<textarea id="openidconnect-auto-provision-groups" name="auto-provision-groups" rows="3"></textarea>
			</p>
			<p>
				<label for="openidconnect-auto-provision-email-claim"><?php p($l->t('Email claim')); ?></label>
				<input type="text" id="openidconnect-auto-provision-email-claim" name="auto-provision-email-claim" placeholder="email" />
			</p>
			<p>
				<label for="openidconnect-auto-provision-display-name-claim"><?php p($l->t('Display name claim')); ?></label>
				<input type="text" id="openidconnect-auto-provision-display-name-claim" name="auto-provision-display-name-claim" placeholder="name" />
			</p>
			<p>
				<label for="openidconnect-auto-provision-picture-claim"><?php p($l->t('Picture claim')); ?></label>
				<input type="text" id="openidconnect-auto-provision-picture-claim" name="auto-provision-picture-claim" placeholder="picture" />
			</p>
			<p>
				<label for="openidconnect-auto-provision-provisioning-claim"><?php p($l->t('Provisioning claim')); ?></label>
				<input type="text" id="openidconnect-auto-provision-provisioning-claim" name="auto-provision-provisioning-claim" />
			</p>
			<p>
				<label for="openidconnect-auto-provision-provisioning-attribute"><?php p($l->t('Provisioning attribute')); ?></label>
				<input type="text" id="openidconnect-auto-provision-provisioning-attribute" name="auto-provision-provisioning-attribute" />
			</p>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-auto-provision-update-enabled" name="auto-provision-update-enabled" value="1" />
				<label for="openidconnect-auto-provision-update-enabled"><?php p($l->t('Update email and display name on login')); ?></label>
			</p>
		</fieldset>

		<fieldset>
			<legend><?php p($l->t('Tokens and user info')); ?></legend>
			<p>
				<label for="openidconnect-token-introspection-client-id"><?php p($l->t('Introspection client ID')); ?></label>
				<input type="text" id="openidconnect-token-introspection-client-id" name="token-introspection-endpoint-client-id" autocomplete="off" />
			</p>
			<p>
				<label for="openidconnect-token-introspection-client-secret"><?php p($l->t('Introspection client secret')); ?></label>
				<input type="password" id="openidconnect-token-introspection-client-secret" name="token-introspection-endpoint-client-secret" autocomplete="new-password" />
			</p>
			<p>
				<label for="openidconnect-exchange-token-mode-before-introspection"><?php p($l->t('Token exchange mode')); ?></label>
				<select id="openidconnect-exchange-token-mode-before-introspection" name="exchange-token-mode-before-introspection">
					<option value=""></option>
					<option value="access-token"><?php p($l->t('Access token')); ?></option>
					<option value="refresh-token"><?php p($l->t('Refresh token')); ?></option>
				</select>
			</p>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-use-access-token-payload-for-user-info" name="use-access-token-payload-for-user-info" value="1" />
				<label for="openidconnect-use-access-token-payload-for-user-info"><?php p($l->t('Use access token payload for user info')); ?></label>
			</p>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-use-access-token-introspection-for-user-info" name="use-access-token-introspection-for-user-info" value="1" />
				<label for="openidconnect-use-access-token-introspection-for-user-info"><?php p($l->t('Use token introspection for user info')); ?></label>
			</p>
		</fieldset>

		<fieldset>
			<legend><?php p($l->t('Advanced')); ?></legend>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-insecure" name="insecure" value="1" />
				<label for="openidconnect-insecure"><?php p($l->t('Disable TLS verification')); ?></label>
			</p>
			<p class="checkbox-row">
				<input type="checkbox" id="openidconnect-jwt-self-signed-jwk-header-supported" name="jwt-self-signed-jwk-header-supported" value="1" />
				<label for="openidconnect-jwt-self-signed-jwk-header-supported"><?php p($l->t('Allow self-signed JWK headers')); ?></label>
			</p>
			<p>
				<label for="openidconnect-ocis-routing-policy-claim"><?php p($l->t('Routing policy claim')); ?></label>
				<input type="text" id="openidconnect-ocis-routing-policy-claim" name="ocis-routing-policy-claim" />
			</p>
			<p>
				<label for="openidconnect-ocis-routing-policy-cookie"><?php p($l->t('Routing policy cookie')); ?></label>
				<input type="text" id="openidconnect-ocis-routing-policy-cookie" name="ocis-routing-policy-cookie" />
			</p>
			<p>
				<label for="openidconnect-ocis-routing-policy-cookie-directives"><?php p($l->t('Routing policy cookie directives')); ?></label>
				<input type="text" id="openidconnect-ocis-routing-policy-cookie-directives" name="ocis-routing-policy-cookie-directives" />
			</p>
			<p>
				<label for="openidconnect-provider-params"><?php p($l->t('Provider params JSON')); ?></label>
				<textarea id="openidconnect-provider-params" name="provider-params" rows="7"></textarea>
			</p>
			<p>
				<label for="openidconnect-auth-params"><?php p($l->t('Auth params JSON')); ?></label>
				<textarea id="openidconnect-auth-params" name="auth-params" rows="7"></textarea>
			</p>
		</fieldset>

		<p class="openidconnect-actions">
			<button type="submit" class="button primary" id="openidconnect-save"><?php p($l->t('Save')); ?></button>
			<button type="button" class="button" id="openidconnect-reset-appconfig"><?php p($l->t('Use system config')); ?></button>
			<span id="openidconnect-message" class="msg"></span>
		</p>

		<fieldset>
			<legend><?php p($l->t('Generated app configuration')); ?></legend>
			<textarea id="openidconnect-raw-config" readonly="readonly" rows="14"></textarea>
		</fieldset>
	</form>
</div>
