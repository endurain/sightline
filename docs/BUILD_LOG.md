# Vantage — Build Log

Running record of phase status, decisions, and issues. Update as you go.
See `BUILD_PLAN.md` (repo root) for the full phase plan.

## Phase status

| Phase | What | Status | Tested |
|-------|------|--------|--------|
| 0 | Scaffold, schema, seeder | Done | Yes — seeded 2,000 visitors / 4,165 touches / 428 conversions |
| 1 | Client-side capture (framework-agnostic JS) | Done | Yes — getTouches() showed 2 touches, first preserved, dedupe ok |
| 2 | REST ingestion endpoint + sink pattern | Done | Yes — POST /collect returned 200, `{"ok":true,"visitor_id":2002,"inserted":1}` |
| 3 | Forms + identity resolution + iframe attribution | Done (pass 1 forms/identity, pass 2 iframe attribution) | Yes — pass 1: CF7 + WPForms; pass 2: 14/14 headless-Chrome checks with stubbed Pardot/HubSpot (2026-11-19). Not yet tried against a live Pardot/HubSpot account; Gravity Forms not tested |
| 4 | Reporting backend (incl. traffic/drop-off) | Done | Yes — 51/51 fixture assertions; all 423 seed conversions match raw SQL; REST auth/validation checked (2026-11-20) |
| 5 | Admin dashboard (Sankey + traffic view) | Done | Yes: 23/23 headless-Chrome checks (tiles/Sankey/traffic match the API under every filter, fold keeps column totals, XSS probe inert) + 19/19 filter-API checks vs raw SQL (2026-11-24) |
| 6 | Privacy, settings, retention, deletion | Not started | — |
| 7 | Hosted dashboard / SaaS | Parked | — |

## Current version: 0.7.0

## Decisions log

- **Sink pattern.** REST controller never writes to the DB directly; it calls a
  `Vantage_Sink`. Default is `Vantage_DB_Sink`; filter `vantage_sink` swaps
  it. This is what lets Phase 7 (hosted) reuse ingestion unchanged.
- **Email hashing.** `HMAC-SHA256(pepper, normalized_email)`. Email is normalized
  (lowercase + trim only — no gmail dot/plus stripping, too opinionated), hashed,
  and discarded. Never stored.
- **Converters only.** Only visitors who submit a form get a hashed_email.
  Anonymous visitors stay anon_id-only (cleaner privacy, nothing to tie to a name).
- **Pepper storage.** Prefer `VANTAGE_PEPPER` constant (wp-config) when the host
  allows it; otherwise an autoload=off option. Honest trade-off: option storage puts
  the pepper in the same DB as the hashes. Hosted version removes this.
- **Hosted hashing (Phase 7).** Layered: per-site key hashes on the client (email
  never leaves the site), global pepper hashes again server-side (global key never
  touches the client). Neither secret fully crosses the boundary.
- **CMS independence.** capture.js is framework-agnostic (no WP refs, verified in
  build). Headless = the hosted service, not a plugin mode.
- **Iframe attribution.** Pardot: pre-load `src` rewrite with query params (invisible
  if set before first load), on a domain allowlist. HubSpot: prefer postMessage.
  Admin toggle, off by default.
- **Code protection.** Client plugin is GPL/copyable by nature; the moat is the
  server-side logic + hosted service + data, not the plugin code.
- **Endpoint protection.** REST nonce + light per-IP rate limit.
- **CF7 conversion = valid submission, not delivered email.** Conversions are
  recorded on both `wpcf7_mail_sent` and `wpcf7_mail_failed` (CF7 fires exactly
  one). A broken mail setup on the host must not erase attribution. (0.4.1)
- **Iframe attribution = server defers, browser fills.** (0.5.0) An output buffer
  rewrites allowlisted iframes from `src` to `data-vt-src` + `data-vt-pending`;
  `iframe-attribution.js` adds params and sets `src`, so the iframe loads once.
  The server never injects attribution itself: touches live in the browser, and
  server-side values would leak between visitors through full-page caches (the
  deferred HTML is identical for everyone, so it caches safely).
- **Iframes must never stay blank.** No JS: a `<noscript>` copy (id stripped)
  loads and a noscript style hides the deferred one. JS blocked or broken: an
  inline fail-safe releases pending iframes with the original `src` after 2.5s;
  `iframe-attribution.js` also releases them itself if `capture.js` never loads.
