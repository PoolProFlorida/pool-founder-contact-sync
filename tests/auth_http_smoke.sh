#!/usr/bin/env bash
# Integration tests with fake records only. Never run on an active deployment.
set -euo pipefail
export CARDDAV_USERNAME='testuser'
export CARDDAV_PASSWORD='test-legacy-password-for-fixtures-12345'
export CARDDAV_LEGACY_ENABLED=1
export DASHBOARD_USERNAME='testadmin'
export DASHBOARD_PASSWORD_HASH="$(php -r 'echo password_hash("test-dashboard-password-for-fixtures", PASSWORD_DEFAULT);')"
port=18766
base="http://127.0.0.1:$port/pool-founder-contact-sync"
logfile="$(mktemp)"
response="$(mktemp)"
cookies="$(mktemp)"
server_pid=''

# Check for a disposable checkout BEFORE registering a cleanup that removes files.
if [[ -f storage/app-passwords.json || -f storage/login-attempts.json ]]; then
    echo "Refusing to touch an existing authentication store." >&2
    rm -f "$logfile" "$response" "$cookies"
    exit 1
fi
if [[ -f storage/contacts.json ]]; then
    if ! grep -q 'demo-only-001' storage/contacts.json; then
        echo "Refusing to run against non-demo contacts." >&2
        rm -f "$logfile" "$response" "$cookies"
        exit 1
    fi
else
    php scripts/demo.php
fi

cleanup() {
    if [[ -n "$server_pid" ]]; then kill "$server_pid" >/dev/null 2>&1 || true; fi
    rm -f "$logfile" "$response" "$cookies"
    rm -f storage/app-passwords.json storage/app-passwords.lock storage/login-attempts.json
}
trap cleanup EXIT

php -S "127.0.0.1:$port" tests/http_router.php >"$logfile" 2>&1 &
server_pid=$!
code=000
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$base/" || true)"
    if [[ "$code" == "303" ]]; then break; fi
    sleep 1
done
[[ "$code" == "303" ]] || { echo "Unauthenticated dashboard did not redirect to login." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/login.php")"
[[ "$code" == "200" ]] || { echo "Login form was not served." >&2; exit 1; }
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$response" | head -n1)"
[[ $(printf %s "$csrf" | wc -c) -eq 64 ]] || { echo "Login form token missing." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o /dev/null -w '%{http_code}' -X POST "$base/login.php" \
    --data-urlencode "csrf=$csrf" --data-urlencode "username=$DASHBOARD_USERNAME" \
    --data-urlencode "password=wrong-password")"
[[ "$code" == "200" ]] || { echo "Invalid password unexpectedly accepted." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o /dev/null -w '%{http_code}' -X POST "$base/login.php" \
    --data-urlencode "csrf=$csrf" --data-urlencode "username=$DASHBOARD_USERNAME" \
    --data-urlencode 'password=test-dashboard-password-for-fixtures')"
[[ "$code" == "303" ]] || { echo "Valid dashboard login failed." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/")"
[[ "$code" == "200" ]] || { echo "Authenticated dashboard access failed." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' "$base/app-passwords.php")"
[[ "$code" == "200" ]] || { echo "App-password management unavailable." >&2; exit 1; }
csrf="$(sed -n 's/.*name="csrf" value="\([^"]*\)".*/\1/p' "$response" | head -n1)"
[[ $(printf %s "$csrf" | wc -c) -eq 64 ]] || { echo "Admin action token missing." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o "$response" -w '%{http_code}' -X POST "$base/app-passwords.php" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=create' \
    --data-urlencode 'label=Integration test client')"
[[ "$code" == "200" ]] || { echo "App-password creation failed." >&2; exit 1; }
secret="$(sed -n 's/.*<div class="code"><code>\([^<]*\)<\/code><\/div>.*/\1/p' "$response" | head -n1)"
id="$(sed -n 's/.*name="id" value="\([a-f0-9]\{24\}\)".*/\1/p' "$response" | head -n1)"
[[ $(printf %s "$secret" | wc -c) -ge 40 && $(printf %s "$id" | wc -c) -eq 24 ]] || {
    echo "Generated app password or revoke action missing." >&2; exit 1;
}
if grep -Fq "$secret" storage/app-passwords.json; then
    echo "Raw app password persisted in the private store." >&2; exit 1;
fi

root="$base/carddav/"
code="$(curl -sS -u "$CARDDAV_USERNAME:$secret" -X PROPFIND -H 'Depth: 0' -o /dev/null -w '%{http_code}' "$root")"
[[ "$code" == "207" ]] || { echo "Generated app password rejected by CardDAV." >&2; exit 1; }
code="$(curl -sS -u "$CARDDAV_USERNAME:$CARDDAV_PASSWORD" -X PROPFIND -H 'Depth: 0' -o /dev/null -w '%{http_code}' "$root")"
[[ "$code" == "207" ]] || { echo "Legacy CardDAV credential stopped working during migration." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o /dev/null -w '%{http_code}' -X POST "$base/app-passwords.php" \
    --data-urlencode "csrf=$csrf" --data-urlencode 'action=revoke' --data-urlencode "id=$id")"
[[ "$code" == "303" ]] || { echo "App-password revoke failed." >&2; exit 1; }
code="$(curl -sS -u "$CARDDAV_USERNAME:$secret" -X PROPFIND -H 'Depth: 0' -o /dev/null -w '%{http_code}' "$root")"
[[ "$code" == "401" ]] || { echo "Revoked app password remains valid." >&2; exit 1; }
code="$(curl -sS -u "$CARDDAV_USERNAME:$CARDDAV_PASSWORD" -X PROPFIND -H 'Depth: 0' -o /dev/null -w '%{http_code}' "$root")"
[[ "$code" == "207" ]] || { echo "Revocation disrupted legacy CardDAV login." >&2; exit 1; }

code="$(curl -sS -c "$cookies" -b "$cookies" -o /dev/null -w '%{http_code}' -X POST "$base/logout.php" \
    --data-urlencode "csrf=$csrf")"
[[ "$code" == "303" ]] || { echo "Logout failed." >&2; exit 1; }
code="$(curl -sS -c "$cookies" -b "$cookies" -o /dev/null -w '%{http_code}' "$base/")"
[[ "$code" == "303" ]] || { echo "Dashboard session survived logout." >&2; exit 1; }

echo "Modern dashboard login, app-password creation/revocation, and legacy migration smoke tests passed."
