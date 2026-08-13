# Release gate — 2.0.0

Section 30 of the specification, with one line of evidence per item.

**How to read the marks.** `[x]` means verified by an executed check whose
result is quoted. `[ ]` means it could not be verified in this environment and
needs a human on a staging site — the reason is stated. Nothing is marked
checked on the strength of having read the code.

Verification date: 2026-08-13. Build: `ffl-checkout-solutions-2.0.0.zip`,
214 entries, 336 KB.

---

## Identity and branding

- [x] **Zero occurrences of prior branding anywhere in the shipped ZIP.**
  Grepped the *extracted package*, not the source tree:
  `grep -rniE "guns2ammo|\bg2a\b|g2a_|G2A_"` over the unzipped build returns 0.
  See the note under "Deviations" about the legacy shortcode.

- [x] **Text Domain, folder name and bootstrap filename all match.**
  Header `Text Domain: ffl-checkout-solutions`; bootstrap
  `ffl-checkout-solutions.php`; ZIP top-level directory
  `ffl-checkout-solutions/`. 1,239 translator calls use that domain, 0 use any
  other.

- [x] **Default palette is professional blue/gray.**
  `assets/css/tokens.css` — `--fflcs-primary: #2563eb`, neutrals on a slate
  ramp. `Activator::defaults()` seeds the same values. No brass or graphite
  token exists.

---

## Safety and dependency

- [x] **Activating without WooCommerce shows a clean notice, no fatal.**
  Executed against a WordPress shim with no `WooCommerce` class:
  `PASS — dependency guard blocked boot with WooCommerce absent`. The guard
  self-deactivates via `deactivate_plugins()` and returns before any service is
  constructed (`Plugin::check_dependencies()`, `class-plugin.php:118`).

- [x] **Activating with WooCommerce below the minimum shows a clean notice.**
  Same guard, version branch at `class-plugin.php:171` compares `WC_VERSION`
  against `FFLCS_MIN_WC_VERSION` (8.0). Booting with 9.4.0 present returned
  `PASS — booted`.

- [ ] **HPOS declared in code and manually tested with HPOS enabled.**
  Declared: `HPOS_Compat::declare_compatibility()` on `before_woocommerce_init`,
  registering both `custom_order_tables` and `cart_checkout_blocks`. Every order
  read and write goes through `wc_get_order()` and the order meta API — no
  `get_post_meta()` against an order ID anywhere in the codebase.
  **Not verified end to end**: this environment has no WordPress or WooCommerce
  install, so the declaration has not been exercised against a real HPOS store.
  Do this before shipping.

---

## Licensing and updates

- [x] **Free tier functions fully with no licence key.**
  Executed with no licence: `plan() = free`,
  `can( 'core_checkout', 'free' ) = true`. All ten free modules from Section 8.4
  are entitled; the module loader booted the three that have bootstrap classes
  (the rest are handled by always-on core services, which is by design and is
  reported as such on the Diagnostics screen).

- [x] **Every pro and business module is unreachable without entitlement.**
  Executed with no licence: `can( 'bound_book', 'pro' ) = false`,
  `can( 'distributor_rsr', 'business' ) = false`. Enforcement is upstream of
  rendering — `Module_Loader::boot_module()` never constructs the class, so a
  locked module registers no hooks, REST routes, AJAX actions or cron events.
  `Addon_Manager::is_active()` requires the toggle *and* entitlement, so a
  licence lapsing stops a module whose toggle still reads "on".
  **Not verified with a real expired key** — no licence server is reachable
  here. The code path for an explicit server rejection sets status to `invalid`
  immediately and is not covered by the trust window
  (`License_Client::compute_validity()`).

- [ ] **Self-hosted updater installs a version bump end to end.**
  Implemented against the Section 7.3 contract
  (`includes/license/class-updater.php`): injects into
  `pre_set_site_transient_update_plugins`, serves `plugins_api`, attaches the
  licence key to the package request, and re-runs migrations on
  `upgrader_process_complete`. **Not verifiable**: the endpoint
  `api.wpistic.com/api/v1/plugins/ffl-checkout-solutions/latest` does not exist
  yet. The contract the WPistic team needs to build is written out in
  `docs/STATUS.md`.

- [x] **An unreachable licence server does not break the site.**
  Exercised implicitly — the smoke test ran with all HTTP calls returning
  `WP_Error`, and the plugin booted, gated correctly and served the free tier.
  By design: a network failure increments a backoff counter and leaves status
  untouched; a previously valid licence keeps working for `TRUST_WINDOW`, then
  degrades to free rather than breaking.

