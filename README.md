# Pool Founder Contact Sync

**Know who's calling before you answer.**

A self-hosted, read-only customer address book for [Pool Founder](https://www.poolfounder.com/?ref=pool-pro-florida). Built in PHP, it pulls customer contact details from Pool Founder's documented public API and syncs them to iPhone Contacts (or another compatible CardDAV client).

**Independent project by [Pool Pro Florida](https://poolproflorida.com). Not an official Pool Founder product.**

> **Status:** Working on a real iPhone/CardDAV installation, with automated PHP and HTTP tests. Other hosting setups and CardDAV clients have not been broadly tested; no independent security audit has been completed. See the [production checklist](docs/PRODUCTION_CHECKLIST.md).

## What it does

- **Customer caller ID:** Names, phone numbers, emails, and service days when supplied by the public API.
- **Private, read-only CardDAV:** Contacts sync to your phone; no edits are sent back to Pool Founder.
- **Customer profile links:** Each contact has a Work website link to its Pool Founder customer page. On iPhone, this currently opens the web page, **not necessarily the native app**.
- **Admin dashboard:** Refresh, search, filter, preview, and download contacts.
- **Secure access:** Required dashboard session login and individually revocable CardDAV app passwords.
- **Refresh options:** Manual dashboard refresh, or an optional cron job. The dashboard supplies a copyable command for a **30-minute** schedule.
- **Failure protection:** Refreshes retain the last good snapshot on errors, incomplete responses, or suspicious large drops in contact count.

Runs on Apache/cPanel with PHP. No WordPress, AI, or extra phone app required.

**Current limits:** This build syncs **customers only**, not leads. Service addresses appear only if Pool Founder's public customer API supplies them; they were absent in sampled responses. See [public API field requests](docs/PUBLIC_API_FIELDS.md). Native-app deep links are not confirmed.

## Install

**Requirements:** PHP 8.1+ with cURL and DOM/XML, Apache 2.4+ with working `.htaccess` / `mod_rewrite`, HTTPS, and writable private storage.

Install in its own website subdirectory:

```bash
cd /path/to/your-web-root
git clone https://github.com/PoolProFlorida/pool-founder-contact-sync.git
cd pool-founder-contact-sync
cp .env.example .env
chmod 600 .env
chmod 700 storage
```

Edit the **private server-side** `.env`:

```dotenv
POOL_FOUNDER_API_KEY=your-own-api-key
DASHBOARD_USERNAME=admin
DASHBOARD_PASSWORD_HASH='your-generated-hash'
CARDDAV_USERNAME=poolcontacts
```

Before opening the dashboard, generate a dashboard password hash using `php scripts/hash-dashboard-password.php` (interactive; minimum 16-character password) and paste that hash into your private `.env`. **Neither the old shared dashboard login nor a shared CardDAV password is enabled.** Never commit `.env`, saved contacts, or credentials.

**Before adding real data**, verify the web server blocks direct public access to `.env`, `.git`, `src/`, `scripts/`, and `storage/`. If Apache ignores `.htaccess`, do not deploy under the public web root. Follow the [production checklist](docs/PRODUCTION_CHECKLIST.md).

Open the **dashboard login page** at:

```text
https://example.com/pool-founder-contact-sync/login.php
```

Replace `example.com` and the installation folder with your own HTTPS address. The dashboard home page (`/pool-founder-contact-sync/`) also redirects to this login screen when you're signed out. Sign in using `DASHBOARD_USERNAME` and the **original password** you chose when generating `DASHBOARD_PASSWORD_HASH`—do not paste the hash into the login form. The old browser Basic Auth login is no longer supported.

After signing in, click **Refresh contacts** to build the first snapshot. Your private CardDAV endpoint is:

```text
https://example.com/pool-founder-contact-sync/carddav/
```

From the signed-in dashboard, select **Manage CardDAV app passwords** and generate a separate password for each device. Add the address book on your phone as a **CardDAV account** using `CARDDAV_USERNAME` and that generated **app password**, not the dashboard login password. See [authentication setup and migration](docs/AUTHENTICATION.md).

## Automatic sync

**Phone ↔ address book:** Your CardDAV client periodically retrieves the current server snapshot.

**Pool Founder → server:** Click **Refresh contacts**, or schedule the CLI worker. The **Automatic refresh** section in the logged-in dashboard provides Copy buttons for:

- **Schedule:** Every 30 minutes (`*/30 * * * *`)
- **Command:** Your installation's local PHP CLI command (with no embedded credentials)

In **cPanel → Cron Jobs**, choose Every 30 Minutes and paste the generated command into the **Command** field. **The dashboard does not install the cron job for you.** Verify it runs by checking **Last successful refresh** afterward. Your host may require a different PHP CLI executable; set `CRON_PHP_BINARY` in your private `.env` if needed.

For a manual CLI refresh from the project directory:

```bash
php scripts/sync.php
```

The worker does not print customer data. If fetching fails or the contact count drops unexpectedly, it keeps the previous snapshot.

## Verify your deployment

Run these non-destructive checks from the installed directory:

```bash
php scripts/check-deployment.php https://example.com/pool-founder-contact-sync/
php scripts/check-carddav.php https://example.com/pool-founder-contact-sync/carddav/
```

The first checks public file access and unauthenticated endpoints; the second checks read-only CardDAV discovery and retrieval. Neither is a substitute for a security audit. The CardDAV check uses local credentials; for app-password-only installations, see the [production checklist](docs/PRODUCTION_CHECKLIST.md).

For isolated, **fake-data** tests in a disposable checkout, GitHub Actions runs the PHP, authentication, vCard, CardDAV, cron, and HTTP smoke tests. **Never run demo or destructive test fixtures on your active installation.**

## Update safely

From the installed project directory:

```bash
git pull --ff-only origin main
```

**Existing installations:** before updating, verify your dashboard username/hash work and every device has a tested app password. Legacy dashboard Basic Auth is removed; shared CardDAV passwords are disabled by default. If you still need a short transition, explicitly set `CARDDAV_LEGACY_ENABLED=1` **before upgrading**, then disable it as soon as devices migrate. Back up `.env` and `storage/`. See [migration steps](docs/AUTHENTICATION.md).

## Community Over Competition

Built and shared by **[Pool Pro Florida](https://poolproflorida.com)** for other pool service professionals. Contributions are welcome: [Contributing](CONTRIBUTING.md) · [MIT License](LICENSE).

[GitHub issues](https://github.com/PoolProFlorida/pool-founder-contact-sync/issues) · [Implementation notes](docs/IMPLEMENTATION.md)
