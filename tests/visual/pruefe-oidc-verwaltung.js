/**
 * Verwaltungsseite von OpenID Connect im Redesign.
 *
 * Voraussetzung: "bash oidc-testdaten.sh einrichten" (App-Konfiguration gesetzt).
 * Die Probe speichert und entfernt die App-Konfiguration; danach
 * "oidc-testdaten.sh einrichten" erneut ausführen.
 *
 * Geprüft wird:
 *   - Seite deutsch, keine englischen Beschriftungen mehr
 *   - Herkunft der Konfiguration lesbar statt "appconfig"/"empty"
 *   - nach dem Öffnen keine stehende Statusmeldung
 *   - Speichern ist ein erkennbarer Hauptknopf (Fläche, Kontrast)
 *   - Gruppentitel kleiner als der Kartentitel
 *   - Speichern per Tastatur: Meldung, Fokus zurück auf dem Knopf
 *   - ungültiges JSON: deutsche Fehlermeldung als Alarm
 *   - "Systemkonfiguration verwenden" fragt im Kerndialog; Abbrechen lässt
 *     alles, wie es ist, und gibt den Fokus zurück
 *   - 400 px ohne Querrollen, keine Konsolenfehler
 *
 * Aufruf: OC_PASSWORD=... node tests/visual/pruefe-oidc-verwaltung.js
 *
 * @copyright Copyright (c) 2026, BW-Tech GmbH
 * @license GPL-2.0
 */
'use strict';

let chromium;
try {
	({ chromium } = require('playwright'));
} catch (e) {
	({ chromium } = require('C:/git/owncloud.online-redesign/node_modules/playwright'));
}
const path = require('path');
const fs = require('fs');

const BASIS = process.env.OC_URL || 'http://127.0.0.1:18130';
const PASSWORT = process.env.OC_PASSWORD;
const BILDER = process.env.BILDER || path.join(__dirname, 'bilder');
if (!PASSWORT) {
	console.error('OC_PASSWORD fehlt.');
	process.exit(2);
}
fs.mkdirSync(BILDER, { recursive: true });

const ergebnisse = [];
function pruefe(name, ok, zusatz) {
	ergebnisse.push({ name, ok: ok === true, zusatz: zusatz === undefined ? '' : String(zusatz) });
}

