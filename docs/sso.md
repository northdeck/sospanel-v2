# Single sign-on

Northdeck's build of the panel signs people in with any identity provider that speaks **OpenID Connect** or **SAML 2.0**. There's no code per vendor: the presets below only fill in the form, and every connection runs on the same OIDC or SAML code.

This is Northdeck-only. Upstream ServerOS keeps SSO in its paid package; this build has its own, under `app/Northdeck/Sso`.

- [How it works](#how-it-works)
- [The URLs your identity provider needs](#the-urls-your-identity-provider-needs)
- [Setting up a connection](#setting-up-a-connection)
    - [Google Workspace](#google-workspace)
    - [Microsoft Entra ID](#microsoft-entra-id)
    - [Okta](#okta)
    - [authentik](#authentik)
    - [Keycloak](#keycloak)
    - [OneLogin, JumpCloud, Auth0](#onelogin)
    - [Generic OpenID Connect](#generic-openid-connect)
    - [Generic SAML (ADFS, Entra SAML, Okta SAML and others)](#generic-saml)
- [Groups and roles](#groups-and-roles)
- [Require SSO and break-glass](#require-sso-and-break-glass)
- [Single logout](#single-logout)
- [One connection for the whole install](#one-connection-for-the-whole-install)
- [SCIM (preview)](#scim-preview)
- [Settings](#settings)
- [Troubleshooting](#troubleshooting)

## How it works

1. A workspace verifies its email domain (Settings › Single sign-on › Verified domains, a DNS TXT record). A domain can only be verified by one workspace.
2. An admin adds a connection and picks which verified domains it covers.
3. People sign in from **Sign in with SSO** on the sign-in page by typing their work email. The panel looks up the domain and sends them to the right identity provider. `https://<panel>/sso/<workspace>` goes straight there too.
4. After the provider sends them back, the panel:
    - finds the person by `(connection, subject)`: the provider's ID for them, never their email;
    - if the connection hasn't seen them before, links an existing account **only** when the provider vouches for the email (see below) and the email is on one of the connection's verified domains;
    - otherwise makes an account, if "Add people when they first sign in" (JIT) is on;
    - adds them to the workspace, and applies the roles their groups map to.

**When is an email trusted?** OpenID Connect: when the ID token says `email_verified: true`. Microsoft Entra ID doesn't send that, so its email is trusted when the connection is pinned to one tenant ID, or when the token has `xms_edov: true`. With `organizations`, set up `xms_edov` as an optional claim (the [nOAuth](https://www.descope.com/blog/post/noauth) problem is why). SAML: the assertion must be signed by the provider the workspace set up for its own verified domain, so its email is trusted. An untrusted email can never link to an existing account.

Every sign-in, failed sign-in, connection change and Require SSO change is in the workspace's Activity.

## The URLs your identity provider needs

All of them are on **one host: the panel's `APP_URL`**, whatever address people use to reach a workspace (the dashboard domain, a custom domain, an old `acme.panel.example.com` subdomain). Sign-ins that start elsewhere hop to that host first, and the session cookie (`SESSION_DOMAIN`) carries back. Each connection's card in Settings › Single sign-on shows its exact URLs with copy buttons.

| What                         | URL                                                         |
| ---------------------------- | ----------------------------------------------------------- |
| OIDC redirect (callback) URI | `https://<APP_URL host>/sso/callback`                       |
| OIDC sign-out redirect URI   | `https://<APP_URL host>/sso/signed-out`                     |
| SAML ACS URL                 | `https://<APP_URL host>/sso/connections/<id>/saml/acs`      |
| SAML Entity ID (audience)    | `https://<APP_URL host>/sso/connections/<id>/saml/metadata` |
| SAML metadata                | the same URL as the Entity ID                               |
| SAML single logout URL       | `https://<APP_URL host>/sso/connections/<id>/saml/slo`      |
| Start a sign-in (bookmarks)  | `https://<APP_URL host>/sso/connections/<id>/start`         |

The SAML URLs are stable for the life of a connection. If `APP_URL` changes, update them at the provider.

## Setting up a connection

In the workspace: **Settings › Single sign-on**.

1. Under **Verified domains**, add your domain, add the TXT record, and press **Check**.
2. Press **Add a connection**, pick your provider, and fill in what it asks for (below).
3. Tick the domains it covers. Save.
4. Copy the URLs on the connection's card into the app at your provider.
5. Press **Test connection**. You sign in at the provider, but nobody is signed in to the panel: the card shows what came back (subject, email, whether it's verified, name, groups, every claim) and what a real sign-in would do.
6. When the test works, people can sign in. Turn on **Require SSO** once you're happy.

Client secrets and SAML private keys are encrypted in the database with `APP_KEY` and never sent back to the browser. To change a secret, type a new one; leaving it empty keeps the old one.

### Google Workspace

1. In Google Cloud Console, **APIs & Services › OAuth consent screen**: make it **Internal** (only your Workspace).
2. **Credentials › Create credentials › OAuth client ID**, type **Web application**.
3. **Authorized redirect URIs**: the OIDC redirect URI above.
4. In the panel, pick **Google Workspace**. Issuer is `https://accounts.google.com`. Paste the client ID and secret.
5. **Hosted domain**: your Workspace domain, like `acme.com`. The panel sends it as `hd` and refuses any token whose `hd` claim doesn't match, so personal Gmail accounts can't get in.

Google doesn't send groups in its ID token, so group mapping doesn't apply; use the default role.

### Microsoft Entra ID

1. Entra admin center › **App registrations › New registration**. Accounts in **this organizational directory only**.
2. Redirect URI: platform **Web**, the OIDC redirect URI above.
3. **Certificates & secrets › New client secret**. Copy the value.
4. **Token configuration**: add optional claims `email` (ID token). For groups, **Add groups claim** (Security groups; emit as Group ID or `sAMAccountName`).
5. In the panel, pick **Microsoft Entra ID**. **Tenant ID** is on the app's Overview (a GUID). Paste the Application (client) ID and the secret.

People are keyed on `tid` + `oid`, which stay the same across apps, rather than `sub`. With a tenant ID the panel refuses tokens from any other tenant. `organizations` lets in any work account; only use it with `xms_edov` (see [How it works](#how-it-works)). Group claims arrive as object IDs unless you chose names, so map those.

### Okta

1. Okta admin › **Applications › Create App Integration** › **OIDC** › **Web Application**.
2. **Sign-in redirect URI**: the OIDC redirect URI. **Sign-out redirect URI**: the sign-out one.
3. Assign the people or groups who may sign in.
4. For groups: **Sign On › OpenID Connect ID Token › Groups claim filter**, name `groups`, matching your filter.
5. In the panel, pick **Okta**. Issuer is `https://<your-org>.okta.com` (or an authorization server like `https://<your-org>.okta.com/oauth2/default`). Scopes `openid email profile groups`.

### authentik

1. **Applications › Providers › Create › OAuth2/OpenID Provider**. Client type **Confidential**. Redirect URI: the OIDC redirect URI (strict).
2. Scopes: `openid`, `email`, `profile`. authentik sends groups in the `groups` claim with the default profile mapping.
3. **Applications › Create**, using that provider. The issuer is on the provider's page: `https://authentik.example.com/application/o/<slug>/`.
4. In the panel, pick **authentik** and paste the issuer, client ID and secret.

If authentik is on your private network, set `NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS=true`.

### Keycloak

1. In your realm, **Clients › Create client**, type **OpenID Connect**, client authentication **On**.
2. **Valid redirect URIs**: the OIDC redirect URI. **Valid post logout redirect URIs**: the sign-out one.
3. **Credentials**: copy the client secret.
4. For groups: **Client scopes › <client>-dedicated › Add mapper › Group Membership**, token claim name `groups`, full group path off.
5. In the panel, pick **Keycloak**. Issuer is `https://keycloak.example.com/realms/<realm>`.

Keycloak marks emails verified only when they are (`email_verified`). Turn on "Email verified" for users, or have Keycloak verify them, or accounts can't be linked.

<a id="onelogin"></a>

### OneLogin, JumpCloud, Auth0

All three are OpenID Connect; pick the preset and follow the provider's "OIDC web app" steps with the redirect URI above.

- **OneLogin**: issuer `https://<subdomain>.onelogin.com/oidc/2`. Token endpoint auth method **POST** or **Basic** both work.
- **JumpCloud**: issuer `https://oauth.id.jumpcloud.com/`. Add the `email` and `groups` attributes to the app.
- **Auth0**: issuer `https://<tenant>.auth0.com/` (with the slash). Groups need an Action that adds a namespaced claim; set **Groups claim** under More settings to its name.

### Generic OpenID Connect

Anything with a `/.well-known/openid-configuration`. The panel uses the authorization code flow with PKCE (S256), and checks `state`, `nonce`, the ID token's signature against the provider's JWKS (fetched again when a token names a new key), `iss`, `aud`/`azp`, `exp` and `iat`. Public clients without a secret work too. If the ID token leaves out the email, `email_verified`, the name or the groups, the panel asks the UserInfo endpoint (only answers for the same `sub` count). The ID token wins where both have a claim, and `email_verified` only counts for the address it came with: a verified email in UserInfo never vouches for a different one in the ID token. Under **More settings** you can change which claims hold the email, name and groups.

### Generic SAML

Works with ADFS, Entra ID (Enterprise app › SAML), Okta SAML, OneLogin, JumpCloud, Google SAML apps, Shibboleth and others.

1. In the panel, pick **SAML 2.0**, give it a name and save it with the provider's **metadata URL**, or upload the metadata XML. (Or, under More settings, type the entity ID, SSO URL, SLO URL and signing certificate.)
2. Give the provider the panel's **metadata URL** from the connection's card, or the ACS URL and Entity ID by hand.
3. The provider must **sign assertions** (signing only the response isn't enough). Use SHA-256.
4. NameID: **persistent** (or unspecified with a stable value). Transient NameIDs are refused, because they change at every sign-in; if that's all your provider sends, set **Subject attribute** to a stable attribute instead.
5. Send the email in an attribute (`email`, `mail`, or the `.../claims/emailaddress` URI all work), and groups in `groups`, `memberOf` or the Microsoft groups claim URI.

Optional: turn on **Encrypted assertions** and give the provider the panel's certificate (it's in the metadata). Sign-ins started from the provider's app list (IdP-initiated) are **off** unless you turn on **Sign-ins started at the provider**: an unsolicited response could have been posted by any website (login CSRF), so it's refused. With it on, the person still sees a "Continue as …" page and has to confirm before they're signed in. Sign-ins started from the panel always check that the response answers a request this browser made (InResponseTo). Each assertion ID is remembered until it expires, so a captured response can't be used twice; clocks may differ by up to 3 minutes.

- **ADFS**: Add Relying Party Trust › import from the metadata URL. Claim rules: send `E-Mail-Address` as `E-Mail Address`, and a persistent NameID.
- **Entra ID (SAML)**: Enterprise applications › New › Create your own › Integrate any other application › Single sign-on › SAML. Upload the metadata file, or paste Identifier and Reply URL. Under Attributes & Claims add a groups claim if you want group mapping. Set the signing option to **Sign SAML assertion** (or response and assertion).
- **Okta SAML**: Create App Integration › SAML 2.0. Single sign-on URL: the ACS URL; Audience URI: the Entity ID. Name ID format **Persistent** or **EmailAddress**. Add a group attribute statement named `groups`.

## Groups and roles

On each connection, **Groups to roles** maps a group name (or ID) to Admin, Member or Viewer. At every sign-in the person gets the highest role any of their groups maps to. People whose groups map to nothing keep their role; new people get the connection's default role. The workspace owner always stays owner. Role changes from groups show in Activity.

## Require SSO and break-glass

**Require SSO** (Settings › Single sign-on) holds members to SSO:

- A password sign-in by a member is refused once the password is right ("<workspace> asks you to sign in with SSO."), so it says nothing to someone guessing emails. Google and GitHub sign-in are refused the same way.
- Someone already signed in another way is sent to SSO when they open the workspace.
- API keys and MCP keys keep working. AI apps people connected with their own sign-in have to connect again.

**Break-glass**: the workspace owner and instance admins can still sign in with a password **if they have two-factor on**, so a broken identity provider never locks a workspace out. Each break-glass entry is in Activity. Turning Require SSO on needs a connection with a verified domain, and you have to be able to get in yourself (break-glass, or signed in with SSO).

## Single logout

Signing out of the panel signs out at the provider too, when it supports it and **Sign out at the provider too** is on:

- OpenID Connect: RP-initiated logout (`end_session_endpoint`), with `id_token_hint` and the sign-out redirect URI.
- SAML: a signed LogoutRequest to the provider's SLO URL. A LogoutRequest from the provider ends the panel session and answers with a LogoutResponse.

Otherwise only the panel session ends.

## One connection for the whole install

An instance admin can set one connection in `.env` instead of in a workspace. It shows as **Sign in with <name>** on the sign-in page.

```dotenv
NORTHDECK_SSO_PROTOCOL=oidc
NORTHDECK_SSO_NAME="Northdeck SSO"
NORTHDECK_SSO_PRESET=keycloak
NORTHDECK_SSO_DOMAINS=northdeck.example
NORTHDECK_SSO_WORKSPACE=northdeck          # people join this workspace
NORTHDECK_SSO_OIDC_ISSUER=https://id.northdeck.example/realms/staff
NORTHDECK_SSO_OIDC_CLIENT_ID=panel
NORTHDECK_SSO_OIDC_CLIENT_SECRET=...
```

For SAML, set `NORTHDECK_SSO_PROTOCOL=saml` and either `NORTHDECK_SSO_SAML_METADATA_URL` or the `..._IDP_ENTITY_ID`, `..._IDP_SSO_URL`, `..._IDP_SLO_URL` and `..._IDP_CERT` settings. The domains in `NORTHDECK_SSO_DOMAINS` are trusted as given (no DNS check), so only list your own. Change `.env` and the connection follows at the next sign-in.

<a id="scim-provisioning"></a>

## SCIM (preview)

Off unless `NORTHDECK_SCIM=true`. With it on, Settings › Single sign-on has a **SCIM provisioning** card:

1. Press **Make a token** and copy it (it's shown once; only a hash is kept).
2. At your provider, set the SCIM base URL to `https://<APP_URL host>/api/scim/v2` and the token as a bearer token (Okta: "HTTP Header"; Entra: "Secret Token").
3. Assign people and push groups.

What it does:

- **Users**: creating one adds that person to the workspace (making an account if there isn't one; the email must be on a verified domain). `active: false` or deleting removes them from the workspace, and signs them out if it was their last workspace. The owner is never removed. Names follow the provider; the sign-in email doesn't (it's logged instead), so a provider can't point an account at another address.
- **Groups**: membership maps to roles through the same **Groups to roles** table sign-ins use, matched on the group's display name or external ID.
- Supported: list with `filter=<attr> eq "<value>"` (`userName`, `externalId`, `emails.value`, `displayName`), get, create, replace (PUT), PATCH and delete. Not supported: bulk, sorting, ETags, password changes.
- PATCH does `add`, `replace` and `remove`, and checks every operation before applying any, so a bad one changes nothing. Any other op is `400 invalidSyntax`; an unknown attribute is `400 invalidPath`. `remove` clears `externalId` on users, and on groups removes members (all of them, a list, or Entra's `members[value eq "..."]`) or `externalId`. Removing what the panel can't do without (`userName`, `active`, `emails`, the name, a group's `displayName`) is `400 mutability`; to take someone out, set `active` to false or delete them.
- Core and enterprise attributes the panel doesn't keep (`title`, `phoneNumbers`, `addresses`, the enterprise extension and so on) are accepted and not stored, as SCIM allows, so Entra and Okta can send their usual payloads.

Every change shows in Activity.

## Settings

| Variable                              | Default | What it does                                             |
| ------------------------------------- | ------- | -------------------------------------------------------- |
| `NORTHDECK_SSO`                       | `true`  | Turns SSO on for every workspace.                        |
| `NORTHDECK_SSO_CLOCK_SKEW`            | `120`   | Seconds of clock difference allowed on ID tokens.        |
| `NORTHDECK_SSO_CACHE_TTL`             | `3600`  | Seconds discovery documents and signing keys are cached. |
| `NORTHDECK_SSO_HTTP_TIMEOUT`          | `10`    | Seconds to wait for the provider.                        |
| `NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS`   | `false` | Allow providers on private addresses and plain http.     |
| `NORTHDECK_SSO_PROTOCOL` and the rest | empty   | The install-wide connection, above.                      |
| `NORTHDECK_SCIM`                      | `false` | SCIM 2.0 provisioning (preview).                         |

By default the panel only talks to providers on public https addresses, so a workspace admin can't point it at your internal network. Every request to a provider (discovery, signing keys, the token and UserInfo endpoints, SAML metadata):

- resolves the host once, over IPv4 and IPv6, and refuses it if **any** address is private, loopback, link-local, carrier-grade NAT, multicast, reserved or documentation-only, including IPv6 forms that carry an IPv4 address (`::ffff:`, 6to4, NAT64);
- connects to the address it checked (curl's `CURLOPT_RESOLVE`), so a second DNS answer can't send it elsewhere (DNS rebinding). TLS still checks the certificate for the real host name;
- doesn't follow redirects.

Set `NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS=true` for a LAN authentik or Keycloak; requests are still pinned to the address that was looked up.

## Troubleshooting

- **"That sign-in expired or was started in another browser."** The sign-in has to finish in the browser it started in, within 15 minutes. Also check `SESSION_DOMAIN` covers the `APP_URL` host.
- **"The ID token is from a different issuer."** The issuer in the panel must match the `issuer` in the provider's discovery document exactly (trailing slash included for Auth0 and authentik).
- **"The identity provider didn't confirm this email address."** See [How it works](#how-it-works). For Keycloak, mark emails verified; for Entra, pin the tenant.
- **"Emails on x can't sign in with this connection."** Verify the domain, then tick it on the connection.
- **SAML "didn't check out (...)"**: usually the assertion isn't signed, the certificate changed (save the metadata again), the ACS URL or Entity ID at the provider doesn't match the card, or the clocks are more than 3 minutes apart.
- **"...is on a private network."** See `NORTHDECK_SSO_ALLOW_PRIVATE_HOSTS`.
