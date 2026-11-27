<?php
/**
 * Sidebar definition. Groups are collapsible (<details>), items are filtered by role.
 * [href, label key, icon, minimum role, external, also active under (prefixes), search words (label keys), shown only when installed]
 * The groups after the 'server' divider are the server administration (admins only).
 */
$navGroups = [
    ['', null, [
        ['/', 'nav.dashboard', 'home', 'user'],
    ]],
    ['sites', 'nav.g_sites', [
        ['/domains', 'nav.domains', 'globe', 'user'],
        ['/apps', 'nav.apps', 'rocket', 'admin', false, [], ['app.k_words']],
        ['/files', 'nav.files', 'folder', 'user'],
        ['/ftp', 'nav.ftp', 'upload', 'user'],
        ['/dns', 'nav.dns', 'dns', 'user'],
        ['/cron', 'nav.cron', 'clock', 'user'],
    ]],
    ['data', 'nav.g_data', [
        ['/databases', 'nav.databases', 'db', 'user'],
        ['/phpmyadmin/', 'nav.phpmyadmin', 'table', 'user', true, [], [], 'pma'],
        ['/backups', 'nav.backups', 'archive', 'user'],
    ]],
    ['stats', 'nav.g_stats', [
        ['/traffic', 'nav.traffic', 'chart', 'user'],
        ['/logs', 'nav.logs', 'list', 'user'],
        ['/limits', 'nav.limits', 'gauge', 'user'],
    ]],
    ['security', 'nav.g_security', [
        ['/waf', 'nav.waf', 'shield', 'user'],
        ['/firewall', 'nav.firewall', 'wall', 'admin'],
        ['/addons/fail2ban', 'Fail2ban', 'ban', 'admin', false, [], [], 'fail2ban'],
    ]],
    ['accounts', 'nav.g_accounts', [
        ['/users', 'nav.users', 'users', 'reseller'],
        ['/packages', 'nav.packages', 'box', 'reseller'],
        ['/branding', 'nav.branding', 'brush', 'reseller'],
    ]],
    ['-', 'nav.s_server', []],
    ['monitor', 'nav.g_monitor', [
        ['/server', 'nav.monitor', 'pulse', 'admin'],
        ['/server/resources', 'nav.resources', 'cpu', 'admin'],
        ['/server/processes', 'nav.processes', 'list', 'admin'],
        ['/services', 'nav.services', 'server', 'admin'],
        ['/server/updates', 'nav.os_updates', 'download', 'admin', false, [], ['osu.k_words']],
        ['/server/notifications', 'nav.notifications', 'bell', 'admin'],
    ]],
    ['config', 'nav.g_config', [
        ['/server/webserver', 'nav.webserver', 'web', 'admin', false, [], ['nav.ws_settings', 'nav.t_ws_perf']],
        ['/server/php', 'nav.php_versions', 'php', 'admin', false, [], ['phpv.k_words']],
        ['/databases/tuning', 'nav.mariadb', 'sliders', 'admin', false, ['/server/mysql'], ['nav.db_tuning', 'nav.mysql_procs']],
    ]],
    ['addons', 'nav.g_addons', [
        ['/addons', 'nav.addons_all', 'grid', 'admin'],
        ['/addons/redis', 'Redis', 'bolt', 'admin', false, [], [], 'redis'],
        ['/addons/meilisearch', 'Meilisearch', 'search', 'admin', false, [], [], 'meilisearch'],
        ['/addons/memcached', 'Memcached', 'stack', 'admin', false, [], [], 'memcached'],
        ['/addons/nodejs', 'Node.js', 'hexagon', 'admin', false, [], [], 'nodejs'],
    ]],
    ['panel', 'nav.g_panel', [
        ['/transfers', 'nav.transfers', 'transfer', 'admin'],
        ['/server/settings', 'nav.panel_settings', 'cog', 'admin'],
    ]],
];

