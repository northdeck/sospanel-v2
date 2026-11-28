# Admin pages

Northdeck's build has `/admin` pages for the people who run the install. They're Northdeck-only; upstream's own admin pages ship in a separate package.

## Who gets in

Instance admins, made on the command line:

```sh
php artisan admin:grant you@example.com
php artisan admin:grant someone@example.com --revoke
```

(In the Docker install: `serveros-panel artisan admin:grant you@example.com`.)

An admin also needs a verified email and two-factor on before `/admin` opens. After that, admins can make other people admins from **Users**. Nobody can take admin away from themselves, so there's always at least one.

Admins see **Admin** in the account menu. `NORTHDECK_ADMIN=false` turns the pages off.

## Users

Everyone with an account, with search by name or email. Click someone to change:

- **Name** and **email**. Their old address gets an email about the change. The new address has to be confirmed from an email, unless you tick **Mark it as verified**.
- **Admin**: opens these pages for them.

### Invites

**Invite someone** sends an email to make an account. An invite works whatever the sign-up rules say: on any domain, a blocked one included, and with sign-up off. Optionally pick a workspace and role; they then also get that workspace's invitation and join it once their account exists.

Invites last 14 days (`NORTHDECK_INVITE_DAYS`). Pending ones are listed on the page, where you can send them again or cancel them. Someone who already has an account can't be invited; add them from the workspace's Members page.

Every change goes to the admin log (`admin_actions`) and the person's account history.

## Sign-up rules

**Settings › New accounts**, or `.env`:

| Setting                    | `.env`                           |                                                                                                                                                                              |
| -------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Who can sign up            | `NORTHDECK_SIGN_UPS`             | `open` (anyone), `domains` (only the allowed domains), `invite` (people with a workspace or admin invite; upstream's `SERVEROS_SIGN_UPS=closed`), `off` (only admin invites) |
| Allowed domains            | `NORTHDECK_SIGN_UP_DOMAINS`      | For `domains`. `acme.com` also covers `eu.acme.com`.                                                                                                                         |
| Blocked domains            | `NORTHDECK_BLOCKED_DOMAINS`      | Never get a new account, in any mode, unless an admin invites them.                                                                                                          |
| SSO and SCIM make accounts | `NORTHDECK_SSO_CREATES_ACCOUNTS` | Off: workspace SSO and SCIM only sign in or add people who already have an account. Either way they follow the blocked domains.                                              |

The rules cover password sign-up, GitHub and Google sign-in, SSO and SCIM. They only stop new accounts: people who already have one keep signing in. The first account on a new install is always allowed, so the installer works. With sign-up off, the sign-in page drops "Create an account".

## Settings

The `.env` settings that can change while the panel runs, in sections: Panel, New accounts, Email (mailer, SMTP host, port, security, username and password, Postmark and Resend keys, send-from address and name), Support and links, Sign-in options (GitHub and Google client IDs and secrets, Turnstile keys), Features (SSO, SSO on private networks, SCIM, custom roles, the Groq key for Ask AI), and Updates and privacy (the daily ping, the panel and daemon update feeds).

- An empty field uses the `.env` value, shown in grey. Each field shows the `.env` name it goes over.
- Secrets (passwords, client secrets, API keys) are stored encrypted with `APP_KEY` and never sent back to the browser. Leave one blank to keep it; **Clear** goes back to `.env`.
- Changes apply right away, and the queue workers restart to pick them up.
- Saved values live in the `northdeck_panel_settings` table and are applied when the panel boots, so they work with `php artisan config:cache` too.

**Only in .env** lists what can't change here, and why: `APP_KEY`, `APP_URL`/`SERVEROS_DOMAIN`, the database and Redis, session, cache and queue drivers, the daemon gateway and CA, `APP_ENV`/`APP_DEBUG`/logging, and `NORTHDECK_ADMIN`. Change those in `.env` and restart the panel.

`MAIL_FROM_NAME` defaults to `${APP_NAME}` in `.env`, so changing **Name** doesn't change the sender name; set **Sender name** as well if you want both.

## Sending a test email

**Settings › Email › Send a test email** sends one email with the settings saved now, to your address or another. If it can't send, it says why in plain words (wrong password, port blocked, sender refused). With **Send with** set to Log it's written to the log instead.

## Updates

Northdeck's build updates from its own GitHub repository (`NORTHDECK_UPDATE_REPO`, default `northdeck/sospanel-v2`, branch `NORTHDECK_UPDATE_BRANCH`, default `main`), not from upstream's releases.

- Every hour the panel asks GitHub for the newest commit on the branch. **Admin › Updates** shows the commit the panel runs, the newest one, and what's changed in between, with **Check now** and **Update now**.
- **Auto-update** (`NORTHDECK_AUTO_UPDATE`, on by default) asks for the update by itself at `NORTHDECK_AUTO_UPDATE_HOUR` (default 4, server time; empty means as soon as a commit is found). A commit that failed to install by itself isn't tried again by itself; **Update now** still can. Both are in **Settings › Updates and privacy**.
- The update itself runs on the server, not in the panel: `serveros-panel updater` (cron, every minute) picks it up, backs up, builds the commit from a clean copy of the repository into your image (`SERVEROS_IMAGE`), and restarts the panel on it. Migrations run as it starts. The panel is down for a few minutes while it builds and restarts.
- A private repository needs a GitHub token that can read it (`NORTHDECK_UPDATE_TOKEN`) for the panel's check; the server's `git` needs its own access (a deploy key) too.

### Setting it up on the server, once

The server needs `git`, and Northdeck's version of the `serveros-panel` script:

```sh
sudo apt install -y git
cd ~/sospanel-v2 && git pull
sudo install -m 755 docker/self-hosted/serveros-panel /usr/local/bin/serveros-panel
sudo serveros-panel upgrade
sudo serveros-panel schedule
```

`serveros-panel upgrade` builds the branch's newest commit (cloned into `/opt/serveros/source`) and records the commit in the image, so the panel knows what it runs. `schedule` writes the cron that runs the updater every minute. After that, each update installs the script from the commit it builds, so it stays current.

From then on, `sudo serveros-panel upgrade` updates by hand from the server too. Updates happen only while `SERVEROS_IMAGE` is your own image; on upstream's `ghcr.io/serveroshq/panel` the script works as upstream's does.

If you build by hand, pass the commit so the panel knows it:

```sh
docker build -f docker/self-hosted/Dockerfile --build-arg NORTHDECK_COMMIT=$(git rev-parse HEAD) -t northdeck/sospanel:dev .
```
