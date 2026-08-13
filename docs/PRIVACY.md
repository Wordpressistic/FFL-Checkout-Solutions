# External services and data flows

**FFL Checkout Solutions 2.0.0**

This document lists every third party this plugin can contact, exactly what is
sent, when, and how to stop it. It exists because a compliance or legal team
evaluating this plugin will ask "what data leaves my site and where does it go" —
and a vague answer to that question is both a lost sale and a real liability.

Nothing in this list is contacted unless the module that uses it is switched on.
Modules are off by default unless marked otherwise in **FFL Checkout → Add-ons**,
and each module's card in that screen restates its own data flow at the moment
you decide to enable it.

---

## Summary table

| Service | Data sent | When | Tier | Can it be disabled? |
|---|---|---|---|---|
| **WPistic** (`api.wpistic.com`) | Site URL, licence key, plugin version, install identifier | Daily licence check, twice-daily update check | All | No — it is what delivers your updates and security patches |
| **ATF licensee file** (`atf.gov`) | Nothing. The public file is downloaded | Monthly cron, or when you press Sync | Free | Yes — turn off `atf_sync` |
| **zippopotam.us** | A five-digit ZIP code. No customer data | The first time any given ZIP is searched, then never again for that ZIP | Free | No — it is how distance search works |
| **EasyPost** | Ship-to name, address, package weight | When you quote rates or buy a label | Pro | Yes — turn off `carrier_tracking` |
| **Your background-check provider** | Whatever their own webhook contract specifies | On an inbound webhook, or manual entry | Pro | Yes — turn off `nics_tracking` |
| **Federal Register** (`federalregister.gov`) | Nothing. The public API is read | Nightly | Pro | Yes — turn off `regulatory_watch` |
| **NMI** | Tokenised card data only. Card details go from the browser to NMI and never touch your server | At checkout, via Collect.js | Pro | Yes — deactivate the gateway |
| **Your SMS provider** (Twilio or similar) | Customer phone number, message text | On notable transfer status changes | Pro | Yes — turn off `sms_notifications` |
| **Credova** | Order total, customer name, email, phone | When a customer chooses financing | Business | Yes — deactivate the gateway |
| **Lipsey's / Sports South / RSR / Bill Hicks / Chattanooga** | Order line items and the receiving dealer's FFL details | Only when a staff member clicks Submit Order | Business | Yes — turn off the individual distributor |
| **GunBroker** | Product catalog data outbound, order data inbound | On listing sync and hourly order pull | Business | Yes — turn off `gunbroker_sync` |
| **Your own webhook endpoints** | Transfer state and dealer ID. Never identity documents, staff notes or customer IPs | On configured events | Pro | Yes — turn off `webhooks_out` |

---

## Notes worth reading

**Distributor orders are never automatic.** Catalog syncing reads data on a
schedule; placing a wholesale order — which spends your money and ships a
firearm — happens only when a person clicks. There is no scheduled job anywhere
in this plugin that submits an order.

**Card data never reaches your server.** The NMI integration uses Collect.js, so
the browser posts card details straight to NMI and your server receives only a
one-time token. This is what keeps the store in SAQ A-EP scope rather than
handling primary account numbers.

**The ZIP lookup sends no customer data.** It sends the five digits and nothing
else — not a name, not an order, not an IP. The result is cached locally
forever, so a given ZIP is looked up once in the lifetime of the install.

**Analytics are local.** Transfer volumes, funnel data and charts are computed
from your own database and rendered by a chart script bundled with the plugin.
No analytics service is contacted, and no chart library is loaded from a CDN.

---

## What is stored on your own site

Beyond the third parties above, this plugin stores the following on your own
server:

- **Transfer records** — customer name, email, phone, IP at time of order, the
  firearm's details, and the full status history.
- **Audit events** — an append-only log of every status change, who made it, and
  from what IP. Nothing in this plugin edits or deletes a row in this log.
- **Bound book entries** — acquisition and disposition records, retained for 20
  years as ATF requires.
- **Dealer portal tokens** — stored only as SHA-256 hashes. The raw token exists
  in the dealer's email and nowhere else.
- **Certified licence copies and identity documents** — in
  `wp-content/uploads/fflcs-private/`, outside the media library, with an
  `.htaccess` deny rule, an index stub and unguessable filenames.
- **Secrets** — licence keys, distributor credentials, webhook secrets and 2FA
  secrets are encrypted at rest with AES-256-GCM.

On the limits of that encryption: it protects against database-only exposure — a
leaked SQL dump, a backup on shared storage, a read-only injection. It cannot
protect against an attacker who already holds the filesystem, because the key
material derives from your `wp-config.php` salts. That is the boundary every
WordPress plugin operates under. It is worth having, and it is worth not
overstating.

---

## Privacy requests

The plugin registers with WordPress's own personal data tools, under
**Tools → Export Personal Data** and **Tools → Erase Personal Data**.

Erasure anonymises the customer's identifying fields — name, email, phone, IP —
on their transfers, and **retains the transfer and bound-book records
themselves**. Federal law requires a licensee to keep those for 20 years, and a
privacy request does not override a federal record-keeping obligation. The
erasure response says so explicitly rather than leaving the requester to assume
everything was deleted.

---

*Last reviewed for version 2.0.0. If you add a module or a filter that contacts
a service not listed here, update this document — an out-of-date disclosure is
worse than none.*
