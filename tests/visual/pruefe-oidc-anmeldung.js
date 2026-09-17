/**
 * Anmeldung über OpenID Connect am laufenden Redesign-Kern.
 *
 * Voraussetzung: Testanbieter idp-probe.mjs auf http://127.0.0.1:18150 und
 * "bash oidc-testdaten.sh einrichten" auf der Instanz.
 *
 * Geprüft wird:
 *   - der Anmeldeknopf erscheint auf der Anmeldeseite (Name aus der Konfiguration)
 *   - Anmeldung beim Anbieter führt zurück, angemeldet als oidcprobe
 *   - ohne angeforderte Seite landet man auf der Startseite
 *   - mit angeforderter Seite (redirect_url) landet man DORT - der Redesign-Kern
 *     wertet redirect_url nicht mehr in OC_Util::getDefaultPageUrl() aus
 *   - ein Ziel mit '@' führt nicht nach draußen, sondern auf die Startseite
 *   - Frontchannel-Abmeldung ohne iss/sid oder mit falscher sid beendet die
 *     Sitzung NICHT (CSRF-Schutz aus 2.4.5)
 *   - Abmelden führt über den Anbieter zurück zur Anmeldeseite
 *   - keine Konsolenfehler auf den Seiten der Instanz
 *
 * Aufruf: OC_URL=http://127.0.0.1:18130 node tests/visual/pruefe-oidc-anmeldung.js
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

const BASIS = process.env.OC_URL || 'http://127.0.0.1:18130';
const IDP = process.env.IDP_URL || 'http://127.0.0.1:18150';

const ergebnisse = [];
function pruefe(name, ok, zusatz) {
	ergebnisse.push({ name, ok: ok === true, zusatz: zusatz === undefined ? '' : String(zusatz) });
}

async function neuerKontext(browser, konsole) {
	const kontext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'de-DE' });
	const seite = await kontext.newPage();
	seite.on('console', (m) => {
		if (m.type() === 'error' && seite.url().startsWith(BASIS)) {
			konsole.push(seite.url().replace(BASIS, '') + ': ' + m.text().slice(0, 140));
		}
	});
	return { kontext, seite };
}

/** Beim Anbieter anmelden (devInteractions: Anmeldung, dann Zustimmung). */
async function beimAnbieterAnmelden(seite, nutzer) {
	await seite.waitForURL((u) => u.toString().startsWith(IDP), { timeout: 20000 });
	await seite.fill('input[name=login]', nutzer);
	await seite.fill('input[name=password]', 'egal');
	await Promise.all([seite.waitForNavigation({ timeout: 20000 }), seite.click('button[type=submit]')]);
	for (let i = 0; i < 3 && seite.url().startsWith(IDP); i++) {
		const knopf = seite.locator('button[type=submit], button[autofocus]');
		if (!(await knopf.count())) {
			break;
		}
		await Promise.all([seite.waitForNavigation({ timeout: 20000 }).catch(() => {}), knopf.first().click()]);
	}
	await seite.waitForURL((u) => u.toString().startsWith(BASIS), { timeout: 30000 });
	await seite.waitForLoadState('load');
}

async function angemeldetAls(seite) {
	return seite.evaluate(() => (window.OC && OC.currentUser) || null).catch(() => null);
}

