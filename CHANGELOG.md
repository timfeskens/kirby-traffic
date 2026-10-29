# Changelog

## 0.1.1 — 2026-10-29

- Move the legacy page-view/UTM report to its own **Verkeer (deprecated)**
  sidebar area at `/panel/traffic-deprecated`, freeing `/panel/traffic` for the
  SEO plugin's Search Console dashboard.
- Preserve existing tracking settings, database path, schema and stored counts.
- Clarify plugin ownership and migration in the README; test the new routes.

## 0.1.0

- Extract the administrator-only Verkeer Panel area into an independent MIT plugin.
- Cookie-free daily page-view counters, sources, media, UTM campaigns, referrers and pages.
- Preserve the existing SQLite schema, storage location, privacy filters and retention.
- Explicit host allowlist (empty by default) and configurable reporting timezone.
- PHP regression tests, real Panel browser checks and PHP 8.4/8.5 CI.
