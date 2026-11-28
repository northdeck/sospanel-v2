# Admin pages

Northdeck's build has `/admin` pages for the people who run the install. They're Northdeck-only; upstream's own admin pages ship in a separate package.

## Who gets in

Instance admins, made on the command line:

```sh
php artisan admin:grant you@example.com
php artisan admin:grant someone@example.com --revoke
```

An admin also needs a verified email and two-factor on before `/admin` opens. After that, admins can make other people admins from **Users**. Nobody can take admin away from themselves, so there's always at least one.

Admins see **Admin** in the account menu. `NORTHDECK_ADMIN=false` turns the pages off.

## Users

Everyone with an account, with search by name or email. Click someone to change:

- **Name** and **email**. Their old address gets an email about the change. The new address has to be confirmed from an email, unless you tick **Mark it as verified**.
- **Admin**: opens these pages for them.

Every change goes to the admin log (`admin_actions`) and the person's account history.

## Panel settings

| Field             | Sets                                                   | `.env` value it goes over  |
| ----------------- | ------------------------------------------------------ | -------------------------- |
| Name              | the panel's name in page titles, emails, sign-in pages | `APP_NAME`                 |
| Send from         | the From address on every email                        | `MAIL_FROM_ADDRESS`        |
| Sender name       | the name next to it                                    | `MAIL_FROM_NAME`           |
| Support           | where people write for help                            | `NORTHDECK_SUPPORT_EMAIL`  |
| Security reports  | the reply-to on vulnerability emails                   | `NORTHDECK_SECURITY_EMAIL` |
| Help desk or docs | the Help page and menus                                | `NORTHDECK_SUPPORT_URL`    |
| Community chat    | the chat link (empty hides it)                         | `NORTHDECK_CHAT_URL`       |

An empty field uses the `.env` value, shown in grey. Saved values live in the `northdeck_panel_settings` table and are applied when the panel boots, so they work with `php artisan config:cache` too.

`MAIL_FROM_NAME` defaults to `${APP_NAME}` in `.env`, so changing **Name** here doesn't change the sender name; set **Sender name** as well if you want both.