- **What goes in the iframe URL:** first + last `utm_source/medium/campaign`
  only (`vt_first_*`, `vt_last_*`), for URL length limits. Existing params are
  never overwritten. Native forms get the fuller set filled into any existing
  `vt_*` fields (term, content, `vt_first_landing`, `vt_last_referrer`,
  `vt_touch_count`). Vantage fills fields that exist; it never creates them.
- **Allowlist matching:** exact host or a true subdomain (`go.pardot.com` matches
  `x.go.pardot.com`, not `go.pardot.com.evil.test`). Defaults: `go.pardot.com`,
  `pi.pardot.com`, `share.hsforms.com`, `share-eu1.hsforms.com`. Toggle off by default.
- **HubSpot postMessage:** on `hsFormCallback` / `onFormReady`, fill `vt_*` fields
  in any same-origin iframe (HubSpot's script-built embed) and in the page itself
  (raw embed). Cross-origin iframes are skipped; share links use the `src` rewrite.
- **Late-loading iframes and forms:** a MutationObserver handles iframes and forms
  added after load. A late allowlisted iframe that already has a `src` may load
  twice (once bare, once with params); only server-deferred iframes avoid that.
- **Reports: person = hashed email.** (0.6.0) Visitor rows sharing a
  `hashed_email` are one person in every report, so cross-device journeys merge
  (issue #4). Stitching happens at read time; the tables are not rewritten.
  Visitors with no hash are a person each. Reports never return the hash.
- **Channel labels.** UTM touch: `source / medium` (lowercased; `· campaign`
  appended with `group_by=campaign`). Click id with no UTMs: `google / (click id)`
  etc. Referrer only: search engines -> `google / organic`, social sites ->
  `facebook / social`, anything else -> `host / referral`; the site's own host
  counts as direct. Nothing at all: `(direct)`.
- **A conversion's path** = all of that person's touches at or before the
  conversion time (touches before the date range included; touches after the
  conversion excluded). Repeat conversions each get the full journey to that
  point. A conversion with no touches gets `(no touch recorded)`.
- **Attribution models:** first touch, last touch, linear, position-based
  (40/40 to first/last, 20% split over the middle; 50/50 for two touches).
- **Sankey:** consecutive repeat channels collapse (A A B -> A B); paths longer
  than `max_steps` (default 5) keep the first touch + the last `max_steps-1`.
  Node ids are `step|label` (unique per column, so no cycles); final node
  `end|Converted`, plus `end|No conversion` with `include_nonconverters`.
- **Traffic:** a person counts for a channel if they had a touch from it in range,
  and as a converter for it if they converted in range at or after that touch.
- **Drop-off:** people active in range, bucketed by all-time touch count (1..6+),
  split converted (ever) / still active / went quiet (no touch for `quiet_days`,
  default 14, and never converted). On-site behavior only.
- **Date ranges** are whole days in the site timezone (default: last 90 days,
  max ~2 years); every time returned is UTC ISO-8601.
- **Dashboard** (0.7.0, Phase 5). Vantage > Dashboard (`page=vantage`); the old
  admin page moved to Vantage > Settings (`page=vantage-settings`). Filter row
  (date presets + custom, campaign, channel, group by) above tiles and two
  equal-weight tabs: Conversion paths (Sankey, credit by model, top paths) and
  Traffic & drop-off (traffic by channel, drop-off split, last channel before
  going quiet). Filters live in the URL hash, so a view can be bookmarked.
- **Dashboard rendering.** Only the Sankey uses ECharts; everything else is
  HTML/CSS (crisper, accessible, a table by construction). Sankey: one navy hue
  for channel nodes, orange only on Converted; node order fixed (same channel,
  same rank, every column; `layoutIterations: 0`); labels only where the node is
  tall enough, truncated to the column width; full values in tooltips and the
  Table toggle. Long tail: the 7 biggest channels by name, the rest "Other".
- **Dashboard colors.** Brand navy `#122A3F` fails the categorical-fill gates
  (too dark, too gray), so it stays for ink and chrome. Data colors were
  validated against the salt surface `#F6F5F2`: converted `#1F5A8C`, still active
  `#4E9FD8`, went quiet `#C2561F` (brand orange). Worst adjacent CVD dE 22.0,
  normal-vision 22.1; still active is under 3:1 contrast, so every value is also
  printed or in a table.
- **ECharts 6.1.0 bundled** in `assets/vendor/echarts/` (WP.org disallows CDN
  libraries), enqueued only on the dashboard screen (verified on four admin
  hooks). Apache-2.0 (GPLv3-compatible, so fine under GPL-2.0-or-later); the dist
  also embeds zrender and d3 parts (BSD-3-Clause). LICENSE, NOTICE,
  LICENSE-zrender and LICENSE-d3 ship next to it. Full build (1.1 MB); a custom
  sankey-only build is a later size optimization.
- **Report API** is admin-only (`manage_options`), GET, standard WP REST cookie
  auth, `Cache-Control: no-store`. Computed per request in PHP (0.12s for the
  2,000-visitor seed); pre-aggregation is the upgrade path if sites get large.
- **Filters** (0.7.0, Phase 5). `campaign` (exact utm_campaign) and `channel`
  (exact `source / medium` label, e.g. `google / cpc` or `(direct)`, independent
  of `group_by`) keep a person if ANY of their touches matches, at any time; both
  together = AND. So a filtered traffic row can count slightly fewer people than
  the filter kept (traffic only counts in-range touches). `reports/filters` lists
  the campaigns and channels on people active in range, with people counts, and
  ignores the active filters so every option stays listed.

## Issues log

| # | Issue | Status |
|---|-------|--------|
| 1 | Chrome blocks console paste (self-XSS guard) during testing | Resolved — use Application > Storage panel, or type `allow pasting` |
| 2 | Full-page caches can serve a stale REST nonce to anonymous visitors | Open — Phase 6, switch to a cache-friendly token |
| 3 | anon_id hidden field injected at load only; forms added later (popups/AJAX) miss it | Resolved in 0.5.0 — `capture.js` MutationObserver prepares forms added after load (verified) |
| 4 | Cross-device identity: same hashed_email on two anon_ids = two visitor rows | Resolved in 0.6.0 at reporting — reports treat one hash as one person (verified with a two-device fixture). Rows stay separate in the DB by design |
| 5 | CF7 conversions only hook `wpcf7_mail_sent`; if the notification email fails (`mail_failed`), the conversion is silently dropped (reproduced 2026-11-19) | Resolved in 0.4.1 — `Vantage_Forms` now also hooks `wpcf7_mail_failed`; verified a forced mail failure records the conversion |
| 6 | Current CF7 (6.2+) requires PHP 8.3; Local test site runs PHP 8.2, so testing used CF7 6.1.4 | Note — bump site PHP to test against current CF7 |
| 7 | Pass 1 never filled the `vt_first_*` / `vt_last_*` CRM fields the user guide promised; only `vt_anon_id` was injected | Resolved in 0.5.0 — `capture.js` `fillFields()` fills existing fields (verified on CF7 `[hidden]` fields) |
| 8 | Iframe attribution only tested against stubbed Pardot/HubSpot responses | Open, **pre-ship blocker**: verify with a real Pardot form (prefill from URL) and a HubSpot share link / embed before shipping |
| 9 | Iframes outside the main HTML response (added by page-builder JS) aren't deferred, so they may load twice | Known limit — documented; acceptable |
| 10 | Reports load all touches for in-range people per request | Watch — fine at seed scale (0.12s); add caching or pre-aggregation if a real site is slow |
| 11 | Seeder timestamps can run past "now": journeys start 1-90 days ago and each touch adds up to 10 days with no cap (current seed: 169 of 4,149 touches and 20 of 466 conversions are in the future, so default "last 90 days" reports drop them). Also `current_time('timestamp')` + `gmdate()` shifts seed times by the UTC offset on non-UTC sites (this site is UTC, so latent here) | Resolved 2026-11-24: gaps scale down to fit before now; `time()` replaces `current_time()`. Re-seeded: 0 future touches / conversions |
| 12 | No report lists the campaigns / channels present in a date range, so dashboard filter dropdowns have no data source (deriving them from `traffic?group_by=campaign` labels would mean parsing display strings) | Resolved in 0.7.0: `reports/filters` (verified against raw SQL) |
| 13 | No `source` / channel filter param on the reports (only `campaign`, exact match) | Resolved in 0.7.0: `channel` param on every report (verified against raw SQL) |
| 14 | Sankey has no long-tail cap: `group_by=campaign` on the seed returns 67 nodes / 313 links (by source: 40 / 182; with non-converters: 42 / 286) | Resolved in 0.7.0: dashboard folds all but the 7 biggest channels into "Other" in every column (display only, column totals verified) |
| 15 | Gravity Forms hook is wired but has never been run (paid plugin, not installed); README now says so | Open, **pre-ship**: test it or keep the claim softened |

## Test environment

- Local WordPress (Local by WP Engine), PHP 8.2. Contact Form 7 6.1.4 and
  WPForms Lite for form testing. Test pages: `/vantage-form-test/`,
  `/vantage-wpforms-test/`, `/vantage-iframe-test/` (renamed from `/sightline-*/`
  on 2026-11-24).
- DB inspection via TablePlus / Adminer, or Local's bundled `mysql` + WP-CLI.
- Browser tests: headless Chrome via puppeteer-core, with request interception
  stubbing Pardot/HubSpot/YouTube so iframe loads can be counted.

---

## Rename note (2026-11-24)

The plugin was renamed Sightline to Vantage at v0.6.0 (prefix `sl_` to `vt_`).
Historical names in this log were rewritten by the rename: the Phase 3 pass 1
build shipped as `sightline-phase3pass1.zip`, and earlier test results refer to
`wp_sl_*` tables and `sl_*` fields. The dev DB was not migrated: reactivation
created fresh `wp_vt_*` tables and a new pepper, re-seeded. The old `wp_sl_*`
tables and `sightline_*` options are still in the dev DB and can be dropped.

## Testing checklist (state of each item)

### Phase 0 — scaffold / schema / seeder  — VERIFIED
- [x] Plugin activates with no errors; Vantage admin menu appears.
- [x] Tables created: `{prefix}_vt_visitors`, `_vt_touches`, `_vt_conversions`.
- [x] Seed button creates ~2,000 visitors / ~4,165 touches / ~428 conversions.
- [x] Pepper status shows "Database"; converters have 64-char hash, others NULL.
- [x] Clear button empties tables.

### Phase 1 — client capture  — VERIFIED
- [x] Visiting `?utm_source=...` sets an `vt_anon` cookie.
- [x] `Vantage.getTouches()` shows the touch; first touch preserved on 2nd campaign.
- [x] Refresh within 30 min does NOT add a duplicate touch.
- [x] `capture.js` contains zero WordPress references (build-checked).

### Phase 2 — REST ingestion  — VERIFIED
- [x] `POST /wp-json/vantage/v1/collect` returns 200 with
      `{"ok":true,"visitor_id":N,"inserted":M}`.
- [x] A real browser visit creates a NEW visitor row (id > 2000) and touch rows.
- [x] Server dedupes identical consecutive touches.

### Phase 3 pass 1 — forms + identity  — VERIFIED 2026-11-19 (CF7 6.1.4, WPForms Lite 2.0.5.1, headless Chrome)
- [x] After reinstall, a page with a CF7 form contains a hidden
      `<input name="vt_anon_id" value="<uuid>">` (injected by capture.js).
- [x] Submitting the form inserts a row in `_vt_conversions` with
      `form_type = 'cf7'` and a valid `visitor_id`.
- [x] That visitor row now has a 64-char `hashed_email`.
- [x] **PRIVACY CHECK:** search the ENTIRE database for the email you submitted.
      It must NOT appear in any Vantage table. (It may appear in the form
      plugin's own tables — that's the form plugin, not Vantage.)
- [x] Re-submitting with a different email on the same browser does NOT overwrite
      the existing `hashed_email` (first identity wins).
- [ ] Repeat for Gravity Forms (`form_type = 'gravityforms'`) if available. *(Not tested — paid plugin, not installed.)*
- [x] Repeat for WPForms (`form_type = 'wpforms'`) if available.
- [x] Submit with JS disabled / no `vt_anon_id`: handler bails cleanly, no PHP error.

### Cross-cutting checks
- [x] No PHP notices/warnings with `WP_DEBUG` on during any of the above.
- [x] Deactivate + reactivate keeps data intact (no teardown until Phase 6).
- [x] Confirm `capture.js` loads with `?ver=0.4.0` (bust browser cache after reinstall).
      (Re-verified at `?ver=0.5.0`.)

### Phase 3 pass 2 — iframe attribution  — VERIFIED 2026-11-19 (stubbed CRM endpoints)
- [x] Toggle off (default): no iframe rewritten, `iframe-attribution.js` not enqueued.
- [x] Toggle on: allowlisted Pardot iframe is served as `data-vt-src` + `<noscript>` copy.
- [x] Pardot iframe loads **exactly once**, with `vt_first_utm_*` / `vt_last_utm_*`
      (source/medium/campaign) after two campaign visits; existing query param kept.
- [x] YouTube iframe and lookalike domain `go.pardot.com.evil.test` untouched.
- [x] CF7 `[hidden vt_first_utm_source]` etc. filled with first/last touch.
- [x] Iframe added after load (HubSpot share link) gets params.
- [x] Form added after load gets `vt_anon_id` + CRM fields (issue #3).
- [x] HubSpot `onFormReady` postMessage fills fields in a same-origin form iframe.
- [x] Vantage JS blocked (ad blocker): Pardot still loads once with original URL.
- [x] JS disabled: `<noscript>` copy loads once, deferred blank iframe hidden.
- [x] Admin settings save/render; bad nonce rejected; domain input sanitized.
- [x] No JS errors; no PHP notices with `WP_DEBUG` on; pass 1 form tests still pass.
- [ ] Real Pardot form with "prefill from URL" receives the values (issue #8).
- [ ] Real HubSpot share link / embed receives the values (issue #8).

### Phase 4 — reporting backend  — VERIFIED 2026-11-20
Fixture test: six hand-built people in Jan 2020 inside a rolled-back transaction
(51 assertions, all exact values). Seed test: every report against 2,000 seeded visitors.
- [x] Conversions: first/last touch and full path per conversion; newest first;
      pagination; post-conversion touches excluded; pre-range touches included.
- [x] Cross-device: two visitor rows with one hash form one path (`devices: 2`).
- [x] Repeat conversions by one person each get their own row.
- [x] Classification: click id only, organic search, social, referral, internal
      referrer (= direct), direct.
- [x] Attribution: exact credit per model; each model sums to total conversions.
- [x] Sankey: exact links; Converted node = conversions; `max_steps` truncation;
      `include_nonconverters` adds "No conversion".
- [x] Traffic: people/touches/first-touch/converters/avg touches/active days per
      channel (incl. a touch after conversion not counted as converting).
- [x] Drop-off: buckets by touch count; quiet vs still active; `quiet_days`.
- [x] Summary: people, touches, conversions, rate, avg touches, median days, top paths.
- [x] Filters: date range, swapped dates, invalid date, `campaign`, `group_by=campaign`.
- [x] Seed data: all 423 in-range conversions' first/last touch and touch count
      match an independent raw-SQL computation.
- [x] Hashed email never appears in any report output.
- [x] REST: anonymous 401, subscriber 403, unknown report 404, bad args 400,
      admin 200 for all six reports; no PHP notices with `WP_DEBUG` on.

### Phase 5 — admin dashboard  — VERIFIED 2026-11-24 (headless Chrome, seed data)
- [x] Smoke test: six reports return 200, ~0.1s each, shapes match the views.
- [x] `reports/filters` + `channel` param: 19/19 checks against raw SQL.
- [x] Seeder no longer writes future timestamps (#11); re-seeded.
- [x] Dashboard renders both tabs with no console errors; Settings page intact.
- [x] Tiles (visitors, conversions, rate, touches per conversion, top path)
      equal the API for: no filter, campaign, campaign + channel, custom range.
- [x] Sankey Converted total = API under a campaign filter; non-converters toggle
      adds "No conversion"; node tooltip shows value + column.
- [x] Group by campaign: long tail folds into "Other"; every column total in the
      Table view equals the API's.
- [x] Traffic rows follow the filters; drop-off segments show tooltips on hover
      and keyboard focus.
- [x] Filters, tab and view persist in the URL hash; editing the hash reloads.
- [x] Empty state when filters match nobody; seed prompt when there is no data.
- [x] XSS probe (`<img onerror>` campaign, `<b>` source) renders as text in
      selects, tables and tooltips; no element injected, nothing executes.
- [x] ECharts + dashboard assets load only on Vantage > Dashboard.
- [x] No horizontal overflow at 900px wide.
- [ ] Check in a real browser at your usual screen size (all checks so far ran
      headless).

## Known gaps / next work
- **Phase 5 is complete** (0.7.0): dashboard at Vantage > Dashboard. **Next:
  Phase 6** (privacy, settings, retention, deletion), which also owns #2.
- **Pre-ship blockers:** live Pardot/HubSpot iframe test (#8); Gravity Forms
  untested (#15).
- **Open limits:** stale nonce under page caching (#2, Phase 6); report
  performance on large sites (#10); CF7 6.2+ needs PHP 8.3 (#6).
- Phase 7 (hosted) stays parked.

## How to resume in a code tool
Paste `BUILD_PLAN.md` (the phase you're on) + this `BUILD_LOG.md` into the tool
for full context. `docs/DEV_NOTES.md` has the architecture and file map. Keep the
sink pattern (controller never writes the DB directly) and the capture.js
"no WordPress references" rule intact.
