<p align="center">
  <a href="https://github.com/northdeck/sos-panel">
    <img alt="Northdeck Panel" src="https://r2.iambrdn.com/northdeck/Banner.png" width="100%">
  </a>
</p>

<h1 align="center">Northdeck Panel</h1>

<p align="center">Northdeck's build of the ServerOS panel, set up for how Northdeck runs its servers.</p>

<p align="center">
  <a href="LICENSE"><img alt="License: AGPL-3.0" src="https://img.shields.io/github/license/northdeck/sos-panel?style=flat-square&color=2563eb"></a>
  <a href="https://github.com/northdeck/sos-panel/commits/main"><img alt="Last commit" src="https://img.shields.io/github/last-commit/northdeck/sos-panel?style=flat-square"></a>
  <a href="https://github.com/serveroshq/panel"><img alt="Based on ServerOS panel" src="https://img.shields.io/badge/based%20on-ServerOS%20panel-475569?style=flat-square"></a>
</p>

Northdeck Panel is a fork of the open source [ServerOS panel](https://github.com/serveroshq/panel). It keeps everything the upstream panel does, which is connecting your Linux servers, deploying apps, sites and databases, and watching, backing up and fixing them from one place. On top of that it adds the defaults, integrations and changes Northdeck needs.

> [!NOTE]
> This is an independent fork maintained by Northdeck. It is not made, endorsed or supported by ServerOS Cloud Ltd. For problems with this build, contact Northdeck, not the ServerOS team.

## What's different in Northdeck's build

Everything here is specific to Northdeck and isn't in upstream ServerOS.

- **Branding**: Northdeck name, logo and colors across the panel, emails and status pages.
- **Defaults**: preset settings for Northdeck's servers, regions and backup schedules, so new machines are ready without extra setup.
- **Integrations**: hooks into Northdeck's own tools and services. <!-- list them, e.g. billing, SSO, alerting -->
- **Support and docs**: links point to Northdeck's help channels instead of the upstream community.
- **Other changes**: <!-- anything else Northdeck added, removed or changed -->

Everything else, including Machines, Services and projects, Backups, Monitors, Workflows, Teams and the API/MCP server, works the same as upstream.

## Self-hosting

You need a Linux server with Docker, ports 80, 443 and 8443 open, and a domain like `panel.northdeck.example` with two A records pointing at the server: one for `panel.northdeck.example` and one for `*.panel.northdeck.example`.

Clone this repo and build Northdeck's image:

```sh
git clone https://github.com/northdeck/sos-panel.git
cd sos-panel
docker build -t northdeck/sos-panel .
```

<!-- Replace with Northdeck's install script or image registry once published -->

Open `https://panel.northdeck.example`, enter the setup code, and the installer walks you through email and your first account.

Then connect servers from Machines › Connect machine. Each one runs [serverosd](https://github.com/serveroshq/daemon), the open source daemon, which connects out to the panel on port 8443.

[docs/self-hosting.md](docs/self-hosting.md) covers upgrades, backups, installing without Docker, and the anonymous daily ping (and how to turn it off).

## Development

You need PHP 8.4, Composer, Node 22 and npm. SQLite works out of the box.

```sh
git clone https://github.com/northdeck/sos-panel.git
cd sos-panel
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

- Northdeck support: <!-- Northdeck support email, Discord or help desk -->
- Bugs in this build: [open an issue](https://github.com/northdeck/sos-panel/issues/new)
- Bugs that also happen in upstream ServerOS: report them [upstream](https://github.com/serveroshq/panel/issues/new/choose)

Found a security problem? Please don't open a public issue. Contact Northdeck privately at <!-- security contact -->.

## License

Northdeck Panel is licensed under the [GNU Affero General Public License v3.0](LICENSE), like the ServerOS panel it's based on. Original code is copyright © 2026 ServerOS Cloud Ltd. Northdeck's changes are copyright © 2026 Northdeck.

Because this is AGPL software, if you run a modified version for other people over a network, you have to offer them the source code of that version.

This build doesn't include the `serveroshq/panel-ee` enterprise features from the official ServerOS image.

The ServerOS name and logo are trademarks of ServerOS Cloud Ltd and aren't covered by the AGPL. Northdeck uses the name only to say where this project comes from.
