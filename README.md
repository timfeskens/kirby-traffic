# Kirby Traffic

Cookie-free page-view and UTM reports in Kirby 5 Panel. Independent of any SEO
plugin or analytics service. MIT licensed; PHP 8.4+, PDO SQLite, Kirby 5.6.1+.
The Panel area is called **Verkeer** and currently uses Dutch labels.

## Install

Add this VCS repository to your project's `composer.json`:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/timfeskens/kirby-traffic"}
  ],
  "require": {"timfeskens/kirby-traffic": "^0.1"}
}
```

Run `composer update timfeskens/kirby-traffic`. Composer installs the plugin into
`site/plugins/timfeskens-traffic`. No asset build step, credentials, external
endpoint, tracking script or service is required. For manual installation, copy
the release into that directory (Kirby and PDO SQLite must already be installed).

Configure an explicit production host allowlist in `site/config/config.php`:

```php
return [
    'timfeskens.traffic' => [
        'enabled' => true,
        'hosts' => ['example.com', 'www.example.com'],
        'timezone' => 'Europe/Amsterdam',
    ],
];
```

The allowlist is **empty by default**: installing does not start collection.
Use hostnames without schemes/paths; include a port only when needed. Do not add
local development hosts unless you intentionally want to count those requests.
Set `enabled` to `false` to stop collection without deleting reports.

Open **Verkeer** as an administrator. Choose 7, 30 or 90 days and use
**Vernieuwen** to load fresh totals. Menu visibility and the report action both
enforce administrator access. No public reporting API is exposed.

## Attribution and privacy

Example: `https://example.com/?utm_source=newsletter&utm_medium=email&utm_campaign=autumn`.
Reports show page views by source/medium, campaign + landing page, referring
hostname, page and day. Use short non-personal slug labels: letters, numbers,
dots, underscores and hyphens, up to 80 characters. Unknown query parameters are
ignored. Referrers retain only a hostname, never a path or query string.

- Page views, **not unique visitors or sessions**; reloads count again.
- No cookies, browser storage, IP addresses, stored user agents or visitor IDs.
- UTMs apply only to the opened URL, not later navigation. Internal referrals
  show `(intern)` and missing sources show `(direct)`.
- Logged-in editors, non-GET requests, errors, non-HTML responses, known bots,
  Do Not Track and Global Privacy Control requests are excluded.
- To test your own link, use a private browser without a Panel session and with
  DNT/GPC off. The report is a snapshot, not a real-time feed.
- Server/CDN full-page caches that bypass Kirby rendering also bypass tracking.
  Spoofed bots and privacy tools mean counts remain indicative.
- 180-day retention, at most 2,000 new dimension combinations per day. At capacity
  existing counters still increment; new combinations are dropped. Tables show
  up to 100 groups; daily reports show the whole selected period.

## Storage, deployment and migration

Data stays in `kirby()->root('logs') . '/traffic/views.sqlite'`, usually
`site/logs/traffic/views.sqlite`. Keep logs private, blocked by your web server,
ignored by Git, included in private backups and persisted across deployments.
Storage failures never break public HTML; the Panel shows an error for an
unreadable or invalid database. Missing databases show an empty report without
creating one. No data is imported, fabricated or deleted during installation.

When migrating the former `timfs/traffic` plugin, remove its code to avoid
duplicate counting, replace `timfs.traffic.*` config keys with
`timfeskens.traffic.*`, and retain the existing logs mount. The database path and
schema are unchanged; existing totals remain available without a migration.
The Panel area remains `/panel/traffic`; its old page-view report is now in
**Verkeer (deprecated)** at `/panel/traffic-deprecated`. Do not install the old
plugin alongside this one: its reporting and collection code are already included.

In multi-stage Docker builds, copy the Composer-installed plugin from the
dependencies stage into the runtime image. Do not rely on an ignored local copy:

```dockerfile
COPY --from=dependencies /app/site/plugins/timfeskens-traffic ./site/plugins/timfeskens-traffic
```

Changing the timezone affects day boundaries for new views; stored day labels
are not rewritten. There is no built-in purge endpoint; deletion is a separate,
explicit operation with a backup, never part of a release.

## Development

```sh
composer install
composer lint
composer test
npm ci
npx playwright install chromium
npm test
```

Tests use fictional records and isolated temporary storage, not production data.
Browser tests exercise the real Kirby Panel at desktop and phone widths.
CI runs PHP 8.4 and 8.5. Published versions use `v0.1.x` tags.
## Search Console and global SEO settings

Google Search Console charts and the global SEO sidebar editor are provided by
[`timfeskens/kirby-seo`](https://github.com/timfeskens/kirby-seo), not this plugin.
The SEO plugin owns `/panel/traffic` and works without this legacy plugin.
This plugin only adds **Verkeer (deprecated)** at `/panel/traffic-deprecated`
and preserves the existing cookie-free page-view/UTM collection and SQLite data.
