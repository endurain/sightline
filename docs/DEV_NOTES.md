# Vantage — Developer Notes

## Architecture at a glance

```
Browser (capture.js, framework-agnostic)
   | POST /wp-json/vantage/v1/collect  { anon_id, touches[] }
   v
REST controller (class-vantage-rest.php)   <- validate + sanitize only
   | hands batch to
   v
Sink (Vantage_Sink)                          <- destination-agnostic
   - Vantage_DB_Sink (default): writes local WP tables
   - filter: vantage_sink  (Phase 7 swaps in a remote-API sink)
```

The controller never touches the DB. Everything destination-specific lives behind
the sink. Keep it that way.

### Forms and iframe attribution (Phase 3)

```
capture.js            vt_anon_id hidden field + fills existing vt_* CRM fields
                      (MutationObserver covers forms added later); fires
                      `vantage:ready`; exposes Vantage.getAttribution()/fillFields()
class-vantage-forms CF7 / Gravity / WPForms submit hooks -> hash email -> sink
                      (CF7: both wpcf7_mail_sent and wpcf7_mail_failed)

Iframe attribution (toggle: vantage_iframe_enabled, allowlist: vantage_iframe_domains)
class-vantage-iframe  output buffer: allowlisted <iframe src> -> data-vt-src +
                        data-vt-pending, + <noscript> copy, + inline 2.5s fail-safe
iframe-attribution.js   appends vt_first/last_utm_source|medium|campaign, sets src;
                        rewrites late-added iframes; HubSpot onFormReady -> fillFields
```

Never put attribution into server-rendered HTML: it would be cached and served to
other visitors. The server only defers; the browser fills.

### Reporting (Phase 4)

```
GET /wp-json/vantage/v1/reports/{summary|conversions|attribution|sankey|traffic|dropoff|filters}
    common: start, end (YYYY-MM-DD, site tz), group_by=source|campaign,
            campaign (exact utm_campaign), channel (exact "source / medium")
    filters: campaign + channel options in range, people counts (ignores filters)
    conversions: page, per_page   sankey: max_steps, include_nonconverters
    dropoff: quiet_days           auth: manage_options (cookie + X-WP-Nonce)

class-vantage-rest-admin  route + arg validation -> Vantage_Reports::{report}()
class-vantage-reports     load_people(): people touched/converted in range, all
                            their touches + conversions, stitched by hashed_email
                            classify(): touch -> source/medium/campaign/channel
                            journeys(): conversion + path (touches <= conversion)
```

Reports read the local tables directly (they are not on the ingestion path, so
the sink rule doesn't apply). Never return `hashed_email` from a report.
Sankey nodes: `{id: "step|label", label, step, value}`; links reference ids.

### Dashboard (Phase 5)

```
Vantage > Dashboard (toplevel_page_vantage)   Vantage_Admin::render_dashboard(): mount point
  enqueue (this hook only): dashboard.css, vendor/echarts, dashboard.js
  window.VantageDashboard = { api, nonce, today, timezone, settingsUrl }
dashboard.js  filters (URL hash) -> 6 parallel GETs (summary, sankey, attribution,
              traffic, dropoff, filters) -> tiles + two tabs
              Sankey: foldSankey() keeps the 7 biggest channels, rest -> "Other"
              (sums only); fixed node order; labels by node height
              everything else: HTML/CSS tables + bars, shared .vt-tip tooltip
Vantage > Settings (vantage_page_vantage-settings)  the pre-0.7 admin page
```

Channel colors: `CHANNEL_PALETTE` + `assignSlots()` / `colorOf()` in dashboard.js.
Slots persist in localStorage (`vantage.channelSlots.v1`); never color by rank.

Labels are visitor data: build DOM with `textContent` (the `h()` helper), and
`esc()` anything handed to an ECharts tooltip formatter. Brand tokens and the
validated data colors are CSS variables at the top of `dashboard.css`.

## Data model

- `vt_visitors` — `id`, `anon_id` (unique), `hashed_email` (null until convert),
  `first_seen`, `last_seen`.
- `vt_touches` — `id`, `visitor_id`, `utm_*`, `click_ids` (json), `referrer`,
  `landing_url`, `touch_time`.
- `vt_conversions` — `id`, `visitor_id`, `form_id`, `form_type`, `page_url`,
  `conversion_time`.

Identity resolution: `anon_id` links touches before a form submit. On submit, the
hashed email is written to that visitor row, merging their history. Phase 3.

## Hashing

- `Vantage_Pepper::hash_email($email)` = `hash_hmac('sha256', strtolower(trim($email)), pepper)`.
- Raw email is discarded by the caller immediately. Never log it, never store it,
  never put it in an error message.
- Pepper: `VANTAGE_PEPPER` constant preferred, else autoload=off option.
- Rotating the pepper breaks all historical matching. Treat as permanent.

## Capture script rules

- No WordPress references in `capture.js` or `iframe-attribution.js` (code only;
  the explanatory header comment in `capture.js` mentions WordPress by name).
- Config comes in via `window.VantageConfig` (endpoint, nonce, debug), injected
  with `wp_localize_script`. Defaults make it run with no config at all.
- Transport is a no-op when `cfg.endpoint` is absent.

## File map

```
vantage.php                         main loader, hooks
includes/
  class-vantage-schema.php          tables (dbDelta)
  class-vantage-pepper.php          pepper + hash_email
  class-vantage-activator.php       activate/deactivate
  class-vantage-seeder.php          synthetic data
  class-vantage-capture.php         enqueue capture.js + config
  interface-vantage-sink.php        Vantage_Sink
  class-vantage-db-sink.php         local-DB sink (+ dedupe)
  class-vantage-sinks.php           sink resolver (filter)
  class-vantage-rest.php            /collect endpoint
  class-vantage-forms.php           form hooks + identity resolution
  class-vantage-iframe.php          iframe deferral (output buffer) + settings helpers
  class-vantage-reports.php         report computations (Phase 4)
  class-vantage-rest-admin.php      admin-only /reports/* endpoints
  class-vantage-cli.php             wp vantage seed|clear
admin/
  class-vantage-admin.php           Dashboard page + Settings page (counts, pepper,
                                      iframe settings, demo data); scoped enqueue
assets/js/
  capture.js                          framework-agnostic capture + form prep
  iframe-attribution.js               framework-agnostic iframe src rewrite + HubSpot fill
  dashboard.js                        admin dashboard (Phase 5)
assets/css/
  dashboard.css                       dashboard styles + brand tokens
assets/vendor/echarts/                ECharts 6.1.0 + LICENSE/NOTICE files
docs/                                 this folder
```

## Conventions

- Tables via `Vantage_Schema::table('visitors')`.
- Prepared statements for all queries with variables.
- Prefix: tables `vt_`, classes `Vantage_`, options `vantage_`.
- Bump `VANTAGE_VERSION` each phase; `VANTAGE_DB_VERSION` only on schema change.
