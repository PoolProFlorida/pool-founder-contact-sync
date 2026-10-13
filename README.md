# Pool Founder Contact Sync

**Know who's calling. One address book. Customers (and eventually leads) right on your phone.**

A self-hosted, read-only PHP CardDAV project for [Pool Founder](https://www.poolfounder.com/?ref=pool-pro-florida) users.

> **Status: Release candidate — deployment verification required.** Live customer retrieval, private administration, and a deployed CardDAV endpoint have passed their initial checks. Automated PHP and HTTP integration tests cover the admin login, app-password management and read-only DAV behavior. **Full client interoperability and an independent security audit have not been completed.** Before public deployment, complete the [production checklist](docs/PRODUCTION_CHECKLIST.md). Service addresses and leads depend on documented public API support.

## Why We Built This

Hate getting a phone call and not knowing which customer is calling? We do too.

[Pool Founder](https://www.poolfounder.com/?ref=pool-pro-florida) already keeps track of your customers, leads, and service information. You can always open the app to find what you need. But why should you have to?

That's why we built Pool Founder Contact Sync.

It brings your customers and leads into your phone's native contacts, so you know who's calling before you answer. Customer names, service addresses, and other useful details are right there when you need them.

No replacing Pool Founder. No extra app to manage. Just making the phone you already carry a little smarter.

Built by Pool Pro Florida, shared with the pool service community.

**Community Over Competition.**

*The paragraph above describes the product's goal. The current build syncs customers only; leads and service addresses depend on available read-only Pool Founder API fields.*

## What works in this build

- Self-contained PHP application installed in a **subdirectory** of an Apache/cPanel website
- Clean, responsive private administration to refresh customers, search contacts and download individual vCards
- A consistent login screen and app-password management, with no customer API debugging output exposed through the browser
- CLI-only deployment access checks for secrets, private storage, CardDAV authentication and public asset delivery
- Read-only customer fetch using documented GET /api/v1/customers with cursor pagination
- Saved local snapshot: failed/partial fetches and suspicious mass deletions do **not** replace the previous contacts
- Initial read-only CardDAV endpoints for principal discovery, PROPFIND, REPORT, GET, and HEAD
- Separate credentials for API access and CardDAV access
- **Optional modern dashboard authentication**: hashed admin password, HTTPS-only sessions, sign out, CSRF validation and login-attempt throttling
- **Revocable CardDAV app passwords**: unique secret per client, hashed at rest, with one-time display; legacy credentials remain active until intentionally disabled
- Stable filenames derived from customer IDs; no WordPress, Cal.com, or AI dependency

### Current limitations

- **Customer-only** until a public read/list-leads API is verified.
- Service addresses are included **only if the public API supplies them**. An authenticated three-record customer-list sample had no address field. See [public API field requirements](docs/PUBLIC_API_FIELDS.md) for a proposed read-only API extension.
- No lead-to-customer duplicate reconciliation yet.
- CardDAV uses Basic Auth over HTTPS (legacy or per-client app password). The dashboard can separately use cookie sessions. No client can write changes back to Pool Founder.
- The CardDAV handler is an early implementation; client interoperability, including Apple Contacts, must be tested.
- No cron schedule is installed automatically. Refresh manually from the dashboard or run the optional CLI script from your own scheduler.

## Improved authentication (opt-in)

A new dashboard login and independently revocable CardDAV client passwords are available **without changing existing credentials on upgrade**.

See the step-by-step [authentication migration and rollback guide](docs/AUTHENTICATION.md). The new login activates only when **both** DASHBOARD_USERNAME and DASHBOARD_PASSWORD_HASH are configured in the private server .env. Use the interactive hash generator:

    php scripts/hash-dashboard-password.php

After signing in, choose **Manage CardDAV app passwords**. Issue one password per client. Your previous CardDAV username and password continue to work until you deliberately set CARDDAV_LEGACY_ENABLED=0 after validating the replacements.

**Do not turn off the legacy CardDAV password or alter your CardDAV username during the initial upgrade.**

## Install in a subdirectory

**Requirements:** Apache 2.4+ with .htaccess / mod_rewrite enabled, PHP 8.1+ with cURL and DOM/XML extensions, HTTPS, and a writable private storage directory. Use a dedicated URL and private credentials.

Example deployment:

    /path/to/your-web-root/
    └── pool-founder-contact-sync/    # This Git repository
        ├── .htaccess
        ├── .env                      # Create this locally; never commit
        ├── index.php                 # Protected administration dashboard
        ├── carddav.php
        ├── src/
        ├── storage/                  # Private snapshot, not web-accessible
        ├── scripts/
        └── tests/

From an SSH shell, replace the sample web-root path with your own:

    cd /path/to/your-web-root
    git clone https://github.com/PoolProFlorida/pool-founder-contact-sync.git
    cd pool-founder-contact-sync
    cp .env.example .env
    chmod 600 .env
    chmod 700 storage

Edit .env on **the server**:

    POOL_FOUNDER_API_KEY=your-real-api-key
    CARDDAV_USERNAME=poolcontacts
    CARDDAV_PASSWORD=some-long-unique-random-password

Use a new dedicated CardDAV password of at least 16 characters. Avoid characters requiring special dotenv quoting unless you quote the value; this app accepts plain KEY=VALUE and matching single/double outer quotes.

The PHP/web-server account must have write permission to storage. On shared hosting, PHP usually runs as your cPanel account; adjust ownership/permissions only if necessary. Do **not** make storage world-writable.

Open:

- **Dashboard:** https://example.com/pool-founder-contact-sync/
- **CardDAV:** https://example.com/pool-founder-contact-sync/carddav/

The dashboard uses a separate cookie-based login when configured, or the older Basic Auth flow until migrated. CardDAV clients use HTTPS Basic Auth with individually revocable app passwords. The dashboard provides contact counts, a manual refresh action, search/filtering, vCard downloads, and access to app-password management.

### Public API field availability

Service addresses appeared in none of the three masked customer records sampled during development; this is a public API field limitation, not a reason to expose raw customer responses in the dashboard. See [public API field requirements](docs/PUBLIC_API_FIELDS.md). Browser diagnostics are removed from the production administration.

**Before entering any real credentials or fetching customer data**, verify that your server blocks direct HTTP access to:

- /pool-founder-contact-sync/.env
- /pool-founder-contact-sync/.git/HEAD
- /pool-founder-contact-sync/storage/contacts.json
- /pool-founder-contact-sync/src/Config.php

They should return 403/404, never their contents. Verify HTTPS is enabled and the dashboard returns 401 when unauthenticated. If the server ignores .htaccess, **do not use this webroot deployment** until it is fixed or you move private files outside the document root.

## Try it with fake data first

Before connecting your Pool Founder API key, you can verify the dashboard and vCard links using one fake contact:

    php scripts/demo.php

This command works only in the terminal and **refuses to overwrite an existing snapshot**. Set your CardDAV username/password in `.env`, open the protected dashboard, then click **View** and **VCF**. The demo is visibly fake (`example.invalid` email and example address).

When ready for real customers, click **Refresh contacts** on the dashboard after adding your real API key. A successful full refresh replaces the demo snapshot. If the refresh fails, the demo stays in place. Do not connect a production phone while demo data is loaded.

## Optional scheduled refresh

To refresh from a trusted terminal or an existing cron scheduler:

    php scripts/sync.php

This command reads your local `.env`, fetches all customer pages and writes a snapshot only after a complete successful response. On a failure it returns a nonzero exit status and preserves your last snapshot. It also **refuses unexpectedly large drops in customer count** (more than 25% when 10+ contacts were previously saved). That safeguard prevents a broken upstream response from removing most of a phone's contacts. To accept a truly intentional mass removal, review the data and make a backup before resetting the existing snapshot.

Do not set up a cron schedule until the first manual refresh and CardDAV tests work.

## Update

Run inside the project directory:

    git pull --ff-only origin main

Your local .env and storage snapshot are excluded from Git. Make a backup before major upgrades. This application is confined to its own installation directory. See [production upgrade and security checks](docs/PRODUCTION_CHECKLIST.md) before applying changes.

## Production deployment checks

Before treating a deployment as production-ready, run the non-destructive external access check from the application directory:

```bash
php scripts/check-deployment.php https://example.com/pool-founder-contact-sync/
```

This checks that private files, customer snapshots, internal scripts and old debug endpoints are not reachable over public HTTPS. It also verifies that the dashboard and CardDAV reject unauthenticated requests. It does not display any saved customer records or credentials. **A successful check does not replace a security audit.** See the [production checklist](docs/PRODUCTION_CHECKLIST.md).

## Validate CardDAV without a phone

Once you have a complete saved customer snapshot, run the included **read-only protocol check** from the deployment directory:

```bash
php scripts/check-carddav.php https://example.com/pool-founder-contact-sync/carddav/
```

Replace the example URL with your installation's HTTPS CardDAV URL. The script uses the locally configured `CARDDAV_USERNAME` and `CARDDAV_PASSWORD`, verifies anonymous requests are rejected, discovers the principal and address book, runs an address-book REPORT, and validates one vCard GET and ETag if any cards exist. **It never writes contacts or prints names, phone numbers, addresses, API tokens, or downloaded vCard content.** It requires PHP cURL and DOM/XML extensions.

If a check fails, the script prints the failing protocol stage. This is an interoperability smoke test, not full CardDAV conformance certification.

## Test

From the checkout:

    php tests/vcard_test.php
    php tests/api_inspector_test.php
    php tests/dav_test.php
    php -l scripts/check-carddav.php
    php scripts/preview.php

The preview script reads your API key from an environment variable, not the .env file. To run it manually:

    POOL_FOUNDER_API_KEY='YOUR_KEY' php scripts/preview.php

The snapshot refresh in the dashboard reads .env automatically.

On the server, use an authenticated PROPFIND request to confirm DAV XML, for example:

    curl -i -u 'poolcontacts:YOUR_PASSWORD' -X PROPFIND \
      -H 'Depth: 0' \
      https://example.com/pool-founder-contact-sync/carddav/

Do not paste your real password into shared terminals or logs; use a safer credential method where possible. Client compatibility is not yet certified.

## Security

- **Never commit credentials or customer data to this public repository.**
- Dashboard account password is stored only as a password hash; CardDAV client app passwords are stored only as hashes and may be revoked separately.
- New dashboard login and CardDAV migration are opt-in; see the linked migration guide before enabling.
- API key is used only for read-only GET customer calls; this project does not edit Pool Founder.
- All protected interfaces require HTTPS. The dashboard supports secure session login, while CardDAV clients authenticate with HTTPS Basic Auth and revocable app passwords.
- The Apache .htaccess file denies access to private application directories and dotfiles. Check these rules on your particular host before going live.
- Failed API fetches leave the previous saved snapshot intact.
- Treat backups and the storage directory as sensitive personal data.

## Community Over Competition

Created by **[Pool Pro Florida](https://poolproflorida.com)** for the pool service community.

Independent community project, not an official [Pool Founder](https://www.poolfounder.com/?ref=pool-pro-florida) product.

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE).
