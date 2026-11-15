<h1 class="h1"><?= e(t('nav.dashboard')) ?></h1>
<?php if (Havoc\Core\Auth::user()['role'] === 'admin' && ($newRel = Havoc\Services\UpdateService::available())): ?>
<a href="/server/settings#update" class="mb-6 flex items-center justify-between gap-3 rounded-xl border border-brand/30 bg-brand/5 px-4 py-3 text-sm hover:bg-brand/10">
  <span><span class="font-semibold"><?= e(t('update.banner', ['v' => $newRel['version']])) ?></span> <span class="text-slate-500"><?= e(t('update.banner_hint')) ?></span></span>
  <span class="text-brand">&rarr;</span></a>
<?php endif; ?>
<?php if ($info):
  $gb = static fn(int|float $b) => $b >= 1099511627776 ? round($b / 1099511627776, 2) . ' TB' : ($b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : round($b / 1048576) . ' MB');
  $up = (int)$info['uptime']; $upText = ($up >= 86400 ? intdiv($up, 86400) . t('dash.d') . ' ' : '') . intdiv($up % 86400, 3600) . t('dash.h') . ' ' . intdiv($up % 3600, 60) . t('dash.m');
  $bar = static fn(float $pct) => '<div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-1.5 rounded-full ' . ($pct >= 90 ? 'bg-rose-500' : ($pct >= 75 ? 'bg-amber-500' : 'bg-brand')) . '" data-pct="' . max(1, min(100, (int)round($pct))) . '"></div></div>';
  $memPct = $info['mem_total'] ? 100 * $info['mem_used'] / $info['mem_total'] : 0;
?>
<div class="card mb-6">
  <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <h2 class="h2 mb-0"><?= e(t('dash.server')) ?> <span class="ml-1 font-mono text-sm font-normal text-slate-500"><?= e($info['hostname']) ?></span></h2>
    <span class="flex gap-3 text-sm"><a class="link" href="/server"><?= e(t('nav.monitor')) ?></a><a class="link" href="/server/processes"><?= e(t('nav.processes')) ?></a></span>
  </div>
  <dl class="grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.ip')) ?></dt>
      <dd class="mt-0.5 space-y-0.5 font-mono"><?php foreach ($info['ips'] ?: ['–'] as $ip): ?><span class="block select-all break-all"><?= e($ip) ?></span><?php endforeach; ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.os')) ?></dt><dd class="mt-0.5 font-medium"><?= e($info['os']) ?></dd><dd class="text-xs text-slate-500"><?= e(t('dash.kernel')) ?> <?= e($info['kernel']) ?> · <?= e($info['arch']) ?></dd></div>
    <div><dt class="text-xs text-slate-500">CPU</dt><dd class="mt-0.5 truncate font-medium" title="<?= e($info['cpu']) ?>"><?= e($info['cpu']) ?></dd>
      <dd class="text-xs text-slate-500"><?= (int)$info['cores'] ?> <?= e(t('dash.cores')) ?> · <?= e(t('dash.load')) ?> <?= e(implode(' / ', array_map(static fn($l) => number_format($l, 2), $info['load']))) ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.uptime')) ?></dt><dd class="mt-0.5 font-medium"><?= e($upText) ?></dd><dd class="text-xs text-slate-500"><?= e(date('Y-m-d H:i', $info['time'])) ?> (<?= e($info['timezone']) ?>)</dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.memory')) ?></dt><dd class="mt-0.5"><span class="font-medium"><?= e($gb($info['mem_total'] - $info['mem_used'])) ?></span> <?= e(t('dash.free_of', ['total' => $gb($info['mem_total'])])) ?></dd>
      <?= $bar($memPct) ?><?php if ($info['swap_total']): ?><dd class="mt-1 text-xs text-slate-500">Swap: <?= e($gb($info['swap_used'])) ?> / <?= e($gb($info['swap_total'])) ?></dd><?php endif; ?></div>
    <?php foreach ($info['disks'] as $dk): ?>
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.disk')) ?> <span class="font-mono"><?= e($dk['mount']) ?></span></dt><dd class="mt-0.5"><span class="font-medium"><?= e($gb($dk['free'])) ?></span> <?= e(t('dash.free_of', ['total' => $gb($dk['total'])])) ?></dd><?= $bar((float)$dk['pct']) ?></div>
    <?php endforeach; ?>
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.software')) ?></dt>
      <dd class="mt-0.5"><?= $ws ? e($ws['name'] . ' ' . ($ws['version'] ?? '')) : '' ?></dd>
      <dd class="text-xs text-slate-500">PHP <?= e(implode(', ', $info['php'])) ?><?= $info['mariadb'] !== '' ? ' · MariaDB ' . e($info['mariadb']) : '' ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('dash.panel')) ?></dt><dd class="mt-0.5 font-medium">Havoc WEB Panel <?= e(Havoc\Services\UpdateService::current()) ?></dd></div>
  </dl>
</div>
<?php endif; ?>
<div class="grid gap-4 sm:grid-cols-3">
  <div class="card"><p class="text-sm text-slate-500"><?= e(t('nav.domains')) ?></p><p class="mt-1 text-3xl font-bold"><?= (int)$domains ?></p></div>
  <?php if ($users): ?><div class="card"><p class="text-sm text-slate-500"><?= e(t('nav.users')) ?></p><p class="mt-1 text-3xl font-bold"><?= (int)$users ?></p></div><?php endif; ?>
  <?php if ($ws && !$info): ?><div class="card"><p class="text-sm text-slate-500"><?= e(t('dash.webserver')) ?></p><p class="mt-1 text-xl font-bold"><?= e($ws['name']) ?> <?= e($ws['version'] ?? '') ?></p></div><?php endif; ?>
</div>
<?php if ($audit): ?>
<h2 class="h2 mt-8"><?= e(t('dash.audit')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.time')) ?></th><th><?= e(t('nav.users')) ?></th><th><?= e(t('common.action')) ?></th><th>IP</th></tr></thead>
  <tbody><?php foreach ($audit as $a): ?><tr><td><?= e($a['created_at']) ?></td><td><?= e((string)$a['username']) ?></td><td><?= e($a['action']) ?> <span class="text-slate-400"><?= e((string)$a['target']) ?></span></td><td><?= e((string)$a['ip']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
