#!/bin/bash
# Testdaten für pruefe-oidc-anmeldung.js auf einer Testinstanz (Standard /opt/oco-schnell).
#
# Aufruf (in WSL, als root):
#   bash oidc-testdaten.sh einrichten   OIDC-Konfiguration als App-Konfiguration setzen,
#                                       Konto oidcprobe (oidcprobe@probe.test) anlegen,
#                                       Bruteforce-Tabelle leeren
#   bash oidc-testdaten.sh entfernen    beides wieder entfernen
#
# Der Identity Provider ist idp-probe.mjs (oidc-provider, http://127.0.0.1:18150):
#   cd /opt/idp-probe && npm i oidc-provider@8 && node idp.mjs
#
# @copyright Copyright (c) 2026, BW-Tech GmbH
# @license GPL-2.0
set -euo pipefail
ZIEL=${OC_ZIEL:-/opt/oco-schnell}
BASIS=${OC_BASIS:-http://127.0.0.1:18130}
IDP=${IDP_URL:-http://127.0.0.1:18150}
OCC="sudo -u www-data php8.4 $ZIEL/occ"
case "${1:-}" in
	einrichten)
		KONFIG=$(python3 -c "import json,sys; print(json.dumps({
			'provider-url': sys.argv[1],
			'client-id': 'owncloud',
			'client-secret': 'owncloud-probe-geheim',
			'scopes': ['openid', 'email', 'profile'],
			'mode': 'email',
			'search-attribute': 'email',
			'loginButtonName': 'Probe-IdP',
			'post_logout_redirect_uri': sys.argv[2] + '/index.php/login',
		}))" "$IDP" "$BASIS")
		$OCC config:app:set openidconnect openid-connect --value="$KONFIG" > /dev/null
		# occ kennt kein user:info - Vorhandensein über user:list prüfen
		sudo -u www-data php8.4 -r 'require "'"$ZIEL"'/lib/base.php"; \OC::$server->getDatabaseConnection()->executeStatement("DELETE FROM *PREFIX*bruteforce_attempts");'
		if ! $OCC user:list oidcprobe | grep -q '^  - oidcprobe:'; then
			sudo -u www-data env OC_PASS='Oidc-Probe-Nutzer-2026!' php8.4 $ZIEL/occ user:add --password-from-env --display-name 'OIDC Probe' --email oidcprobe@probe.test oidcprobe > /dev/null
		fi
		$OCC user:modify oidcprobe email oidcprobe@probe.test > /dev/null
		$OCC user:setting oidcprobe core lang --value de > /dev/null
		echo "OIDC eingerichtet (Anbieter $IDP), Konto oidcprobe bereit"
		;;
	entfernen)
		$OCC config:app:delete openidconnect openid-connect > /dev/null || true
		$OCC user:delete oidcprobe > /dev/null 2>&1 || true
		echo "OIDC-Testkonfiguration und Konto oidcprobe entfernt"
		;;
	*)
		echo "Aufruf: $0 einrichten | entfernen" >&2
		exit 2
		;;
esac
