# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/).

## [3.0.4] - 2026-11-13

### Behoben
- Ohne verteilten Zwischenspeicher sperrt OpenID Connect nicht mehr die ganze Weboberfläche (vorher HTTP 503 auf jeder Seite, auch Anmeldung und Verwaltung). Die Verwaltungsseite nennt die fehlende Voraussetzung und speichert erst, wenn sie erfüllt ist.
- Ist der Anbieter nicht erreichbar, erscheint eine übersetzte Fehlerseite mit „Zurück zur Anmeldung“; bei automatischer Weiterleitung führt sie zur Anmeldung mit Kennwort.
- Fehlerseite der Anmeldung: „Zurück zur Anmeldung“ ist der Hauptknopf der Karte, „Beim Anbieter abmelden“ ein Verweis darunter.
- Verwaltung: „Systemkonfiguration verwenden“ steht links, „Speichern“ rechts; fehlende Pflichtfelder meldet die Seite selbst statt der Browserblase.

## [3.0.3] - 2026-11-04

### Geändert
- Setzt owncloud.online 11.1 voraus.

## [3.0.2] - 2026-10-14

Übernahme von main (2.4.8): die Einträge der main-Versionen 2.4.7 (2026-09-26)
und 2.4.8 (2026-09-29).

### Fixed

- **Ein nicht erreichbarer Anbieter endete in HTTP 500 statt in einer
  kontrollierten Antwort.** Der HTTP-Abruf der App reichte die rohe
  Guzzle-Ausnahme durch; die Bibliothek wirft an dieser Stelle eine
  `OpenIDConnectClientException`, und nur auf die sind die catch-Blöcke der App
  gebaut. Dasselbe galt für Fehlerstatus des Anbieters (4xx/5xx). Jetzt kommt
  eine `OpenIDConnectClientException`, und alle Wege antworten kontrolliert:
  - `/index.php/apps/openidconnect/config` (Ziel von
    `/.well-known/openid-configuration`): HTTP 503 mit
    `{"error":"temporarily_unavailable","error_description":"The OpenID Connect provider is not available."}`
    – auch wenn der Anbieter kein JSON-Objekt liefert (Fehlerseite eines
    Proxys, leerer Body, JSON-Liste) oder keine `provider-url` gesetzt ist.
    Desktop-, Android- und iOS-Client werten nur HTTP 200 aus und fallen wie
    bisher bei 500 auf ihre Vorgaben zurück.
  - Login-Knopf und Rückkehr vom Anbieter (Discovery, Token-, JWKS- und
    Userinfo-Endpunkt): Fehlerseite „Sign-in with the identity provider
    failed“ (HTTP 503) ohne interne Adressen. Ein Fehler am
    Userinfo-Endpunkt wird jetzt wie einer beim Anmeldeschritt davor
    behandelt, statt ungefangen durchzulaufen.
  - `Authorization: Bearer …` an Seiten- und OCS-Routen: nicht angemeldet
    (401) statt HTTP 500 beziehungsweise einer leeren Antwort mit HTTP 200 bei
    OCS. WebDAV antwortete schon vorher mit 401.
  - Angemeldete OIDC-Sitzungen: Ließ sich das Zugriffstoken beim Anbieter
    nicht prüfen oder erneuern – Anbieter weg oder Refresh-Token dort
    abgelehnt (`invalid_grant`) –, endete bei fälliger Erneuerung jede Seite in
    HTTP 500, bis der Anbieter wieder antwortete. Jetzt wird die Sitzung
    beendet und die Anmeldeseite erscheint; Widerruf und Abmeldung beim
    Anbieter werden dabei nicht mehr mit Stacktrace protokolliert.

  Die Ursache steht einmal je Anfrage als Warnung im Protokoll:
  `OpenID Connect provider request failed: <Methode> <Adresse>: <Ausnahme>: <Meldung>`.
  Bisher kamen dazu Fehlerzeilen mit vollständigem Stacktrace. War der
  Anbieter in einer Anfrage schon nicht erreichbar (DNS, Verbindung,
  Zeitüberschreitung), wird er in derselben Anfrage nicht noch einmal
  angefragt.
