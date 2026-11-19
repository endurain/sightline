# Sightline — Build Plan

> Working name: **Sightline**. This file is the master context document. Paste the
> relevant phase section (plus this header) into a fresh Claude instance to resume
> work without re-explaining the project.

---

## Project one-liner
A WordPress plugin that captures every UTM/ad-click touch a visitor makes,
stores the full touch history first-party, ties repeat visits to a single
person via a **hashed email** (email is hashed with a server-side pepper, then
discarded), and visualizes multi-touch attribution as a Sankey diagram on the
admin screen. A REST endpoint receives captured touches for processing.

## Why it exists
Pardot and similar tools overwrite UTMs, drop attribution when leads move to the
CRM, and can't show the real path to conversion. This captures and persists that
data at the source and makes the journey visible.

## Stack
- PHP (WordPress plugin, custom DB tables)
- Vanilla JS capture script (no framework, loads on every page)
- MySQL (WordPress `$wpdb`, custom tables)
- Admin dashboard: vanilla JS + a Sankey lib (ECharts recommended; d3-sankey as alt)
- No external services in v1. Everything runs inside WordPress.

## Architecture rule that protects the future (read before building)
v1 is **local-first**: the plugin captures touches and stores them in the site's own
WordPress DB, and the dashboard lives in wp-admin. But the capture pipeline must be
**destination-agnostic**: capture logic hands finished touch data to a "sink", and the
local DB is simply the default sink. This keeps the door open for a hosted SaaS
dashboard later (Phase 7) where the sink becomes "POST to Sightline's central API"
instead, with zero rewrite of the capture code. Do not hard-wire DB writes into the
capture/ingestion path; route them through a sink interface.

## CMS independence / headless (design principle, costs nothing in v1)
Only the **storage, admin UI, ingestion endpoint, and server-side form hooks** are
WordPress-specific. The **capture script is pure browser JS** and already works on any
front end (WordPress, Next.js, static, anything with a script tag). Rule for v1:
- Write `capture.js` and the touch payload as **standalone, framework-free code**. It
  must NOT depend on WordPress globals (no hardcoded WP REST path, no WP nonce baked in).
  The endpoint URL and any auth token are **configuration passed in**, not assumed.
- v1 wraps this JS in the plugin and points it at the local WP endpoint. Because the JS
  is clean, the exact same file lifts into the hosted/headless world unchanged.
- **Headless is not a plugin feature; it is the hosted service (Phase 7).** Once the
  endpoint + dashboard live outside WordPress, the CMS underneath stops mattering.
  WordPress becomes just one front end that uses the service. Do not build a separate
  "headless mode" into the plugin.

## Privacy rules (apply in every phase)
- Email is **never stored**. On capture: `hash = HMAC-SHA256(pepper, normalized_email)`,
  then discard the raw email.
- Pepper is a server-side secret (wp-config constant or options table, never in client JS).
- Rotating the pepper breaks historical identity matching. Treat it as permanent.
- Store a retention window and provide a delete-by-hash function for erasure requests.
- Positioning: "privacy-first, no email addresses stored." NOT "anonymous."

## Data model (created in Phase 0)
- `sl_visitors` — `id` (PK), `anon_id` (first-party cookie uuid), `hashed_email`
  (nullable until a form is submitted), `first_seen`, `last_seen`.
- `sl_touches` — `id`, `visitor_id` (FK), `utm_source/medium/campaign/term/content`,
  `click_ids` (json: gclid, fbclid, etc.), `referrer`, `landing_url`, `touch_time`.
- `sl_conversions` — `id`, `visitor_id` (FK), `form_id`, `form_type`, `conversion_time`,
  `page_url`.
- Identity resolution: anon_id links touches before a form submit; on submit, the
  hashed_email is written to that visitor row, merging their history.

---

## How to use this plan
Each phase below is self-contained. When starting a phase in a fresh instance,
paste: (1) everything above this line, and (2) the phase's own section. Each phase
ends with a **Done when** checklist so you know it's complete before moving on.

---

## Phase 0 — Scaffold, schema, seed data
**Builds:** Plugin skeleton that activates cleanly and creates the three tables.
A synthetic-data seeder so later phases have something to build against without a
live site.
- Standard plugin header, main file, activation/deactivation hooks.
- `dbDelta()` schema for `sl_visitors`, `sl_touches`, `sl_conversions`.
- Pepper bootstrap: read from `SIGHTLINE_PEPPER` constant, generate + store one if absent.
- `wp sightline seed` WP-CLI command (or an admin button) that inserts ~2,000 fake
  visitors with realistic messy journeys (multiple touches, return visits, some
  converting, varied UTM combos).
