<?php

declare(strict_types=1);

namespace TimFeskens\Traffic;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Cookie-free daily counters: never stores an IP, user agent, or visitor ID. */
final class Traffic
{
    public const RETENTION_DAYS = 180;
    public const DAILY_DIMENSION_LIMIT = 2000;

    public static function isAdmin($user): bool
    {
        return $user !== null && $user->isAdmin() === true;
    }

    /** Explicit allowlist only; no automatic collection on development hosts. */
    public static function hosts(array $hosts): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($host) => is_string($host) ? strtolower(trim($host)) : '',
            $hosts
        ))));
    }

    /** Only count public HTML requests, not editors or known bots. */
    public static function eligible(array $server, array $hosts, bool $authenticated): bool
    {
        $host = strtolower($server['HTTP_HOST'] ?? '');
        $agent = $server['HTTP_USER_AGENT'] ?? '';
        return !$authenticated
            && ($server['REQUEST_METHOD'] ?? '') === 'GET'
            && in_array($host, self::hosts($hosts), true)
            && ($server['HTTP_DNT'] ?? '') !== '1'
            && ($server['HTTP_SEC_GPC'] ?? '') !== '1'
            && $agent !== ''
            && !preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|monitor|uptime|curl|wget|python|facebookexternalhit|httpclient/i', $agent)
            && !preg_match('~^/(panel|api|media|public|favicon|site|kirby|healthz|__health)(/|$)~i', parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    }

    /** UTM labels are short slugs; reject URLs, email addresses and arbitrary text. */
    public static function label($value): string
    {
        return is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\z/D', $value)
            ? strtolower($value) : '';
    }

    public static function dimensions(array $query, string $referrer, string $path, array $hosts): array
    {
        $origin = parse_url(substr($referrer, 0, 2048));
        $host = strtolower($origin['host'] ?? '');
        if (!in_array(strtolower($origin['scheme'] ?? ''), ['http', 'https'], true)
            || !preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D', $host)) {
            $host = '';
        }
        // Match HTTP_HOST's explicit port when sites use a non-default port.
        $authority = $host . (isset($origin['port']) ? ':' . $origin['port'] : '');
        $internal = in_array($authority, self::hosts($hosts), true);
        $source = self::label($query['utm_source'] ?? null);
        return [
            'source' => $source ?: ($internal ? '(intern)' : ($host ?: '(direct)')),
            'medium' => self::label($query['utm_medium'] ?? null) ?: ($host && !$internal ? 'referral' : ''),
            'campaign' => self::label($query['utm_campaign'] ?? null),
            'referrer' => $internal ? '' : $host,
            // Supplied by Kirby's page model, never the raw request/query string.
            'path' => substr('/' . ltrim($path, '/'), 0, 255),
        ];
    }

    private static function day(string $timezone): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    }

    private static function connect(string $file): PDO
    {
        $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=300');
        return $db;
    }

    public static function record(string $file, array $dimensions, string $timezone = 'Europe/Amsterdam'): void
    {
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
            throw new \RuntimeException('Traffic storage unavailable');
        }
        $db = self::connect($file);
        chmod($file, 0600);
        $db->exec('CREATE TABLE IF NOT EXISTS views (
            day TEXT NOT NULL, source TEXT NOT NULL, medium TEXT NOT NULL,
            campaign TEXT NOT NULL, referrer TEXT NOT NULL, path TEXT NOT NULL,
            views INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY(day, source, medium, campaign, referrer, path)
        ) WITHOUT ROWID');
        $db->exec('CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $day = self::day($timezone);
        $db->exec('BEGIN IMMEDIATE');
        try {
            $values = [$day, $dimensions['source'], $dimensions['medium'], $dimensions['campaign'], $dimensions['referrer'], $dimensions['path']];
            $update = $db->prepare('UPDATE views SET views = views + 1 WHERE day=? AND source=? AND medium=? AND campaign=? AND referrer=? AND path=?');
            $update->execute($values);
            if ($update->rowCount() === 0) {
                $count = $db->prepare('SELECT COUNT(*) FROM views WHERE day=?');
                $count->execute([$day]);
                if ((int)$count->fetchColumn() < self::DAILY_DIMENSION_LIMIT) {
                    $db->prepare('INSERT INTO views(day,source,medium,campaign,referrer,path) VALUES(?,?,?,?,?,?)')->execute($values);
                }
            }
            if ($db->query("SELECT value FROM metadata WHERE key='pruned'")->fetchColumn() !== $day) {
                $cutoff = (new DateTimeImmutable($day))->modify('-' . (self::RETENTION_DAYS - 1) . ' days')->format('Y-m-d');
                $db->prepare('DELETE FROM views WHERE day < ?')->execute([$cutoff]);
                $db->prepare("INSERT OR REPLACE INTO metadata(key,value) VALUES('pruned',?)")->execute([$day]);
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    public static function report(string $file, int $days, string $timezone = 'Europe/Amsterdam'): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $report = ['days' => $days, 'timezone' => $timezone, 'total' => 0, 'tagged' => 0, 'sources' => [], 'campaigns' => [], 'pages' => [], 'referrers' => [], 'daily' => [], 'error' => false];
        if (!is_file($file)) return $report;
        try {
            $db = self::connect($file);
            $db->exec('PRAGMA query_only=ON');
            $end = self::day($timezone);
            $start = (new DateTimeImmutable($end))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
            $range = [$start, $end];
            $total = $db->prepare("SELECT COALESCE(SUM(views),0) AS total, COALESCE(SUM(CASE WHEN campaign<>'' THEN views ELSE 0 END),0) AS tagged FROM views WHERE day BETWEEN ? AND ?");
            $total->execute($range);
            $totals = $total->fetch(PDO::FETCH_ASSOC);
            $report['total'] = (int)$totals['total'];
            $report['tagged'] = (int)$totals['tagged'];
            foreach ([
                'sources' => "SELECT source, medium, SUM(views) AS views FROM views WHERE day BETWEEN ? AND ? GROUP BY source, medium ORDER BY views DESC, source LIMIT 100",
                'campaigns' => "SELECT campaign, source, medium, path, SUM(views) AS views FROM views WHERE day BETWEEN ? AND ? AND campaign<>'' GROUP BY campaign, source, medium, path ORDER BY views DESC, campaign LIMIT 100",
                'pages' => 'SELECT path, SUM(views) AS views FROM views WHERE day BETWEEN ? AND ? GROUP BY path ORDER BY views DESC, path LIMIT 100',
                'referrers' => "SELECT referrer, SUM(views) AS views FROM views WHERE day BETWEEN ? AND ? AND referrer<>'' GROUP BY referrer ORDER BY views DESC, referrer LIMIT 100",
                'daily' => 'SELECT day, SUM(views) AS views FROM views WHERE day BETWEEN ? AND ? GROUP BY day ORDER BY day',
            ] as $key => $sql) {
                $query = $db->prepare($sql);
                $query->execute($range);
                $report[$key] = $query->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable) {
            $report['error'] = true;
        }
        return $report;
    }
}
