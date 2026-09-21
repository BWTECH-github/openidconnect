# OpenID Connect for owncloud.online

Authentication and SSO with OpenID Connect (OIDC) for owncloud.online Server. This is a
PHP 8.4 fork of the upstream `openidconnect` app, maintained by BW-Tech GmbH for
[owncloud.online](https://github.com/BWTECH-github/owncloud.online) at
[BWTECH-github/openidconnect](https://github.com/BWTECH-github/openidconnect).

The app integrates an external Identity Provider (Keycloak, Kopano Konnect, Ping
Federate, ADFS, Azure AD, etc.) into owncloud.online as the primary login mechanism.

## Features

- Login on the owncloud.online web UI through an external OpenID Connect provider.
- Bearer-token authentication for desktop, mobile and Phoenix clients via the
  registered auth module.
- WebDAV/Sabre Bearer / PoP token authentication.
- Automatic provisioning of unknown users on first login (configurable).
- Optional automatic update of email and display name on each login.
- Optional automatic redirect from the owncloud.online login page to the IdP.
- Front-channel logout endpoint for IdP-initiated logout.
- RFC 8693 token exchange before introspection (e.g., refresh-token → access-token).
- Restriction of OIDC logins to specific user backends.
- Routing-policy cookie based on a user-info claim (for ocis routing).

## Requirements

- owncloud.online 11 (`<owncloud min-version="11">` in `info.xml`); version 3.x
  is the line for the owncloud.online 11.1 redesign and still runs on 11.0.
- PHP 8.4 or newer.
- A working distributed memory cache (Redis, Memcached, APCu) - the app refuses
  to boot otherwise on non-CLI requests.
- An OpenID Connect provider that exposes a `.well-known/openid-configuration`
  document.

## Installation

```bash
cd /path/to/owncloud/apps
git clone https://github.com/BWTECH-github/openidconnect.git
cd openidconnect
composer install --no-dev
chown -R www-data:www-data .
cd ../..
sudo -u www-data ./occ app:enable openidconnect
```

Replace `www-data` with the user under which your web server runs.

## Configuration

The app reads its configuration from `config/config.php` under the
`openid-connect` key (or - if present - from the `appconfig` table). Minimum
configuration:

```php
<?php
$CONFIG = [
    'openid-connect' => [
        'provider-url'  => 'https://idp.example.com',
        'client-id'     => 'owncloud-online',
        'client-secret' => 'change-me',
        'loginButtonName' => 'Login via OpenID Connect',
        'mode'          => 'userid',          // or 'email'
        'search-attribute' => 'preferred_username',
    ],
];
```

### Common keys

| Key | Type | Description |
| --- | --- | --- |
| `provider-url` | string | Issuer URL of the IdP. |
| `client-id` | string | OAuth2 client id registered with the IdP. |
| `client-secret` | string | OAuth2 client secret. |
| `scopes` | string[] | Scopes to request. Defaults to `['openid', 'profile', 'email']`. |
| `mode` | string | `userid` (default) maps the IdP attribute to the owncloud.online user id; `email` looks the user up by email. |
| `search-attribute` | string | Claim used to identify the user. Defaults to `email`. |
| `loginButtonName` | string | Label of the alternative login button. |
| `autoRedirectOnLoginPage` | bool | If `true`, the login page redirects to the IdP automatically. |
| `insecure` | bool | Disable TLS host/peer verification (development only). |
| `redirect-url` | string | Override the OAuth2 redirect URL. |
| `post_logout_redirect_uri` | string | URL to redirect to after IdP logout. |
| `auto-provision` | array | See *Auto provisioning* below. |
| `allowed-user-backends` | string[] | Restrict logins to users from these backend classes. |
| `provider-params` | array | Static OpenID configuration if `.well-known` is unavailable. |
| `auth-params` | array | Extra query parameters added to the authorization request. |
| `token-introspection-endpoint-client-id` | string | Client used for RFC 7662 introspection. |
| `token-introspection-endpoint-client-secret` | string | Secret for the introspection client. |
| `exchange-token-mode-before-introspection` | string | `access-token` or `refresh-token`; performs an RFC 8693 exchange before introspection. |
| `use-access-token-payload-for-user-info` | bool | Use the JWT payload instead of `userinfo`. |
| `use-access-token-introspection-for-user-info` | bool | Use introspection results as the user-info source. |
| `jwt-self-signed-jwk-header-supported` | bool | Allow self-signed JWK headers. |
| `token-aud-check` | bool | **On by default.** The access token must name this relying party, so a token the same issuer minted for another client is rejected (OC10-115, OC10-147). For a JWT that means the configured `client-id` has to appear in `aud`; for an opaque token the introspection response has to carry a matching `client_id` (RFC 7662 §2.2), with `aud` as the fallback — which is why an IdP that puts a resource name into `aud` (Keycloak writes `account`, Okta `api://default`) still works. Set to `false` only for an IdP that can neither be fixed nor name this instance: that reopens both findings. |
| `ocis-routing-policy-claim` | string | User-info claim that drives the routing-policy cookie. |
| `ocis-routing-policy-cookie` | string | Cookie name (default `owncloud-selector`). |
| `ocis-routing-policy-cookie-directives` | string | Cookie directives (default `path=/;`). |

### Auto provisioning

```php
'openid-connect' => [
    // ...
    'auto-provision' => [
        'enabled' => true,
        'groups'  => ['oidc-users'],
        'email-claim'        => 'email',
        'display-name-claim' => 'name',
        'picture-claim'      => 'picture',
        'provisioning-claim'     => 'roles',
        'provisioning-attribute' => 'owncloud-online-user',
        'update' => [
            'enabled' => true,   // sync e-mail and display name on each login
        ],
    ],
],
```

`provisioning-claim` and `provisioning-attribute` together gate provisioning:
the user is only created if the claim contains the listed attribute.

## OCC commands

This app does not register any custom OCC commands. Operate it through the
standard ones:

| Command | Purpose |
| --- | --- |
| `occ app:enable openidconnect` | Enable the app. |
| `occ app:disable openidconnect` | Disable the app. |
| `occ config:list system` | Inspect the configured `openid-connect` block. |
| `occ config:system:set openid-connect ...` | Edit configuration without touching `config.php`. |
| `occ user:delete <uid>` | Remove a provisioned user. |

## Daily usage

- Users open `/login` and are either redirected to the IdP automatically (if
  `autoRedirectOnLoginPage` is set) or click the configured login button. An
  empty `loginButtonName` shows "OpenID Connect".
- After the IdP login users land on the page they originally requested
  (`redirect_url`, e.g. a file link or an OAuth2 authorization of a client),
  otherwise on the start page. Targets containing `@` are dropped, as in the
  core login.
- If the identity from the IdP matches no usable account (unknown user,
  not unique, wrong user backend, auto provisioning off), users see a
  translated page with a link back to the login page (no link when
  `autoRedirectOnLoginPage` is set) and, if the IdP publishes an
  `end_session_endpoint`, a link to sign out there (otherwise the IdP signs the
  same account in again). The log names the cause without the identity, e.g.
  `OpenID::login: the account belongs to a user backend that is not in
  allowed-user-backends (OC\User\Database)`.
- Desktop and mobile clients send the IdP's access token in the `Authorization`
  header (`Bearer ...` or `PoP ...`). The auth module verifies the token via
  signature or introspection, looks up / provisions the user and continues.
- Logout from owncloud.online calls `revokeToken` and `signOut` on the IdP. The
  back-channel logout endpoint at `/apps/openidconnect/logout` accepts an `iss`
  / `sid` pair from the IdP and invalidates the cached session.

## Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `A real distributed mem cache setup is required` on boot | No memcache configured. | Configure Redis, Memcached or APCu in `config.php` (`memcache.distributed`). |
| `Configuration issue in openidconnect app` thrown on login | `openid-connect` config missing or unreadable. | Verify `config:list system` output and provider URL. |
| `Self signed JWK header is not valid` | IdP issues self-signed JWK headers. | Set `jwt-self-signed-jwk-header-supported => true` if you trust the IdP. |
| `Token cannot be verified` | Signature check failed (clock skew, wrong issuer, stale JWKS). | Check IdP `jwks_uri` reachability and server time sync. |
| Page "The login with your external account did not work" | See the `OpenID::login:` warning in the log: `no account matches the identity` (create the account or enable auto provisioning), `several accounts match the identity` (de-duplicate e-mails or use `mode=userid`), `... not in allowed-user-backends (<class>)`, `the configured claim is missing in the user info (<claim>)` (fix `search-attribute`), `the claim or value required for auto provisioning is missing (<claim>)`, `creating the account failed`. | As named in the log line. |
| Login ends on the start page instead of the requested page | owncloud.online 11.1 core with openidconnect older than 3.0.0 (the core no longer evaluates `redirect_url` for the default page). | Update to 3.0.0. |
| Login loop on `/login` | `autoRedirectOnLoginPage` plus an IdP that bounces back without a session. | Disable `autoRedirectOnLoginPage` while debugging. |
| Bearer auth fails on WebDAV | Token expired or `oca.openid-connect.2` cache stale. | Re-authenticate; clear the memcache namespace `oca.openid-connect.2`. |

Enable debug logging in `config.php` (`'loglevel' => 0`) to capture the verbose
trace messages this app emits via the `OpenID` log context.

## Attribution

Fork of an open-source app licensed under GPLv2; the original copyright notices
are kept in the source files and the upstream history is preserved. This fork
is maintained by BW-Tech GmbH under the same licence. Issues and pull requests
go to <https://github.com/BWTECH-github/openidconnect/issues>.
