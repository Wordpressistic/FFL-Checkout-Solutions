# User guide

**FFL Checkout Solutions 2.0.0**

---

## Getting started

Activating the plugin sends you to a four-step setup wizard. It takes about two
minutes and covers everything needed for a working FFL checkout.

**1. Store and FFL details.** Your business name, your own FFL licence number if
you have one, and the address that receives operational alerts. These appear in
customer email, on the dealer confirmation portal and on the tracking page.
You will also acknowledge the legal disclaimer here; that acknowledgement is
recorded with your user ID and a timestamp.

**2. Flag your products.** The dealer selector only appears when the cart holds
a product marked as requiring an FFL transfer. Until at least one product is
flagged, nothing changes at checkout.

**3. Licence.** Enter a WPistic licence key, or continue on the free tier. The
free tier is fully functional for FFL checkout, dealer management, the
confirmation portal and state notices — it is not a trial.

**4. Features.** Turn on the modules you want. Everything is changeable later
under **FFL Checkout → Add-ons**.

### Importing the dealer database

Go to **FFL Checkout → Dealers** and press **Sync ATF dealer list**. This
downloads ATF's monthly licensee file — roughly 80,000 dealers — and imports it
in small batches over several minutes. You can leave the page; it runs in the
background and shows progress when you come back.

Until this finishes, dealer search at checkout will find nothing.

---

## Flagging a product

Edit any firearm product and open the **General** tab of the Product data panel:

- **FFL transfer required** — makes the dealer selector appear when this product
  is in a cart.
- **Item type** — handgun, rifle, shotgun or other. Worth setting properly: it
  drives your state notices, the bound book, the 4473 worksheet and federal
  multiple-sale detection.
- **Manufacturer, model, caliber** — pre-fills paperwork later, saving re-keying.
- **Federal excise tax** — only if you are the manufacturer or importer of
  record. Under 26 U.S.C. § 4181 this is owed by manufacturers and importers,
  not resellers. Not tax advice; check with your accountant.
- **Age-restricted item** — for things that ship directly to the buyer but are
  still age-restricted, like ammunition. Independent of the FFL flag.

Variations inherit the parent's FFL flag unless they set their own, so you do
not have to tick every variation of a firearm.

---

## How a transfer flows

1. **Customer picks a dealer at checkout.** They search by ZIP, name, licence
   number or phone. Dealers you have recommended appear first.
2. **Order is paid.** One transfer record is created per physical firearm — a
   quantity of three creates three records, because each needs its own bound-book
   entry and its own 4473.
3. **Dealer is emailed a confirmation link** (if you have their email address on
   file, and automatic notification is on).
4. **You ship, and record the tracking number.** With carrier tracking on, the
   transfer advances to "shipped" automatically.
5. **Dealer confirms receipt** through the link. No account, no password.
6. **You record the background check outcome** and, when the firearm changes
   hands, mark the transfer complete.

The customer can follow all of this on a signed tracking page linked from their
order and their confirmation emails.

---

## The dealer confirmation portal

Dealers get a one-click link. They are not WordPress users and never see
wp-admin.

**Second factor** (Settings → Portal):

- **None** — the link alone. Fine when your dealer relationships are settled.
- **Last 4 of the FFL licence number** — guards against a misdirected email, not
  against a determined attacker. Use it as a sanity check, not as security.
- **Emailed one-time code** — the stronger option. The code goes to the address
  on the dealer's record, never to one supplied in the request.

**Link lifetime** is configurable from 7 to 180 days. Links are single-use by
default, and issuing a new one immediately revokes the previous one — so a
forwarded old email cannot be used after a re-send.

If a dealer loses their link, open the transfer and press **Issue a new link and
email it**.

---

## Dealers with no email address

The ATF file contains no email addresses, so most dealers arrive without one.
The plugin records this plainly on the transfer rather than failing silently.

Add an address on the dealer's record and the confirmation link will send. Until
then, you can still copy the link from the transfer screen and pass it on by
phone.

---

## Compliance features

Everything in this section records what a person did. Nothing here makes a
compliance decision, files anything with a government body, or completes a
transfer on its own. See `docs/LEGAL-DISCLAIMER.md`.

**State notices** ship as conservative defaults for all 50 states and DC, and
are fully editable. A notice can be informational (the default) or blocking,
which stops an order being placed online — a store policy control, not a legal
determination.

