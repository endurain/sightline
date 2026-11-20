# Sightline — Developer Notes

## Architecture at a glance

```
Browser (capture.js, framework-agnostic)
   | POST /wp-json/sightline/v1/collect  { anon_id, touches[] }
   v
REST controller (class-sightline-rest.php)   <- validate + sanitize only
   | hands batch to
   v
Sink (Sightline_Sink)                          <- destination-agnostic
   - Sightline_DB_Sink (default): writes local WP tables
   - filter: sightline_sink  (Phase 7 swaps in a remote-API sink)
```

The controller never touches the DB. Everything destination-specific lives behind
the sink. Keep it that way.

### Forms and iframe attribution (Phase 3)

```
capture.js            sl_anon_id hidden field + fills existing sl_* CRM fields
                      (MutationObserver covers forms added later); fires
                      `sightline:ready`; exposes Sightline.getAttribution()/fillFields()
class-sightline-forms CF7 / Gravity / WPForms submit hooks -> hash email -> sink
                      (CF7: both wpcf7_mail_sent and wpcf7_mail_failed)

Iframe attribution (toggle: sightline_iframe_enabled, allowlist: sightline_iframe_domains)
class-sightline-iframe  output buffer: allowlisted <iframe src> -> data-sl-src +
                        data-sl-pending, + <noscript> copy, + inline 2.5s fail-safe
iframe-attribution.js   appends sl_first/last_utm_source|medium|campaign, sets src;
                        rewrites late-added iframes; HubSpot onFormReady -> fillFields
```

Never put attribution into server-rendered HTML: it would be cached and served to
other visitors. The server only defers; the browser fills.

## Data model

- `sl_visitors` — `id`, `anon_id` (unique), `hashed_email` (null until convert),
  `first_seen`, `last_seen`.
- `sl_touches` — `id`, `visitor_id`, `utm_*`, `click_ids` (json), `referrer`,
  `landing_url`, `touch_time`.
- `sl_conversions` — `id`, `visitor_id`, `form_id`, `form_type`, `page_url`,
  `conversion_time`.

Identity resolution: `anon_id` links touches before a form submit. On submit, the
hashed email is written to that visitor row, merging their history. Phase 3.

## Hashing

- `Sightline_Pepper::hash_email($email)` = `hash_hmac('sha256', strtolower(trim($email)), pepper)`.
- Raw email is discarded by the caller immediately. Never log it, never store it,
  never put it in an error message.
- Pepper: `SIGHTLINE_PEPPER` constant preferred, else autoload=off option.
- Rotating the pepper breaks all historical matching. Treat as permanent.

## Capture script rules

- No WordPress references in `capture.js` or `iframe-attribution.js` (code only;
  the explanatory header comment in `capture.js` mentions WordPress by name).
- Config comes in via `window.SightlineConfig` (endpoint, nonce, debug), injected
  with `wp_localize_script`. Defaults make it run with no config at all.
- Transport is a no-op when `cfg.endpoint` is absent.

## File map

```
sightline.php                         main loader, hooks
includes/
  class-sightline-schema.php          tables (dbDelta)
  class-sightline-pepper.php          pepper + hash_email
  class-sightline-activator.php       activate/deactivate
  class-sightline-seeder.php          synthetic data
  class-sightline-capture.php         enqueue capture.js + config
  interface-sightline-sink.php        Sightline_Sink
  class-sightline-db-sink.php         local-DB sink (+ dedupe)
  class-sightline-sinks.php           sink resolver (filter)
  class-sightline-rest.php            /collect endpoint
  class-sightline-forms.php           form hooks + identity resolution
  class-sightline-iframe.php          iframe deferral (output buffer) + settings helpers
  class-sightline-cli.php             wp sightline seed|clear
admin/
  class-sightline-admin.php           admin page (counts, pepper, iframe settings, demo data)
assets/js/
  capture.js                          framework-agnostic capture + form prep
  iframe-attribution.js               framework-agnostic iframe src rewrite + HubSpot fill
docs/                                 this folder
```

## Conventions

- Tables via `Sightline_Schema::table('visitors')`.
- Prepared statements for all queries with variables.
- Prefix: tables `sl_`, classes `Sightline_`, options `sightline_`.
- Bump `SIGHTLINE_VERSION` each phase; `SIGHTLINE_DB_VERSION` only on schema change.
