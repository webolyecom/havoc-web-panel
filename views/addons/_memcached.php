<?php $on = ($d['active'] ?? '') === 'active'; ?>
<div class="card space-y-4">
  <div class="flex items-center justify-between"><h2 class="h2 mb-0">Memcached <?php if (!empty($d['version'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($d['version']) ?></span><?php endif; ?></h2>
    <?php if (!empty($d['installed']) && !$busy): ?><span class="badge <?= $on ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($on ? 'addon.running' : 'addon.stopped')) ?></span><?php endif; ?></div>
<?php if (empty($d['installed'])): ?>
  <p class="text-sm text-slate-500"><?= e(t('addon.mc_install_hint')) ?></p>
  <?php if (!$busy): ?><form method="post" action="/addons/memcached/install"><?= csrf_field() ?><button class="btn"><?= e(t('addon.install', ['name' => 'Memcached'])) ?></button></form><?php endif; ?>
<?php elseif (!$busy): ?>
  <?php if ($on): ?>
  <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
    <div><dt class="text-xs text-slate-500"><?= e(t('stack.redis_mem')) ?></dt><dd class="font-medium"><?= e(bytes((int)$d['bytes'])) ?> / <?= e(bytes((int)$d['limit'])) ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('addon.mc_items')) ?></dt><dd class="font-medium"><?= (int)$d['items'] ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('addon.mc_hits')) ?></dt><dd class="font-medium"><?= $d['hit_rate'] === null ? '–' : e($d['hit_rate'] . ' %') ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('stack.redis_clients')) ?></dt><dd class="font-medium"><?= (int)$d['clients'] ?></dd></div>
  </dl>
  <?php endif; ?>
  <div class="flex flex-wrap gap-2">
    <form method="post" action="/addons/memcached/service/<?= $on ? 'deactivate' : 'activate' ?>"><?= csrf_field() ?><button class="<?= $on ? 'btn-danger' : 'btn' ?>"><?= e(t($on ? 'stack.deactivate' : 'stack.activate')) ?></button></form>
    <form method="post" action="/addons/memcached/service/restart"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('svc.restart')) ?></button></form>
    <?php if ($on): ?><form method="post" action="/addons/memcached/flush" data-confirm="<?= e(t('addon.mc_flush_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('addon.mc_flush')) ?></button></form><?php endif; ?>
  </div>
  <form method="post" action="/addons/memcached/settings" class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800"><?= csrf_field() ?>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label class="label"><?= e(t('addon.mc_memory')) ?></label><input class="input" type="number" name="memory" min="16" max="<?= (int)max(16, intdiv((int)$d['mem_mb'], 2)) ?>" value="<?= (int)$d['config']['memory'] ?>"></div>
      <div><label class="label"><?= e(t('addon.mc_conns')) ?></label><input class="input" type="number" name="conns" min="64" max="65535" value="<?= (int)$d['config']['conns'] ?>"></div>
    </div>
    <button class="btn"><?= e(t('common.save')) ?></button>
    <p class="text-xs text-slate-500"><?= e(t('addon.mc_save_hint')) ?></p>
  </form>
  <p class="text-xs text-slate-500"><?= e(t('addon.mc_conn')) ?><?php if (!empty($d['php_ext'])): ?> <?= e(t('stack.redis_php', ['v' => implode(', ', $d['php_ext'])])) ?><?php endif; ?></p>
<?php endif; ?>
</div>
