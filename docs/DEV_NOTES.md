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
  class-vantage-cli.php             wp vantage seed|clear
admin/
  class-vantage-admin.php           admin page (counts, pepper, iframe settings, demo data)
assets/js/
  capture.js                          framework-agnostic capture + form prep
  iframe-attribution.js               framework-agnostic iframe src rewrite + HubSpot fill
docs/                                 this folder
```

## Conventions

- Tables via `Vantage_Schema::table('visitors')`.
- Prepared statements for all queries with variables.
- Prefix: tables `vt_`, classes `Vantage_`, options `vantage_`.
- Bump `VANTAGE_VERSION` each phase; `VANTAGE_DB_VERSION` only on schema change.