**Files:** `sightline.php`, `includes/class-sightline-activator.php`, `includes/class-sightline-schema.php`,
`includes/class-sightline-seeder.php`, `includes/class-sightline-pepper.php`, `includes/class-sightline-cli.php`.
**Done when:** plugin activates, three tables exist, seeder fills them, deactivate
leaves data intact.

## Phase 1 — Client-side capture library
**Builds:** The JS that runs on every page, reads UTMs + click IDs + referrer from
the URL, and persists them first-party across the visit.
- **Framework-agnostic (important):** no WordPress globals, no hardcoded WP paths. The
  endpoint URL and any token come from a small config object the host injects. This same
  file must lift into the headless/hosted world unchanged (see CMS-independence rule).
- Capture `utm_*`, `gclid`, `fbclid`, `msclkid`, `ttclid`, referrer, landing URL.
- Store an `anon_id` (uuid) in a first-party cookie; create on first visit.
- Append each new touch to a touch list in localStorage (fallback to cookie).
- First-touch is preserved and never overwritten; each new campaign hit is a new touch.
- Enqueue the script from the plugin, passing config (endpoint URL, nonce) via
  `wp_localize_script` so the JS stays WP-ignorant.
**Files:** `assets/js/capture.js`, `includes/class-sightline-capture.php` (enqueue + config).
**Done when:** visiting with `?utm_source=...` writes an anon_id cookie and a touch
entry you can see in devtools; a second campaign visit adds a second touch; capture.js
contains zero WordPress-specific references.

## Phase 2 — REST ingestion endpoint
**Builds:** The endpoint the capture script POSTs to, which writes touches to the DB.
- Register `POST /wp-json/sightline/v1/collect`.
- Validate + sanitize payload (anon_id, touches, landing url, referrer).
- Upsert the visitor by anon_id; insert new touches (dedupe identical consecutive touches).
- Nonce / rate-limit basic protection.
- Capture script sends its buffered touches on page load.
**Files:** `includes/class-sightline-rest.php`, `includes/interface-sightline-sink.php`, `includes/class-sightline-db-sink.php`, `includes/class-sightline-sinks.php`, update `capture.js` to POST.
**Done when:** real visits (not just seed data) land in `sl_visitors` / `sl_touches`.

## Phase 3 — Form integration + identity resolution
**Builds:** Hook form submissions, resolve the anonymous visitor to a hashed person,
and feed attribution into the CRM.
- Hook Gravity Forms, Contact Form 7, WPForms submit events.
- Pull the email field, normalize, `hash = HMAC-SHA256(pepper, normalized_email)` via `Sightline_Pepper::hash_email()`, discard email.
- Write hashed_email onto the current anon_id's visitor row (merge history).
- Record a row in `sl_conversions`.
- Inject hidden fields (first/last touch UTMs) into forms so the values reach
  Pardot/HubSpot/etc. on submit.

**Form embed compatibility (same-origin policy matters here):**
Two separate jobs. Job 1 (log attribution in Sightline's own DB) always works, because
touches are captured on the parent page regardless of embed type. Job 2 (push attribution
INTO the CRM) depends on the embed:
- **Native / on-domain HTML forms** (Gravity, CF7, WPForms, HubSpot raw embed, Pardot
  Form Handlers): full DOM access, inject hidden fields directly. Happy path.
- **HubSpot iframe embed:** no direct DOM access, but HubSpot fires `postMessage`
  (`onFormReady` / `onFormSubmitted`) the parent can use to set fields and detect submit.
- **Pardot native iframe forms:** cross-origin, no DOM access, no reliable postMessage.
  Handle via the **"Enable iframe form attribution"** toggle below.

**"Enable iframe form attribution" feature (admin toggle, off by default):**
- Scans the page for iframes whose `src` is on a **configurable domain allowlist**
  (Pardot/HubSpot form domains), so it never rewrites unrelated iframes (YouTube, etc.).
- Rewrites the matched iframe `src` to append captured attribution as query params
  (e.g. `?utm_source=...&first_utm_source=...`). Setting an attribute on an element in
  your own page is allowed; reaching into the iframe is not.
- Rewrite the `src` **before first load** (or set params as soon as capture runs) to
  avoid a double load. Mind Pardot URL length limits: pass first + last touch UTMs, not
  everything.
- This gets data to the doorstep; the CRM form must be configured to **prefill from the
  query string** to receive it. Ship a short in-admin setup checklist so users know to
  set that up, and aren't confused if it silently doesn't populate.
**Files:** `includes/class-sightline-forms.php` (forms + identity resolution), hidden-field JS in `capture.js`,
`assets/js/iframe-attribution.js`.
**Done when:** submitting a native form ties that session's touches to a hashed id, logs
a conversion, and hidden fields carry attribution through; AND with the toggle on, a
matched Pardot/HubSpot iframe's src is rewritten with attribution params (allowlist
respected, no double load).

