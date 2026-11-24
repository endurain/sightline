# Vantage

Privacy-first multi-touch attribution for WordPress. Captures UTM / ad-click
touches, stores them first-party, ties repeat visits to a hashed identity (email
is hashed with a server-side pepper, then discarded), and will visualize the path
to conversion.

**Status: v0.6.0, Phases 0–4 built.** Capture, REST ingestion, form integration
with identity resolution (CF7, Gravity Forms, WPForms), iframe form attribution
for Pardot/HubSpot embeds, and the reporting API (attribution, Sankey data,
traffic and drop-off) at `/wp-json/vantage/v1/reports/*`. The admin dashboard
(Phase 5) is next. See `docs/BUILD_LOG.md` for status and test results.

## Install (local testing)

1. Copy the `vantage` folder into `wp-content/plugins/`.
2. In wp-admin, go to **Plugins** and activate **Vantage**.
3. A new **Vantage** menu item appears in the admin sidebar.

## Test that Phase 0 works

1. Open the **Vantage** admin page. You should see three tables (Visitors,
   Touches, Conversions) all at 0, and a Pepper status of "Database".
2. Click **Seed demo data**. After a few seconds the counts should jump to
   ~2,000 visitors, several thousand touches, and a few hundred conversions.
3. Confirm the tables exist in the database (TablePlus / Adminer):
   `wp_vt_visitors`, `wp_vt_touches`, `wp_vt_conversions`
   (your table prefix may differ from `wp_`).
4. Spot-check `wp_vt_visitors`: converters have a 64-char `hashed_email`,
   non-converters have `NULL`. No raw email appears anywhere.
5. **Clear data** empties the tables.

## WP-CLI (optional)

    wp vantage seed --count=2000
    wp vantage clear

## Pepper notes

- By default the pepper is generated on activation and stored as an autoload=off
  option (works on locked-down hosts like WP Engine).
- For stronger isolation on hosts that allow wp-config edits, define:
  `define( 'VANTAGE_PEPPER', 'your-long-random-string' );`
  The plugin prefers the constant when present.
- Changing the pepper breaks historical identity matching. Treat it as permanent.

## What's next

- Phase 5: admin dashboard (Sankey + traffic view).
- Phase 6: privacy, settings, retention, deletion.
- Phase 7 (parked): hosted dashboard / SaaS.
