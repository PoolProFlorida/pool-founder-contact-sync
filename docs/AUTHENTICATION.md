# Authentication and upgrading safely

**New installations use one dashboard login and individually revocable CardDAV app passwords.** The old browser Basic Auth dashboard login is removed; shared CardDAV passwords are disabled by default.

## New installations

1. Set your Pool Founder read-only API key and stable `CARDDAV_USERNAME` in your private `.env`.
2. In a trusted interactive terminal run `php scripts/hash-dashboard-password.php`. Choose a unique dashboard password (at least 16 characters) and enter it twice. The script outputs a password hash, not your plaintext password.
3. Add both values to your private `.env`:

   ```dotenv
   DASHBOARD_USERNAME=admin
   DASHBOARD_PASSWORD_HASH='$2y$...replace-with-generated-hash...'
   CARDDAV_USERNAME=poolcontacts
   ```

   The password hash above is only a placeholder; keep the real hash and all credentials private.
4. Sign in to your HTTPS dashboard with the username and password you chose.
5. Select **Manage CardDAV app passwords** and generate a separate password for each phone/computer. Save each one when shown; only a hash is stored.
6. Add the CardDAV address book to the device with the stable CardDAV username and that device's unique password.

There is **no** `CARDDAV_PASSWORD` setting for a new installation. Device access remains Basic authentication **over HTTPS**, as required for CardDAV compatibility, but each device uses a separate app password. The dashboard uses an HTTPS session cookie; **no HTTP Basic Auth dashboard popup**.

## Existing installations: migrate **before** updating

**Important: upgrading without preparation can interrupt dashboard access or stop old CardDAV clients from syncing.** Back up your private `.env` and the entire `storage/` directory first. Do not publish backups, tokens, or customer details.

1. On the existing version, configure and test `DASHBOARD_USERNAME` and `DASHBOARD_PASSWORD_HASH` (see the hashing steps above). Verify the modern login in a fresh browser window.
2. Sign in and generate a unique CardDAV app password for every currently connected device. Update each device using the **unchanged** CardDAV server URL and `CARDDAV_USERNAME`. Test each device's sync.
3. After all devices use app passwords, set `CARDDAV_LEGACY_ENABLED=0` (or remove it) in your private `.env`. Existing `CARDDAV_PASSWORD` becomes unused and can be deleted **after** verification.
4. Only then pull the new code (`git pull --ff-only origin main`) and confirm dashboard access and CardDAV sync.

**Short transition for unconverted devices:** Before upgrading, you can explicitly set both `CARDDAV_PASSWORD` (the existing strong password) and `CARDDAV_LEGACY_ENABLED=1` in the private `.env`. The shared password continues working **only while this flag is explicitly enabled**. Migrate the devices promptly, then remove the flag or set it to `0`. This option is not part of new-install setup and does **not** restore the old dashboard login.

Never change `CARDDAV_USERNAME` during an upgrade: the DAV address-book paths use it.

## Troubleshooting and rollback

- **Dashboard HTTP 503:** The username/hash is absent, invalid, or incomplete. Restore the correct modern login values in the private `.env` or generate a new hash. The removed dashboard Basic Auth fallback cannot be reactivated.
- **CardDAV HTTP 401:** Verify the device is using its own active app password. For a short preplanned migration window, use `CARDDAV_LEGACY_ENABLED=1` only if the previous shared password is still present; turn this off again once devices work.
- **Revoked password:** Generate a new app password. Plaintext cannot be recovered.
- Keep `storage/app-passwords.json` and the entire existing snapshot intact. Do **not** delete storage to troubleshoot authentication.
- If a code rollback is needed, restore the prior known-good version and backed-up private config, then complete the migration before trying again.

## Security details

- Dashboard session cookies: Secure, HttpOnly, SameSite=Strict. Session expiry after 30 minutes idle or 12 hours total; sign-in is rate limited and actions require CSRF tokens.
- Only hashes of device app passwords are stored. Revocation is per-device.
- CardDAV is read-only; API customer retrieval is read-only.
- Server `.htaccess` must block public access to `.env`, storage, source code, and scripts. Verify using `scripts/check-deployment.php`.
- No authentication feature substitutes for current PHP updates, correct filesystem permissions, and private backups.
