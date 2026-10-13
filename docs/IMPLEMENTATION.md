# Implementation status

## Implemented (early test build)

- Standalone PHP deployment beneath a site subdirectory, protected by scoped Apache rules
- Password-protected dashboard with API refresh and vCard preview/download
- Customer API GET /api/v1/customers with cursor pagination
- Atomic local JSON snapshot storage; incomplete/failing requests preserve the previous snapshot
- Customer vCard generation and multi-service-location mapping when the public API provides address fields
- Read-only CardDAV skeleton: principal and address book PROPFIND discovery, query/multiget REPORT, card GET/HEAD, OPTIONS, write-method denial
- Fake demo snapshot generator and offline smoke tests

**Not yet verified:** real API integration, CardDAV client interoperability on iOS/macOS, Apache/cPanel deployment and web access-denial checks.

## Next

1. Validate Apache .htaccess protections and PHP cURL/DOM availability on a test host before adding credentials.
2. Use demo data to test the dashboard, VCF download and CardDAV endpoint in a separate subdirectory.
3. Test a full Pool Founder customer API snapshot and confirm which address fields the public API actually returns.
4. Test on iPhone/macOS Contacts; adjust DAV XML and discovery compatibility as needed.
5. Confirm public read-only lead listing endpoint and pagination before implementing leads. Define explicit lead-to-customer identity reconciliation to avoid duplicates.
6. Only after all tests pass consider automatic scheduled refresh and a stable release.

## Security invariants

- Pool Founder access is read-only; the app does not modify other applications or installations.
- Never expose API keys, CardDAV password, saved contacts, internal notes, gate codes or pricing through the public repo or unauthenticated HTTP.
- Both dashboard and CardDAV demand HTTPS with independent Basic Auth credentials.
- Existing saved snapshot survives failed, incomplete or unexpectedly empty API reads.
- Each vCard gets a stable internal filename derived from its source customer ID.
