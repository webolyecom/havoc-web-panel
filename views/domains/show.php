<?php
require __DIR__ . '/../_nav.php';        // $navIcon
require __DIR__ . '/_sections.php';      // $siteSections, $siteHref
$id = (int)$d['id'];
[$sslCls, $sslTxt] = Havoc\Services\SslService::describe($d);
$https = $d['ssl_mode'] !== 'none';
$url = ($https ? 'https://' : 'http://') . $d['name'];
$on = static fn(bool $b): string => $b ? t('dsec.on') : t('dsec.off');
$flags = array_keys(array_filter(['HTTPS' => $d['force_https'], 'HSTS' => $d['hsts'], 'WAF' => $d['waf']]));
// short status per tile: [text, badge class or '']
$status = [
    'files' => [t('dsec.d_files'), ''],
    'logs' => [$d['logs_enabled'] ? t('dsec.d_logs') : t('dsec.logs_off'), ''],
    'wordpress' => match ($app['status'] ?? null) {
        'installed' => ['WordPress ' . (string)$app['version'], 'badge-ok'],
        'installing' => [t('app.st_installing'), 'badge-warn'],
        'failed' => [t('app.st_failed'), 'badge-err'],
        default => [t('dsec.d_wordpress'), ''],
    },
    'php' => ['PHP ' . $d['php_version'] . ' · OPcache ' . $on((bool)$d['opcache_enabled']), ''],
    'php-ini' => [$counts['ini'] ? t('dsec.n_changed', ['n' => $counts['ini']]) : t('dsec.defaults'), ''],
    'ssl' => $https ? [$d['ssl_mode'] === 'letsencrypt' ? "Let's Encrypt" : ucfirst($d['ssl_mode']), 'badge-ok']
        : [$sslTxt !== '' ? $sslTxt : t('dsec.no_cert'), 'badge-warn'],
    'security' => [$flags ? implode(' · ', $flags) : t('dsec.d_security'), ''],
    'nginx' => [$counts['rules'] ? t('dsec.n_rules', ['n' => $counts['rules']]) : t('dsec.none'), ''],
    'aliases' => [$counts['aliases'] ? t('dsec.n_aliases', ['n' => $counts['aliases']]) : t('dsec.none'), ''],
    'redirects' => [$counts['redirects'] ? t('dsec.n_redirects', ['n' => $counts['redirects']]) : t('dsec.none'), ''],
    'databases' => [$counts['dbs'] ? t('dsec.n_dbs', ['n' => $counts['dbs']]) : t('dsec.none'), ''],
    'search' => $searchOn ? [t('search.on'), 'badge-ok'] : [t('dsec.off'), ''],
    'delete' => [t('dsec.d_delete'), ''],
];
$groups = ['site' => 'dsec.g_site', 'php' => 'dsec.g_php', 'security' => 'dsec.g_security', 'domains' => 'dsec.g_domains', 'data' => 'dsec.g_data'];
?>
<?php if (($app['status'] ?? '') === 'installing' || $d['ssl_auto'] === 'running'): ?><div data-autorefresh></div><?php endif; ?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <div class="flex min-w-0 items-center gap-3">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand"><?= $navIcon('globe') ?></span>
    <div class="min-w-0"><h1 class="h1 mb-0 truncate"><?= e($d['name']) ?></h1>
      <p class="text-sm text-slate-500"><?= e(t('domains.type_' . $d['type'])) ?> · <?= e($owner) ?> · <span class="badge <?= $d['status'] === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e($d['status']) ?></span></p></div>
  </div>
  <div class="flex flex-wrap gap-2">
    <a class="btn-ghost inline-flex items-center gap-1.5 border border-slate-300 dark:border-slate-700" href="<?= e($url) ?>" target="_blank" rel="noopener"><?= $navIcon('external') ?> <?= e(t('dsec.visit')) ?></a>
    <form method="post" action="/domains/<?= $id ?>/upload-link"><?= csrf_field() ?><button class="btn-ghost inline-flex items-center gap-1.5 border border-slate-300 dark:border-slate-700"><?= $navIcon('upload') ?> <?= e(t('upload.make_link')) ?></button></form>
    <a class="btn inline-flex items-center gap-1.5" href="/domains/<?= $id ?>/files"><?= $navIcon('folder') ?> <?= e(t('files.title')) ?></a>
  </div>
