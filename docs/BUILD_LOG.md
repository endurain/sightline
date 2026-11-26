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
| T1 | Risk register Tier 1 (#1-#6): cache, Safari, click ids, bots, timezone, spoofing | Done (0.8.0) | Yes: 82 server + 30 HTTP + 17 browser checks (2026-11-24); limits logged as #16-#27 |

## Current version: 0.8.0

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
- **Endpoint protection.** (0.8.0, replaces nonce.) No nonce: a nonce baked into a
  full-page-cached page goes stale and WP core rejects the request (403) before
  Vantage runs, silently dropping anonymous traffic. Instead: Origin (or Referer)
  must be this site; bots and prefetch dropped (200, nothing stored); anon_id must
  be a UUID v4; landing_url must be on this site; touch time clamped to
  [now - 30 days, now]; 20 touches per request; 100 touches per visitor per day;
  600 requests per IP per minute. Honest limit: a script forging Origin and a
  browser UA is accepted (anything a browser can send, a script can).
- **Cache and optimizers.** capture.js and its inline config carry `nowprocket`,
  `data-no-optimize`, `data-no-defer`, `data-noptimize`, `data-cfasync="false"`
  so "delay JS until interaction" doesn't skip bounced visitors.
- **Identity on Safari.** /collect re-sets `vt_anon` with HTTP Set-Cookie (365
  days, host-only, path /, SameSite=Lax, readable by JS). ITP's 7-day cap applies
  to cookies created by `document.cookie`, not to first-party server cookies.
- **Bots.** Server: user-agent filter (`Vantage_Bots`, filterable) chosen so
  in-app browsers (Facebook, Instagram, LinkedIn, X, TikTok, Snapchat, Gmail app)
  pass; `Sec-Purpose`/`Purpose: prefetch` dropped. Client: skip when
  `navigator.webdriver`; wait for `prerenderingchange` on prerendered pages.
  Link unfurlers never run JS, so they never created touches.
- **Click ids** (0.8.0) map to what they prove: gclid / wbraid / gbraid ->
  `google / cpc`, dclid -> `google / display`, msclkid -> `bing / cpc`, other
  ad-platform ids -> `<platform> / cpc`. fbclid -> `facebook / social` (Facebook
  adds it to organic clicks too). UTMs win; a missing utm_medium is filled from a
  same-source click id.
- **Return visits from other sites are touches** (0.8.0). Before, a no-UTM visit
  was only recorded on the first ever visit, so later organic / social / referral
  returns were lost. Now any visit with an external referrer is a touch (client
  and server dedupe compare referrer host when there are no UTMs). Payment and
  sign-in returns (PayPal, Stripe, Google/Apple/Microsoft sign-in) are excluded;
  add more with the `vantage_ignore_referrers` filter.
- **Referrer classification order** (0.8.0): Android app ids first (Gmail ->
  email, Google app -> organic, YouTube -> social), then webmail hosts (email),
  then Google's non-search subdomains (docs, drive, accounts... -> referral), then
  search engines. Before, any host containing "google" was organic search.
- **Timezone.** All date ranges are whole days in the site timezone (verified in
  America/New_York across the Nov 1 DST change against an independent count);
  durations are timezone-free. The dashboard header names the timezone and links
  to Settings > General.
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
  appended with `group_by=campaign`). Click id with no UTMs: see "Click ids"
  below (0.8.0; was `google / (click id)`). Referrer only: search engines -> `google / organic`, social sites ->
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
  HTML/CSS (crisper, accessible, a table by construction). Sankey node order is
  fixed (same channel, same rank, every column; `layoutIterations: 0`); labels
  only where the node is tall enough, truncated to the column width; full values
  in tooltips and the Table toggle. Long tail: the 7 biggest channels by name,
  the rest "Other". Hover focuses the adjacent path (`focus: 'adjacency'`).
- **Color means channel, everywhere** (0.7.1). Fixed 8-hue palette (blue,
  orange, aqua, yellow, magenta, green, violet, red; validated on salt `#F6F5F2`:
  worst adjacent CVD dE 9.1, normal-vision 19.6; four hues under 3:1, so every
  channel also has a text label or the Table view). Used on Sankey nodes and
  links (`color: 'source'`), traffic bars, "went quiet" bars, attribution
  swatches and path chips. A channel's slot is assigned once (alphabetical for
  newcomers) and remembered in localStorage, so filters never repaint; which
  channels get a color comes from the range's unfiltered channel list; past 8,
  the 7 biggest keep a color and the rest are "Other" gray. Under group by
  campaign, a node takes its base channel's color.
- **Reserved colors.** Brand orange `#C2561F` = the goal: Sankey Converted node
  and drop-off "Converted". Drop-off states (validated, worst dE 22.0): converted
  `#C2561F`, still active `#4E9FD8`, went quiet `#1F5A8C`. (0.7.0 had went quiet
  in orange; swapped so orange always means converted.) Brand navy `#122A3F` fails
  the categorical-fill gates (too dark, too gray): it stays for ink, chrome and
  the top-paths bars (a path is not a channel).