/** Cheap checks (file exists) for menu entries of optional software. */
$navInstalled = static function (string $what): bool {
    static $cache = [];
    return $cache[$what] ??= match ($what) {
        'pma' => is_file('/opt/havoc-pma/index.php'),
        'redis' => is_file('/usr/bin/redis-server'),
        'meilisearch' => is_file('/usr/local/bin/meilisearch'),
        'memcached' => is_file('/usr/bin/memcached'),
        'fail2ban' => is_file('/usr/bin/fail2ban-server'),
        'nodejs' => is_file('/usr/bin/node'),
        default => true,
    };
};

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
        'eye' => 'M2 10s3-6 8-6 8 6 8 6-3 6-8 6-8-6-8-6zM10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
        'rocket' => 'M10 3c3 1 4.5 3.5 4.5 7l-2 2H7.5l-2-2C5.5 6.5 7 4 10 3zM10 7.5a1.2 1.2 0 100 2.4 1.2 1.2 0 000-2.4zM7.5 14l-2 3 3-1M12.5 14l2 3-3-1',
        'download' => 'M10 3v10m0 0-4-4m4 4 4-4M4 15v2h12v-2',
        'pencil' => 'M13.5 3.5l3 3L7 16H4v-3l9.5-9.5zM11.5 5.5l3 3',
        'cpu' => 'M6 6h8v8H6zM8.5 8.5h3v3h-3zM8 3v3M12 3v3M8 14v3M12 14v3M3 8h3M3 12h3M14 8h3M14 12h3',
        'server' => 'M4 4h12v5H4zM4 11h12v5H4zM7 6.5h.01M7 13.5h.01',
        'grid' => 'M3.5 3.5h5v5h-5zM11.5 3.5h5v5h-5zM3.5 11.5h5v5h-5zM11.5 11.5h5v5h-5z',
        'bolt' => 'M11 2.5 4.5 11H10l-1 6.5L15.5 9H10z',
        'search' => 'M9 15A6 6 0 1 0 9 3a6 6 0 0 0 0 12Zm4.5-1.5L17 17',
        'ban' => 'M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM5 5l10 10',
        'hexagon' => 'M10 2.5 16.5 6.25v7.5L10 17.5l-6.5-3.75v-7.5zM8 8v4M8 10h4M12 8v4',
        'transfer' => 'M3.5 7h11l-3-3M16.5 13h-11l3 3',
        'bell' => 'M5 14V9a5 5 0 0 1 10 0v5l1.5 1.5h-13zM8.5 17.5h3',
        'stack' => 'M10 3 3 6.5 10 10l7-3.5zM3 10l7 3.5 7-3.5M3 13.5 10 17l7-3.5',
        'cog' => 'M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M4.7 15.3l1.4-1.4M13.9 6.1l1.4-1.4',
        'users' => 'M7.5 9a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM2.5 16c0-2.8 2.2-4.5 5-4.5s5 1.7 5 4.5M13 4.2a2.5 2.5 0 0 1 0 4.6M14.5 11.7c1.8.5 3 2 3 4.3',
        'box' => 'M10 3 3.5 6.5v7L10 17l6.5-3.5v-7zM3.5 6.5 10 10l6.5-3.5M10 10v7',
        'brush' => 'M14 3.5 8 11l1.5 1.5L17 6.5zM8 11c-2 0-3 1.5-3 3s-1 2-2 2.5c2.5.5 6 0 6.5-3',
        'logout' => 'M8 4H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3M12 13.5 15.5 10 12 6.5M15.5 10H8',
        'panel' => 'M3 4h14v12H3zM8 4v12M13 8.5 11.5 10l1.5 1.5',
        'user' => 'M10 9.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 17c0-3.3 2.7-5 6-5s6 1.7 6 5',
        'lock' => 'M5 9h10v8H5zM7.5 9V6.5a2.5 2.5 0 0 1 5 0V9M10 12.25v1.5',
        'code' => 'M7 6 3 10l4 4M13 6l4 4-4 4M11.5 4.5l-3 11',
        'link' => 'M8.5 11.5a3 3 0 0 0 4.2 0l2.6-2.6a3 3 0 0 0-4.2-4.2l-1 1M11.5 8.5a3 3 0 0 0-4.2 0l-2.6 2.6a3 3 0 0 0 4.2 4.2l1-1',
        'redirect' => 'M4 16v-6a3.5 3.5 0 0 1 3.5-3.5H16M12.5 3l3.5 3.5-3.5 3.5',
        'trash' => 'M3.5 6h13M8 6V3.5h4V6M5 6l.8 10.5h8.4L15 6M8.5 9v5M11.5 9v5',
        'doc' => 'M5 2.5h7l3 3V17.5H5zM12 2.5v3h3M7.5 10h5M7.5 13h5',
        'wp' => 'M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM5.5 7.5 8 14.5l2-5 2 5 2.5-7',
        'php' => 'M2.5 10c0-2.8 3.4-5 7.5-5s7.5 2.2 7.5 5-3.4 5-7.5 5-7.5-2.2-7.5-5ZM5.5 12.5l1-5h1.6a1.25 1.25 0 0 1 0 2.5H6.5M12 12.5l1-5h1.6a1.25 1.25 0 0 1 0 2.5H13M9.5 7.5l-1 5M9 10h2.5l-.5 2.5',
        'external' => 'M11.5 3.5h5v5M16.5 3.5 9 11M14 11.5V16.5H3.5V6H8.5',
        'arrow-left' => 'M16 10H4M8.5 5.5 4 10l4.5 4.5',
    ][$name] ?? '';
    return '<svg viewBox="0 0 20 20" class="h-[18px] w-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $p . '"/></svg>';
};
