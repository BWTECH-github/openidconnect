// OIDC-Testanbieter für openidconnect-Proben (nur lokal, nur Test).
// Start: node /opt/idp-probe/idp.mjs  -> http://127.0.0.1:18150
// Konten: jede Anmeldung mit beliebigem Namen; Claims aus KONTEN, sonst <name>@probe.test.
import Provider from 'oidc-provider';

const PORT = 18150;
const ISSUER = `http://127.0.0.1:${PORT}`;
const OC = process.env.OC_BASIS || 'http://127.0.0.1:18130';

const KONTEN = {
	oidcprobe: { email: 'oidcprobe@probe.test', name: 'OIDC Probe', preferred_username: 'oidcprobe' },
	admin: { email: 'admin@probe.test', name: 'admin', preferred_username: 'admin' },
};

const provider = new Provider(ISSUER, {
	clients: [{
		client_id: 'owncloud',
		client_secret: 'owncloud-probe-geheim',
		redirect_uris: [`${OC}/index.php/apps/openidconnect/redirect`, `${OC}/apps/openidconnect/redirect`],
		post_logout_redirect_uris: [`${OC}/index.php/login`, `${OC}/`],
		grant_types: ['authorization_code', 'refresh_token'],
		response_types: ['code'],
		token_endpoint_auth_method: 'client_secret_basic',
	}],
	pkce: { required: () => false },
	claims: { openid: ['sub'], email: ['email', 'email_verified'], profile: ['name', 'preferred_username'] },
	features: { devInteractions: { enabled: true }, rpInitiatedLogout: { enabled: true } },
	scopes: ['openid', 'offline_access', 'email', 'profile'],
	async findAccount(ctx, id) {
		const k = KONTEN[id] || { email: `${id}@probe.test`, name: id, preferred_username: id };
		return {
			accountId: id,
			async claims() {
				return { sub: id, email: k.email, email_verified: true, name: k.name, preferred_username: k.preferred_username };
			},
		};
	},
	ttl: { AccessToken: 3600, IdToken: 3600, Session: 3600, Interaction: 600, Grant: 3600 },
	cookies: { keys: ['idp-probe-schluessel'] },
});
provider.proxy = false;
provider.listen(PORT, '127.0.0.1', () => {
	console.log(`IdP läuft: ${ISSUER}/.well-known/openid-configuration`);
});