function kontrast(a, b) {
	const lum = (farbe) => {
		const w = (farbe.match(/[\d.]+/g) || []).slice(0, 3).map((x) => {
			const c = parseFloat(x) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * w[0] + 0.7152 * w[1] + 0.0722 * w[2];
	};
	const [h, d] = [lum(a), lum(b)].sort((x, y) => y - x);
	return Math.round(((h + 0.05) / (d + 0.05)) * 100) / 100;
}

const ADRESSE = BASIS + '/index.php/settings/admin?sectionid=openidconnect';

(async () => {
	const browser = await chromium.launch();
	const kontext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'de-DE' });
	const seite = await kontext.newPage();
	const konsole = [];
	seite.on('console', (m) => {
		if (m.type() === 'error') {
			konsole.push(m.text().slice(0, 160));
		}
	});

	await seite.goto(BASIS + '/index.php/login', { waitUntil: 'domcontentloaded' });
	await seite.fill('#user', 'admin');
	await seite.fill('#password', PASSWORT);
	await Promise.all([seite.waitForNavigation({ timeout: 60000 }).catch(() => {}), seite.click('#submit, button[type=submit], input[type=submit]')]);
	await seite.goto(ADRESSE, { waitUntil: 'load' });
	await seite.waitForFunction(() => {
		const f = document.getElementById('openidconnect-admin-form');
		return f && f.getAttribute('aria-busy') === 'false';
	}, null, { timeout: 30000 }).catch(() => {});

	const stand = await seite.evaluate(() => {
		const karte = document.getElementById('openidconnect-admin');
		const text = karte.innerText;
		const h2 = karte.querySelector('h2');
		const legend = karte.querySelector('legend');
		const speichern = document.getElementById('openidconnect-save');
		const s = getComputedStyle(speichern);
		return {
			text,
			quelle: document.getElementById('openidconnect-config-source').textContent,
			meldung: document.getElementById('openidconnect-message').textContent,
			h2Groesse: parseFloat(getComputedStyle(h2).fontSize),
			legendGroesse: parseFloat(getComputedStyle(legend).fontSize),
			speichernFlaeche: s.backgroundColor,
			speichernText: s.color,
			speichernRahmen: s.borderTopColor,
			knopfText: speichern.textContent.trim(),
		};
	});
	const englisch = ['Configuration source', 'Provider URL', 'Client secret', 'User mapping', 'Login and logout', 'Auto provisioning', 'Tokens and user info', 'Use system config', 'Generated app configuration', 'Required fields'];
	const gefunden = englisch.filter((w) => stand.text.indexOf(w) !== -1);
	pruefe('keine englischen Beschriftungen', gefunden.length === 0, gefunden.join(', '));
	pruefe('Knopf heißt "Speichern"', stand.knopfText === 'Speichern', stand.knopfText);
	pruefe('Herkunft lesbar', stand.quelle === 'App-Konfiguration (auf dieser Seite gesetzt)', stand.quelle);
	pruefe('nach dem Öffnen keine Statusmeldung', stand.meldung === '', stand.meldung);
	pruefe('Speichern hat eine farbige Fläche', stand.speichernFlaeche !== 'rgb(255, 255, 255)' && stand.speichernFlaeche !== 'rgba(0, 0, 0, 0)', stand.speichernFlaeche);
	const k = kontrast(stand.speichernText, stand.speichernFlaeche);
	pruefe('Speichern-Text mind. 4,5:1', k >= 4.5, k);
	pruefe('Gruppentitel nicht größer als der Kartentitel', stand.legendGroesse <= stand.h2Groesse, stand.legendGroesse + ' / ' + stand.h2Groesse);
	await seite.screenshot({ path: path.join(BILDER, 'oidc-verwaltung-1440.png'), clip: { x: 440, y: 60, width: 1000, height: 840 } });

	// Speichern per Tastatur
	await seite.focus('#openidconnect-save');
	await seite.keyboard.press('Enter');
	await seite.waitForFunction(() => /gespeichert/.test(document.getElementById('openidconnect-message').textContent), null, { timeout: 15000 }).catch(() => {});
	await seite.waitForTimeout(300);
	const nachSpeichern = await seite.evaluate(() => ({
		meldung: document.getElementById('openidconnect-message').textContent,
		fokus: document.activeElement ? document.activeElement.id || document.activeElement.tagName : null,
	}));
	pruefe('Speichern meldet Erfolg auf Deutsch', /wurde gespeichert/.test(nachSpeichern.meldung), nachSpeichern.meldung);
	pruefe('Fokus nach dem Speichern wieder auf dem Knopf', nachSpeichern.fokus === 'openidconnect-save', nachSpeichern.fokus);

	// Ungültiges JSON
	await seite.fill('#openidconnect-provider-params', '{ kaputt');
	await seite.click('#openidconnect-save');
	await seite.waitForFunction(() => document.getElementById('openidconnect-message').getAttribute('role') === 'alert', null, { timeout: 15000 }).catch(() => {});
	const fehler = await seite.evaluate(() => ({
		meldung: document.getElementById('openidconnect-message').textContent,
		rolle: document.getElementById('openidconnect-message').getAttribute('role'),
	}));
	pruefe('ungültiges JSON: deutsche Fehlermeldung mit Feldbeschriftung', fehler.meldung.indexOf('Anbieter-Parameter (JSON) muss ein gültiges JSON-Objekt') === 0, fehler.meldung);
	pruefe('ungültiges JSON: als Alarm', fehler.rolle === 'alert', fehler.rolle);
	await seite.fill('#openidconnect-provider-params', '');

	// Systemkonfiguration verwenden: Abbrechen
	await seite.focus('#openidconnect-reset-appconfig');
	await seite.keyboard.press('Enter');
	const dialog = seite.locator('.oc-dialog:visible');
	await dialog.first().waitFor({ timeout: 10000 }).catch(() => {});
	const dialogText = (await dialog.count()) ? await dialog.first().innerText() : '';
	pruefe('Rückfrage im Kerndialog', /config\.php verwenden\?/.test(dialogText), dialogText.replace(/\s+/g, ' ').slice(0, 120));
	const dialogAria = await seite.evaluate(() => {
		// offsetParent ist bei position:fixed immer null - sichtbar heißt: hat Boxen.
		const d = Array.from(document.querySelectorAll('.oc-dialog')).find((x) => x.getClientRects().length > 0 && getComputedStyle(x).display !== 'none');
		if (!d) {
			return null;
		}
		const beschreibung = document.getElementById(d.getAttribute('aria-describedby') || '');
		return {
			beschreibung: beschreibung ? beschreibung.textContent.trim() : null,
			knoepfe: Array.from(d.querySelectorAll('.oc-dialog-buttonrow button')).map((b) => b.textContent.trim()),
			fokus: document.activeElement ? document.activeElement.textContent.trim() : null,
		};
	});
	pruefe('Dialog beschreibt sich mit der Frage (aria-describedby)', !!dialogAria && /config\.php verwenden\?/.test(dialogAria.beschreibung || ''), dialogAria && dialogAria.beschreibung);
	pruefe('Dialogknöpfe nennen die Handlung', !!dialogAria && JSON.stringify(dialogAria.knoepfe) === JSON.stringify(['Abbrechen', 'App-Konfiguration entfernen']), dialogAria && JSON.stringify(dialogAria.knoepfe));
	await seite.keyboard.press('Escape');
	await seite.waitForTimeout(600);
	const nachAbbruch = await seite.evaluate(() => ({
		dialogOffen: Array.from(document.querySelectorAll('.oc-dialog')).some((d) => d.getClientRects().length > 0 && getComputedStyle(d).display !== 'none'),
		quelle: document.getElementById('openidconnect-config-source').textContent,
		fokus: document.activeElement ? document.activeElement.id : null,
	}));
	pruefe('Abbrechen schließt den Dialog', nachAbbruch.dialogOffen === false);
	pruefe('Abbrechen lässt die Konfiguration stehen', nachAbbruch.quelle === 'App-Konfiguration (auf dieser Seite gesetzt)', nachAbbruch.quelle);
	pruefe('Abbrechen gibt den Fokus an den Knopf zurück', nachAbbruch.fokus === 'openidconnect-reset-appconfig', nachAbbruch.fokus);

	// Systemkonfiguration verwenden: Bestätigen
	await seite.click('#openidconnect-reset-appconfig');
	await dialog.first().waitFor({ timeout: 10000 }).catch(() => {});
	const ja = seite.locator('.oc-dialog:visible .oc-dialog-buttonrow button', { hasText: 'App-Konfiguration entfernen' });
	if (await ja.count()) {
		await ja.first().click();
	}
	await seite.waitForFunction(() => /wurde entfernt/.test(document.getElementById('openidconnect-message').textContent), null, { timeout: 15000 }).catch(() => {});
	const nachReset = await seite.evaluate(() => ({
		quelle: document.getElementById('openidconnect-config-source').textContent,
		meldung: document.getElementById('openidconnect-message').textContent,
	}));
	pruefe('Bestätigen entfernt die App-Konfiguration', nachReset.quelle === 'Nicht eingerichtet', nachReset.quelle + ' / ' + nachReset.meldung);
	pruefe('Meldung behauptet keine geltende config.php', nachReset.meldung === 'Die App-Konfiguration von OpenID Connect wurde entfernt.', nachReset.meldung);
	const leereOption = await seite.evaluate(() => document.querySelector('#openidconnect-exchange-token-mode-before-introspection option[value=""]').textContent.trim());
	pruefe('leere Austausch-Option ist benannt', leereOption === 'Kein Token-Austausch', leereOption);

	// Schmal
	await seite.setViewportSize({ width: 400, height: 860 });
	await seite.waitForTimeout(600);
	const schmal = await seite.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
	pruefe('400 px ohne Querrollen', schmal === false);
	await seite.screenshot({ path: path.join(BILDER, 'oidc-verwaltung-400.png') });

	pruefe('keine Konsolenfehler', konsole.filter((z) => !/status of 400/.test(z)).length === 0, konsole.join(' | '));
	await browser.close();

	let fehlerZahl = 0;
	for (const e of ergebnisse) {
		console.log((e.ok ? 'OK    ' : 'FEHL  ') + e.name + (e.zusatz ? '  (' + e.zusatz + ')' : ''));
		if (!e.ok) {
			fehlerZahl++;
		}
	}
	console.log('\n' + (ergebnisse.length - fehlerZahl) + '/' + ergebnisse.length + ' bestanden');
	process.exit(fehlerZahl === 0 ? 0 : 1);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
