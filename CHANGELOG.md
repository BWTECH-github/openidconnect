# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [2.4.6] - 2026-09-21

### Security

- **Das Publikum des Zugriffstokens wird jetzt standardmäßig geprüft.** Ein
  korrekt signiertes, nicht abgelaufenes Token, das derselbe Herausgeber für
  einen *anderen* Client ausgestellt hat, wurde bisher akzeptiert und meldete
  als das zugehörige Konto an — über Bearer-Auth (API, WebDAV) wie über die
  Browser-Sitzung. Upstream führt das als **OC10-115**.

  In 2.4.5 gab es dafür bereits den Schalter `token-aud-check`, er war aber
  **standardmäßig aus**: der Befund war also auf jeder Installation offen, die
  ihn nicht ausdrücklich eingeschaltet hatte. Die Prüfung ist jetzt an, sofern
  sie nicht ausdrücklich abgeschaltet wird.

- **Undurchsichtige Tokens (Introspection) wurden überhaupt nicht geprüft.**
  `verifyToken()` hat zwei Zweige: JWTs werden aus der entschlüsselten Nutzlast
  geprüft, alles andere geht an die Token-Introspection nach RFC 7662. Der
  `aud`-Vergleich hing nur am JWT-Zweig; der Introspection-Zweig prüfte nichts
  außer `error` und `active`. Ein undurchsichtiges Token, das derselbe
  Herausgeber für einen anderen Client ausgestellt hat, gilt dort als `active`
  und wurde angenommen — **unauthentifiziert erreichbar über den
  Authorization-Header**. Upstream führt das als **OC10-147**.

  Der Introspection-Zweig vergleicht jetzt zuerst `client_id` (RFC 7662 §2.2 —
  der Client, für den das Token ausgestellt wurde) und fällt erst dann auf
  `aud` zurück. Das ist der Grund, warum die Prüfung hier keine funktionierenden
  Installationen zerlegt: Keycloak trägt in `aud` „account" ein, Okta
  „api://default", Ory Hydra ein leeres Feld — `client_id` benennt dagegen
  genau uns. Benennt keines von beiden diese Instanz, wird abgewiesen.

### Changed

- `token-aud-check` ist von einem Einschalter zu einem **Ausschalter** geworden:
  Standard `true`, und `false` stellt das alte Verhalten wieder her. Das öffnet
  OC10-115 und OC10-147 erneut und steht nur bereit, damit eine Installation mit
  einem Identitätssystem, das sich weder richten lässt noch diese Instanz
  benennt, beim Update nicht jede Anmeldung verliert.

  **Beim Update zu beachten:** Trägt das Identitätssystem in `aud` des
  JWT-Zugriffstokens etwas anderes als die `client-id`, melden sich Nutzer nach
  dem Update nicht mehr an. Vorher prüfen, im Zweifel `token-aud-check` auf
  `false` setzen und das Identitätssystem nachziehen.

### Fixed

- Zwei Tests im Bereich des Logout-CSRF-Fixes waren seit 2.4.5 rot: Sie haben
  noch den alten Wortlaut der Protokollmeldung erwartet und verlangt, dass ohne
  `iss`/`sid` abgemeldet wird — also genau das Verhalten, das der Fix beseitigt.
  Sie prüfen jetzt das korrigierte Verhalten, und ein neuer Test hält fest, dass
  eine fremde `sid` keine Abmeldung auslöst.

## [2.4.5] - 2026-09-16

### Security

- Front-Channel-Logout beendet die Sitzung nur noch, wenn `iss` und `sid`
  mitgeliefert werden und die `sid` zur bei der Anmeldung gespeicherten Sitzung
  passt. Bisher wurde zuerst abgemeldet und danach geprüft, sodass jede fremde
  Seite per `<img src=".../logout">` eine Abmeldung erzwingen konnte (CSRF).
- Zugriffs-, Refresh- und ID-Tokens sowie die entschlüsselten Claims stehen
  nicht mehr im Debug-Protokoll; protokolliert wird nur noch, ob ein Token
  vorhanden ist, seine Ablaufzeit und die Namen der Claims.
