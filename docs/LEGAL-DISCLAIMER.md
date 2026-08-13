# Legal disclaimer

**FFL Checkout Solutions**

---

FFL Checkout Solutions is a workflow and record-keeping tool. It does not
provide legal advice, does not guarantee compliance with ATF regulations or
state and local firearms laws, and does not replace your obligation to
independently verify every transfer, NICS result and compliance requirement.

All compliance decisions — approving or denying a transfer, filing Form 3310.4,
verifying FFL validity — remain the sole responsibility of the licensed dealer
using this software.

WordPressistic and FFListic are not liable for compliance failures, missed
filings, or regulatory penalties arising from use of this plugin.

Consult qualified legal counsel for compliance questions specific to your
business.

---

## What this means in practice

The paragraphs above are the formal statement. This section is what it actually
means when you use the software, because a disclaimer nobody understands
protects nobody.

**Nothing here decides anything.** There is no code path in this plugin that
approves a transfer, denies one, clears a background check, or completes a sale.
Every one of those is a human action recorded after the fact. If a screen shows
you that a three-business-day window has elapsed, that is information; the
decision to proceed under 18 U.S.C. § 922(t)(1)(B)(ii) is yours, and it is
yours alone.

**Nothing here is filed with anyone.** The multiple-sale watcher detects a
pattern that may require ATF Form 3310.4 and emails you the same day, because
the filing deadline is the close of business on the day of the second transfer.
It does not file the form. It cannot file the form. Marking an entry "filed"
records that *you* say you filed it.

**The 4473 worksheet is not a 4473.** It pre-fills information you already hold
so you are not re-keying it. Every rendering carries a banner saying so. The
transfer must be completed on the current official form, in person, at your
licensed premises.

**There is no automated FFL verification, because there is no way to do it.**
ATF publishes no eZ Check API. Any product claiming to verify a licence with ATF
automatically is either scraping a service that forbids it or is not telling you
the truth. This plugin gives you two honest things instead: a check against the
ATF licensee file it has imported, clearly labelled as being against a
*snapshot* rather than against ATF, and a log where you record what you found
when you checked eZ Check yourself.

**The state rules are defaults, not law.** The plugin ships conservative notices
for every state. Firearms law changes frequently and varies by locality. The
admin screen exists precisely so you maintain these against your own counsel's
advice. Treating the shipped defaults as authoritative would be a mistake, and
the plugin does not represent them as such.

**A "blocking" state rule is a store policy control.** It stops an order being
placed on your website. It is not a legal determination about that buyer, and
turning it off is not a violation of anything — it is a decision about how you
want your storefront to behave.

**The risk score is advisory and always will be.** It is rules-based and
transparent: every point comes from a named rule you can read and re-weight. It
never blocks, holds or cancels anything. A high score puts a row in front of a
person.

---

## Record retention

Bound-book (acquisition and disposition) entries are retained for 20 years and
are excluded from the GDPR erasure tool. This is not an oversight — it is the
federal retention requirement, and a privacy request cannot override it. The
erasure response tells the requester exactly what was retained and why.

---

## Acknowledgement

The setup wizard records that an administrator read this disclaimer, storing the
timestamp and user ID in `fflcs_legal_disclaimer_accepted_at` and
`fflcs_legal_disclaimer_accepted_by`. That record is visible under
**FFL Checkout → Settings → Compliance**.

---

*Last reviewed for version 2.0.0.*
