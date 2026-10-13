# Authentication and safe migration

This is a self-contained, read-only community PHP application. All URLs, usernames and examples below are fictional.

## Separate access methods

- **Dashboard administrator:** Optional username + hashed password, Secure/HttpOnly/SameSite=Strict session cookies, CSRF protection, sign out, session expiry, and login-attempt throttling. The plain dashboard password is never stored.
- **CardDAV clients:** The same stable CardDAV username as before, now paired with a distinct random app password for each client. Only app-password hashes are saved. Revoking a password does not affect other clients or the dashboard.
- **Pool Founder API key:** Remains private on the server. Not used for dashboard or CardDAV login.

CardDAV continues to support Basic authentication **over HTTPS** for broad compatibility. The dashboard uses session cookies after migration.

## Safe rollout for an existing installation

1. Back up your **private** .env and storage directory. Confirm your web server denies public access to those files. Never upload them to GitHub.
2. Update project code. **Existing authentication stays active until you opt in.** With dashboard settings absent, the old dashboard login still works. The existing CardDAV password remains valid by default.
3. In a private interactive SSH terminal, run:

       php scripts/hash-dashboard-password.php

   Enter a new unique dashboard password (minimum 16 characters) twice. Input is hidden. The command outputs **only a password hash**, not plaintext. Never pass a password on the command line.
4. Copy the generated hash into your private .env and configure BOTH new values:

       DASHBOARD_USERNAME=admin
       DASHBOARD_PASSWORD_HASH='$2y$...paste-generated-hash-here...'

   The above hash is illustrative, not usable. Preserve all dollar signs and do not post the hash online. A partial/invalid dashboard setup fails closed rather than silently reverting to Basic Auth.
5. Open the dashboard in a new browser session. You should get a login form instead of the browser Basic Auth popup. Sign in, confirm the contact preview, and sign out and back in.
6. Choose **Manage CardDAV app passwords** in the dashboard. Generate a unique secret for each client. The password appears **once**; only its hash is saved. Store it in your password manager.
7. Test one client with the unchanged CardDAV URL and unchanged CardDAV username, but use its newly generated app password. Revoke a temporary test password and confirm it no longer works. Existing clients continue working with their original shared password during migration.
8. **Only after all clients have switched**, optionally disable the old password in your private .env:

       CARDDAV_LEGACY_ENABLED=0

   Never change CARDDAV_USERNAME during migration, because it appears in all DAV principal URLs.

## Rollback

- For dashboard login issues, restore the backed-up .env or remove **both** DASHBOARD_USERNAME and DASHBOARD_PASSWORD_HASH. The previous dashboard Basic Auth returns.
- If a CardDAV client loses access before migration is complete, temporarily set CARDDAV_LEGACY_ENABLED=1 and issue a fresh app password. A revoked secret cannot be recovered.
- Do not delete storage/app-passwords.json when active clients depend on it.

## Private storage and security

- App password hashes: storage/app-passwords.json. Updates use atomic replacement and a separate lock file.
- Login throttling: storage/login-attempts.json stores hashed origin identifiers, not raw IP addresses.
- .htaccess denies direct HTTP access to private files and storage. Verify this on your hosting environment.
- Session cookies require HTTPS and expire after 30 minutes idle or 12 hours total. Changing the dashboard password hash invalidates sessions.
- Dashboard actions require session-based CSRF tokens. CardDAV remains read-only regardless of credentials.
- No credentials or customer data are sent to GitHub. Keep private backups protected.

## Testing

The following isolated test does not modify a real password store:

    php tests/auth_test.php

Run the existing HTTP smoke test **only in a disposable checkout containing fake data**, never in the active deployment:

    bash tests/http_smoke.sh

No authentication mechanism replaces server hardening, restricted access, and security updates.