(async () => {
	const browser = await chromium.launch();
	const konsole = [];

	// 1. Anmeldeseite und einfache Anmeldung
	let { kontext, seite } = await neuerKontext(browser, konsole);
	await seite.goto(BASIS + '/index.php/login', { waitUntil: 'load' });
	const knopf = seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' });
	pruefe('Anmeldeknopf "Probe-IdP" auf der Anmeldeseite', (await knopf.count()) === 1, await knopf.count());
	await knopf.first().click();
	await beimAnbieterAnmelden(seite, 'oidcprobe');
	pruefe('zurück und angemeldet als oidcprobe', (await angemeldetAls(seite)) === 'oidcprobe', await angemeldetAls(seite));
	pruefe('ohne angeforderte Seite auf der Startseite', /\/apps\/dashboard\/?/.test(seite.url()), seite.url());

	// 2. Frontchannel-Abmeldung darf ohne passende sid nichts beenden
	for (const [name, pfad] of [
		['ohne iss und sid', '/index.php/apps/openidconnect/logout'],
		['mit falscher sid', '/index.php/apps/openidconnect/logout?iss=' + encodeURIComponent(IDP) + '&sid=falsch-' + Date.now()],
	]) {
		const antwort = await seite.request.get(BASIS + pfad);
		const noch = await seite.request.get(BASIS + '/ocs/v1.php/cloud/user?format=json', { headers: { 'OCS-APIREQUEST': 'true' } });
		pruefe('Frontchannel-Abmeldung ' + name + ' beendet die Sitzung nicht', noch.status() === 200, 'logout ' + antwort.status() + ', danach ' + noch.status());
	}

	// 3. Abmelden über den Anbieter
	const abmelden = await seite.evaluate(() => {
		const a = document.querySelector('#logout, a[href*="logout"]');
		return a ? a.href : null;
	});
	pruefe('Abmelde-Verweis vorhanden', !!abmelden, abmelden);
	if (abmelden) {
		await seite.goto(abmelden, { waitUntil: 'load' }).catch(() => {});
		for (let i = 0; i < 3 && seite.url().startsWith(IDP); i++) {
			const ja = seite.locator('button[type=submit][value=yes], button[autofocus], button[type=submit]');
			if (!(await ja.count())) {
				break;
			}
			await Promise.all([seite.waitForNavigation({ timeout: 20000 }).catch(() => {}), ja.first().click()]);
		}
		await seite.waitForURL((u) => u.toString().startsWith(BASIS), { timeout: 30000 }).catch(() => {});
		const noch = await seite.request.get(BASIS + '/ocs/v1.php/cloud/user?format=json', { headers: { 'OCS-APIREQUEST': 'true' } });
		pruefe('nach dem Abmelden abgemeldet', noch.status() === 401, noch.status());
		pruefe('nach dem Abmelden auf der Anmeldeseite der Instanz', /\/login/.test(seite.url()), seite.url());
	}
	await kontext.close();

	// 4. Angeforderte Seite bleibt über die Anmeldung erhalten
	({ kontext, seite } = await neuerKontext(browser, konsole));
	await seite.goto(BASIS + '/index.php/apps/files/?dir=%2F&view=files', { waitUntil: 'load' });
	pruefe('Dateien ohne Anmeldung leiten zur Anmeldung mit redirect_url', /redirect_url=/.test(seite.url()), seite.url());
	await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
	await beimAnbieterAnmelden(seite, 'oidcprobe');
	pruefe('nach der Anmeldung auf der angeforderten Seite (Dateien)', /\/apps\/files\//.test(seite.url()), seite.url());
	await kontext.close();

	// 5. Ziel mit '@' führt nicht nach draußen
	({ kontext, seite } = await neuerKontext(browser, konsole));
	await seite.goto(BASIS + '/index.php/login?redirect_url=' + encodeURIComponent(':nutzer@fremd.example/pfad'), { waitUntil: 'load' });
	await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
	await beimAnbieterAnmelden(seite, 'oidcprobe');
	pruefe('Ziel mit @ endet auf der Instanz, nicht draußen', seite.url().startsWith(BASIS) && !/fremd\.example/.test(seite.url()), seite.url());
	pruefe('Ziel mit @ endet auf der Startseite', /\/apps\/dashboard\/?/.test(seite.url()), seite.url());
	await kontext.close();

	// 5b. Ziel mit kodiertem Zeilenumbruch: Startseite statt leerer Seite
	({ kontext, seite } = await neuerKontext(browser, konsole));
	await seite.goto(BASIS + '/index.php/login?redirect_url=' + encodeURIComponent('%0d%0aX-Probe: 1'), { waitUntil: 'load' });
	await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
	await beimAnbieterAnmelden(seite, 'oidcprobe');
	pruefe('Ziel mit Zeilenumbruch endet auf der Startseite', /\/apps\/dashboard\/?/.test(seite.url()), seite.url());
	await kontext.close();

	// 5c. Abgebrochener Vorgang mit Ziel wirkt nicht in der nächsten Anmeldung nach
	({ kontext, seite } = await neuerKontext(browser, konsole));
	await seite.goto(BASIS + '/index.php/apps/openidconnect/redirect?redirect_url=' + encodeURIComponent('/index.php/settings/personal'), { waitUntil: 'load' });
	await seite.waitForURL((u) => u.toString().startsWith(IDP), { timeout: 20000 }).catch(() => {});
	await seite.goto(BASIS + '/index.php/login', { waitUntil: 'load' });
	await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
	await beimAnbieterAnmelden(seite, 'oidcprobe');
	pruefe('abgebrochenes Ziel wirkt nicht nach', /\/apps\/dashboard\/?/.test(seite.url()), seite.url());
	await kontext.close();

	// 6. Identität ohne Konto auf der Instanz: deutsche Seite, Rückweg und
	// Abmeldung beim Anbieter
	({ kontext, seite } = await neuerKontext(browser, konsole));
	await seite.goto(BASIS + '/index.php/login', { waitUntil: 'load' });
	await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
	const antworten = [];
	seite.on('response', (r) => {
		if (r.url().indexOf('/apps/openidconnect/redirect') !== -1) {
			antworten.push(r.status());
		}
	});
	await beimAnbieterAnmelden(seite, 'unbekannt-' + Date.now());
	const fehlseite = await seite.evaluate(() => ({
		text: document.body.innerText,
		zurueck: (document.getElementById('openidconnect-back-to-login') || {}).href || null,
		anbieter: (document.getElementById('openidconnect-provider-logout') || {}).href || null,
	}));
	pruefe('ohne Konto: Status 403', antworten.indexOf(403) !== -1, antworten.join(','));
	pruefe('ohne Konto: deutsche Meldung', /Die Anmeldung mit (deinem|Ihrem) externen Konto hat nicht geklappt/.test(fehlseite.text), fehlseite.text.replace(/\s+/g, ' ').slice(0, 200));
	pruefe('ohne Konto: keine englische Rohmeldung', !/is not known/.test(fehlseite.text));
	pruefe('ohne Konto: Rückweg zur Anmeldung', !!fehlseite.zurueck && /\/login/.test(fehlseite.zurueck), fehlseite.zurueck);
	pruefe('ohne Konto: Abmeldung beim Anbieter angeboten', !!fehlseite.anbieter && fehlseite.anbieter.startsWith(IDP) && /client_id=owncloud/.test(fehlseite.anbieter), fehlseite.anbieter);
	if (fehlseite.zurueck) {
		await seite.goto(fehlseite.zurueck, { waitUntil: 'load' });
		pruefe('Rückweg führt zur Anmeldeseite mit OIDC-Knopf', (await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).count()) === 1);
		// Solange die Sitzung beim Anbieter besteht, geht es sofort wieder auf die Fehlseite.
		await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
		await seite.waitForURL((u) => u.toString().indexOf('/apps/openidconnect/redirect') !== -1, { timeout: 30000 }).catch(() => {});
		await seite.waitForLoadState('load');
	}
	const anbieterLink = await seite.evaluate(() => (document.getElementById('openidconnect-provider-logout') || {}).href || null);
	if (anbieterLink) {
		await seite.goto(anbieterLink, { waitUntil: 'load' });
		for (let i = 0; i < 3 && seite.url().startsWith(IDP); i++) {
			const ja = seite.locator('button[type=submit][value=yes], button[autofocus], button[type=submit]');
			if (!(await ja.count())) {
				break;
			}
			await Promise.all([seite.waitForNavigation({ timeout: 20000 }).catch(() => {}), ja.first().click()]);
		}
		pruefe('Abmeldung beim Anbieter führt zur Anmeldeseite der Instanz', seite.url().startsWith(BASIS) && /\/login/.test(seite.url()), seite.url());
		await seite.locator('#alternative-logins a', { hasText: 'Probe-IdP' }).first().click();
		await seite.waitForURL((u) => u.toString().startsWith(IDP), { timeout: 20000 }).catch(() => {});
		const anmeldefeld = await seite.locator('input[name=login]').count();
		pruefe('danach fragt der Anbieter wieder nach dem Konto', anmeldefeld === 1, seite.url());
	}
	await kontext.close();

	pruefe('keine Konsolenfehler auf Seiten der Instanz', konsole.filter((z) => !/status of 403/.test(z)).length === 0, konsole.join(' | '));
	await browser.close();

	let fehler = 0;
	for (const e of ergebnisse) {
		console.log((e.ok ? 'OK    ' : 'FEHL  ') + e.name + (e.zusatz ? '  (' + e.zusatz + ')' : ''));
		if (!e.ok) {
			fehler++;
		}
	}
	console.log('\n' + (ergebnisse.length - fehler) + '/' + ergebnisse.length + ' bestanden');
	process.exit(fehler === 0 ? 0 : 1);
})().catch((e) => {
	console.error(e);
	process.exit(1);
});