- Neuer Konfigurationsschalter `token-aud-check` (Standard: aus): erzwingt,
  dass das `aud`-Feld des Zugriffstokens zur eigenen `client-id` passt. Aus,
  weil manche IdPs (z. B. Keycloak) dort einen Ressourcennamen eintragen.
  Diese Fixes liefen seit dem 03.07.2026 nur im SaaS-Bündel und fehlten hier
  und im Marktplatz-Paket.

## [2.4.4] - 2026-08-13

### Fixed

- Paketbau nahm templates, js und css nicht mit. Die App registrierte damit
  einen Verwaltungsbereich, dessen Aufruf mit einem Internal Server Error
  endete, weil die Vorlage im Paket fehlte.

## [2.4.3] - 2026-08-13

### Changed

- Produktname, Beschreibung und uebersetzte Zeichenketten nennen owncloud.online;
  Verweise auf Fehlerbereich, Repository und Dokumentation zeigen auf das eigene
  Repository. Screenshots aus fremden Repositories entfernt.

## [2.4.2] - 2026-07-31

### Fixed
- No longer bundles `phpseclib/phpseclib` (and its paragonie dependencies), which
  the server already provides. A bundled `vendor/autoload.php` is prepended ahead
  of the server's autoloader, so shipping a diverging phpseclib copy risked a
  class-shadow conflict on a future server bump. The package is now declared via
  `composer replace`, so `jumbojett/openid-connect-php` always uses the server's
  phpseclib. Verified: RS256 JWT signature sign/verify works unchanged against the
  server's phpseclib 3.0.55; the PHP 8.4 nullable-parameter patch still applies.

## [Unreleased] - XXXX-XX-XX

### Changed