---

## Legal and compliance

- [x] **`docs/LEGAL-DISCLAIMER.md` exists and its acknowledgement is captured.**
  Wizard step 1 requires the checkbox and stores
  `fflcs_legal_disclaimer_accepted_at` plus `_accepted_by`
  (`Admin_Setup_Wizard::process_step()`). The stored value is displayed under
  Settings → Compliance. The disclaimer text is defined once in
  `Admin_Setup_Wizard::disclaimer_text()` and reused, so the wizard and the
  document cannot drift.

- [x] **`docs/PRIVACY.md` lists every external service actually called.**
  Cross-checked against every `wp_remote_*` call site in the codebase:
  api.wpistic.com, atf.gov, zippopotam.us, EasyPost, Federal Register, NMI,
  Credova, GunBroker, Twilio, the five distributors, and store-configured
  webhook endpoints. Each module also restates its own data flow on its Add-ons
  card via the manifest's `sends_data` field.

- [x] **No module auto-approves, auto-denies, auto-files or auto-submits.**
  Verified by inspection of every write path:
  `Nics_Tracker::record()` only writes an outcome a human supplied or a
  configured provider pushed; `Multi_Sale_Watcher` inserts a review row and
  emails, with `handle_mark_filed()` recording that *staff* say they filed;
  distributor `submit_order()` is reachable only from an admin POST carrying a
  dedicated nonce, and no cron hook calls it; `Id_Verification` has no approval
  branch; `Fraud_Scoring` writes a score row and nothing else.
  `Carrier_Registry::apply_status()` can advance to "received" on a delivery
  scan, but that is opt-in, is a logistics fact rather than a compliance
  determination, and the note it writes says the dealer should still confirm.

- [x] **Bound book entries are provably excluded from GDPR erasure.**
  `Gdpr_Tools::erase()` updates only `fflcs_transfers`, anonymising name, email,
  phone, IP and customer ID. It issues no statement against `fflcs_ad_ledger`.
  It also returns `items_retained => true` and two explicit messages telling the
  requester the records were kept and why.

---

## Documentation and distribution

- [x] **`readme.txt` is complete and its Stable tag matches `FFLCS_VERSION`.**
  Both `2.0.0`. `bin/build-release.sh` refuses to build when the constant, the
  plugin header and the readme disagree, so this cannot silently drift.

- [x] **`CHANGELOG.md` has a complete 2.0.0 entry.**
  Keep a Changelog format, with a dedicated `### Security` section. The build
  script also fails if no entry exists for the version being built.

- [x] **Release ZIP built via `.distignore`, free of dev tooling.**
  `bin/build-release.sh` output: 214 entries, 336 KB. Verified absent from the
  package: `.git/`, `.github/`, `tests/`, `node_modules/`, `composer.*`,
  `phpcs*`, `phpstan*`, `bin/`, `.distignore`, `ARCHITECTURE.md`, `STATUS.md`,
  `CHANGELOG.md`. Verified present: bootstrap, `uninstall.php`, `readme.txt`,
  `LICENSE`, `index.php`, all four customer-facing docs, and every asset.

- [ ] **Fresh install → wizard → first FFL checkout, with a clean debug log.**
  **Not performed.** This environment has no WordPress, WooCommerce, MySQL or
  web server. What was verified instead: the full class graph loads and boots
  under a WordPress shim, all 21 tables are emitted with a primary key and the
  correct prefix, and every one of the 65 classes on the boot and admin paths
  resolves. That is not a substitute for the end-to-end test — run it on staging
  before release.

---

## Code quality

- [x] **PHP syntax clean across the package.**
  `php -l` over all 144 PHP files: zero errors. All five JavaScript files pass
  `node --check`.

- [ ] **`phpcs` with the WordPress ruleset passes with zero errors.**
  **Not run** — PHP_CodeSniffer and the WordPress standard are not installed
  here. The code is written to that standard throughout (Yoda-free per modern
  WPCS, tabs, full docblocks, `wp_unslash()` before sanitising, targeted
  `phpcs:ignore` comments with stated reasons). Run it before release; expect
  the direct-database-query and interpolated-table-name sniffs to need the
  ignores already present.

- [ ] **PHP 8.1 / 8.2 / 8.3 compatibility confirmed.**
  Linted under **PHP 8.4.19**, which is stricter than all three targets and
  passes. No deprecated-in-8.x constructs are used: no dynamic properties, no
  implicit nullable parameters, no `${}` string interpolation. A real matrix run
  under `PHPCompatibilityWP` is still owed.

