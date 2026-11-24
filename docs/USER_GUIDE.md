# Vantage — User Guide

Privacy-first multi-touch attribution for WordPress. Captures the full path your
visitors take before they convert, without storing their email address.

## Install

1. Upload the plugin zip under Plugins > Add New > Upload, or copy the `vantage`
   folder into `wp-content/plugins/`.
2. Activate **Vantage**.
3. That's it for capture. Visits and touches start recording immediately.

## How it works (plain version)

- Every visitor gets an anonymous id stored in their own browser.
- Vantage records each "touch" — the campaign (UTMs) or ad click that brought
  them, plus the referrer and landing page.
- When someone submits a form, Vantage hashes their email into a fingerprint and
  **discards the email**. The fingerprint links their visits together without ever
  storing who they are.
- You see the whole journey, including people who never converted.

## CRM field setup (optional, one time)

Vantage always records attribution on its own side. If you also want the data
pushed into Pardot / HubSpot / your CRM, create these fields in your CRM once.
Vantage auto-fills whichever exist and ignores the rest.

**Minimal (recommended):**

| Field (CRM) | Holds |
|-------------|-------|
| `vt_first_utm_source` | First-touch source |
| `vt_first_utm_medium` | First-touch medium |
| `vt_first_utm_campaign` | First-touch campaign |
| `vt_last_utm_source` | Last-touch source |
| `vt_last_utm_medium` | Last-touch medium |
| `vt_last_utm_campaign` | Last-touch campaign |

**Fuller (optional):** add `vt_first_utm_term`, `vt_first_utm_content`,
`vt_last_utm_term`, `vt_last_utm_content`, `vt_first_landing`, `vt_last_referrer`,
`vt_touch_count`.

- **Pardot:** create these as custom prospect fields, then map them to matching
  fields on your Form or Form Handler.
- **HubSpot:** create these as contact properties; Vantage fills the matching
  hidden form fields.

You never add hidden fields to your WordPress forms by hand. Vantage injects its
own tracking field automatically. The CRM fields above are the only manual step, and
only if you want CRM pass-through.

## Forms with embedded (iframe) CRM forms

If your form is an embedded Pardot/HubSpot iframe, open the **Vantage** admin
page and turn on **Enable iframe form attribution**. Then:

- Check the **form domain allowlist**. It starts with `go.pardot.com`,
  `pi.pardot.com`, `share.hsforms.com` and `share-eu1.hsforms.com`. Add your Pardot
  custom tracker domain (e.g. `go.yourcompany.com`). Subdomains match too. Only
  iframes on these domains are ever changed; YouTube, maps etc. are left alone.
- Add the six **minimal** fields above to the embedded form as hidden fields, and
  set them to **prefill from the URL / query string** (Pardot). Vantage puts the
  values in the iframe's URL; the form has to be set to read them, or they're
  silently ignored.
- Only first and last touch source / medium / campaign are passed to iframes (URL
  length limits). The fuller fields work on native WordPress forms only.
- Test: open a page with the form using `?utm_source=test`, inspect the iframe in
  your browser's devtools, and confirm its URL contains `vt_first_utm_source=test`.

Visitors without JavaScript, or with an ad blocker that blocks Vantage, still see
the form; it just loads without attribution.

## Native WordPress forms (CRM pass-through)

To pass attribution through a native form (e.g. Contact Form 7 posting to Pardot or
HubSpot), add hidden fields with the CRM field names, e.g. in Contact Form 7:

    [hidden vt_first_utm_source]
    [hidden vt_last_utm_source]
    [hidden vt_last_utm_campaign]

Vantage fills them automatically, including in forms that appear later (popups).

## The dashboard

Open **Vantage > Dashboard** in wp-admin.

- **Filters** (top row) apply to everything below: date range, campaign, channel,
  and whether channels are grouped by source or by source + campaign.
- **Tiles:** visitors, conversions, conversion rate, **touches per conversion**
  (how many touches it really takes; single-touch tools can't show this), and the
  top converting path.
- **Conversion paths:** the Sankey shows each conversion's touches in order, left
  to right. Hover a node or flow for exact numbers; **Table** shows the same data
  as a table. **+ Non-converters** adds the visitors who didn't convert. Below it:
  how much credit each channel gets under four attribution models, and the most
  common exact paths.
- **Traffic & drop-off:** every visitor, not just converters. Where traffic
  enters, how visitors split into converted / still active / went quiet by number
  of touches, and the last channel people saw before they went quiet.

Vantage only sees what happens on your site, so "went quiet" means "stopped
visiting", not "went to a competitor".

Settings (iframe attribution, demo data) are under **Vantage > Settings**.

## Privacy

- Email addresses are never stored. Only a one-way fingerprint is kept.
- Marketed accurately: this is privacy-first and pseudonymous, not "anonymous."
- To delete a person's data, use the deletion tool (Phase 6) and enter their email;
  Vantage fingerprints it and removes the matching records.
