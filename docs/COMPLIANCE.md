# Compliance notes

**FFL Checkout Solutions 2.0.0**

This document explains what the plugin does and does not do in each compliance
area. It is written for the person who has to answer an auditor's questions.

Read `docs/LEGAL-DISCLAIMER.md` first. Nothing here is legal advice.

---

## The governing principle

Every compliance decision in this plugin is made by a person and recorded by the
software. There is no code path that approves, denies, files or submits anything
to a government system. That is a design constraint, enforced in code, not a
statement of intent — see `docs/ARCHITECTURE.md` for how it is enforced.

---

## Records and retention

| Record | Where | Retention |
|---|---|---|
| Transfer records | `fflcs_transfers` | Configurable; 20 years recommended |
| Audit events | `fflcs_events` | Never pruned. Append-only. |
| Bound book | `fflcs_ad_ledger` | 20 years. Excluded from GDPR erasure. |
| Signatures | `fflcs_signatures` | Append-only. A re-signature adds a row. |
| Certified copies | `fflcs-private/` on disk | Until deleted by staff |
| Analytics | `fflcs_analytics_events` | Configurable, default 365 days |

The audit log records, for every status change: what changed, who changed it,
when, from what IP, and any note they left. Nothing in the plugin edits or
deletes a row in it.

---

## Background checks

The plugin records outcomes. It does not obtain them, and it does not interpret
them.

The three-business-day computation under 18 U.S.C. § 922(t)(1)(B)(ii) excludes
weekends and the eleven federal holidays, with fixed-date holidays shifted to
their observed day per 5 U.S.C. § 6103. Getting this wrong in the other
direction — omitting holidays — would compute an expiry date *earlier* than the
statute allows, which is why the holiday list is explicit rather than
approximated. Add your own closures via `fflcs_federal_holidays` if your premises
shut on days the federal calendar does not.

When a window elapses you receive one email. Nothing advances automatically.

---

## Multiple sales (Form 3310.4)

Detection triggers on the *disposition* of a second handgun to the same buyer
within five business days — not on the order date, since the reporting trigger is
the transfer.

The alert is sent immediately rather than in a nightly digest, because
27 CFR 478.126a requires the report by close of business on the day of the second
transfer. A digest tomorrow would be useless.

Marking an entry "filed" records that you say you filed it, with your user ID and
a timestamp. The plugin has not contacted ATF.

---

## FFL verification

There is no automated path, because ATF publishes no eZ Check API. Any product
claiming otherwise is either scraping a service that forbids it or is not being
straight with you.

What the plugin offers instead:

1. **A check against the imported ATF licensee file** — clearly labelled as
   being against a snapshot with a known import date, not against ATF live.
2. **A manual eZ Check log** — you check, you record what you found. That record
   is what an audit asks for.
3. **Certified copy tracking** with expiry reminders at 60, 30, 7 and 0 days.

---

## Form 4473

The worksheet pre-fills information you already hold, so you are not re-keying
it. Every rendering — screen and PDF — carries **DRAFT — NOT FOR ATF SUBMISSION**.

It is not ATF Form 4473, is not a substitute for it, and cannot be submitted. The
transfer must be completed on the current official form, in person, at the
licensed premises.

Signature captures are append-only: a re-signature adds a row and never
overwrites the earlier one, so the trail survives.

---

## Privacy versus retention

The GDPR eraser anonymises the customer's name, email, phone and IP on their
transfers, and retains the transfer and bound-book records themselves.

The erasure response says so explicitly. A requester is entitled to know what was
kept and why, and silently retaining data someone believes was deleted would be
the worst of both worlds.

---

## Interstate transfers

A dealer in a different state from the buyer is the normal case for online
firearm sales, not a red flag. The plugin logs the pair for audit purposes and
weights it very low in risk scoring precisely because it is expected.

---

## What to show an auditor

- **Activity Log** — the complete event history, filterable by type.
- **Compliance → Bound book** — acquisitions and dispositions, CSV-exportable.
- **Compliance → Multiple sales** — detections and their filing status.
- **Compliance → Verification hub** — licence copies and your eZ Check log.
- **Compliance → Audit** — a configuration and completeness snapshot. Note that
  it reports facts about this installation; it does not certify compliance, and
  it says so on the page.