## Phase 4 — Reporting backend
**Builds:** The queries and internal data API that assemble attribution for display.
- First-touch, last-touch, and full multi-touch path per converting visitor.
- Aggregate path data into Sankey-ready nodes + links (source -> ... -> conversion).
- **Traffic & drop-off report:** all visitors by campaign (converters AND non-converters),
  touch counts, active span, conversion rate per source, and a drop-off view (visitors
  who had N touches then went quiet without converting). On-site behavior only; cannot
  see where a visitor goes after leaving the site.
- Admin data endpoint the dashboard will call.
- Build and test entirely against Phase 0 seed data.
**Files:** `includes/class-sightline-reports.php`, `includes/class-sightline-rest-admin.php`.
**Done when:** an admin-only endpoint returns correct first/last/multi-touch JSON
for seeded conversions, plus traffic/drop-off JSON covering non-converting visitors.

## Phase 5 — Admin dashboard (Sankey + traffic view)
**Builds:** The admin screens that visualize the data.
- Admin menu page + enqueue the dashboard script and Sankey lib (ECharts).
- **Conversion view:** the multi-touch Sankey from the Phase 4 data API.
- **Traffic & drop-off view:** full-funnel report including non-converters (traffic by
  campaign, conversion rate, drop-off). The Sankey is the conversion story; this is the
  whole-funnel story.
- Basic filters (date range, campaign). Summary tiles (visitors, conversions, top paths).
**Files:** `admin/class-sightline-admin.php`, `assets/js/dashboard.js`.
**Done when:** the admin page shows a working Sankey AND a traffic/drop-off view of the
real/seeded data, with date filtering.

## Phase 6 — Privacy, settings, polish
**Builds:** The production-readiness layer.
- Settings page: pepper status, retention window, which forms to track, consent toggle.
- Retention cron: purge touches older than the window.
- Delete-by-hash function for erasure requests (hash an email, delete that visitor).
- Consent gating so capture respects a consent signal.
- README, inline docs, uninstall cleanup.
**Files:** `admin/class-sightline-settings.php`, `includes/class-sightline-privacy.php`, `uninstall.php`,
`README.md`.
**Done when:** settings work, old data purges on schedule, a given email can be
erased, and the plugin uninstalls cleanly.

## Phase 7 — Hosted dashboard (PARKED, do not build yet)
**Status:** Future / business v2. Not part of shipping v1. Listed here so v1's
architecture stays compatible with it.
**The idea:** marketing directors and agencies don't want to log into wp-admin. A
hosted web app where they log in and view attribution for one or more sites is what
turns this from a one-time plugin into a monthly SaaS, and it's how you land agencies
(one login, many client sites).
**What makes it possible without a rewrite:** the Phase 2 ingestion pipeline uses a
destination-agnostic sink (see architecture rule up top). v1's sink = local WP DB.
v2 adds a second sink = "POST to Sightline's central API," plus:
- A central service (server + database) that receives touch data from client sites.
- Auth + multi-tenant accounts (an org, its sites, its users).
- The same reporting + Sankey + traffic views, served from the hosted app.
- Billing.
**Why parked:** running a hosted service costs money and means holding other companies'
data (security, uptime, privacy obligations). Don't take that on until people are paying.
Prove v1 with real/contract users first.
**Done when:** N/A (future project). Revisit after v1 is in real use.

---

## Build order notes
- Phases are sequential; each depends on the ones before it.
- Phase 0's seeder is what lets you build Phases 4–5 without a live site.
- Keep the raw email out of every log, var_dump, and error message.
- Fresh repo, nothing from any prior employer's code.
