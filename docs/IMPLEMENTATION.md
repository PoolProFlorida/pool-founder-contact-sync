# Implementation status

## Implemented (release candidate)

- Standalone PHP deployment beneath a site subdirectory, protected by scoped Apache rules
- Responsive production-oriented admin dashboard for refresh, contact search and individual vCard download; no browser-facing API debug views
- Customer API GET /api/v1/customers with cursor pagination
- Atomic local JSON snapshot storage; incomplete/failing requests preserve the previous snapshot
- Customer vCard generation and multi-service-location mapping when the public API provides address fields
- Read-only CardDAV skeleton: principal and address book PROPFIND discovery, query/multiget REPORT, card GET/HEAD, OPTIONS, write-method denial
- Optional hashed-password dashboard login with secure cookie sessions, logout, CSRF validation and persistent login-attempt throttling
- Read-only CardDAV app passwords: distinct hashed secrets for individual clients, one-time display, and revocation
- Migration-safe authentication defaults (old CardDAV password retained until explicitly disabled); separate credential setup instructions in [AUTHENTICATION.md](AUTHENTICATION.md)
- CLI-only deployment checks for private-file exposure, login, DAV authentication and static stylesheet access
- Fake demo snapshot generator and CI-only offline smoke tests

**Verified in a deployed environment (user-reported):** authenticated public customer API retrieval, dashboard preview/search, and six non-destructive CardDAV protocol checks: authentication challenge, principal discovery, home discovery, address-book discovery, address-book REPORT, individual vCard GET and ETag. The protocol check returned contacts successfully. No customer data or private installation details were committed.

**CI verified:** PHP syntax; session sign-in and logout; login throttling; per-client app-secret hashing and revocation; fake-data HTTP CardDAV legacy compatibility; and existing vCard/DAV tests.

**Not yet verified:** the refreshed admin on the live host after this update, broad CardDAV client interoperability, independent security review, Apache access-denial rules on every host, and lead/address support in the public API.

## Next

1. Use the [production checklist](PRODUCTION_CHECKLIST.md) to deploy safely and confirm the new protected admin, private-file denial, manual refresh and CardDAV connection.
2. Run non-destructive DAV checks for multiget, conditional GET/ETag and read-only method rejection; do not require a phone.
3. Review portability and security with external testers before marking a stable community release.
4. Wait for documented public read-only service address and lead APIs before mapping those fields.
5. After verified operation, offer optional server-side scheduled refresh with conservative defaults.

## Security invariants

- Pool Founder access is read-only; the app does not modify other applications or installations.
- Never expose API keys, CardDAV password, saved contacts, internal notes, gate codes or pricing through the public repo or unauthenticated HTTP.
- Both dashboard and CardDAV demand HTTPS. Admin login may use a secure cookie session; CardDAV uses read-only app passwords via HTTPS Basic Auth.
- Existing saved snapshot survives failed, incomplete or unexpectedly empty API reads.
- Each vCard gets a stable internal filename derived from its source customer ID.
