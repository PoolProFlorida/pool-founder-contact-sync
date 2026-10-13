# Implementation status

## Working features

- Standalone PHP installation under an Apache/cPanel site subdirectory with protected private files
- Private dashboard: contact count, manual refresh, search/filter, vCard preview and download
- Optional separate dashboard sign-in with secure sessions, CSRF protection, login throttling, and logout
- Per-client revocable CardDAV app passwords; opt-in migration from legacy CardDAV credentials
- Customer fetch from documented read-only `GET /api/v1/customers` with pagination
- Snapshot writes that preserve previous data when fetching fails, is incomplete, or causes a suspicious mass deletion
- Read-only CardDAV: discovery, PROPFIND, REPORT, GET/HEAD and write-method denial
- Customer website links in vCards (web dashboard; native app launch not confirmed)
- Optional server cron refresh via `scripts/sync.php`; authenticated dashboard shows a copyable 30-minute schedule and CLI command
- Fake-data automated PHP, auth, pagination, vCard, CardDAV, cron, and HTTP integration tests

## What has been tested

**On one real installation, user-reported:** successful public customer API retrieval, authenticated dashboard, manual refresh, iPhone CardDAV contact synchronization, customer profile website links opening in a browser, and non-destructive DAV checks. Earlier deployment hardening checks reported 11 passes for secret-file denial, dashboard/DAV authentication and CSS access. Those checks preceded the newer admin clipboard JavaScript asset; the updated deployment check covers that asset as well.

**Automated CI:** PHP syntax, contact/field privacy, authentication and app-password management, customer pagination/search, vCard content, DAV behavior, cron-command generation and fake-data HTTP smoke tests. Each merged feature PR passed its tests.

## Remaining limitations

- The documented public API supports customer listing; no verified public read/list-leads API for this project
- Service addresses only appear when public API responses provide them; sampled records did not include those fields
- iPhone customer website links open the web dashboard; a native-app Universal Link/deep-link handler has not been confirmed
- iPhone sync has been exercised on one installation, but interoperability across clients and hosts remains unverified
- Cron command generation exists; **cron scheduling itself must be configured by each administrator in cPanel or another server scheduler**
- No independent security audit has been performed

## Next steps

1. Collect more real-world CardDAV client compatibility results without publishing customer records.
2. Verify scheduled cron operation on each deployment using the dashboard's last successful refresh time.
3. Seek an independent security review before describing this as a broadly production-ready release.
4. Support service addresses and leads only if documented read-only public API endpoints/fields are available.

## Security invariants

- Never commit API keys, app passwords, contact snapshots, service notes, gate codes, pricing, or installation secrets.
- Customer API access is read-only; CardDAV denies writes; no external production systems are changed.
- Failed/incomplete/suspicious fetches keep the last known-good snapshot.
- Keep authentication, snapshots, and logs restricted on each host. Validate deployment access controls with `scripts/check-deployment.php`.

See [README](../README.md), [production checklist](PRODUCTION_CHECKLIST.md), [authentication migration](AUTHENTICATION.md), and [public API field requests](PUBLIC_API_FIELDS.md).
