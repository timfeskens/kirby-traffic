<?php

declare(strict_types=1);

use Kirby\Cms\App as Kirby;
use Kirby\Exception\PermissionException;
use TimFeskens\Traffic\Traffic;

require_once __DIR__ . '/src/Traffic.php';

Kirby::plugin('timfeskens/traffic', [
    'options' => ['enabled' => true, 'hosts' => [], 'timezone' => 'Europe/Amsterdam'],
    'hooks' => [
        'page.render:after' => function ($page, $contentType, $html) {
            $kirby = kirby();
            try {
                $hosts = Traffic::hosts($kirby->option('timfeskens.traffic.hosts', []));
                if ($kirby->option('timfeskens.traffic.enabled') !== true
                    || $page->isErrorPage() || !$page->isPublished()
                    || !in_array($contentType, [null, 'html'], true)
                    || $kirby->response()->code() >= 300
                    || !Traffic::eligible($_SERVER, $hosts, $kirby->user() !== null)) {
                    return $html;
                }
                Traffic::record(
                    $kirby->root('logs') . '/traffic/views.sqlite',
                    Traffic::dimensions($_GET, $_SERVER['HTTP_REFERER'] ?? '', $page->isHomePage() ? '/' : $page->id(), $hosts),
                    $kirby->option('timfeskens.traffic.timezone', 'Europe/Amsterdam')
                );
            } catch (\Throwable) {
                // Analytics must never break page rendering or retry a failed write.
            }
            return $html;
        },
    ],
    'areas' => [
        'traffic-deprecated' => function () {
            return [
                'label' => 'Verkeer (deprecated)',
                'icon' => 'chart',
                'menu' => Traffic::isAdmin(kirby()->user()),
                'link' => 'traffic-deprecated',
                'views' => [[
                    'pattern' => 'traffic-deprecated/(:num?)',
                    'action' => function ($days = '30') {
                        if (!Traffic::isAdmin(kirby()->user())) {
                            throw new PermissionException('Alleen beheerders kunnen verkeersgegevens bekijken.');
                        }
                        return [
                            'component' => 'timfeskens-traffic-view',
                            'title' => 'Verkeer (deprecated)',
                            'props' => ['report' => Traffic::report(
                                kirby()->root('logs') . '/traffic/views.sqlite',
                                (int)$days,
                                kirby()->option('timfeskens.traffic.timezone', 'Europe/Amsterdam')
                            )],
                        ];
                    },
                ]],
            ];
        },
    ],
]);
