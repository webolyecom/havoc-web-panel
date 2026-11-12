<?php
/**
 * Sidebar definition. Groups are collapsible (<details>), items are filtered by role.
 * [href, label key, icon, minimum role, external]
 */
$navGroups = [
    ['', null, [
        ['/', 'nav.dashboard', 'home', 'user'],
    ]],
    ['sites', 'nav.g_sites', [
        ['/domains', 'nav.domains', 'globe', 'user'],
        ['/files', 'nav.files', 'folder', 'user'],
        ['/logs', 'nav.logs', 'list', 'user'],
        ['/traffic', 'nav.traffic', 'chart', 'user'],
        ['/cron', 'nav.cron', 'clock', 'user'],
    ]],
    ['data', 'nav.g_data', [
        ['/databases', 'nav.databases', 'db', 'user'],
        ['/phpmyadmin/', 'nav.phpmyadmin', 'table', 'user', true],
        ['/databases/tuning', 'nav.db_tuning', 'sliders', 'admin'],
        ['/backups', 'nav.backups', 'archive', 'user'],
    ]],
    ['access', 'nav.g_access', [
        ['/ftp', 'nav.ftp', 'upload', 'user'],
        ['/dns', 'nav.dns', 'dns', 'user'],
    ]],
    ['security', 'nav.g_security', [
        ['/waf', 'nav.waf', 'shield', 'user'],
        ['/firewall', 'nav.firewall', 'wall', 'admin'],
        ['/limits', 'nav.limits', 'gauge', 'user'],
    ]],
    ['server', 'nav.g_server', [
        ['/server', 'nav.monitor', 'pulse', 'admin'],
        ['/server/webserver', 'nav.webserver', 'web', 'admin'],
        ['/services', 'nav.services', 'server', 'admin'],
        ['/stack', 'nav.redis', 'stack', 'admin'],
        ['/server/settings', 'nav.panel_settings', 'cog', 'admin'],
    ]],
    ['accounts', 'nav.g_accounts', [
        ['/users', 'nav.users', 'users', 'reseller'],
        ['/packages', 'nav.packages', 'box', 'reseller'],
        ['/branding', 'nav.branding', 'brush', 'reseller'],
    ]],
];

/** 20x20 outline icons (own drawings, stroke = currentColor). */
$navIcon = static function (string $name): string {
    $p = [
        'home' => 'M3 9.5 10 4l7 5.5V16a1 1 0 0 1-1 1h-3.5v-4.5h-5V17H4a1 1 0 0 1-1-1z',
        'globe' => 'M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm-7-7h14M10 3c2 2 2.8 4.5 2.8 7S12 15 10 17c-2-2-2.8-4.5-2.8-7S8 5 10 3Z',
        'web' => 'M3 4h14v12H3zM3 7.5h14M5.5 5.75h.01M7.5 5.75h.01M7 11l-1.5 1.5L7 14M13 11l1.5 1.5L13 14M11 10.5 9 14.5',
        'folder' => 'M3 6a1 1 0 0 1 1-1h4l1.5 2H16a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z',
        'list' => 'M7 5h10M7 10h10M7 15h10M3.5 5h.01M3.5 10h.01M3.5 15h.01',
        'chart' => 'M3 16h14M5.5 13V9M10 13V5M14.5 13v-6',
        'clock' => 'M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14Zm0-10.5V10l2.5 1.5',
        'db' => 'M4 5c0-1.1 2.7-2 6-2s6 .9 6 2-2.7 2-6 2-6-.9-6-2Zm0 0v10c0 1.1 2.7 2 6 2s6-.9 6-2V5M4 10c0 1.1 2.7 2 6 2s6-.9 6-2',
        'table' => 'M3 4h14v12H3zM3 8h14M3 12h14M8 8v8',
        'sliders' => 'M4 6h8M15 6h1M4 14h2M9 14h7M12 4v4M6 12v4',
        'archive' => 'M3 4h14v3H3zM4 7v9h12V7M8 10.5h4',
        'upload' => 'M10 13V4m0 0L6.5 7.5M10 4l3.5 3.5M4 13v2.5a.5.5 0 0 0 .5.5h11a.5.5 0 0 0 .5-.5V13',
        'dns' => 'M4 4h12v4H4zM4 12h12v4H4zM7 6h.01M7 14h.01',
        'shield' => 'M10 3 4 5.5V10c0 3.5 2.6 6 6 7 3.4-1 6-3.5 6-7V5.5z',
        'wall' => 'M3 5h14v10H3zM3 10h14M7 5v5M13 5v5M10 10v5',
        'gauge' => 'M4 14a6 6 0 1 1 12 0M10 14l3-4',
        'pulse' => 'M3 10h3l2-5 4 10 2-5h3',
        'server' => 'M4 4h12v5H4zM4 11h12v5H4zM7 6.5h.01M7 13.5h.01',
        'stack' => 'M10 3 3 6.5 10 10l7-3.5zM3 10l7 3.5 7-3.5M3 13.5 10 17l7-3.5',
        'cog' => 'M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4',
        'users' => 'M7.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM2.5 16c0-2.8 2.2-4.5 5-4.5s5 1.7 5 4.5M13 4.2a2.5 2.5 0 0 1 0 4.6M14.5 11.7c1.8.5 3 2 3 4.3',
        'box' => 'M10 3 3.5 6.5v7L10 17l6.5-3.5v-7zM3.5 6.5 10 10l6.5-3.5M10 10v7',
        'brush' => 'M14 3.5 8 11l1.5 1.5L17 6.5zM8 11c-2 0-3 1.5-3 3s-1 2-2 2.5c2.5.5 6 0 6.5-3',
        'logout' => 'M8 4H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3M12 13.5 15.5 10 12 6.5M15.5 10H8',
        'panel' => 'M3 4h14v12H3zM8 4v12M13 8.5 11.5 10l1.5 1.5',
        'user' => 'M10 9.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 17c0-3.3 2.7-5 6-5s6 1.7 6 5',
    ][$name] ?? '';
    return '<svg viewBox="0 0 20 20" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $p . '"/></svg>';
};
