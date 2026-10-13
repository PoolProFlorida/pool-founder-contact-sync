#!/usr/bin/env bash
# Fake-data HTTP smoke test. Safe for CI; not a production web server.
set -euo pipefail

# Refuse to touch any active authentication stores or non-demo snapshots.
if [[ -f storage/app-passwords.json || -f storage/login-attempts.json ]]; then
    echo "Refusing to touch an existing authentication store." >&2
    exit 1
fi
if [[ -f storage/contacts.json ]] && ! grep -q 'demo-only-001' storage/contacts.json; then
    echo "Refusing to test against a non-demo snapshot." >&2
    exit 1
fi

export CARDDAV_USERNAME='testuser'
export DASHBOARD_USERNAME='testadmin'
export DASHBOARD_PASSWORD_HASH="$(php -r 'echo password_hash("test-dashboard-password-for-fixtures", PASSWORD_DEFAULT);')"
export CARDDAV_PASSWORD='test-old-shared-password-for-fixtures'
unset CARDDAV_LEGACY_ENABLED # Verify default-off behavior, not just an explicit off flag.
port=18765
base="http://127.0.0.1:$port/pool-founder-contact-sync"
auth='' 
cookies="$(mktemp)"
logfile="$(mktemp)"
response="$(mktemp)"

php scripts/demo.php
php -S "127.0.0.1:$port" tests/http_router.php >"$logfile" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" >/dev/null 2>&1 || true; rm -f "$logfile" "$response" "$cookies" storage/app-passwords.json storage/app-passwords.lock storage/login-attempts.json' EXIT

code=000
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$base/" || true)"
    if [[ "$code" == "303" ]]; then break; fi
    sleep 1
