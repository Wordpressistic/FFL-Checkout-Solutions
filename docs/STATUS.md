# Implementation status and open verification items

**FFL Checkout Solutions 2.0.0**

Every place in this codebase where a third-party contract could not be verified
during development carries an `HONESTY NOTE` in the source at the point of
uncertainty. This document collects them so nobody has to grep for them, and so
a reviewer can see the whole picture in one place.

The rule that produced this list: where a protocol detail was unconfirmed, the
code follows the vendor's published documentation, handles the response
defensively, records the full raw response, and says here that it is unverified.
It does not guess silently.

---

## Needs verification before first live use

### Distributor wire protocols

**Lipsey's** — `includes/distributors/class-distributor-lipseys.php`
Authentication and the catalog feed follow the published dealer API guide. The
exact order-submission *response* shape could not be verified without a live
dealer account, so the client parses the documented fields and stores the
complete response either way. Place one test order and confirm the returned
order number is captured before relying on it.

**Sports South** — `includes/distributors/class-distributor-sports-south.php`
The `DailyItemUpdate` catalog call and the `AddHeader → AddDetail → Submit`
order sequence follow their integration documentation. Element *ordering* within
the order calls is inferred from that documentation. Verify against a test
account.

**RSR Group** — `includes/distributors/class-distributor-rsr.php`
The most important item on this list. RSR's inventory and order files are
fixed-position, and the layout is versioned by RSR and not discoverable at
runtime. **A shifted column would silently order the wrong item.** Confirm the
field positions in `sync_catalog()` and `submit_order()` against your current
dealer packet before enabling live ordering.

**Bill Hicks & Co.** — `includes/distributors/class-distributor-bill-hicks.php`
Directory and file naming vary per dealer account, so they are settings
(`fflcs_bill_hicks_catalog_path`, `fflcs_bill_hicks_order_path`) rather than
constants. Set them from your dealer packet.

**Chattanooga** — `includes/distributors/class-distributor-chattanooga.php`
Follows the documented API including its FFL-on-file precondition, which the
client checks before submitting rather than letting the order be rejected. Note
that the plugin cannot put a licence on file on your behalf.

### Inbound webhooks

**NICS results** — `includes/rest/class-webhook-controller.php`
There is no national NICS webhook standard; the FBI exposes no push API to
retailers. The endpoint implements a generic HMAC-signed contract that a
background-check *service provider* can be configured to call. Field names vary
by provider and are mapped through the `fflcs_nics_webhook_payload` filter
rather than assumed. **Manual entry in the admin is the supported path** and
needs no configuration.

**Carrier status** — `includes/carriers/class-carrier-registry.php`
Each aggregator (EasyPost, Shippo, AfterShip, ShipStation) uses a different
payload shape. The handler covers the EasyPost tracker shape and a generic flat
shape; anything else maps through `fflcs_carrier_webhook_payload`.

### Marketplace

**GunBroker** — `includes/modules/gunbroker-sync/class-gunbroker-sync.php`
Authentication and the `OrdersSold` shape follow their published API. Two things
could not be verified without an approved DevKey: the exact field names carrying
an order's FFL details, and production rate limits. The client checks the
documented spellings and routes unmatched orders to a "needs dealer" queue
rather than guessing.

### Data sources

**ATF licensee file** — `includes/services/class-atf-sync.php`
The `mYYYYffllistcsv.zip` filename pattern is the long-standing convention
observed in ATF's published data directory, not a documented versioned API. If
ATF changes it, discovery returns null, the admin screen reports it, and the
`fflcs_atf_url_override` setting is the intended fallback.

---

## Server-side contract owed by the WPistic team

The self-hosted updater (Section 7) expects this endpoint to exist. Until it
does, update checks fail silently and sites keep running their installed
version — which is the designed failure mode, but it does mean no updates are
delivered.

```
GET https://api.wpistic.com/api/v1/plugins/ffl-checkout-solutions/latest
    ?license_key={key}&site={url}&version={current}&instance={uuid}

200 → {
  "version":      "2.1.0",
  "download_url": "https://... (short-lived, signed)",
  "changelog":    "<p>…</p>",
  "requires":     "6.4",
  "requires_php": "8.1",
  "tested":       "6.7",
  "last_updated": "2026-01-15"
}
```

The licence endpoints (`/api/v1/licenses/activate`, `/validate`, `/deactivate`)
are consumed by `includes/license/class-license-client.php`; see that file for
the expected request and response bodies.

`download_url` must be short-lived and signed. The plugin also attaches the
licence key as an `X-Fflcs-License` header on the package request, so the
download can be authorised twice over.

---

## Deliberate non-features

These are absent by design, not unfinished. Listed here so nobody adds them
later thinking they were forgotten.

- **No automated ATF eZ Check.** No API exists. Manual log only.
- **No automatic NICS decision.** The plugin records outcomes; it never
  determines one.
- **No automatic Form 3310.4 filing.** Detection and alerting only.
- **No automatic wholesale ordering.** Every distributor order is an explicit,
  attributable click.
- **No auto-approval anywhere in the identity verification flow.** A person
  decides.
- **No third-party analytics, and no CDN-hosted chart library.** Everything is
  computed locally and rendered by a bundled script.

---

## Testing notes

The dependency guard, HPOS declaration, entitlement gating and rate limiting are
all exercised by ordinary use, but the following are worth explicitly testing on
a staging site before a production rollout:

1. Activate with WooCommerce deactivated — expect a clean admin notice and
   self-deactivation, no fatal error.
2. Enable HPOS and place an FFL order — confirm the transfer is created and the
   dealer appears on the order screen.
3. Enter an invalid licence key — confirm every pro module stops running, not
   just that its UI hides.
4. Block outbound HTTP to `api.wpistic.com` — confirm the site keeps working and
   free-tier features are unaffected.
5. Run the GDPR eraser against a customer with a completed transfer — confirm
   the bound-book entry survives and the response explains why.
