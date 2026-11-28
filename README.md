<p align="center">
  <a href="https://github.com/northdeck/sospanel-v2">
    <img alt="Northdeck Panel" src="https://r2.iambrdn.com/northdeck/Banner.png" width="100%">
  </a>
</p>

<h1 align="center">Northdeck Panel</h1>

<p align="center">Northdeck's build of the ServerOS panel, set up for how Northdeck runs its servers.</p>

<p align="center">
  <a href="LICENSE"><img alt="License: AGPL-3.0" src="https://img.shields.io/github/license/northdeck/sospanel-v2?style=flat-square&color=2563eb"></a>
  <a href="https://github.com/northdeck/sospanel-v2/commits/main"><img alt="Last commit" src="https://img.shields.io/github/last-commit/northdeck/sospanel-v2?style=flat-square"></a>
  <a href="https://github.com/serveroshq/panel"><img alt="Based on ServerOS panel" src="https://img.shields.io/badge/based%20on-ServerOS%20panel-475569?style=flat-square"></a>
</p>

Northdeck Panel is a fork of the open source [ServerOS panel](https://github.com/serveroshq/panel). It keeps everything the upstream panel does, which is connecting your Linux servers, deploying apps, sites and databases, and watching, backing up and fixing them from one place. On top of that it adds the defaults, integrations and changes Northdeck needs.

> [!NOTE]
> This is an independent fork maintained by Northdeck. It is not made, endorsed or supported by ServerOS Cloud Ltd. For problems with this build, contact Northdeck, not the ServerOS team.

## What's different in Northdeck's build

Everything here is specific to Northdeck and isn't in upstream ServerOS.

- **Single sign-on**: OpenID Connect and SAML 2.0 with any identity provider, with presets for Google Workspace, Microsoft Entra ID, Okta, OneLogin, JumpCloud, Keycloak, authentik, Auth0 and generic SAML (ADFS and others). Email-domain routing, JIT accounts, group to role mapping, Require SSO with break-glass for owners and admins, single logout, and an optional connection for the whole install. Settings › Single sign-on; setup steps in [docs/sso.md](docs/sso.md).
- **Custom roles**: workspaces can make their own roles with exactly the permissions they pick (Settings › Members), and SSO and SCIM groups can map to them. On unless `NORTHDECK_CUSTOM_ROLES=false`.
- **Admin pages**: `/admin` for instance admins, with **Users** (search everyone, change names and emails, make people admins) and **Panel settings** (the panel's name, the send-from, support and security addresses, and help links, saved over the `.env` values). Make the first admin with `php artisan admin:grant you@example.com`; admins need a verified email and two-factor on. Off with `NORTHDECK_ADMIN=false`. See [docs/admin.md](docs/admin.md).
- **Branding**: Northdeck name, logo, icons and colors on the sign-in pages, the app header and sidebar, emails and status pages. The logo follows the light or dark theme. Assets are in `public/northdeck/`, colors in `resources/js/northdeck/theme.css`.
- **Support and docs**: support, source and issue links point to Northdeck (`NORTHDECK_SUPPORT_*`), and the Help page has a Northdeck support row. Upstream's "donate or buy a license" prompt is hidden.
- **Telemetry**: the anonymous daily ping to serveros.com is off unless `SERVEROS_TELEMETRY=true` (see [Self-hosting](#self-hosting)).
- **Defaults**: preset settings for Northdeck's servers, regions and backup schedules, so new machines are ready without extra setup.
- **Integrations**: hooks into Northdeck's own tools and services. <!-- list them, e.g. billing, alerting (SSO is listed above) -->
- **Other changes**:
    - SSO adds `require_sso` to `workspaces` when it isn't there, and four `northdeck_sso_*` tables (migration `2027_01_10_000000_create_northdeck_sso_tables`).
    - `.env.example` and `docker/self-hosted/compose.yaml` default `APP_NAME` to "Northdeck Panel".
    - SCIM 2.0 provisioning, as a preview behind `NORTHDECK_SCIM=true` ([docs/sso.md](docs/sso.md#scim-provisioning)).
    - The admin pages add a `northdeck_panel_settings` table (migration `2027_01_20_000000_create_northdeck_panel_settings_table`).
    - Page titles and the admin sidebar use the panel name from the server, so a name saved on **Panel settings** shows without a rebuild.

### Where Northdeck's code lives

To keep merges from upstream clean, Northdeck's code is in its own folders and plugs in through the panel's extension points ([docs/extensions.md](docs/extensions.md)):

| What                     | Where                                                         |
| ------------------------ | ------------------------------------------------------------- |
| PHP                      | `app/Northdeck/` (`NorthdeckServiceProvider` wires it all up) |
| Config                   | `config/northdeck.php`                                        |
| Migrations               | `database/migrations/*_northdeck_*`                           |
| Vue pages, slots, styles | `resources/js/northdeck/`                                     |
| Blade                    | `resources/views/northdeck/`                                  |
| Assets                   | `public/northdeck/`                                           |
| Tests                    | `tests/Feature/Northdeck/`, `tests/Support/Northdeck/`        |

Where an upstream file had to change, the change is a small hook marked `NORTHDECK:`. To list them all:

```sh
git grep -n "NORTHDECK" -- ':!app/Northdeck' ':!resources/js/northdeck' ':!README.md'
```

Everything else, including Machines, Services and projects, Backups, Monitors, Workflows, Teams and the API/MCP server, works the same as upstream.

## Self-hosting

You need a Linux server with Docker, ports 80, 443 and 8443 open, and a domain like `panel.northdeck.example` with two A records pointing at the server: one for `panel.northdeck.example` and one for `*.panel.northdeck.example`.

Clone this repo and build Northdeck's image:

```sh
git clone https://github.com/northdeck/sospanel-v2.git
cd sospanel-v2
docker build -f docker/self-hosted/Dockerfile -t northdeck/sospanel:dev .
sudo bash docker/self-hosted/install.sh --domain=panel.northdeck.example --email=you@northdeck.example --image=northdeck/sospanel --version=dev
```

<!-- Replace with Northdeck's install script or image registry once published -->

Open `https://panel.northdeck.example`, enter the setup code, and the installer walks you through email and your first account.

Then connect servers from Machines › Connect machine. Each one runs [serverosd](https://github.com/serveroshq/daemon), the open source daemon, which connects out to the panel on port 8443.

[docs/self-hosting.md](docs/self-hosting.md) covers upgrades, backups and installing without Docker.

**The anonymous daily ping.** Upstream panels send serveros.com a small anonymous ping once a day (install id, version, a few counts; [what's in it](docs/self-hosting.md#what-your-install-sends)). Northdeck's build doesn't send it unless you set `SERVEROS_TELEMETRY=true` in `.env`. With it unset or `false`, nothing is sent and `php artisan install:ping` won't send it either. The hourly update checks are separate; `SERVEROS_UPDATE_FEED_URL=` and `DAEMON_RELEASE_FEED_URL=` (empty) turn those off.

**Single sign-on.** Set it up per workspace in Settings › Single sign-on, following [docs/sso.md](docs/sso.md). The URLs your identity provider needs are all on `APP_URL`'s host.

## Development

You need PHP 8.4, Composer, Node 22 and npm. SQLite works out of the box.

```sh
git clone https://github.com/northdeck/sospanel-v2.git
cd sospanel-v2
composer setup   # install, .env, key, migrate, build
composer dev     # the panel, queue worker, logs and Vite together
```

Workspaces live on subdomains, so set these in `.env` and open `http://northdeck.localhost:8000`:

```dotenv
APP_URL=http://northdeck.localhost:8000
APP_DOMAIN=northdeck.localhost
SESSION_DOMAIN=.northdeck.localhost
```

### Keeping up with upstream

Northdeck pulls changes from ServerOS regularly. To sync:

```sh
git remote add upstream https://github.com/serveroshq/panel.git
git fetch upstream
git merge upstream/main
```

Keep Northdeck-specific changes in their own commits or files where you can, so merges stay clean.

## Help

- Northdeck support: <!-- TODO(northdeck): support email or help desk --> set `NORTHDECK_SUPPORT_EMAIL` and `NORTHDECK_SUPPORT_URL`, and the panel's Help page and emails use them
- Bugs in this build: [open an issue](https://github.com/northdeck/sospanel-v2/issues/new)
- Bugs that also happen in upstream ServerOS: report them [upstream](https://github.com/serveroshq/panel/issues/new/choose)

Found a security problem? Please don't open a public issue. Contact Northdeck privately at <!-- TODO(northdeck): security contact, also NORTHDECK_SECURITY_EMAIL -->.

## License

Northdeck Panel is licensed under the [GNU Affero General Public License v3.0](LICENSE), like the ServerOS panel it's based on. Original code is copyright © 2026 ServerOS Cloud Ltd. Northdeck's changes are copyright © 2026 Northdeck.

Because this is AGPL software, if you run a modified version for other people over a network, you have to offer them the source code of that version.

This build doesn't include the `serveroshq/panel-ee` enterprise features from the official ServerOS image.

The ServerOS name and logo are trademarks of ServerOS Cloud Ltd and aren't covered by the AGPL. Northdeck uses the name only to say where this project comes from.