**Background checks** are recorded manually or received by webhook. A delayed
result starts the federal three-business-day clock, computed with weekends *and*
federal holidays excluded. When the window elapses you get an email; the
decision to proceed is yours.

**The bound book** fills itself in as transfers progress — acquisition on
receipt, disposition on completion. Record the serial number when the firearm
arrives; the dashboard flags entries missing one. Exports as ATF-format CSV.

**Multiple sales** are detected when two or more handguns transfer to one buyer
within five business days, and you are emailed the same day, because the Form
3310.4 deadline is close of business on the day of the second transfer. The
plugin does not file it.

**The verification hub** tracks certified licence copies and their expiry, with
reminders at 60, 30, 7 and 0 days. It also holds your manual eZ Check log — ATF
publishes no verification API, so that log is what an audit actually asks for.

---

## Filters and actions

### Actions

```php
do_action( 'fflcs_transfer_created', $transfer_id, $context );
do_action( 'fflcs_transfer_status_changed', $transfer_id, $new_status, $old_status );
do_action( 'fflcs_dealer_confirmed', $transfer_id, $dealer_id );
do_action( 'fflcs_nics_status_updated', $transfer_id, $nics_status );
do_action( 'fflcs_carrier_status_received', $transfer_id, $carrier_data );
do_action( 'fflcs_webhook_sent', $connection_id, $payload, $response );
do_action( 'fflcs_module_toggled', $module_id, $active );
do_action( 'fflcs_after_init', $plugin );
```

### Filters

```php
apply_filters( 'fflcs_entitlement', $entitled, $addon_id, $required, $current );
apply_filters( 'fflcs_license_valid', $bool );
apply_filters( 'fflcs_trusted_proxies', array() );
apply_filters( 'fflcs_state_rules_seed', array() );
apply_filters( 'fflcs_state_rules_seed_topup', array() );
apply_filters( 'fflcs_federal_holidays', $dates, $year );
apply_filters( 'fflcs_carrier_providers', $carriers );
apply_filters( 'fflcs_distributor_providers', $distributors );
apply_filters( 'fflcs_regulatory_watch_terms', $terms );
apply_filters( 'fflcs_fraud_score_weights', $weights );
apply_filters( 'fflcs_disposable_email_domains', $domains );
apply_filters( 'fflcs_sms_message', $message, $status, $transfer );
apply_filters( 'fflcs_sms_request', $request, $to, $message, $provider );
apply_filters( 'fflcs_theming_settings', $settings );
apply_filters( 'fflcs_checkout_localize', $data );
apply_filters( 'fflcs_can_notify_dealer_on_order', $bool, $order, $transfer_id );
apply_filters( 'fflcs_dealer_portal_email', $email, $dealer_id );
apply_filters( 'fflcs_search_rate_limit', $config, $bucket );
apply_filters( 'fflcs_status_email_copy', $copy, $status );
apply_filters( 'fflcs_nics_webhook_payload', $payload, $request );
apply_filters( 'fflcs_carrier_webhook_payload', $payload, $request );
apply_filters( 'fflcs_module_manifests', $manifests );
apply_filters( 'fflcs_cors_allowed_origins', $origins );
apply_filters( 'fflcs_atf_download_url', $url );
```

### wp-config constants

```php
define( 'FFLCS_TOKEN_SECRET', '…' );   // Keeps the HMAC secret out of the database
define( 'FFLCS_LICENSE_KEY', '…' );    // Useful on staging, so a clone does not
define( 'FFLCS_LICENSE_PRODUCT_ID', 0 );  // consume the production activation slot
define( 'FFLCS_LICENSE_SERVER', 'https://api.wpistic.com' );
```

---

## Troubleshooting

**The dealer selector does not appear.** Check that a product in the cart is
flagged FFL-required, and that the widget is enabled under Settings → Checkout.

**Dealer search finds nothing.** Run the ATF sync. Until it completes there are
no dealers to find.

**A dealer's confirmation email never arrives.** Check the mail log under
**Diagnostics**. The most common cause is no email address on the dealer record —
the ATF file does not include them.

**Pro features stopped working.** Check **FFL Checkout → Licence**. Entitlement
is re-checked on every request, so an expired licence stops pro modules even
though their toggles still read "on".

**The portal link 404s.** Changing the portal slug needs a permalink flush —
visit **Settings → Permalinks** once.

**Diagnostics** shows PHP and WooCommerce versions, HPOS state, table health,
module status, scheduled jobs and the mail log. Worth opening before contacting
support.
