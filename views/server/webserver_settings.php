<?php
/** @var array $tu  helper webserver.tuning: type, version, values, spec, sites, saved, cache_dir, cache_size */
$type = $tu['type'];
$v = $tu['values'];
$spec = $tu['spec'];
$name = Havoc\Services\WebServerService::NAMES[$type] ?? $type;
// field => [label key, hint key|null, unit]
$groups = [
    'nginx' => [
        'perf' => ['worker_processes' => ['wst.worker_processes', 'wst.h_worker_processes', ''], 'worker_connections' => ['wst.worker_connections', 'wst.h_worker_connections', ''],
            'keepalive_timeout' => ['wst.keepalive_timeout', 'wst.h_keepalive_timeout', 's'], 'keepalive_requests' => ['wst.keepalive_requests', null, ''],
            'timeout' => ['wst.timeout', 'wst.h_timeout', 's'], 'fastcgi_read_timeout' => ['wst.fastcgi_read_timeout', 'wst.h_fastcgi_read_timeout', 's'],
            'client_max_body_size' => ['wst.client_max_body_size', 'wst.h_client_max_body_size', 'MB'], 'open_file_cache' => ['wst.open_file_cache', 'wst.h_open_file_cache', '']],
        'gzip' => ['gzip' => ['wst.gzip', null, ''], 'gzip_level' => ['wst.gzip_level', 'wst.h_gzip_level', '']],
        'static' => ['static_expires' => ['wst.static_expires', 'wst.h_static_expires', 'wst.days']],
        'cache' => ['cache' => ['wst.cache_nginx', 'wst.h_cache_nginx', ''], 'cache_zone_mb' => ['wst.cache_zone_mb', 'wst.h_cache_zone_mb', 'MB'],
            'cache_max_mb' => ['wst.cache_max_mb', null, 'MB'], 'cache_inactive' => ['wst.cache_inactive', 'wst.h_cache_inactive', 'wst.minutes']],
    ],
    'ols' => [
        'perf' => ['max_connections' => ['wst.max_connections', null, ''], 'max_ssl_connections' => ['wst.max_ssl_connections', null, ''],
            'conn_timeout' => ['wst.conn_timeout', null, 's'], 'keepalive_timeout' => ['wst.keepalive_timeout', 'wst.h_keepalive_timeout', 's'],
            'max_keepalive_req' => ['wst.keepalive_requests', null, ''], 'max_req_body' => ['wst.client_max_body_size', 'wst.h_client_max_body_size', 'MB'],
            'php_conns' => ['wst.php_conns', 'wst.h_php_conns', ''], 'quic' => ['wst.quic', 'wst.h_quic', '']],
        'gzip' => ['gzip' => ['wst.gzip', null, ''], 'gzip_level' => ['wst.gzip_level', 'wst.h_gzip_level', ''], 'dyn_gzip' => ['wst.dyn_gzip', 'wst.h_dyn_gzip', '']],
        'static' => ['static_expires' => ['wst.static_expires', 'wst.h_static_expires', 'wst.days']],
        'cache' => ['cache' => ['wst.cache_ls', 'wst.h_cache_ols', ''], 'cache_max_obj' => ['wst.cache_max_obj', null, 'MB']],
    ],
    'lsws' => [
        'perf' => ['max_connections' => ['wst.max_connections', null, ''], 'max_ssl_connections' => ['wst.max_ssl_connections', null, ''],
            'conn_timeout' => ['wst.conn_timeout', null, 's'], 'keepalive_timeout' => ['wst.keepalive_timeout', 'wst.h_keepalive_timeout', 's'],
            'max_keepalive_req' => ['wst.keepalive_requests', null, ''], 'max_req_body' => ['wst.client_max_body_size', 'wst.h_client_max_body_size', 'MB'],
            'php_conns' => ['wst.php_conns', 'wst.h_php_conns', '']],
        'gzip' => ['gzip' => ['wst.gzip', null, ''], 'gzip_level' => ['wst.gzip_level', 'wst.h_gzip_level', ''], 'dyn_gzip' => ['wst.dyn_gzip', 'wst.h_dyn_gzip', '']],
        'static' => ['static_expires' => ['wst.static_expires', 'wst.h_static_expires', 'wst.days']],
        'cache' => ['cache' => ['wst.cache_ls', 'wst.h_cache_lsws', '']],
    ],
][$type];
$titles = ['perf' => 'wst.g_perf', 'gzip' => 'wst.g_gzip', 'static' => 'wst.g_static', 'cache' => 'wst.g_cache'];
$field = static function (string $k, array $def) use ($v, $spec): string {
    [$label, $hint, $unit] = $def;
    $s = $spec[$k];
    $unitTxt = $unit === '' ? '' : (str_contains($unit, '.') ? t($unit) : $unit);
    if ($s['min'] === null) {
        return '<label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="v[' . e($k) . ']" value="1"' . ($v[$k] ? ' checked' : '') . '>'
            . '<span>' . e(t($label)) . ($hint ? '<span class="block text-xs text-slate-500">' . e(t($hint)) . '</span>' : '') . '</span></label>';
    }
    $isAuto = $s['default'] === 'auto';
    $range = t('wst.range', ['min' => $s['min'], 'max' => $s['max']]) . ($isAuto ? ' ' . t('wst.or_auto') : '');
    return '<div><label class="label" for="f-' . e($k) . '">' . e(t($label)) . '</label><div class="flex items-center gap-2">'
        . '<input class="input" id="f-' . e($k) . '" name="v[' . e($k) . ']" ' . ($isAuto ? 'type="text" pattern="auto|[0-9]+"' : 'type="number" min="' . (int)$s['min'] . '" max="' . (int)$s['max'] . '"')
        . ' value="' . e((string)$v[$k]) . '" required>' . ($unitTxt !== '' ? '<span class="shrink-0 text-sm text-slate-500">' . e($unitTxt) . '</span>' : '') . '</div>'
        . '<p class="mt-1 text-xs text-slate-500">' . e(($hint ? t($hint) . ' ' : '') . $range . ' · ' . t('wst.default', ['v' => (string)$s['default']])) . '</p></div>';
};
$ttlDefault = $type === 'nginx' ? 60 : 120;
?>
<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
  <div>
    <a href="/server/webserver" class="text-sm text-slate-500 hover:text-brand">← <?= e(t('nav.webserver')) ?></a>
    <h1 class="h1 mb-0 mt-1"><?= e(t('wst.title', ['name' => $name])) ?></h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-500"><?= e(t('wst.intro', ['name' => $name])) ?><?= $tu['version'] !== '' ? ' ' . e(t('ws.version', ['v' => $tu['version']])) . '.' : '' ?></p>
  </div>
  <?php if (!$tu['saved']): ?><span class="badge badge-warn"><?= e(t('wst.not_saved')) ?></span><?php endif; ?>
