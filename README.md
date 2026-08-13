# FFL-Checkout-Solutions

=== FFL Checkout Solutions ===
Contributors: wordpressistic
Tags: ffl, firearms, woocommerce, checkout, compliance
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete FFL dealer management, WooCommerce checkout, and transfer lifecycle tracking for federally licensed firearms retailers.

== Description ==

FFL Checkout Solutions handles the part of selling firearms online that
WooCommerce does not: getting the right dealer selected at checkout, getting the
firearm to them, and keeping a record of what happened.

**What it does**

Customers pick a licensed FFL dealer during checkout, searching by ZIP code,
business name, licence number or phone. Dealers come from ATF's own monthly
licensee file — about 80,000 of them — imported automatically and searchable by
distance without any mapping service.

Once an order is paid, the plugin creates one transfer record per firearm and
emails the receiving dealer a one-click confirmation link. The dealer confirms
receipt without creating an account. The customer follows progress on their own
tracking page, with the dealer's phone number and directions when it is time to
collect.

Every status change is written to an append-only audit log with the user, the
time and the IP.

**What it does not do**

It does not make compliance decisions. It records the ones you make.

Nothing in this plugin approves or denies a transfer, clears a background check,
files Form 3310.4, or submits anything to ATF. It computes the federal
three-business-day window and emails you when it elapses; the decision to
proceed is yours. It detects multiple handgun sales and alerts you the same day;
you file the form. It cannot verify an FFL with ATF automatically, because ATF
publishes no API for that — instead it checks against its imported licensee data
and gives you a log for the eZ Check you performed yourself.

See the Legal Disclaimer in the plugin's docs folder.

**Free and paid**

The free tier is not a trial. FFL checkout, ATF dealer sync, distance search,
the confirmation portal, transfer records, customer tracking, state compliance
notices, email notifications, saved dealers, theming and GDPR tools all work
with no licence key, permanently.

A licence adds compliance tooling (bound book, 4473 worksheet, multiple-sale
detection, verification hub, NICS tracking), carrier tracking with label
purchase, risk scoring, analytics, outbound webhooks, SMS, admin two-factor, and
payment gateways. Business adds five drop-ship distributor integrations,
GunBroker sync and Credova financing.

A licence is also what delivers updates and security patches to your site.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate. WooCommerce is required and is checked automatically — if it is
   missing or too old you get a clear notice, not a broken site.
3. Follow the setup wizard. It takes about two minutes.
4. Go to FFL Checkout → Dealers and press "Sync ATF dealer list". The import
   runs in the background and takes a few minutes.
5. Edit a firearm product and tick "FFL transfer required".

== Frequently Asked Questions ==

= Does this file NICS checks or ATF paperwork automatically? =

No. This is a workflow and record-keeping tool, not a compliance-filing service.
Every regulatory decision is made by your staff and recorded by the software.
See the Legal Disclaimer in the docs folder.

= Can it verify an FFL with ATF? =

Not automatically, because ATF publishes no verification API. Any plugin
claiming to do this is either scraping a service that forbids it or is not being
straight with you. What this plugin does instead: checks the dealer against its
imported ATF licensee data with the import date clearly shown, and gives you a
log to record the eZ Check you performed. That log is what an audit asks for.

= What happens to my data if I uninstall? =

By default, nothing is deleted. Deactivating stops scheduled work and touches no
data. Deleting the plugin also preserves everything, unless you have explicitly
ticked "Delete all plugin data when the plugin is deleted" under
Settings → Advanced beforehand.

That default is deliberate. This plugin stores transfer and bound-book records
that federal law requires you to keep for 20 years, and deleting a plugin is a
routine troubleshooting step. Destroying compliance records should never be a
side effect of one.

With that option ticked, deleting the plugin removes all plugin tables, all
plugin options, the dealer role, and the private uploads directory holding
certified licence copies and identity documents. Your WooCommerce orders are
never touched.

= Does it work with HPOS? =

Yes. Compatibility with High-Performance Order Storage and with the Cart and
Checkout blocks is declared in code, and every order read and write goes through
the WooCommerce order API rather than post meta.

= What data leaves my site? =

Every external service is listed in the docs folder's Privacy document, with what
is sent, when, and how to switch it off. Each module also restates its own data
flow on its card in the Add-ons screen, at the moment you decide to enable it.

= Do I need a Google Maps API key? =

No. Distance search runs against locally cached ZIP centroids with no mapping
service involved.

== Screenshots ==

1. The dealer selector at checkout, searching by ZIP code.
2. The dashboard, showing what needs attention today.
3. The transfer detail screen with its audit trail.
4. The dealer confirmation portal, as a dealer sees it on a phone.
5. The customer tracking page.
6. The add-ons screen, with each module's data disclosure.

== Changelog ==

= 2.0.0 =
* Initial public release.
* FFL dealer selection at checkout, with ZIP, name, licence and phone search.
* Chunked, resumable ATF licensee import.
* On-demand ZIP centroid engine — no bulk import, no mapping service.
* Eleven-stage transfer lifecycle with an append-only audit log.
* HMAC-signed single-use dealer confirmation portal, with optional second factor.
* Signed public customer tracking page with calendar invite.
* State compliance notices for all 50 states and DC, fully editable.
* WPistic licensing with tier-based module entitlement.
* Self-hosted update mechanism.
* Four-step first-run setup wizard.
* Compliance tooling: bound book, 4473 worksheet, multiple-sale detection,
  verification hub, NICS tracking with federal-holiday-aware date computation.
* Carrier tracking and label purchase via EasyPost.
* Five drop-ship distributor integrations, GunBroker sync, NMI and Credova.
* Risk scoring, analytics, outbound webhooks, SMS, admin two-factor.
* GDPR export and erasure that preserves ATF-required records and says so.

See CHANGELOG.md for the full entry.

== Upgrade Notice ==

= 2.0.0 =
First public release. Upgrading from an earlier internal build migrates your
data automatically — back up your database first, as you would for any major
version.
