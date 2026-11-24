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
| 4 | Reporting backend (incl. traffic/drop-off) | Not started | — |
| 5 | Admin dashboard (Sankey + traffic view) | Not started | — |
| 6 | Privacy, settings, retention, deletion | Not started | — |
| 7 | Hosted dashboard / SaaS | Parked | — |

## Current version: 0.5.0

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

## Issues log

| # | Issue | Status |
|---|-------|--------|
| 1 | Chrome blocks console paste (self-XSS guard) during testing | Resolved — use Application > Storage panel, or type `allow pasting` |
| 2 | Full-page caches can serve a stale REST nonce to anonymous visitors | Open — Phase 6, switch to a cache-friendly token |
| 3 | anon_id hidden field injected at load only; forms added later (popups/AJAX) miss it | Resolved in 0.5.0 — `capture.js` MutationObserver prepares forms added after load (verified) |
| 4 | Cross-device identity: same hashed_email on two anon_ids = two visitor rows | Open — stitch at reporting (Phase 4) or a later merge |
| 5 | CF7 conversions only hook `wpcf7_mail_sent`; if the notification email fails (`mail_failed`), the conversion is silently dropped (reproduced 2026-11-19) | Resolved in 0.4.1 — `Vantage_Forms` now also hooks `wpcf7_mail_failed`; verified a forced mail failure records the conversion |
| 6 | Current CF7 (6.2+) requires PHP 8.3; Local test site runs PHP 8.2, so testing used CF7 6.1.4 | Note — bump site PHP to test against current CF7 |
| 7 | Pass 1 never filled the `vt_first_*` / `vt_last_*` CRM fields the user guide promised; only `vt_anon_id` was injected | Resolved in 0.5.0 — `capture.js` `fillFields()` fills existing fields (verified on CF7 `[hidden]` fields) |
| 8 | Iframe attribution only tested against stubbed Pardot/HubSpot responses | Open — verify with a real Pardot form (prefill from URL) and a HubSpot share link / embed before shipping |
| 9 | Iframes outside the main HTML response (added by page-builder JS) aren't deferred, so they may load twice | Known limit — documented; acceptable |

## Test environment

- Local WordPress (Local by WP Engine), PHP 8.2. Contact Form 7 6.1.4 and
  WPForms Lite for form testing. Test pages: `/vantage-form-test/`,
  `/vantage-wpforms-test/`, `/vantage-iframe-test/`.
- DB inspection via TablePlus / Adminer, or Local's bundled `mysql` + WP-CLI.
- Browser tests: headless Chrome via puppeteer-core, with request interception
  stubbing Pardot/HubSpot/YouTube so iframe loads can be counted.

---

## HANDOFF NOTE (2026-11-19)

Development continues in a code tool / IDE from here. Code of record: the
`vantage/` plugin folder at version **0.4.0** (shipped as
`vantage-phase3pass1.zip`). Everything below is built and lints clean; the
Phase 3 pass-1 work is **built but NOT yet tested on a live site**. Start by
running the Phase 3 checklist, then continue with Phase 3 pass 2 (iframe).

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

## Known gaps / next work
- **Phase 3 is complete** (0.5.0). Before shipping, verify iframe attribution
  against a live Pardot form and HubSpot form (issue #8).
- **Open limits:** cross-device same-hash = two rows (issue #4, Phase 4);
  stale nonce under page caching (issue #2, Phase 6).
- Then Phases 4 (reporting incl. traffic/drop-off), 5 (Sankey dashboard),
  6 (privacy/settings/retention/deletion), 7 (hosted, parked).

## How to resume in a code tool
Paste `BUILD_PLAN.md` (the phase you're on) + this `BUILD_LOG.md` into the tool
for full context. `docs/DEV_NOTES.md` has the architecture and file map. Keep the
sink pattern (controller never writes the DB directly) and the capture.js
"no WordPress references" rule intact.
