<?php
// Site pages: key => [icon, title key, group]. Used by the overview tiles and the page switcher.
// Keys without a "/domains/{id}/<key>" page of their own (files, logs) link elsewhere.
$siteSections = [
    'files'     => ['folder', 'files.title', 'site'],
    'logs'      => ['doc', 'domains.logs', 'site'],
    'wordpress' => ['wp', 'app.title', 'site'],
    'php'       => ['php', 'dsec.php', 'php'],
    'php-ini'   => ['sliders', 'phpini.title', 'php'],
    'ssl'       => ['lock', 'dsec.ssl', 'security'],
    'security'  => ['shield', 'domains.security', 'security'],
    'nginx'     => ['code', 'nginx.title', 'security'],
    'aliases'   => ['link', 'domains.aliases', 'domains'],
    'redirects' => ['redirect', 'domains.redirects', 'domains'],
    'databases' => ['db', 'nav.databases', 'data'],
    'search'    => ['search', 'search.title', 'data'],
    'delete'    => ['trash', 'dsec.delete', 'danger'],
];
$siteHref = static fn(array $d, string $k): string => match ($k) {
    'files' => '/domains/' . (int)$d['id'] . '/files',
    'logs' => '/logs/view?domain_id=' . (int)$d['id'] . '&type=access',
    default => '/domains/' . (int)$d['id'] . '/' . $k,
};
