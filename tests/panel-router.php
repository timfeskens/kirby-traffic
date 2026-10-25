<?php

declare(strict_types=1);

// Isolated loopback-only fixture. Never used by consuming sites.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/index.php';

$root = getenv('TRAFFIC_PANEL_TEST_ROOT');
if (!$root || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/kirby/') || preg_match('~^/media/panel/[^/]+/(.*)$~', $path, $matches)) {
    $file = realpath(dirname(__DIR__) . (isset($matches[1]) ? '/kirby/panel/dist/' . $matches[1] : $path));
    $assets = realpath(dirname(__DIR__) . '/kirby/panel/dist') . '/';
    if ($file && str_starts_with($file, $assets) && is_file($file)) {
        header('Content-Type: ' . match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'application/javascript', 'css' => 'text/css', 'woff2' => 'font/woff2', default => 'application/octet-stream',
        });
        readfile($file);
        return;
    }
    http_response_code(404);
    return;
}
$kirby = new Kirby\Cms\App([
    'roots' => ['index' => $root],
    'urls' => ['index' => 'http://' . $_SERVER['HTTP_HOST']],
    'options' => ['debug' => true, 'panel.install' => false, 'api.allowImpersonation' => true],
    'users' => [['id' => 'traffic-test', 'email' => 'traffic@example.test', 'role' => 'admin', 'language' => 'nl']],
    'site' => ['content' => ['title' => 'Fictional traffic tests'], 'children' => [
        ['slug' => 'home', 'content' => ['title' => 'Home']],
        ['slug' => 'error', 'content' => ['title' => 'Error']],
    ]],
]);
$kirby->impersonate('traffic-test');
if ($path === '/fixture/seed') {
    \TimFeskens\Traffic\Traffic::record($kirby->root('logs') . '/traffic/views.sqlite', [
        'source' => '<script>window.pwned=true</script>', 'medium' => 'email',
        'campaign' => 'fictional-autumn', 'referrer' => 'example.test', 'path' => '/fictional',
    ]);
    echo 'ok';
    return;
}
if ($path === '/fixture/corrupt') {
    file_put_contents($kirby->root('logs') . '/traffic/views.sqlite', 'not sqlite');
    echo 'ok';
    return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
echo $kirby->render();