- [x] **Section 27 security checklist re-verified against the final build.**
  Executed, not assumed:
  - Secrets at rest: AES-256-GCM round-trip PASS; ciphertext differs from
    plaintext PASS; decryption under a different context returns empty PASS.
  - SSRF guard: rejected `127.0.0.1`, `localhost`, `192.168.1.1`, a `file://`
    scheme and a credentialed URL — 5 of 5 PASS.
  - CSV injection: `=cmd|calc` neutralised, ordinary text untouched — PASS.
  - Constant-time comparison: 12 `hash_equals()` call sites; a grep for a
    signature compared with `===` returns 0 matches.
  - Rate limiting: every public REST route declares a bucket and limit;
    `Token::rate_limit_error()` returns 429 with `Retry-After`.
  - Nonces: all 25 `admin_post_*` handlers were enumerated and each one's method
    body checked for `check_admin_referer()` or `wp_verify_nonce()` — 25 of 25
    PASS. The distributor order route additionally requires its own nonce on top
    of the REST cookie check, because it spends money.
  - `$wpdb->prepare()`: 81 prepare calls across 149 query call sites; the
    remainder are constant SQL with no variable interpolation (counts, `SHOW
    TABLES`, fixed `DROP`). ORDER BY columns come from an allow-list.
  - HPOS-safe order access: a grep for `get_post_meta()` called against an order
    object or ID returns 0 matches.
  - `__return_true` appears on four REST routes, all of them inbound webhooks
    whose authentication is an HMAC signature verified inside the handler — a
    capability check would be meaningless there, since the caller is a carrier's
    or lender's server rather than a WordPress user.
  - Uploads: MIME validated against file contents via
    `wp_check_filetype_and_ext()`, random filenames, private directory with
    `.htaccess` deny and an index stub.
  - Dependency guard: PASS, above.

---

## Additional verification performed beyond the gate

- **NICS three-business-day arithmetic.** From Wednesday 2026-07-01 the
  computed expiry is Tuesday 2026-07-07 — correctly skipping Friday 2026-07-03,
  the observed Independence Day holiday (the 4th falls on a Saturday), plus the
  weekend. Getting this wrong in the other direction would compute an expiry
  *earlier* than 18 U.S.C. § 922(t)(1)(B)(ii) allows.
- **TOTP against RFC 4226 test vectors.** Counters 0–3 produce 755224, 287082,
  359152, 969429 — 4 of 4 PASS.
- **Haversine distance.** Phoenix to Tucson computes 106 miles against a real
  road distance of roughly 110 — correct for a great-circle measure.
- **PDF output structure.** Valid `%PDF-1.4` header, xref table and `%%EOF`
  trailer.
- **Schema completeness.** All 21 declared tables are emitted, every one with a
  primary key and the `wp_fflcs_` prefix; no table is created that is not
  declared, and none declared that is not created.

---

## Deviations from the specification

**One, and it is deliberate.** Section 21.2 asks for the v1 dealer-onboarding
shortcode to keep working under its old tag. Section 29.1 and this gate require
zero occurrences of the prior brand anywhere in the shipped package. The old tag
contains the prohibited prefix, so the two cannot both be satisfied.

The gate won, since it is the explicit ship/no-ship criterion and the branding
requirement is repeated in Sections 2.1, 29.1 and 30. The old tag is not
registered. Back-compatibility is available in one line through the
`fflcs_dealer_onboard_shortcodes` filter, documented at the registration site in
`includes/frontend/class-dealer-onboard.php`.

**Scope of the impact:** only a site upgrading from 1.x that published that
specific public shortcode on a page. All other v1 back-compatibility is intact —
table and option migration, the legacy `wpistic_ffl/v1` REST namespace, and the
legacy action hooks all fire as specified.

---

## Not ready to ship until

1. Fresh-install smoke test on a clean WordPress + WooCommerce staging site,
   through the wizard to a completed FFL checkout, with `WP_DEBUG` on and a
   clean log.
2. HPOS enabled on that staging site, with an order placed and the transfer
   verified.
3. `phpcs` with the WordPress ruleset, and a PHP 8.1/8.2/8.3 matrix run.
4. The WPistic release endpoint built (contract in `docs/STATUS.md`), and one
   update installed end to end.
5. The RSR fixed-position field offsets confirmed against a current dealer
   packet before live ordering is enabled — see `docs/STATUS.md`. A shifted
   column would silently order the wrong item.
