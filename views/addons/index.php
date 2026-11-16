<?php
require __DIR__ . "/../_nav.php";   // $navIcon
$meta = [
    'redis'       => ['Redis', 'bolt', 'addon.d_redis'],
    'meilisearch' => ['Meilisearch', 'search', 'addon.d_meilisearch'],
    'memcached'   => ['Memcached', 'stack', 'addon.d_memcached'],
    'fail2ban'    => ['Fail2ban', 'ban', 'addon.d_fail2ban'],
    'nodejs'      => ['Node.js', 'hexagon', 'addon.d_nodejs'],
];
?>
<h1 class="h1"><?= e(t('nav.addons_all')) ?></h1>
<p class="mb-6 max-w-3xl text-sm text-slate-500"><?= e(t('addon.intro')) ?></p>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
  <?php foreach ($meta as $k => [$title, $icon, $desc]):
    $a = $addons[$k] ?? ['installed' => false, 'active' => '', 'busy' => false, 'failed' => false, 'version' => ''];
    [$cls, $label] = match (true) {
        $a['busy'] => ['badge-warn', t('stack.working')],
        $a['failed'] && !$a['installed'] => ['badge-warn', t('addon.failed')],
        !$a['installed'] => ['bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400', t('stack.not_installed_short')],
        $k === 'nodejs' => ['badge-ok', t('addon.installed') . ($a['version'] !== '' ? ' · v' . $a['version'] : '')],
        $a['active'] === 'active' => ['badge-ok', t('addon.running')],
        default => ['badge-warn', t('addon.stopped')],
    };
  ?>
  <a href="/addons/<?= e($k) ?>" class="card group flex flex-col gap-3 transition hover:border-brand/40 hover:shadow-md">
    <div class="flex items-start justify-between gap-3">
      <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand"><?= $navIcon($icon) ?></span>
      <span class="badge <?= $cls ?>"><?= e($label) ?></span>
    </div>
    <div>
      <h2 class="text-base font-semibold text-slate-900 dark:text-white"><?= e($title) ?></h2>
      <p class="mt-1 text-sm text-slate-500"><?= e(t($desc)) ?></p>
    </div>
    <span class="mt-auto text-sm font-medium text-brand group-hover:underline"><?= e(t($a['installed'] ? 'addon.manage' : 'addon.install_open')) ?> &rarr;</span>
  </a>
  <?php endforeach; ?>
</div>
