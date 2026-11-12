<h1 class="h1"><?= e(t('nav.monitor')) ?></h1>
<div id="monitor" data-url="/server/stats" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
  <?php foreach (['cpu' => 'CPU', 'mem' => 'RAM', 'disk' => t('mon.disk')] as $k => $label): ?>
  <div class="card"><p class="text-sm text-slate-500"><?= e($label) ?></p><p class="mt-1 text-3xl font-bold" data-v="<?= $k ?>">–</p>
    <div class="mt-3 h-2 rounded bg-slate-200 dark:bg-slate-800"><div class="h-2 rounded bg-brand" data-bar="<?= $k ?>"></div></div></div>
  <?php endforeach; ?>
  <div class="card"><p class="text-sm text-slate-500">Load average</p><p class="mt-1 text-3xl font-bold" data-v="load">–</p></div>
  <div class="card"><p class="text-sm text-slate-500"><?= e(t('mon.network')) ?></p><p class="mt-1 text-xl font-bold">↓ <span data-v="rx">–</span> · ↑ <span data-v="tx">–</span></p></div>
  <div class="card"><p class="text-sm text-slate-500"><?= e(t('mon.uptime')) ?></p><p class="mt-1 text-xl font-bold" data-v="uptime">–</p></div>
</div>
