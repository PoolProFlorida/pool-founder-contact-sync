#!/usr/bin/env bash
# Fake-data HTTP smoke test. Safe for CI; not a production web server.
set -euo pipefail

export CARDDAV_USERNAME='testuser'
export CARDDAV_PASSWORD='test-password-for-fixtures-12345'
port=18765
base="http://127.0.0.1:$port/pool-founder-contact-sync"
auth="$CARDDAV_USERNAME:$CARDDAV_PASSWORD"
logfile="$(mktemp)"
response="$(mktemp)"

php scripts/demo.php
php -S "127.0.0.1:$port" tests/http_router.php >"$logfile" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" >/dev/null 2>&1 || true; rm -f "$logfile" "$response"' EXIT

code=000
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "$base/" || true)"
    if [[ "$code" == "401" ]]; then break; fi
    sleep 1
done
if [[ "$code" != '401' ]]; then
    echo "Protected dashboard did not challenge unauthenticated requests." >&2
    exit 1
fi

code="$(curl -sS -u "$auth" -o "$response" -w '%{http_code}' "$base/")"
[[ "$code" == "200" ]] || { echo "Dashboard request failed: HTTP $code" >&2; exit 1; }
grep -q 'Alex Example' "$response" || { echo "Fake demo contact not visible" >&2; exit 1; }

code="$(curl -sS -u "$auth" -o "$response" -w '%{http_code}' "$base/?q=Alex")"
[[ "$code" == "200" ]] || { echo "Authenticated dashboard search failed" >&2; exit 1; }
grep -q 'Alex Example' "$response" || { echo "Contact search did not return matching demo contact" >&2; exit 1; }

code="$(curl -sS -u "$auth" -o "$response" -w '%{http_code}' "$base/?only=missing-phone")"
[[ "$code" == "200" ]] || { echo "Missing-phone dashboard filter failed" >&2; exit 1; }
grep -q '0 matching contacts' "$response" || { echo "Missing-phone filter returned the wrong count" >&2; exit 1; }

resource="$(php -r "echo hash('sha256', 'customer:demo-only-001');").vcf"
code="$(curl -sS -o /dev/null -w '%{http_code}' "$base/?download=$resource")"
[[ "$code" == "401" ]] || { echo "vCard download must require authentication" >&2; exit 1; }

# Production dashboard must not expose diagnostic pages.
code="$(curl -sS -u "$auth" -o /dev/null -w '%{http_code}' "$base/api-test.php")"
[[ "$code" == "404" ]] || { echo "Diagnostic endpoint is still accessible." >&2; exit 1; }

# Local stylesheet must be served without exposing any PHP source.
code="$(curl -sS -o "$response" -w '%{http_code}' "$base/assets/admin.css")"
[[ "$code" == "200" ]] || { echo "Admin stylesheet not available." >&2; exit 1; }
grep -q '.topbar' "$response" || { echo "Admin stylesheet was incomplete." >&2; exit 1; }
code="$(curl -sS -u "$auth" -o "$response" -w '%{http_code}' "$base/")"
[[ "$code" == "200" ]] || { echo "Dashboard status incorrect." >&2; exit 1; }
if grep -q "Inspect live API response" "$response"; then
    echo "The diagnostic control is still shown." >&2; exit 1;
fi
grep -q "Contacts dashboard" "$response" || { echo "Production dashboard missing." >&2; exit 1; }

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
