# Pool Founder Contact Sync

**Know who's calling. One address book. Customers (and eventually leads) right on your phone.**

A self-hosted, read-only PHP CardDAV project for [Pool Founder](https://www.poolfounder.com/?ref=pool-pro-florida) users.

> **Status: Early testing release.** The customer preview and an initial CardDAV server are implemented, but **have not yet been tested against a live Pool Founder API or an iPhone CardDAV account**. Lead retrieval is not implemented because the public read-only leads endpoint is not yet confirmed. Not production-ready.

## Why We Built This

Hate getting a phone call and not knowing which customer is calling? We do too.

[Pool Founder](https://www.poolfounder.com/?ref=pool-pro-florida) already keeps track of your customers, leads, and service information. You can always open the app to find what you need. But why should you have to?

That's why we built Pool Founder Contact Sync.

It brings your customers and leads into your phone's native contacts, so you know who's calling before you answer. Customer names, service addresses, and other useful details are right there when you need them.

No replacing Pool Founder. No extra app to manage. Just making the phone you already carry a little smarter.

Built by Pool Pro Florida, shared with the pool service community.

**Community Over Competition.**

*The paragraph above describes the product's goal. The current test build syncs customers only; leads and service addresses depend on available read-only Pool Founder API fields.*

## What works in this build

- Self-contained PHP application installed in a **subdirectory** of an Apache/cPanel website
- Password-protected dashboard to fetch customers and inspect/download vCards
- Read-only customer fetch using documented GET /api/v1/customers with cursor pagination
- Saved local snapshot: failed/partial fetches do **not** replace the previous contacts
- Initial read-only CardDAV endpoints for principal discovery, PROPFIND, REPORT, GET, and HEAD
- Separate credentials for API access and CardDAV access
- Stable filenames derived from customer IDs; no WordPress, Cal.com, or AI dependency

### Current limitations

- **Customer-only** until a public read/list-leads API is verified.
- Service addresses are included **only if the public API supplies them**. Availability through a separate Pool Founder integration does not guarantee public API availability.
- No lead-to-customer duplicate reconciliation yet.
- Only Basic Auth over HTTPS is supported. No edits from your phone will be sent back to Pool Founder.
- The CardDAV handler is an early implementation; client interoperability, including Apple Contacts, must be tested.
- No automatic timer/cron refresh configured. The dashboard refresh button fetches a snapshot manually.

## Install in a subdirectory

**Requirements:** Apache 2.4+ with .htaccess / mod_rewrite enabled, PHP 8.1+ with cURL and DOM/XML extensions, HTTPS, and a writable private storage directory. Use a dedicated URL and private credentials.

Example deployment:

    /home/ACCOUNT/public_html/
    ├── sync/                         # Your existing tool — DO NOT MODIFY
    └── pool-founder-contact-sync/    # This Git repository
        ├── .htaccess
        ├── .env                      # Create this locally; never commit
        ├── index.php                 # Protected preview dashboard
        ├── carddav.php
        ├── src/
        ├── storage/                  # Private snapshot, not web-accessible
        ├── scripts/
        └── tests/

From your server's SSH shell (replace ACCOUNT with your real username):

    cd /home/ACCOUNT/public_html
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

- **Dashboard:** https://poolproflorida.com/pool-founder-contact-sync/
- **CardDAV:** https://poolproflorida.com/pool-founder-contact-sync/carddav/

Both endpoints require the configured username and password. The dashboard lets you refresh customer data and click **View** or **VCF** for a contact.

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

When ready for real customers, click **Fetch customers & rebuild preview** on the dashboard after adding your real API key. A successful full refresh replaces the demo snapshot. If the refresh fails, the demo stays in place. Do not connect a production phone while demo data is loaded.

## Update

Run inside the project directory:

    git pull --ff-only origin main

Your local .env and storage snapshot are excluded from Git. Make a backup before major upgrades. This tool does not touch the existing /sync/ application.

## Test

From the checkout:

    php tests/vcard_test.php
    php tests/dav_test.php
    php scripts/preview.php

The preview script reads your API key from an environment variable, not the .env file. To run it manually:

    POOL_FOUNDER_API_KEY='YOUR_KEY' php scripts/preview.php

The snapshot refresh in the dashboard reads .env automatically.

On the server, use an authenticated PROPFIND request to confirm DAV XML, for example:

    curl -i -u 'poolcontacts:YOUR_PASSWORD' -X PROPFIND \
      -H 'Depth: 0' \
      https://poolproflorida.com/pool-founder-contact-sync/carddav/

Do not paste your real password into shared terminals or logs; use a safer credential method where possible. Client compatibility is not yet certified.

## Security

- **Never commit credentials or customer data to this public repository.**
- API key is used only for read-only GET customer calls; this project does not edit Pool Founder.
- The web dashboard, VCF downloads, and CardDAV require HTTPS + Basic Auth.
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