- Forked as `bwtech/openidconnect` for [owncloud.online](https://github.com/BWTECH-github/owncloud.online) by BW-Tech GmbH.
- Bumped PHP minimum requirement to 8.4.
- Modernized code base to PHP 8.4 idioms (constructor property promotion, `readonly`, typed properties, `#[\Override]`).
- Replaced ownCloud reusable workflows with self-contained CI that clones `BWTECH-github/owncloud.online` as the test core.
- Updated `info.xml` branding (website, bugs, repository, author) to BW-Tech GmbH / owncloud.online.

## [2.3.3] - 2026-04-07

### Changed

- [#345](https://github.com/owncloud/openidconnect/pull/345) - bump phpseclib to 3.0.50



## [2.3.2] - 2025-04-29

### Fixed

- [#330](https://github.com/owncloud/openidconnect/pull/330) - feat: use OCP\Http\Client\IClientService


## [2.3.1] - 2024-10-17

### Fixed

- [#319](https://github.com/owncloud/openidconnect/pull/319) - fix: do not spam log file when running in parallel with oauth app


## [2.3.0] - 2024-07-10

### Added 

- [#255](https://github.com/owncloud/openidconnect/pull/255) - feat: use password policy app to generate password for provisioned users (#282)

### Changed

- [#286](https://github.com/owncloud/openidconnect/pull/286) - chore: remove unused config option `use-token-introspection-endpoint`
- [#298](https://github.com/owncloud/openidconnect/pull/298) - chore: drop php 7.3 - ownCloud server 10.12 is minimum criteria
- [#314](https://github.com/owncloud/openidconnect/pull/314) - sec: bump phpseclib to 3.0.39
- dependency updates; github/settings updates; README.md updates.


## [2.2.0] - 2022-12-21

### Fixed

- [#239](https://github.com/owncloud/openidconnect/pull/239) - fix: auto update function return
- [#246](https://github.com/owncloud/openidconnect/pull/246) - Duo SSO/code_challenge_methods_supported
- [#250](https://github.com/owncloud/openidconnect/pull/250) - fix: user information is only read from the JWT token if configured
- [#255](https://github.com/owncloud/openidconnect/pull/255) - fix: restrict usage of self signed JWK header in JWTs
- [#259](https://github.com/owncloud/openidconnect/pull/259) - fix: log url in case of curl error

### Added

- [#222](https://github.com/owncloud/openidconnect/pull/222) - feat: account info auto-update
- [#243](https://github.com/owncloud/openidconnect/pull/243) - feat: JWT token will always be used for user info, expiry and verification
- [#253](https://github.com/owncloud/openidconnect/pull/253) - Add config option to allow basic auth only for guests
- [#257](https://github.com/owncloud/openidconnect/pull/257) - feat: add translations support
- [#272](https://github.com/owncloud/openidconnect/pull/272) - Send to auth module so the login type is recognized in core


## [2.1.1] - 2022-02-25

### Fixed

- Public Link Uploads Fail for Anonymous Users - [#203](https://github.com/owncloud/openidconnect/pull/203)
- Read openid configuration from DB first before using config.php - [#200](https://github.com/owncloud/openidconnect/pull/200)

### Changed

- Regular Maintenance (Library updates)



## [2.1.0] - 2021-10-29

- chore: jumbojett/openid-connect-php seems unmaintained - we move to juliuspc/openid-connect-php [#183](https://github.com/owncloud/openidconnect/pull/183)
- [Enhancement] Add db as additional settings storage backend [167](https://github.com/owncloud/openidconnect/pull/167)
- PKCE Flow challenge was not used - [#170](https://github.com/owncloud/openidconnect/pull/170)
- Use random_bytes to generate auto-provisioning user-id and password - [#154](https://github.com/owncloud/openidconnect/issues/154)
- Provision accounts based on auto-provisioning claim - [#149](https://github.com/owncloud/openidconnect/issues/149)
- Add app db table as additional, optional config storage - [#67](https://github.com/owncloud/openidconnect/pull/167)


## [2.0.0] - 2021-01-10

### Added

- Import user from openid provider: Auto provisioning mode - [#85](https://github.com/owncloud/openidconnect/issues/85)
- Azure AD: Use access token payload instead of user info endpoint - [#103](https://github.com/owncloud/openidconnect/issues/103)
- Limit OpenID Connect logins to users of specific user backend - [#100](https://github.com/owncloud/openidconnect/issues/100)

### Changed

- Message: Object of class OCA\OpenIdConnect\Application could not be converted to string - [#112](https://github.com/owncloud/openidconnect/issues/112)
- Properly handle token expiry in the sabre dav auth backend - [#106](https://github.com/owncloud/openidconnect/issues/106)
- Properly evaluate the config setting use-token-introspection-endpoint [#98](https://github.com/owncloud/openidconnect/issues/98)
- Use built-in session functions of the OpenID Connect Library - [#97](https://github.com/owncloud/openidconnect/issues/97)
- Bump libraries

## [1.0.0] - 2020-10-16

### Added

- Add configurable post_logout_redirect_uri - [#90](https://github.com/owncloud/openidconnect/issues/90)

### Changed

- Properly handle token expiry in the sabre dav auth backend - [#108](https://github.com/owncloud/openidconnect/pull/108)
- Limit OpenID Connect logins to users of specific user backend - [#100](https://github.com/owncloud/openidconnect/issues/100)
- Properly evaluate the config setting use-token-introspection-endpoint - [#98](https://github.com/owncloud/openidconnect/issues/98)
- Bump libraries


## [0.2.0] - 2020-02-11

### Changed

- Drop Support for PHP7.0 - [#40](https://github.com/owncloud/openidconnect/pull/40)
- Perform local logout before calling idp - [#45](https://github.com/owncloud/openidconnect/pull/45)
- Introduce LoginPageBehaviour - [#53](https://github.com/owncloud/openidconnect/pull/53)
- Re-license under GPLv2 - [#57](https://github.com/owncloud/openidconnect/pull/57)

## 0.1.0 - 2019-11-13

- Initial Release

[Unreleased]: https://github.com/owncloud/openidconnect/compare/v2.3.1...master
[2.3.1]: https://github.com/owncloud/openidconnect/compare/v2.3.0...v2.3.1
[2.3.0]: https://github.com/owncloud/openidconnect/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/owncloud/openidconnect/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/owncloud/openidconnect/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/owncloud/openidconnect/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/owncloud/openidconnect/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/owncloud/openidconnect/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/owncloud/openidconnect/compare/0.1.0...v0.2.0