done
[[ "$code" == "303" ]] || { echo "Dashboard must redirect to session login, not old Basic Auth." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/login.php")"
[[ "$code" == "200" ]] || { echo "Modern login page unavailable." >&2; exit 1; }
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$response" | head -n1)"
[[ ${#csrf} -eq 64 ]] || { echo "Modern login CSRF token missing." >&2; exit 1; }
code="$(curl -sS -c "$cookies" -b "$cookies" -o /dev/null -w '%{http_code}' -X POST "$base/login.php" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'username=testadmin' \
    --data-urlencode 'password=test-dashboard-password-for-fixtures')"
[[ "$code" == "303" ]] || { echo "Modern dashboard login failed." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/")"
[[ "$code" == "200" ]] || { echo "Dashboard request failed: HTTP $code" >&2; exit 1; }
grep -q 'Alex Example' "$response" || { echo "Fake demo contact not visible" >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/?q=Alex")"
[[ "$code" == "200" ]] || { echo "Authenticated dashboard search failed" >&2; exit 1; }
grep -q 'Alex Example' "$response" || { echo "Contact search did not return matching demo contact" >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/?only=missing-phone")"
[[ "$code" == "200" ]] || { echo "Missing-phone dashboard filter failed" >&2; exit 1; }
grep -q '0 matching contacts' "$response" || { echo "Missing-phone filter returned the wrong count" >&2; exit 1; }

resource="$(php -r "echo hash('sha256', 'customer:demo-only-001');").vcf"
code="$(curl -sS -o /dev/null -w '%{http_code}' "$base/?download=$resource")"
[[ "$code" == "303" ]] || { echo "vCard download must require session authentication" >&2; exit 1; }

# Device CardDAV shared password must not work when migration flag is disabled.
root="$base/carddav/"
code="$(curl -sS -u "$CARDDAV_USERNAME:$CARDDAV_PASSWORD" -X PROPFIND -H 'Depth: 0' -o /dev/null -w '%{http_code}' "$root")"
[[ "$code" == "401" ]] || { echo "Shared CardDAV password must be disabled by default." >&2; exit 1; }

# Generate a real per-device app password via the private admin.
code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/app-passwords.php")"
[[ "$code" == "200" ]] || { echo "App password management unavailable." >&2; exit 1; }
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$response" | head -n1)"
code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' -X POST "$base/app-passwords.php" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=create' --data-urlencode 'label=Smoke test iPhone')"
[[ "$code" == "200" ]] || { echo "App password creation failed." >&2; exit 1; }
secret="$(sed -n 's/.*<div class="code-secret"><code>\([^<]*\)<\/code><\/div>.*/\1/p' "$response" | head -n1)"
[[ ${#secret} -ge 40 ]] || { echo "Generated client secret missing." >&2; exit 1; }
auth="$CARDDAV_USERNAME:$secret"

# Production dashboard must not expose diagnostic pages.
code="$(curl -sS -u "$auth" -o /dev/null -w '%{http_code}' "$base/api-test.php")"
[[ "$code" == "404" ]] || { echo "Diagnostic endpoint is still accessible." >&2; exit 1; }

# Local stylesheet must be served without exposing any PHP source.
code="$(curl -sS -o "$response" -w '%{http_code}' "$base/assets/admin.css")"
[[ "$code" == "200" ]] || { echo "Admin stylesheet not available." >&2; exit 1; }
grep -q '.topbar' "$response" || { echo "Admin stylesheet was incomplete." >&2; exit 1; }
code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/")"
[[ "$code" == "200" ]] || { echo "Dashboard status incorrect." >&2; exit 1; }
if grep -q "Inspect live API response" "$response"; then
    echo "The diagnostic control is still shown." >&2; exit 1;
fi
grep -q "Contacts dashboard" "$response" || { echo "Production dashboard missing." >&2; exit 1; }
grep -q 'Automatic refresh' "$response" || { echo "Cron setup section missing from dashboard." >&2; exit 1; }
grep -Fq 'value="*/30 * * * *"' "$response" || { echo "Cron schedule is incorrect." >&2; exit 1; }
grep -Fq 'data-copy-target="cron-command"' "$response" || { echo "Cron copy control missing." >&2; exit 1; }
grep -Fq '/scripts/sync.php' "$response" || { echo "Cron command must use the local sync CLI." >&2; exit 1; }

code="$(curl -sS -o "$response" -w '%{http_code}' "$base/assets/admin.js")"
[[ "$code" == "200" ]] || { echo "Local admin copy script unavailable." >&2; exit 1; }
grep -Fq 'navigator.clipboard.writeText' "$response" || { echo "Admin clipboard script missing." >&2; exit 1; }

root="$base/carddav/"
code="$(curl -sS -u "$auth" -X PROPFIND -H 'Depth: 0' \
    -H 'Content-Type: application/xml' \
    --data-binary '<D:propfind xmlns:D="DAV:"><D:prop><D:current-user-principal/></D:prop></D:propfind>' \
    -o "$response" -w '%{http_code}' "$root")"
[[ "$code" == "207" ]] || { echo "Principal discovery failed: HTTP $code" >&2; exit 1; }
grep -q 'current-user-principal' "$response" || { echo "Principal property missing" >&2; exit 1; }

book="$root""addressbooks/testuser/contacts/"
code="$(curl -sS -u "$auth" -X REPORT -H 'Depth: 1' \
    -H 'Content-Type: application/xml' \
    --data-binary '<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop><D:getetag/><C:address-data/></D:prop></C:addressbook-query>' \
    -o "$response" -w '%{http_code}' "$book")"
[[ "$code" == "207" ]] || { echo "Addressbook query failed: HTTP $code" >&2; exit 1; }
grep -q 'BEGIN:VCARD' "$response" || { echo "REPORT response did not contain a vCard" >&2; exit 1; }

resource="$(php -r "echo hash('sha256', 'customer:demo-only-001');").vcf"
card="$book$resource"
code="$(curl -sS -u "$auth" -o "$response" -w '%{http_code}' "$card")"
[[ "$code" == "200" ]] || { echo "Contact GET failed: HTTP $code" >&2; exit 1; }
grep -q 'FN:Alex Example' "$response" || { echo "Downloaded vCard is missing the fake name" >&2; exit 1; }
grep -q 'URL;TYPE=WORK:https://www.poolfounder.com/dashboard/customers/demo-only-001' "$response" || {
    echo "Downloaded vCard is missing its customer profile link" >&2; exit 1;
}

code="$(curl -sS -u "$auth" -X PUT -o /dev/null -w '%{http_code}' "$card")"
[[ "$code" == "403" ]] || { echo "Read-only write guard failed: HTTP $code" >&2; exit 1; }

echo 'Protected dashboard and CardDAV HTTP smoke tests passed (fake contacts only).'