</div>

<?php $ul = $_SESSION['upload_link'] ?? null; if ($ul && (int)$ul['domain'] === $id): unset($_SESSION['upload_link']); ?>
<div class="mb-6 space-y-1 rounded-xl border border-brand/30 bg-brand/5 p-4 text-sm">
  <p class="font-semibold"><?= e(t('upload.link_ready', ['t' => $ul['expires']])) ?></p>
  <code class="block select-all break-all rounded bg-white px-2 py-1 font-mono text-xs dark:bg-slate-900"><?= e($ul['url']) ?></code>
  <p class="text-xs text-slate-500"><?= e(t('upload.link_hint')) ?></p>
</div>
<?php endif; ?>

<dl class="card mb-8 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
  <div><dt class="text-xs text-slate-500">PHP</dt><dd class="font-medium"><?= e($d['php_version']) ?></dd></div>
  <div><dt class="text-xs text-slate-500">SSL</dt><dd><span class="badge <?= $https ? 'badge-ok' : 'badge-warn' ?>"><?= e($https ? $d['ssl_mode'] : t('dsec.no_cert')) ?></span><?php if ($sslTxt !== ''): ?><span class="mt-1 block text-xs text-slate-500"><?= e($sslTxt) ?></span><?php endif; ?></dd></div>
  <div><dt class="text-xs text-slate-500"><?= e(t('app.title')) ?></dt><dd class="font-medium"><?= e(($app['status'] ?? '') === 'installed' ? 'WordPress ' . $app['version'] : '—') ?></dd></div>
  <div><dt class="text-xs text-slate-500"><?= e(t('nav.databases')) ?></dt><dd class="font-medium"><?= (int)$counts['dbs'] ?></dd></div>
  <div class="sm:col-span-2 xl:col-span-4"><dt class="text-xs text-slate-500"><?= e(t('dsec.docroot')) ?></dt><dd class="break-all font-mono text-xs"><?= e((string)($d['docroot'] ?: '—')) ?></dd></div>
</dl>

<div class="grid gap-6 lg:grid-cols-2">
<?php foreach ($groups as $g => $gk): ?>
  <section class="card p-5">
    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500"><?= e(t($gk)) ?></h2>
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
    <?php foreach ($siteSections as $k => [$ic, $tk, $grp]): if ($grp !== $g || ($k === 'search' && $searchOn === null)) continue; [$st, $bc] = $status[$k]; ?>
      <a href="<?= e($siteHref($d, $k)) ?>" class="group flex flex-col items-center gap-2 rounded-xl px-2 py-3 text-center transition hover:bg-brand/5 dark:hover:bg-white/[0.04]">
        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-brand/10 text-brand transition group-hover:bg-brand group-hover:text-white [&>svg]:h-6 [&>svg]:w-6"><?= $navIcon($ic) ?></span>
        <span class="text-sm font-medium leading-tight text-slate-800 group-hover:text-brand dark:text-slate-100"><?= e(t($tk)) ?></span>
        <?php if ($bc !== ''): ?><span class="badge <?= $bc ?> max-w-full truncate"><?= e($st) ?></span>
        <?php else: ?><span class="text-xs leading-tight text-slate-500"><?= e($st) ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<div class="mt-10 border-t border-slate-200 pt-4 dark:border-slate-800">
  <a href="/domains/<?= $id ?>/delete" class="inline-flex items-center gap-1.5 text-sm text-red-600 hover:underline"><?= $navIcon('trash') ?> <?= e(t('dsec.delete')) ?></a>
</div>