- **Anfragen an den Anbieter haben wieder eine Zeitgrenze.** Die Bibliothek
  begrenzt sie auf 60 Sekunden, der Abruf über den HTTP-Client des Kerns hatte
  keine Grenze: Ein Anbieter, der Pakete verwirft, hielt jede Anfrage bis zum
  Verbindungs-Timeout des Systems fest.
- **Ein nicht erreichbarer Anbieter gilt nicht als „kein
  Introspection-Endpunkt“.** Weil Abruffehler jetzt als
  `OpenIDConnectClientException` kommen, hätte die Probe auf einen
  Introspection-Endpunkt sie als „keiner vorhanden“ gelesen und ein
  undurchsichtiges Token über den Userinfo-Endpunkt geprüft – ohne
  Audience-Prüfung. Die Probe gibt den Abruffehler deshalb weiter, das Token
  wird abgewiesen.
- **Nach einem Umzug von Server-Version 10 lehnte die Audience-Prüfung die Token
  vieler Anbieter ab.** Bis upstream 2.3.4 gab es keine Prüfung und damit auch
  keinen Konfigurationsschlüssel dafür. Seit 2.4.6 musste bei einem JWT die
  `client-id` in `aud` stehen – Keycloak schreibt dort aber `account` oder
  nichts, Entra ID v1.0 die App-ID-URI, AD FS
  `microsoft:identityserver:<Kennung>`. Eine unverändert übernommene
  Konfiguration verlor damit jede Anmeldung. Jetzt gilt dieselbe Regel wie
  upstream 2.3.5/2.4.2 (#374): Passt `aud` nicht, entscheidet der ranghöchste
  vorhandene Claim aus `azp`, `appid`, `client_id` – er muss die `client-id`
  nennen. Ein Token eines anderen Clients bleibt abgewiesen (OC10-115,
  OC10-147); gilt jetzt für JWT und Introspection gleich.
- **Der Schlüssel `audience` aus upstream 2.3.5/2.4.2 wurde ignoriert.** Eine
  übernommene Konfiguration, die ihn setzt, prüfte hier gegen die `client-id`
  und wies die Token ab. `audience` (String oder Liste) ersetzt jetzt wie
  upstream die `client-id` als erwartetes Publikum und schaltet den Weg über
  `azp`/`appid`/`client_id` ab; ein unbrauchbarer Wert weist alles ab und
  meldet das einmal als Warnung. `token-aud-check => false` schaltet weiterhin
  alles ab. Die Fehlermeldung lautet jetzt
  `Token audience does not match the expected audience`.
- **Das Speichern im Einstellungsformular verwarf Schlüssel, die das Formular
  nicht kennt.** Beim ersten Speichern wandert die Konfiguration aus der
  `config.php` in die App-Konfiguration, die `config.php` zählt danach nicht
  mehr. `token-aud-check`, `audience` und alle anderen nicht abgebildeten
  Schlüssel (auch unter `auto-provision`) gingen dabei still verloren. Sie
  bleiben jetzt erhalten.
- **`ocis-routing-poclicy-claim` aus 2.1.x/2.2.x wird wieder gelesen.** Die
  Versionen lasen den Schlüssel mit Tippfehler (upstream #289, behoben in
  2.3.0). Eine übernommene Konfiguration mit dieser Schreibweise gilt weiter,
  die richtige hat Vorrang; das Formular zeigt den Wert im richtigen Feld und
  speichert ihn unter dem richtigen Namen.

### Bekannte Verhaltensänderung bei Umzügen von 1.0.0 bis 2.1.x

- **`use-token-introspection-endpoint` wird nicht mehr ausgewertet – wie
  upstream seit 2.2.0.** In 1.0.0 bis 2.1.1 hat der Schalter jedes Token beim
  Anbieter per Introspection prüfen lassen, auch JWTs, über Bearer-Auth wie
  über die Browser-Sitzung; ein beim Anbieter widerrufenes Token wurde damit
  sofort abgelehnt. Jetzt wird ein JWT lokal gegen die Schlüssel des
  Anbieters (JWKS) geprüft und gilt bis zu seinem `exp`, auch wenn es beim
  Anbieter schon widerrufen ist; nur undurchsichtige Token gehen an die
  Introspection. Die Instanz braucht dafür Zugriff auf den JWKS-Endpunkt des
  Anbieters. Der Schlüssel bleibt in der übernommenen Konfiguration stehen,
  wirkt aber nicht. Wer schnellen Widerruf braucht, stellt am Anbieter eine
  kurze Lebensdauer für Zugriffstoken ein. Bewusst nicht wieder eingeführt:
  Wer von 2.2.0 oder neuer umzieht, hat den Schlüssel oft noch als Altlast in
  der Konfiguration, und er würde dort unvermittelt jede Anfrage an den
  Anbieter binden.

## [3.0.1] - 2026-09-21

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

## [3.0.0] - 2026-09-17

Durchgang für die Redesign-Oberfläche von owncloud.online 11.1, geprüft mit
einem echten OpenID-Connect-Anbieter (oidc-provider): Anmeldung, Weiterleitung,
Frontchannel-Abmeldung, Abmeldung über den Anbieter, Konto ohne Zuordnung,
Verwaltungsseite. Läuft weiter ab owncloud.online 11.

### Fixed

- Nach der Anmeldung landete man immer auf der Startseite, auch wenn eine
  andere Seite angefordert war (Dateilink, OAuth2-Freigabe eines Desktop- oder
  Mobil-Clients). Der Redesign-Kern wertet `redirect_url` nicht mehr in
  `OC_Util::getDefaultPageUrl()` aus; die App tut es jetzt selbst, mit derselben
  Regel wie die Kernanmeldung (Ziele mit `@` werden verworfen). Zusätzlich
  werden Ziele mit Steuerzeichen verworfen (ein kodierter Zeilenumbruch ergab
  eine Weiterleitung ohne Location), und das gespeicherte Ziel gilt nur einmal:
  ein beim Anbieter abgebrochener Vorgang bestimmte sonst das Ziel der nächsten
  Anmeldung im selben Browser.
- Gab es zur Identität des Anbieters kein nutzbares Konto, zeigte der Kern die
  englische Rohmeldung („User with … is not known.“) auf einer Seite ohne
  Rückweg. Jetzt eine übersetzte Seite (Status 403) mit Verweis zur Anmeldung
  und, wenn der Anbieter eine Abmeldeadresse nennt, „Beim Anbieter abmelden“ -
  sonst meldete er dasselbe Konto sofort wieder an. Im Protokoll steht die
  Ursache (kein Konto, nicht eindeutig, Backend nicht erlaubt mit Klassenname,
  Claim fehlt mit Claim-Name, Anlegen aus oder gescheitert), nie die Identität.
- Die Verwaltungsseite war vollständig englisch. Alle 65 Texte in de, de_AT,
  de_CH (du) und de_DE (Sie); Fehlermeldungen des Servers übersetzt und mit der
  sichtbaren Feldbeschriftung.
- „Speichern“ sah im Redesign wie ein Nebenknopf aus (weiße Pille). Jetzt der
  Kartenbaustein `oco-btn-primary`, `primary` bleibt für ältere Kerne.
- Die Herkunft der Konfiguration stand als interner Wert („empty“) da; nach
  dem Öffnen blieb „Configuration loaded.“ stehen.
- „Systemkonfiguration verwenden“ fragte über `window.confirm`; jetzt im
  Dialog des Kerns mit den Knöpfen „Abbrechen“ und „App-Konfiguration
  entfernen“. Nach Speichern und Zurücksetzen fiel der Tastaturfokus auf die
  Seite, jetzt kehrt er zum Knopf zurück.
- Die leere Auswahl beim Token-Austausch heißt „Kein Token-Austausch“.
- Ohne eigene Beschriftung wurde „Login via OpenID Connect“ fest gespeichert;
  ein leeres Feld bleibt jetzt leer, die Anmeldeseite zeigt „OpenID Connect“.
- Abschnittstitel waren größer als der Kartentitel; Farben ohne Tokens;
  wirkungslose `input:required`-Regeln entfernt.

### Changed

- Unit-Tests auf das seit 2.4.5 gültige, CSRF-sichere Abmeldeverhalten
  umgestellt (vorher 5 von 118 rot) und ergänzt um Weiterleitung, fremde Ziele,
  Steuerzeichen, einmalige Ziele, Fehlerseite, protokollierte Ursache und
  sid-Prüfung (auch Sitzungen ohne gespeicherte sid): 128 Tests.
- Zuordnungsfehler werfen `AccountLoginException` (Unterklasse von
  `LoginException`) mit Ursachencode; die Meldungstexte sind unverändert.

### Added

- `tests/visual/`: Testanbieter `idp-probe.mjs`, Testdaten
  `oidc-testdaten.sh`, Browser-Proben `pruefe-oidc-anmeldung.js` (23) und
  `pruefe-oidc-verwaltung.js` (22).

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

- Produktname, Beschreibung und übersetzte Zeichenketten nennen owncloud.online;
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
- Replaced upstream reusable workflows with self-contained CI that clones `BWTECH-github/owncloud.online` as the test core.
- Updated `info.xml` branding (website, bugs, repository, author) to BW-Tech GmbH / owncloud.online.

## [2.3.3] - 2026-04-07

### Changed

- Upstream #345 - bump phpseclib to 3.0.50



## [2.3.2] - 2025-04-29

### Fixed

- Upstream #330 - feat: use OCP\Http\Client\IClientService


## [2.3.1] - 2024-10-17

### Fixed

- Upstream #319 - fix: do not spam log file when running in parallel with oauth app


## [2.3.0] - 2024-07-10

### Added 

- Upstream #255 - feat: use password policy app to generate password for provisioned users (#282)

### Changed

- Upstream #286 - chore: remove unused config option `use-token-introspection-endpoint`
- Upstream #298 - chore: drop php 7.3 - upstream server 10.12 is minimum criteria
- Upstream #314 - sec: bump phpseclib to 3.0.39
- dependency updates; github/settings updates; README.md updates.


## [2.2.0] - 2022-12-21

### Fixed

- Upstream #239 - fix: auto update function return
- Upstream #246 - Duo SSO/code_challenge_methods_supported
- Upstream #250 - fix: user information is only read from the JWT token if configured
- Upstream #255 - fix: restrict usage of self signed JWK header in JWTs
- Upstream #259 - fix: log url in case of curl error

### Added

- Upstream #222 - feat: account info auto-update
- Upstream #243 - feat: JWT token will always be used for user info, expiry and verification
- Upstream #253 - Add config option to allow basic auth only for guests
- Upstream #257 - feat: add translations support
- Upstream #272 - Send to auth module so the login type is recognized in core


## [2.1.1] - 2022-02-25

### Fixed

- Public Link Uploads Fail for Anonymous Users - Upstream #203
- Read openid configuration from DB first before using config.php - Upstream #200

### Changed

- Regular Maintenance (Library updates)



## [2.1.0] - 2021-10-29

- chore: jumbojett/openid-connect-php seems unmaintained - we move to juliuspc/openid-connect-php – Upstream #183
- [Enhancement] Add db as additional settings storage backend – Upstream #167
- PKCE Flow challenge was not used - Upstream #170
- Use random_bytes to generate auto-provisioning user-id and password - Upstream #154
- Provision accounts based on auto-provisioning claim - Upstream #149
- Add app db table as additional, optional config storage - Upstream #167


## [2.0.0] - 2021-01-10

### Added

- Import user from openid provider: Auto provisioning mode - Upstream #85
- Azure AD: Use access token payload instead of user info endpoint - Upstream #103
- Limit OpenID Connect logins to users of specific user backend - Upstream #100

### Changed

- Message: Object of class OCA\OpenIdConnect\Application could not be converted to string - Upstream #112
- Properly handle token expiry in the sabre dav auth backend - Upstream #106
- Properly evaluate the config setting use-token-introspection-endpoint – Upstream #98
- Use built-in session functions of the OpenID Connect Library - Upstream #97
- Bump libraries

## [1.0.0] - 2020-10-16

### Added

- Add configurable post_logout_redirect_uri - Upstream #90

### Changed

- Properly handle token expiry in the sabre dav auth backend - Upstream #108
- Limit OpenID Connect logins to users of specific user backend - Upstream #100
- Properly evaluate the config setting use-token-introspection-endpoint - Upstream #98
- Bump libraries


## [0.2.0] - 2020-02-11

### Changed

- Drop Support for PHP7.0 - Upstream #40
- Perform local logout before calling idp - Upstream #45
- Introduce LoginPageBehaviour - Upstream #53
- Re-license under GPLv2 - Upstream #57

## 0.1.0 - 2019-11-13

- Initial Release
