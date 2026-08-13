# Changelog

All notable changes to FFL Checkout Solutions are documented here.

This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Every security-relevant change gets its own `### Security` entry, however minor —
customers scan for that heading specifically.

## [2.0.0] - 2026-08-13

First public release.

### Added

**Checkout and transfers**
- FFL dealer selector at checkout, searching by ZIP code, business name, licence
  number or phone. Vanilla JavaScript, no page reload, no mapping service.
- Chunked, fully resumable import of ATF's monthly licensee file (~80,000
  dealers), resuming by byte offset so a timed-out batch costs one chunk.
- On-demand ZIP centroid lookup with permanent local caching — one external call
  per distinct ZIP, ever.
- One transfer record per physical firearm, so a quantity-3 order produces three
  records with their own bound-book entries and paperwork.
- Eleven-stage transfer lifecycle with an append-only audit log recording the
  actor, time and IP of every change.
- WooCommerce order status bridge, HPOS-safe throughout.
- Product fields for FFL requirement, item type, manufacturer, model, caliber,
  excise tax and age restriction.

**Dealer portal and customer tracking**
- HMAC-signed single-use dealer confirmation links, with configurable expiry from
  7 to 180 days and an optional second factor (last four of the FFL number, or an
  emailed one-time code).
- Standalone dealer portal, sized for a phone at a shipping counter.
- Signed public customer tracking page with a five-step timeline, dealer contact
  details, directions and a pickup calendar invite.
- Saved dealers for returning customers, and a My FFL Transfers account tab.

**Licensing and modules**
- WPistic licence client with a trust window, exponential backoff and manual-
  attempt rate limiting, so an unreachable licence server never breaks a store.
- Tier-based entitlement (free, starter, pro, business, enterprise) enforced
  before a module class is constructed — a locked module registers no hooks, no
  routes and no cron events.
- Self-hosted update mechanism against api.wpistic.com.
- Add-ons screen where each module states its own third-party data flow at the
  point of activation.
- Four-step first-run setup wizard with a recorded disclaimer acknowledgement.

**Compliance**
- Editable state compliance notices for all 50 states and DC.
- Bound book / A&D ledger, auto-populated on receipt and completion, with
  ATF-format CSV export and 20-year retention.
- Form 4473 worksheet in HTML and PDF, banner-marked as a draft, with
  append-only signature capture.
- Multiple-sale detection with same-day alerting, matching the Form 3310.4
  filing deadline.
- FFL verification hub with certified-copy expiry tracking, reminders at 60, 30,
  7 and 0 days, and a manual eZ Check log.
- NICS outcome tracking with a three-business-day computation that excludes
  weekends and the eleven federal holidays, observed-day shifting included.
- Identity and age verification with staff review and no automatic approval path.
- Transparent, rules-based risk scoring that never blocks, holds or cancels.
- Nightly Federal Register sweep for ATF documents.

**Integrations**
- EasyPost carrier tracking and label purchase, on an explicit click.
- Drop-ship clients for Lipsey's, Sports South, RSR Group, Bill Hicks & Co. and
  Chattanooga Shooting Supplies.
- GunBroker listing and order sync.
- NMI gateway using Collect.js tokenisation, and Credova financing that holds the
  order until the lender approves.
- Outbound webhooks, HMAC-signed with exponential retry and a delivery log.
- SMS notifications through the store's own provider.

**Operations**
- Dashboard leading with what needs attention today.
- Analytics with locally rendered charts — no CDN, no third-party service.
- Operations toolset: bulk dealer fees by state, dealer health alerts.
- Admin TOTP two-factor with single-use backup codes.
- Diagnostics: environment health, module status, scheduled jobs, mail log.
- GDPR export and erasure that anonymises the customer, retains the records ATF
  requires, and tells the requester exactly what was kept.

### Security

- AES-256-GCM encryption at rest for licence keys, distributor credentials,
  webhook secrets, payment keys and 2FA secrets, with per-context key derivation
  so one leaked ciphertext cannot be replayed in another context.
- Dealer portal tokens stored only as SHA-256 hashes; the raw token never
  persists. All comparisons use `hash_equals()`.
- SSRF guard on every admin-supplied outbound URL, re-checked on each delivery
  attempt rather than only at save time.
- Per-IP rate limiting on every public endpoint, returning 429 with `Retry-After`.
- Proxy headers honoured only from explicitly trusted proxies, defaulting to
  none, so rate limits and audit entries cannot be forged.
- CSV formula-injection neutralisation on every exported cell.
- Uploads validated against file contents rather than filename, stored outside
  the media library with random filenames behind a deny rule.
- CORS scoped to the plugin's REST namespace with an empty allow-list by default.
- Database-level uniqueness preventing duplicate transfer creation under the
  concurrent hook firing common to many payment gateways.
- WooCommerce dependency guard that self-deactivates with a clear notice rather
  than fataling on undefined classes.

[2.0.0]: https://github.com/Shubochandrosarker/FFL-Checkout-Solutions/releases/tag/v2.0.0
