<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/index.php';

use TimFeskens\Traffic\Traffic;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "PASS: $message\n";
}

$hosts = ['example.test', 'www.example.test'];
$request = ['HTTP_HOST' => 'example.test', 'REQUEST_METHOD' => 'GET', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Safari/605.1.15', 'REQUEST_URI' => '/?utm_source=test'];
check(Traffic::eligible($request, $hosts, false), 'Public configured page counts');
check(!Traffic::eligible($request, [], false), 'Empty host allowlist disables collection');
check(Traffic::hosts([' EXAMPLE.TEST ', 'example.test', '', null]) === ['example.test'], 'Hosts normalize and deduplicate');
check(Traffic::eligible($request, [' EXAMPLE.TEST '], false), 'Case-insensitive configured host');
foreach ([['HTTP_HOST', 'localhost:8000'], ['REQUEST_METHOD', 'POST'], ['HTTP_DNT', '1'], ['HTTP_SEC_GPC', '1'], ['HTTP_USER_AGENT', 'Googlebot'], ['HTTP_USER_AGENT', ''], ['REQUEST_URI', '/panel'], ['REQUEST_URI', '/api/pages'], ['REQUEST_URI', '/healthz']] as [$key, $value]) {
    check(!Traffic::eligible(array_replace($request, [$key => $value]), $hosts, false), "Exclude $key=$value");
}
check(!Traffic::eligible($request, $hosts, true), 'Exclude logged-in editors');
check(!Traffic::isAdmin(null), 'Anonymous reports forbidden');
check(!Traffic::isAdmin(new class { public function isAdmin() { return false; } }), 'Non-admin reports forbidden');
check(Traffic::isAdmin(new class { public function isAdmin() { return true; } }), 'Admin reports allowed');
$query = ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn', 'email' => 'private@example.test'];
$dimensions = Traffic::dimensions($query, 'https://referrer.example.test/private?token=secret', '/', $hosts);
check($dimensions === ['source' => 'newsletter', 'medium' => 'email', 'campaign' => 'autumn', 'referrer' => 'referrer.example.test', 'path' => '/'], 'UTM attribution; referrer path/query and unknown parameters discarded');
foreach (['private@example.test', '<script>alert(1)</script>', ['array'], str_repeat('a', 81), 'https://example.test', "test\n"] as $label) {
    check(Traffic::label($label) === '', 'Reject invalid or sensitive UTM label');
}
check(Traffic::dimensions([], 'https://example.test/projects', '/', $hosts)['source'] === '(intern)', 'Internal source distinguished');
check(Traffic::dimensions([], 'http://example.test:8000/projects', '/', ['example.test:8000'])['source'] === '(intern)', 'Internal source on explicit port');
check(Traffic::dimensions([], '', '/', $hosts)['source'] === '(direct)', 'Missing referrer is direct');
check(Traffic::dimensions([], 'javascript:alert(1)', '/', $hosts)['referrer'] === '', 'Reject invalid referrer scheme');
$directory = sys_get_temp_dir() . '/kirby-traffic-test-' . bin2hex(random_bytes(8));
$file = $directory . '/views.sqlite';
try {
    check(Traffic::report($file, 30)['total'] === 0 && !file_exists($file), 'Empty reports do not create a database');
    Traffic::record($file, $dimensions);
    Traffic::record($file, $dimensions);
    Traffic::record($file, Traffic::dimensions([], '', '/projects', $hosts));
    $report = Traffic::report($file, 7);
    check($report['total'] === 3 && $report['tagged'] === 2, 'Daily counters aggregate correctly');
    check($report['campaigns'][0]['campaign'] === 'autumn' && $report['campaigns'][0]['views'] === 2, 'Campaign report preserves source, medium, path and count');
    check(count($report['sources']) === 2 && count($report['pages']) === 2 && count($report['daily']) === 1, 'Source, page and daily grouping');
    check(Traffic::report($file, 123)['days'] === 30, 'Period allowlist enforced');
    $db = new PDO('sqlite:' . $file);
    check(array_column($db->query('PRAGMA table_info(views)')->fetchAll(PDO::FETCH_ASSOC), 'name') === ['day', 'source', 'medium', 'campaign', 'referrer', 'path', 'views'], 'Schema stores only aggregate dimensions, never visitor identifiers');
    $db->exec("INSERT INTO views VALUES('2000-01-01','old','','','','/',1)");
    $db->exec("DELETE FROM metadata WHERE key='pruned'");
    Traffic::record($file, $dimensions);
    check((int)$db->query("SELECT COUNT(*) FROM views WHERE day='2000-01-01'")->fetchColumn() === 0, 'Retention removes expired counters');
    $day = $report['daily'][0]['day'];
    $insert = $db->prepare('INSERT OR IGNORE INTO views VALUES(?,?,?,?,?,?,1)');
    $db->beginTransaction();
    for ($i = 0; $i < Traffic::DAILY_DIMENSION_LIMIT; $i++) $insert->execute([$day, "source-$i", '', '', '', '/']);
    $db->commit();
    $before = (int)$db->query('SELECT COUNT(*) FROM views')->fetchColumn();
    Traffic::record($file, Traffic::dimensions(['utm_source' => 'overflow'], '', '/', $hosts));
    check((int)$db->query('SELECT COUNT(*) FROM views')->fetchColumn() === $before, 'Daily cardinality cap bounds storage');
    Traffic::record($file, $dimensions);
    check(Traffic::report($file, 90)['campaigns'][0]['views'] === 4, 'Existing counters still update at capacity');
    check(count(Traffic::report($file, 90)['sources']) === 100, 'Report rows bounded');
    $db = null;
    file_put_contents($directory . '/broken.sqlite', 'not sqlite');
    check(Traffic::report($directory . '/broken.sqlite', 30)['error'], 'Storage failures produce a safe report state');
    $utc = $directory . '/utc.sqlite';
    Traffic::record($utc, $dimensions, 'UTC');
    check(Traffic::report($utc, 7, 'UTC')['daily'][0]['day'] === gmdate('Y-m-d'), 'Configurable timezone applies to recording and reporting');
    check(Traffic::report($utc, 7, 'not-a-timezone')['error'], 'Invalid timezone produces a safe error');
} finally {
    foreach (glob($directory . '/*') ?: [] as $temporary) unlink($temporary);
    if (is_dir($directory)) rmdir($directory);
}

// Real Kirby registration, permissions, hooks and former database compatibility.
$fixture = sys_get_temp_dir() . '/kirby-traffic-app-' . bin2hex(random_bytes(8));
mkdir($fixture . '/content/home', 0700, true);
mkdir($fixture . '/content/error', 0700, true);
mkdir($fixture . '/content/_drafts/draft', 0700, true);
file_put_contents($fixture . '/content/site.txt', 'Title: Fictional traffic tests');
file_put_contents($fixture . '/content/home/home.txt', 'Title: Home');
file_put_contents($fixture . '/content/error/error.txt', 'Title: Error');
file_put_contents($fixture . '/content/_drafts/draft/default.txt', 'Title: Draft');
try {
    $kirby = new Kirby\Cms\App(['roots' => ['index' => $fixture], 'options' => [
        'url' => 'https://example.test', 'timfeskens.traffic.hosts' => $hosts,
    ]]);
    $kirby->site();
    $area = $kirby->extension('areas', 'traffic-deprecated')[0];
    $action = $area()['views'][0]['action'];
    foreach ([null, 'nobody'] as $identity) {
        $kirby->impersonate($identity);
        check($area()['menu'] === false, 'Panel menu hidden from non-admin');
        $denied = false;
        try { $action(); } catch (Kirby\Exception\PermissionException) { $denied = true; }
        check($denied, 'Actual Panel action denies ' . ($identity ?? 'anonymous'));
    }
    $kirby->impersonate('kirby');
    check($area()['menu'] === true, 'Panel menu visible for admin');
    check($area()['link'] === 'traffic-deprecated', 'Separate deprecated sidebar item');
    check($action('7')['props']['report']['days'] === 7, 'Actual admin Panel action returns period report');
    $kirby->impersonate(null);
    $_SERVER = array_replace($_SERVER, $request);
    $_GET = $query;
    $render = fn ($page = null, $type = 'html') => $kirby->apply('page.render:after', ['page' => $page ?? $kirby->site()->homePage(), 'contentType' => $type, 'html' => '<p>fixture</p>', 'data' => []], 'html');
    check($render() === '<p>fixture</p>', 'Tracking preserves rendered HTML');
    $file = $fixture . '/site/logs/traffic/views.sqlite';
    check(Traffic::report($file, 30)['total'] === 1, 'Actual render hook records campaign');
    $kirby->impersonate('kirby');
    check($action()['props']['report']['campaigns'][0]['source'] === 'newsletter', 'Panel reads compatible persistent database path');
    $kirby->impersonate(null);
    $_SERVER['HTTP_DNT'] = '1';
    $render();
    check(Traffic::report($file, 30)['total'] === 1, 'Actual hook honors Do Not Track');
    unset($_SERVER['HTTP_DNT']);
    $_SERVER['HTTP_SEC_GPC'] = '1';
    $render();
    check(Traffic::report($file, 30)['total'] === 1, 'Actual hook honors Global Privacy Control');
    unset($_SERVER['HTTP_SEC_GPC']);
    $kirby->impersonate('kirby');
    $render();
    check(Traffic::report($file, 30)['total'] === 1, 'Actual hook excludes authenticated admins');
    $kirby->impersonate(null);
    $render($kirby->site()->errorPage());
    $render($kirby->site()->draft('draft'));
    $render(null, 'json');
    $kirby->response()->code(404);
    $render();
    check(Traffic::report($file, 30)['total'] === 1, 'Errors, drafts, non-HTML and unsuccessful responses excluded');
    $kirby->response()->code(200);
    // Corrupt only disposable fixture storage to prove fail-open rendering.
    file_put_contents($file, 'not sqlite');
    check($render() === '<p>fixture</p>', 'Storage errors cannot break public HTML');
    $kirby->impersonate('kirby');
    check($action()['props']['report']['error'], 'Actual Panel action exposes safe storage failure');
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $temporary) $temporary->isDir() ? rmdir($temporary->getPathname()) : unlink($temporary->getPathname());
    rmdir($fixture);
}
echo "$checks traffic checks passed.\n";
