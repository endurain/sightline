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

- No WordPress references in `capture.js` (CI check: grep must be empty).
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
  class-sightline-cli.php             wp sightline seed|clear
admin/
  class-sightline-admin.php           Phase 0 admin page
assets/js/
  capture.js                          framework-agnostic capture
docs/                                 this folder
```

## Conventions

- Tables via `Sightline_Schema::table('visitors')`.
- Prepared statements for all queries with variables.
- Prefix: tables `sl_`, classes `Sightline_`, options `sightline_`.
- Bump `SIGHTLINE_VERSION` each phase; `SIGHTLINE_DB_VERSION` only on schema change.
