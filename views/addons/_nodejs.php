<?php $majors = $d['majors'] ?? ['22', '24', '26']; $globals = $d['available_globals'] ?? ['pm2', 'yarn', 'pnpm']; $gdesc = ['pm2' => 'addon.node_g_pm2', 'yarn' => 'addon.node_g_yarn', 'pnpm' => 'addon.node_g_pnpm']; ?>
<div class="card space-y-4">
  <div class="flex items-center justify-between"><h2 class="h2 mb-0">Node.js <?php if (!empty($d['node'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($d['node']) ?></span><?php endif; ?></h2>
    <?php if (!empty($d['installed']) && !$busy): ?><span class="badge badge-ok"><?= e(t('addon.installed')) ?></span><?php endif; ?></div>
<?php if (empty($d['installed'])): ?>
  <p class="text-sm text-slate-500"><?= e(t('addon.node_install_hint')) ?></p>
  <?php if (!$busy): ?>
  <form method="post" action="/addons/nodejs/install" class="space-y-3"><?= csrf_field() ?>
    <div class="max-w-xs"><label class="label"><?= e(t('addon.node_version')) ?></label>
      <select class="input" name="version"><?php foreach ($majors as $m): ?><option value="<?= e($m) ?>" <?= $m === '24' ? 'selected' : '' ?>>Node.js <?= e($m) ?> LTS<?= $m === '24' ? ' — ' . e(t('addon.recommended')) : '' ?></option><?php endforeach; ?></select></div>
    <div><p class="label"><?= e(t('addon.node_globals')) ?></p>
      <?php foreach ($globals as $g): ?><label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="globals[]" value="<?= e($g) ?>"> <span><span class="font-mono"><?= e($g) ?></span> <span class="text-xs text-slate-500"><?= e(t($gdesc[$g])) ?></span></span></label><?php endforeach; ?></div>
    <button class="btn"><?= e(t('addon.install', ['name' => 'Node.js'])) ?></button>
  </form>
  <?php endif; ?>
<?php elseif (!$busy): ?>
  <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
    <div><dt class="text-xs text-slate-500">Node.js</dt><dd class="font-mono font-medium"><?= e($d['node'] ?: '–') ?></dd></div>
    <div><dt class="text-xs text-slate-500">npm</dt><dd class="font-mono font-medium"><?= e($d['npm'] ?: '–') ?></dd></div>
    <div><dt class="text-xs text-slate-500"><?= e(t('addon.node_source')) ?></dt><dd class="font-medium"><?= e($d['nodesource'] ? 'NodeSource (' . $d['major'] . '.x)' : t('addon.node_distro')) ?></dd></div>
  </dl>
  <form method="post" action="/addons/nodejs/install" class="flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800" data-confirm="<?= e(t('addon.node_switch_confirm')) ?>"><?= csrf_field() ?>
    <div><label class="label"><?= e(t('addon.node_switch')) ?></label>
      <select class="input w-auto" name="version"><?php foreach ($majors as $m): ?><option value="<?= e($m) ?>" <?= $m === (string)$d['major'] ? 'selected' : '' ?>>Node.js <?= e($m) ?> LTS</option><?php endforeach; ?></select></div>
    <?php foreach (array_keys($d['globals'] ?? []) as $g): ?><input type="hidden" name="globals[]" value="<?= e($g) ?>"><?php endforeach; ?>
    <button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('addon.node_switch_btn')) ?></button>
  </form>
  <form method="post" action="/addons/nodejs/globals" class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800"><?= csrf_field() ?>
    <p class="label"><?= e(t('addon.node_globals')) ?></p>
    <?php foreach ($globals as $g): $v = $d['globals'][$g] ?? null; ?>
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="globals[]" value="<?= e($g) ?>" <?= $v !== null ? 'checked' : '' ?>>
        <span><span class="font-mono"><?= e($g) ?></span><?php if ($v): ?> <span class="text-xs text-slate-400">v<?= e($v) ?></span><?php endif; ?> <span class="text-xs text-slate-500"><?= e(t($gdesc[$g])) ?></span></span></label>
    <?php endforeach; ?>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
  <p class="text-xs text-slate-500"><?= e(t('addon.node_usage')) ?></p>
<?php endif; ?>
</div>