- **Traffic table notes.** Visitors and Converted count a visitor once per
  channel touched, so columns exceed the totals; First touch only counts
  visitors whose first-ever touch is in range (seed: sums to 1,962 of 1,985; the
  other 23 first arrived before the window, verified in SQL). Both stated under
  the table, with the live numbers.
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
| 2 | Full-page caches can serve a stale REST nonce to anonymous visitors | Resolved in 0.8.0: no nonce on ingestion; verified with a static cached copy (stale nonce baked in) served to two visitors |
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
| 16 | Optimizer exclusion attributes (`nowprocket` etc.) not verified against real WP Rocket / LiteSpeed / Perfmatters / Cloudflare Rocket Loader | Open: install one, enable delay-JS, confirm capture runs without interaction |
| 17 | Server-set cookie not verified on real Safari (no Safari automation here) | Open: Safari, visit, check cookie expiry in Web Inspector > Storage |
| 18 | Safari can wipe localStorage after 7 idle days: the client touch list resets, so `vt_first_*` CRM fields report the post-wipe first touch and the return visit is recorded as a "first visit" (direct) touch. Server history is intact | Open: read first touch from the server or keep it in the server cookie |
| 19 | Per-IP rate limit uses REMOTE_ADDR. Behind a CDN/proxy that the host doesn't unwrap, every visitor shares the CDN IP's 600/min bucket and busy sites drop touches. Trusting X-Forwarded-For would make it spoofable | Open: trusted-proxy setting |
| 20 | /collect accepts scripted requests that forge Origin + a browser UA; rotating ids/IPs can inject fake visitors at volume (each id capped at 100/day) | Known limit, documented. Later: anomaly flags, hosted ingestion |
| 21 | Bots that run JS with a normal UA and no webdriver flag are not filtered | Open: behavioral heuristics (no interaction, impossible timing) |
| 22 | Origin under `Referrer-Policy: no-referrer` verified in Chrome only (Chrome still sends it; spec says "null"). capture.js pins `referrerPolicy: same-origin` as a guard | Open: confirm in Firefox and Safari |
| 23 | Some lost referrers are unfixable client-side: HTTPS -> HTTP downgrade, in-app browsers and email clients that strip referrers, no UTMs. These land in (direct) | Known limit, documented (use UTMs on owned links) |
| 24 | fbclid-only clicks are `facebook / social`: paid vs organic can't be told apart without UTMs | Known limit, documented (tag Meta ads with UTMs) |
| 25 | The referral exclusion list is a starting set; checkout, booking and SSO hosts differ per site | Documented: `vantage_ignore_referrers` filter |
| 26 | Return visits from other sites were not captured before 0.8.0, so earlier data under-counts organic / social / referral touches; cannot be backfilled | Known, historic only |
| 27 | Vantage can't detect a GA4 / CRM timezone mismatch; the dashboard names the site timezone and links to the setting | Documented |
| 28 | **Security:** /collect stores a `vt_cap_*` transient per visitor id per day (and `vt_rl_*` per IP). Without a persistent object cache these are `wp_options` rows: rotating random ids adds thousands of rows a day (expired ones only purged daily), and the visitors table grows with every fake id. Cheap nuisance / DB-bloat attack, not a takeover | Open: count caps in one bounded store (e.g. a small table or a single per-day option keyed by hash bucket), or only cap ids that already exist |
| 29 | **Security:** capture.js copies UTM values from the URL straight into the hidden `vt_first_*` / `vt_last_*` CRM fields. A crafted link can push HTML/script text into Pardot/HubSpot records (stored XSS there depends on how the CRM renders it) | Open: strip tags / control characters and cap length before `fillFields()`; same rule as the server's sanitize_text_field |
| 30 | **Security:** the form handler (`Vantage_Forms::handle`) accepts any `vt_anon_id` up to 64 chars, while /collect requires a UUID v4. A crafted form post can create oddly shaped visitor rows | Open: reuse `Vantage_REST::UUID_RE`; quick fix |
| 31 | **Privacy (register #19):** the option-stored pepper sits in the same DB as the hashes, so a stolen DB lets an attacker test guessed emails against them. "No email stored" is true; "anonymous" is not | Open: push sites to `VANTAGE_PEPPER` in wp-config (settings-page prompt, Phase 6); keep the "pseudonymous" wording |
| 32 | **Privacy (register #20):** `landing_url`, `referrer` and `utm_*` are stored as received; people put emails and names in URLs, breaking the no-PII promise | Open: strip email-like values and known PII params on capture (client and server) |
| 33 | **Privacy (register #21):** capture fires before any consent banner is answered | Open, Phase 6: consent-mode hook (wait for a consent signal / integrate CMPs) |
| 34 | **Security:** no dedicated line-by-line security review has been done yet (the 2026-11-26 assessment was informed, not an audit). `/security-review` failed because `origin/HEAD` isn't set in this repo | Open, **pre-ship**: run `git remote set-head origin main`, then `/security-review` (or `/code-review` at high) from the plugin folder |

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
- [x] 0.7.1 punch-list: each channel has the same color in attribution swatches
      and traffic bars; 8 channels get 8 distinct colors; a campaign filter
      changes counts without repainting any channel; a 9th channel folds to gray.
- [x] Tile values align across all six tiles at 1600 / 1440 / 1280px (subgrid).
- [ ] Check in a real browser at your usual screen size (all checks so far ran
      headless).

### Risk register Tier 1 (#1-#6): VERIFIED 2026-11-24 (0.8.0)
Each fix was attacked, not just exercised. Server checks (PHP, 82), HTTP against
the live endpoint (30), headless Chrome (17); dashboard (26) and filter (19)
suites re-run green. Test rows tagged `vt_selftest`, removed after.
- [x] #1 No-nonce POST accepted; a stale nonce header is still a WP-core 403 (why
      capture.js never sends one); page HTML has no nonce; a static cached copy
      with a stale nonce injected records two distinct visitors; logged-in admin
      on the front end records; optimizer-exclusion attributes on both tags.
- [x] #2 /collect Set-Cookie: same id, ~365 days, path /, SameSite=Lax, not
      HttpOnly, host-only; the browser ends up with exactly one vt_anon.
- [x] #3 gclid / wbraid / msclkid / dclid / fbclid / mixed-UTM cases; empty,
      unknown and malformed click ids; 18 referrer cases (webmail, Android apps,
      Google non-search subdomains, real search engines, lookalike hosts). Browser:
      first, Google, reload, PayPal return, internal, direct return, HN, gclid
      sequence records exactly first + Google + HN + gclid.
- [x] #4 19 bot UAs flagged, 20 human UAs (in-app browsers, TV, console) not;
      bot and prefetch POSTs store nothing; Slack/Facebook/WhatsApp/Twitter unfurls
      create no touch; webdriver browser sends nothing; a prerendered page records
      nothing until opened, then exactly one touch.
- [x] #5 New York: four days incl. the 25-hour DST day match an independent count;
      boundaries 04:00Z / 04:59:59Z; default range ends on New York's today;
      durations identical in Tokyo and New York; timezone option restored.
- [x] #6 No/foreign/lookalike Origin 403; Referer fallback; malformed and
      injection-shaped ids 400; foreign and javascript: landing URLs dropped;
      future and 2020 times clamped, 3-day backlog kept; 20 per request; 100 per
      visitor per day; non-scalar fields; tags stripped; different-origin page
      (fetch, no-cors fetch, sendBeacon) stores nothing; Referrer-Policy
      no-referrer page still records. LIMIT confirmed: forged Origin + UA gets in.
- [x] Regression: CF7 conversion with hidden CRM fields, 64-char hash, raw email
      in no Vantage table.
- Found and fixed while proving: any "google" host (Gmail, Docs, the YouTube app)
  counted as organic search; server dedupe dropped referral touches; return visits
  from other sites were never captured; the CF7 test form still used `sl_` field
  names (rename leftover, fixed in the dev DB).

## Known gaps / next work
- **Phase 5 is complete** (0.7.0) and **Tier 1 of the risk register** (0.8.0).
  **Next:** Phase 6 (privacy, settings, retention, deletion), or the register's
  quick wins (#17 UTM case-normalizing) and Tier 4 (#20 PII in URLs, #21 consent).
- **Security / privacy (2026-11-26 assessment):** WP-side basics are solid
  (capabilities + nonces on admin actions, prepared SQL, escaped output, XSS probe
  inert). Open: #20 forged requests, #28 transient/DB bloat, #29 URL values into
  CRM fields, #30 form-path id check, #31 pepper location, #32 PII in URLs,
  #33 consent, #34 formal review before a paying client.
- **Revisit list from Tier 1:** #16-#22 open (real-plugin and real-Safari checks,
  localStorage wipe, proxy IPs, forged requests, JS bots, non-Chrome Origin);
  #23-#27 documented limits.
- **Pre-ship blockers:** live Pardot/HubSpot iframe test (#8); Gravity Forms
  untested (#15).
- **Open limits:** report performance on large sites (#10); CF7 6.2+ needs
  PHP 8.3 (#6).
- Phase 7 (hosted) stays parked.

## How to resume in a code tool
Paste `BUILD_PLAN.md` (the phase you're on) + this `BUILD_LOG.md` into the tool
for full context. `docs/DEV_NOTES.md` has the architecture and file map. Keep the
sink pattern (controller never writes the DB directly) and the capture.js
"no WordPress references" rule intact.
