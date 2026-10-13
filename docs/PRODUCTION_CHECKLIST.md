# Production rollout checklist

**Scope:** standalone community PHP application. All paths, domains and credentials in this document are examples. Never publish a customer's records, server configuration or credentials in the repository or an issue.

The public project is a **release candidate**, not a security-audited product. The steps below are required on each hosting environment before calling a deployment ready.

## 1. Protect the existing installation

- Back up the existing **private** .env file and the **entire** storage directory outside the public web root. The backup may contain customer details and credential hashes; secure it accordingly.
- Record the current contact count and last successful refresh so you can compare after updating.
- Preserve CARDDAV_USERNAME and all existing app-password hashes. Changing the username breaks existing CardDAV principal URLs.
- **BEFORE pulling the new release:** configure and test `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD_HASH`, then migrate iPhone/other clients to individual app passwords. Legacy dashboard Basic Auth is removed; missing dashboard credentials return HTTP 503. The old shared CardDAV password is disabled by default; if needed briefly, explicitly set `CARDDAV_LEGACY_ENABLED=1` before updating.
- The update must **never delete** storage/contacts.json, storage/app-passwords.json, or your private .env.
- Use HTTPS and ensure Apache honors the bundled .htaccess rules. PHP 8.1+ with cURL and DOM/XML is required. If these rules are not enforced, do not use this webroot deployment.
- Do **not** run scripts/demo.php or tests/http_smoke.sh on a live deployment. They are for separate, disposable fake-data checkouts.

## 2. Update the code

From the installation directory after its release is merged:

    git pull --ff-only origin main

Check that **assets/admin.css** and **assets/admin.js** exist. Confirm the modern dashboard login is already configured in your private `.env` before updating.

The public API diagnostic page has been removed; the new .htaccess additionally blocks any unlisted root PHP entry point. Developer tests stay in the repository, but are not available through the dashboard.

## 3. Check private-file access from outside

Use the HTTPS application root, **not** its CardDAV path:

    php scripts/check-deployment.php https://example.com/pool-founder-contact-sync/

The check does not authenticate, follow redirects or display response bodies. It verifies that .env, Git metadata, customer snapshot, app-password storage, PHP source, CLI scripts, tests, and old API diagnostics are denied by the web server.

It also checks that an anonymous dashboard request requires authentication, the CardDAV endpoint challenges anonymous access, and the local stylesheet loads. **All checks should PASS.** If any private file returns HTTP 200, take the deployment offline until the restriction is fixed.

This is a basic deployment hardening check, not penetration testing or a full security audit.

## 4. Confirm the contact directory

- Sign in using the configured separate dashboard login. There should be **no** API-response inspection button, field-name debug panel or server-readiness test panel.
- Verify the displayed contact count matches your existing snapshot.
- Search for a customer, view their vCard, and download one .vcf file.
- Click **Refresh contacts** once. Confirm the count remains plausible and the last successful refresh updates.
- If a refresh fails, previous contacts must still be available.
- Service addresses and lead records remain conditional on documented public API support. Do not infer them from unrelated private data sources.

## 5. Verify CardDAV without a phone

For installations that still accept the legacy CardDAV password:

    php scripts/check-carddav.php https://example.com/pool-founder-contact-sync/carddav/

For installations using **only app passwords**, the test accepts a temporary environment variable, so the secret need not appear as an argument or in shell history. In a trusted Bash shell:

    read -rs -p 'Temporary CardDAV app password: ' CARDDAV_TEST_PASSWORD
    echo
    export CARDDAV_TEST_PASSWORD
    php scripts/check-carddav.php https://example.com/pool-founder-contact-sync/carddav/
    unset CARDDAV_TEST_PASSWORD

Check that principal discovery, address book discovery, REPORT, individual contact GET, and ETag validation pass. No phone is required. The test never modifies contacts or prints their contents.

## 6. Check authentication lifecycle

- Sign out, verify the private dashboard requires sign-in again.
- Create a **temporary** named app password in the admin area and save it privately.
- Test CardDAV access with that temporary password, then revoke it and confirm it no longer works.
- The old shared CardDAV password is disabled by default. It works temporarily **only** when explicitly set to `CARDDAV_LEGACY_ENABLED=1` and a valid `CARDDAV_PASSWORD` exists. Disable the migration flag once every client has a tested app password.
- Disable the legacy credential **only after** all actual clients are migrated and verified. Do not change CardDAV_USERNAME.
- The admin login and client app passwords are independent. Never publish a generated secret, password hash, or raw API response.

## 7. Ongoing maintenance and rollback

- Refresh contacts from the dashboard or schedule scripts/sync.php in a trusted server cron after confirming manual sync works. Do not use a public URL as a sync trigger.
- Back up private data regularly, restrict backup permissions, and keep PHP and Apache patched.
- If the new code fails, restore the prior known-working release and its matching private backup. Do not solve problems by deleting the contact snapshot or app-password database.
- Follow the [authentication migration guide](AUTHENTICATION.md) for credential rollback.
- Validate additional CardDAV clients independently before claiming broad interoperability. A passing internal smoke test does not establish full protocol conformance.

## Release requirements

A deployment may be described as **locally verified** after all applicable steps pass. A broader production-ready community release still benefits from independent security review, several CardDAV client interoperability checks, and supported public API fields for addresses and leads.
