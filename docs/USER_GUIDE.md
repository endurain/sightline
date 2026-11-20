# Sightline — User Guide

Privacy-first multi-touch attribution for WordPress. Captures the full path your
visitors take before they convert, without storing their email address.

## Install

1. Upload the plugin zip under Plugins > Add New > Upload, or copy the `sightline`
   folder into `wp-content/plugins/`.
2. Activate **Sightline**.
3. That's it for capture. Visits and touches start recording immediately.

## How it works (plain version)

- Every visitor gets an anonymous id stored in their own browser.
- Sightline records each "touch" — the campaign (UTMs) or ad click that brought
  them, plus the referrer and landing page.
- When someone submits a form, Sightline hashes their email into a fingerprint and
  **discards the email**. The fingerprint links their visits together without ever
  storing who they are.
- You see the whole journey, including people who never converted.

## CRM field setup (optional, one time)

Sightline always records attribution on its own side. If you also want the data
pushed into Pardot / HubSpot / your CRM, create these fields in your CRM once.
Sightline auto-fills whichever exist and ignores the rest.

**Minimal (recommended):**

| Field (CRM) | Holds |
|-------------|-------|
| `sl_first_utm_source` | First-touch source |
| `sl_first_utm_medium` | First-touch medium |
| `sl_first_utm_campaign` | First-touch campaign |
| `sl_last_utm_source` | Last-touch source |
| `sl_last_utm_medium` | Last-touch medium |
| `sl_last_utm_campaign` | Last-touch campaign |

**Fuller (optional):** add `sl_first_utm_term`, `sl_first_utm_content`,
`sl_last_utm_term`, `sl_last_utm_content`, `sl_first_landing`, `sl_last_referrer`,
`sl_touch_count`.

- **Pardot:** create these as custom prospect fields, then map them to matching
  fields on your Form or Form Handler.
- **HubSpot:** create these as contact properties; Sightline fills the matching
  hidden form fields.

You never add hidden fields to your WordPress forms by hand. Sightline injects its
own tracking field automatically. The CRM fields above are the only manual step, and
only if you want CRM pass-through.

## Forms with embedded (iframe) CRM forms

If your form is an embedded Pardot/HubSpot iframe, open the **Sightline** admin
page and turn on **Enable iframe form attribution**. Then:

- Check the **form domain allowlist**. It starts with `go.pardot.com`,
  `pi.pardot.com`, `share.hsforms.com` and `share-eu1.hsforms.com`. Add your Pardot
  custom tracker domain (e.g. `go.yourcompany.com`). Subdomains match too. Only
  iframes on these domains are ever changed; YouTube, maps etc. are left alone.
- Add the six **minimal** fields above to the embedded form as hidden fields, and
  set them to **prefill from the URL / query string** (Pardot). Sightline puts the
  values in the iframe's URL; the form has to be set to read them, or they're
  silently ignored.
- Only first and last touch source / medium / campaign are passed to iframes (URL
  length limits). The fuller fields work on native WordPress forms only.
- Test: open a page with the form using `?utm_source=test`, inspect the iframe in
  your browser's devtools, and confirm its URL contains `sl_first_utm_source=test`.

Visitors without JavaScript, or with an ad blocker that blocks Sightline, still see
the form; it just loads without attribution.

## Native WordPress forms (CRM pass-through)

To pass attribution through a native form (e.g. Contact Form 7 posting to Pardot or
HubSpot), add hidden fields with the CRM field names, e.g. in Contact Form 7:

    [hidden sl_first_utm_source]
    [hidden sl_last_utm_source]
    [hidden sl_last_utm_campaign]

Sightline fills them automatically, including in forms that appear later (popups).

## Privacy

- Email addresses are never stored. Only a one-way fingerprint is kept.
- Marketed accurately: this is privacy-first and pseudonymous, not "anonymous."
- To delete a person's data, use the deletion tool (Phase 6) and enter their email;
  Sightline fingerprints it and removes the matching records.
