# Implementation plan

## Current implementation
- Paginated read-only customer retrieval from the documented `GET /api/v1/customers` endpoint.
- Initial customer-to-vCard mapping.
- CLI-only preview and an offline vCard fixture test.

Run the fixture test with `php tests/vcard_test.php`. Run the live preview only on a trusted machine with `POOL_FOUNDER_API_KEY` set: `php scripts/preview.php`. These scripts have not yet been verified against a live API in this repository.

## Next: lead API
Confirm the authenticated read-only lead listing endpoint, response schema, and pagination before integrating leads. Never infer the lead endpoint from the lead-creation endpoint.

## Next: safe snapshot
Fetch all pages for customers and leads before replacing the locally stored contact snapshot. Reject malformed pages, repeated cursors, and partial failures. Only remove stale records after a complete successful snapshot. Keep the previous snapshot available if a refresh fails.

## Next: CardDAV service
Implement an authenticated, HTTPS-only, read-only CardDAV endpoint with address-book discovery, PROPFIND, REPORT (addressbook-query and addressbook-multiget), GET and appropriate error responses for write methods. Support stable resource URLs, ETags, and sync behavior compatible with major clients. Test against iOS and macOS Contacts before release.

## Data and privacy
Never include internal notes, gate codes, pricing, or other private fields in vCards. Only expose the minimal contact fields needed. Do not print API credentials or personal information in logs.

## Identity
Use source IDs as stable internal keys. Define an explicit lead-to-customer identity reconciliation strategy; matching by name alone is unsafe. Multiple service addresses should be preserved when supplied by the API.
