# Sightline — Build Log

Running record of phase status, decisions, and issues. Update as you go.
See `BUILD_PLAN.md` (repo root) for the full phase plan.

## Phase status

| Phase | What | Status | Tested |
|-------|------|--------|--------|
| 0 | Scaffold, schema, seeder | Done | Yes — seeded 2,000 visitors / 4,165 touches / 428 conversions |
| 1 | Client-side capture (framework-agnostic JS) | Done | Yes — getTouches() showed 2 touches, first preserved, dedupe ok |
| 2 | REST ingestion endpoint + sink pattern | Done | Yes — POST /collect returned 200, `{"ok":true,"visitor_id":2002,"inserted":1}` |
| 3 | Forms + identity resolution + iframe attribution | Pass 1 built (forms/identity); pass 2 (iframe) pending | Pass 1 awaiting test |
| 4 | Reporting backend (incl. traffic/drop-off) | Not started | — |
| 5 | Admin dashboard (Sankey + traffic view) | Not started | — |
| 6 | Privacy, settings, retention, deletion | Not started | — |
| 7 | Hosted dashboard / SaaS | Parked | — |

## Current version: 0.4.0

## Decisions log

- **Sink pattern.** REST controller never writes to the DB directly; it calls a
  `Sightline_Sink`. Default is `Sightline_DB_Sink`; filter `sightline_sink` swaps
  it. This is what lets Phase 7 (hosted) reuse ingestion unchanged.
- **Email hashing.** `HMAC-SHA256(pepper, normalized_email)`. Email is normalized
  (lowercase + trim only — no gmail dot/plus stripping, too opinionated), hashed,
  and discarded. Never stored.
- **Converters only.** Only visitors who submit a form get a hashed_email.
  Anonymous visitors stay anon_id-only (cleaner privacy, nothing to tie to a name).
- **Pepper storage.** Prefer `SIGHTLINE_PEPPER` constant (wp-config) when the host
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

## Issues log

| # | Issue | Status |
|---|-------|--------|
| 1 | Chrome blocks console paste (self-XSS guard) during testing | Resolved — use Application > Storage panel, or type `allow pasting` |
| 2 | Full-page caches can serve a stale REST nonce to anonymous visitors | Open — Phase 6, switch to a cache-friendly token |
| 3 | anon_id hidden field injected at load only; forms added later (popups/AJAX) miss it | Open — Phase 3 pass 2 or later, add MutationObserver |
| 4 | Cross-device identity: same hashed_email on two anon_ids = two visitor rows | Open — stitch at reporting (Phase 4) or a later merge |

## Test environment

- Local WordPress (Local by WP Engine), Contact Form 7 for form testing.
- DB inspection via TablePlus / Adminer.

---

## HANDOFF NOTE (2026-11-19)

Development continues in a code tool / IDE from here. Code of record: the
`sightline/` plugin folder at version **0.4.0** (shipped as
`sightline-phase3pass1.zip`). Everything below is built and lints clean; the
Phase 3 pass-1 work is **built but NOT yet tested on a live site**. Start by
running the Phase 3 checklist, then continue with Phase 3 pass 2 (iframe).

## Testing checklist (state of each item)

### Phase 0 — scaffold / schema / seeder  — VERIFIED
- [x] Plugin activates with no errors; Sightline admin menu appears.
- [x] Tables created: `{prefix}_sl_visitors`, `_sl_touches`, `_sl_conversions`.
- [x] Seed button creates ~2,000 visitors / ~4,165 touches / ~428 conversions.
- [x] Pepper status shows "Database"; converters have 64-char hash, others NULL.
- [x] Clear button empties tables.

### Phase 1 — client capture  — VERIFIED
- [x] Visiting `?utm_source=...` sets an `sl_anon` cookie.
- [x] `Sightline.getTouches()` shows the touch; first touch preserved on 2nd campaign.
- [x] Refresh within 30 min does NOT add a duplicate touch.
- [x] `capture.js` contains zero WordPress references (build-checked).

### Phase 2 — REST ingestion  — VERIFIED
- [x] `POST /wp-json/sightline/v1/collect` returns 200 with
      `{"ok":true,"visitor_id":N,"inserted":M}`.
- [x] A real browser visit creates a NEW visitor row (id > 2000) and touch rows.
- [x] Server dedupes identical consecutive touches.

### Phase 3 pass 1 — forms + identity  — NOT YET TESTED (do this first)
- [ ] After reinstall, a page with a CF7 form contains a hidden
      `<input name="sl_anon_id" value="<uuid>">` (injected by capture.js).
- [ ] Submitting the form inserts a row in `_sl_conversions` with
      `form_type = 'cf7'` and a valid `visitor_id`.
- [ ] That visitor row now has a 64-char `hashed_email`.
- [ ] **PRIVACY CHECK:** search the ENTIRE database for the email you submitted.
      It must NOT appear in any Sightline table. (It may appear in the form
      plugin's own tables — that's the form plugin, not Sightline.)
- [ ] Re-submitting with a different email on the same browser does NOT overwrite
      the existing `hashed_email` (first identity wins).
- [ ] Repeat for Gravity Forms (`form_type = 'gravityforms'`) if available.
- [ ] Repeat for WPForms (`form_type = 'wpforms'`) if available.
- [ ] Submit with JS disabled / no `sl_anon_id`: handler bails cleanly, no PHP error.

### Cross-cutting checks
- [ ] No PHP notices/warnings with `WP_DEBUG` on during any of the above.
- [ ] Deactivate + reactivate keeps data intact (no teardown until Phase 6).
- [ ] Confirm `capture.js` loads with `?ver=0.4.0` (bust browser cache after reinstall).

## Known gaps / next work
- **Phase 3 pass 2 (NOT built):** iframe attribution — admin toggle, domain
  allowlist, pre-load `src` rewrite for Pardot, postMessage for HubSpot.
- **Phase 3 pass 1 limits (logged as issues #3, #4):** anon_id field injected at
  load only (dynamic/popup forms miss it); cross-device same-hash = two rows.
- Then Phases 4 (reporting incl. traffic/drop-off), 5 (Sankey dashboard),
  6 (privacy/settings/retention/deletion), 7 (hosted, parked).

## How to resume in a code tool
Paste `BUILD_PLAN.md` (the phase you're on) + this `BUILD_LOG.md` into the tool
for full context. `docs/DEV_NOTES.md` has the architecture and file map. Keep the
sink pattern (controller never writes the DB directly) and the capture.js
"no WordPress references" rule intact.