</div>
<?php if ($switching): ?><div class="mb-6 rounded-xl border border-amber-300/60 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"><?= e(t('wst.switching')) ?></div><?php endif; ?>

<form method="post" action="/server/webserver/settings" class="space-y-6" data-confirm="<?= e(t('wst.confirm')) ?>"><?= csrf_field() ?>
  <div class="grid gap-6 xl:grid-cols-2">
  <?php foreach (['perf', 'gzip', 'static'] as $g): ?>
    <section class="card space-y-4 <?= $g === 'perf' ? 'xl:row-span-2' : '' ?>">
      <h2 class="h2 mb-0"><?= e(t($titles[$g])) ?></h2>
      <?php if ($g === 'static'): ?><p class="text-xs text-slate-500"><?= e(t('wst.static_intro')) ?></p><?php endif; ?>
      <div class="grid gap-4 sm:grid-cols-2">
        <?php foreach ($groups[$g] as $k => $def): ?><?= $field($k, $def) ?><?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  </div>

  <section class="card space-y-4" id="cache">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="h2 mb-0"><?= e(t('wst.g_cache')) ?></h2>
      <?php if ($tu['cache_dir'] !== null): ?>
        <div class="flex items-center gap-3 text-sm text-slate-500">
          <span><?= e(t('wst.cache_size', ['size' => bytes((int)$tu['cache_size'])])) ?></span>
          <button class="btn-ghost border border-slate-300 dark:border-slate-700" formaction="/server/webserver/cache-purge" formnovalidate data-confirm="<?= e(t('wst.purge_confirm')) ?>"><?= e(t('wst.purge')) ?></button>
        </div>
      <?php endif; ?>
    </div>
    <p class="max-w-4xl text-sm text-slate-600 dark:text-slate-300"><?= e(t('wst.cache_intro_' . ($type === 'nginx' ? 'nginx' : 'ls'))) ?></p>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <?php foreach ($groups['cache'] as $k => $def): ?><?= $field($k, $def) ?><?php endforeach; ?>
    </div>
    <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
      <table class="table text-sm">
        <thead><tr><th><?= e(t('wst.site')) ?></th><th><?= e(t('wst.page_cache')) ?></th><th><?= e(t('wst.ttl')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($sites as $s): $ttl = (int)($tu['sites'][(string)$s['id']] ?? 0); ?>
          <tr>
            <td><a class="link font-medium" href="/domains/<?= (int)$s['id'] ?>"><?= e($s['name']) ?></a>
              <span class="text-xs text-slate-500"> · <?= e($s['username']) ?><?= $s['app'] ? ' · ' . e(ucfirst($s['app'])) : '' ?></span></td>
            <td><label class="flex items-center gap-2"><input type="checkbox" name="site_on[<?= (int)$s['id'] ?>]" value="1"<?= $ttl > 0 ? ' checked' : '' ?>> <?= e(t('dsec.on')) ?></label></td>
            <td><div class="flex items-center gap-2"><input class="input w-28" type="number" min="1" max="604800" name="site_ttl[<?= (int)$s['id'] ?>]" value="<?= $ttl ?: $ttlDefault ?>"><span class="text-xs text-slate-500"><?= e(t('wst.seconds')) ?></span></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$sites): ?><tr><td colspan="3" class="text-slate-500"><?= e(t('wst.no_sites')) ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-500"><?= e(t('wst.cache_rules')) ?></p>
    <p class="text-xs text-slate-500"><?= e(t('wst.cache_check_' . ($type === 'nginx' ? 'nginx' : 'ls'))) ?></p>
  </section>

  <div class="flex flex-wrap items-center gap-3">
    <button class="btn"<?= $switching ? ' disabled' : '' ?>><?= e(t('wst.save')) ?></button>
    <span class="text-xs text-slate-500"><?= e(t('wst.save_hint_' . ($type === 'nginx' ? 'nginx' : 'ls'))) ?></span>
  </div>
</form>
