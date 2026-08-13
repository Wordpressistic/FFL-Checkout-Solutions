# Architecture

**FFL Checkout Solutions 2.0.0**

---

## Boot sequence

`ffl-checkout-solutions.php` defines constants, registers a PSR-4-ish
autoloader, and hooks `FFLCS\Plugin::boot()` on `plugins_loaded` at priority 20 —
late enough that WooCommerce has loaded.

`Plugin::boot()` runs in a fixed order, and the order matters:

1. **`check_dependencies()`** — Section 9.0. WooCommerce must be active and at
   least `FFLCS_MIN_WC_VERSION`. On failure the plugin deactivates itself, shows
   an actionable notice, and returns. Nothing below this line runs.
2. **i18n** — text domain loaded.
3. **Schema check** — `Installer::SCHEMA_VERSION` versus the stored
   `fflcs_db_version`. This comparison is the *only* thing that migrates an
   already-activated site, because a plugin ZIP upgrade never re-fires
   `register_activation_hook`.
4. **Cron intervals** registered.
5. **Core services** — licence, updater, theming, REST, checkout, scheduler,
   mailer, status bridge, add-on manager.
6. **Modules** — via `Module_Loader`, each entitlement-gated.
7. **Admin and frontend** controllers, by context.
8. **Cron handlers** wired.

The HPOS compatibility declaration lives at file scope, not in `boot()`, because
`before_woocommerce_init` fires earlier than `plugins_loaded` — and it must run
even when the dependency guard decides not to boot.

---

## Layers

```
includes/
├── class-plugin.php          Container, dependency guard, boot order
├── class-installer.php       Schema, seed data, migrations
├── class-checkout.php        WooCommerce integration
├── class-rest-api.php        Route registration, CORS, shared permissions
├── class-module-loader.php   Discovery + entitlement gate
├── class-addon-manager.php   Module registry and activation state
├── class-crypto.php          AES-256-GCM secrets at rest
├── services/                 Domain logic. No presentation, no routing.
├── rest/                     Controllers. Validate, delegate, shape a response.
├── admin/                    Screens. Presentation and form handling.
├── frontend/                 Public controllers: widget, portal, tracking.
├── carriers/ distributors/ payments/   Third-party clients
├── license/                  Licence client, entitlement, updater
├── modules/                  Feature modules, one directory each
└── lib/                      Vendored: the PDF writer
```

The rule that keeps this honest: **services own state transitions, everything
else calls them.** There is exactly one code path that changes a transfer's
status (`Transfer_Service::set_status()`) and exactly one that writes an audit
event (`Transfer_Service::log_event()`). A controller that wrote to the
transfers table directly would bypass the audit log, which is why none do.

---

## Entitlement gating

`Module_Loader` checks activation *and* entitlement before constructing a module
class. A module that fails either check is never instantiated — so it registers
no hooks, no REST routes, no AJAX actions, no settings tabs and no cron events.

This is stricter than hiding UI. Gating at render time would leave a locked
module's endpoints live and reachable by anyone who knew the URL.

A licence that lapses therefore stops pro modules on the next request, even
though their toggle still says "on" — `Addon_Manager::is_active()` requires both.

---

## Concurrency: one transfer per firearm

Transfer creation is hooked on both `woocommerce_payment_complete` and
`woocommerce_order_status_processing`. Many gateways fire both within
milliseconds for the same order.

A PHP-side "does this already exist?" check is check-then-act and races. The fix
is at the database: `transfers` has a unique index on
`(order_id, order_item_id, order_item_unit)`. Both calls attempt the insert; the
database rejects the loser. `Transfer_Service::create()` reports which call won
via `is_new`, and `Checkout::create_unit_transfer()` fires side effects — event
log, customer email, dealer notification — only for the winner.

One row per *physical unit*, not per line item: a quantity-3 order yields three
transfers, because each firearm needs its own bound-book entry and its own 4473.

---

## Security model

| Concern | Approach |
|---|---|
| Dealer portal auth | HMAC-SHA256 token, SHA-256 hash stored, raw token only in the dealer's email. `hash_equals()` throughout. |
| Token replay | Single-use enforced in the database; issuing a new token revokes the previous one. |
| Rate limiting | Fixed-window per IP via transients. 429 with `Retry-After`. |
| IP spoofing | Proxy headers honoured only when `REMOTE_ADDR` is in `fflcs_trusted_proxies`, which defaults to empty. |
| SSRF | `fflcs_is_url_safe()` on every admin-supplied outbound URL — scheme, credentials and resolved-address checks, re-run on every delivery attempt. |
| SQL injection | `$wpdb->prepare()` everywhere. ORDER BY columns come from an allow-list, never from input. |
| CSV injection | `fflcs_csv_cell()` on every exported cell. |
| Secrets | AES-256-GCM, per-context keys derived from `NONCE_SALT`. |
| Uploads | MIME checked against file *contents*, private directory, random filenames, `.htaccess` deny plus index stub. |
| CORS | Scoped to the plugin namespace, empty allow-list by default. |

---

## Data model

Nine core tables plus twelve module tables, all prefixed `{$wpdb->prefix}fflcs_`.
Two design decisions are worth calling out:

**`events` is append-only by code, not just by convention.** Nothing in this
plugin issues an UPDATE or DELETE against it. That is what makes it usable as an
audit trail rather than a log.

**`ad_ledger` outlives everything.** Bound-book entries are excluded from the
GDPR eraser and from the retention sweep, because ATF requires 20 years and a
privacy request does not override that.

---

## Extension points

Roughly thirty filters and eight actions, listed in `docs/USER-GUIDE.md`. The
ones that matter most for integration work:

- `fflcs_entitlement` — the documented seam for bundling and support overrides.
  A filter rather than a constant, so an override is visible in code review.
- `fflcs_nics_webhook_payload` / `fflcs_carrier_webhook_payload` — provider
  field mapping without patching core.
- `fflcs_distributor_providers` — register an additional distributor.
- `fflcs_federal_holidays` — add your own closures so the three-business-day
  window matches when you are really shut.
- `fflcs_state_rules_seed_topup` — add rules without reproducing the built-in set.

---

## What has no build step

The plugin runs from source. No webpack, no Composer autoloader, no `vendor/`.
JavaScript is vanilla and CSS is hand-written, so the shipped ZIP is what runs.

There *is* a packaging step — `.distignore` decides what reaches a customer —
but that is about what to exclude, not about compiling anything.
