# Implementation status

## Implemented (early test build)

- Standalone PHP deployment beneath a site subdirectory, protected by scoped Apache rules
- Password-protected dashboard with API refresh and vCard preview/download
- Customer API GET /api/v1/customers with cursor pagination
- Atomic local JSON snapshot storage; incomplete/failing requests preserve the previous snapshot
- Customer vCard generation and multi-service-location mapping when the public API provides address fields
- Read-only CardDAV skeleton: principal and address book PROPFIND discovery, query/multiget REPORT, card GET/HEAD, OPTIONS, write-method denial
- Optional hashed-password dashboard login with secure cookie sessions, logout, CSRF validation and persistent login-attempt throttling
- Read-only CardDAV app passwords: distinct hashed secrets for individual clients, one-time display, and revocation
- Migration-safe authentication defaults (old CardDAV password retained until explicitly disabled); separate credential setup instructions in [AUTHENTICATION.md](AUTHENTICATION.md)
- Fake demo snapshot generator and offline smoke tests

**Verified in a deployed environment (user-reported):** authenticated public customer API retrieval, dashboard preview/search, and six non-destructive CardDAV protocol checks: authentication challenge, principal discovery, home discovery, address-book discovery, address-book REPORT, individual vCard GET and ETag. The protocol check returned contacts successfully. No customer data or private installation details were committed.

**CI verified:** PHP syntax; session sign-in and logout; login throttling; per-client app-secret hashing and revocation; fake-data HTTP CardDAV legacy compatibility; and existing vCard/DAV tests.

**Not yet verified:** opt-in dashboard login against an independently hosted production environment, broad client compatibility, independent security review, Apache access-denial behavior across hosting configurations, and lead/address support in public API.

## Next

1. Run the independent, non-destructive CardDAV probe against additional DAV operations (especially addressbook-multiget, conditional GET/ETag and read-only rejection) using generic test data; do not require a phone.
2. Verify web-server access denial for configuration files, stored snapshots and Git metadata before public release.
3. Improve protocol compatibility based on these checks; document confirmed behavior without claiming full conformance.
4. Wait for documented public read-only service address and lead APIs; support them only after their response schemas and pagination are confirmed.
5. Once stable, add optional scheduled refresh with explicit deployment guidance and prepare a tagged test release.

## Security invariants

- Pool Founder access is read-only; the app does not modify other applications or installations.
- Never expose API keys, CardDAV password, saved contacts, internal notes, gate codes or pricing through the public repo or unauthenticated HTTP.
- Both dashboard and CardDAV demand HTTPS with independent Basic Auth credentials.
- Existing saved snapshot survives failed, incomplete or unexpectedly empty API reads.
- Each vCard gets a stable internal filename derived from its source customer ID.
