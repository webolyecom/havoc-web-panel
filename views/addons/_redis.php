  <div class="card space-y-4">
    <?php $r = $st; $rJob = in_array($r['job'] ?? '', ['active', 'activating'], true); $rOn = ($r['active'] ?? '') === 'active'; ?>
    <div class="flex items-center justify-between"><h2 class="h2">Redis <?php if (!empty($r['version'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($r['version']) ?></span><?php endif; ?></h2>
      <span class="badge <?= !empty($r['installed']) && $rOn && !$rJob ? 'badge-ok' : 'badge-warn' ?>"><?= e($rJob ? t('stack.working') : (!empty($r['installed']) ? ($r['active'] ?? '—') : t('stack.not_installed_short'))) ?></span></div>
    <?php if ($rJob): ?><div data-autorefresh></div><?php endif; ?>
    <?php if (!empty($r['log']) && ($rJob || ($r['job'] ?? '') === 'failed')): ?><pre class="max-h-48 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($r['log']) ?></pre><?php endif; ?>
    <?php if (empty($r['installed'])): ?>
      <p class="text-sm text-slate-500"><?= e(t('stack.redis_install_hint')) ?></p>
      <?php if (!$rJob): ?>
        <form method="post" action="/addons/redis/install" data-confirm="<?= e(t('stack.redis_confirm')) ?>"><?= csrf_field() ?><button class="btn"><?= e(t('stack.redis_install')) ?></button></form>
      <?php endif; ?>
    <?php elseif (!$rJob): ?>
    <?php if ($rOn): ?>
    <dl class="grid grid-cols-3 gap-3 text-sm">
      <div><dt class="text-xs text-slate-500"><?= e(t('stack.redis_mem')) ?></dt><dd class="font-medium"><?= e(bytes((int)($r['used_memory'] ?? 0))) ?></dd></div>
      <div><dt class="text-xs text-slate-500"><?= e(t('stack.redis_keys')) ?></dt><dd class="font-medium"><?= (int)($r['keys'] ?? 0) ?></dd></div>
      <div><dt class="text-xs text-slate-500"><?= e(t('stack.redis_clients')) ?></dt><dd class="font-medium"><?= (int)($r['clients'] ?? 0) ?></dd></div>
    </dl>
    <?php endif; ?>
    <div class="flex flex-wrap gap-2">
      <form method="post" action="/addons/redis/service/<?= $rOn ? 'deactivate' : 'activate' ?>"><?= csrf_field() ?>
        <button class="<?= $rOn ? 'btn-danger' : 'btn' ?>"><?= e(t($rOn ? 'stack.deactivate' : 'stack.activate')) ?></button></form>
      <form method="post" action="/addons/redis/service/restart"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('svc.restart')) ?></button></form>
      <?php if (!empty($r['config']['password'])): ?><form method="post" action="/stack/redis/key"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('stack.redis_pw_show')) ?></button></form><?php endif; ?>
    </div>
    <form method="post" action="/stack/redis" class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800"><?= csrf_field() ?>
      <div class="grid gap-3 sm:grid-cols-2">
        <div><label class="label">maxmemory</label><input class="input" name="maxmemory" value="<?= e($r['config']['maxmemory'] ?? '') ?>" placeholder="256mb · 2gb"></div>
        <div><label class="label"><?= e(t('stack.policy')) ?></label><select class="input" name="policy"><?php foreach ($policies as $p): ?><option <?= ($r['config']['policy'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select></div>
      </div>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="appendonly" value="1" <?= ($r['config']['appendonly'] ?? 'no') === 'yes' ? 'checked' : '' ?>> <?= e(t('stack.aof')) ?></label>
      <div><label class="label"><?= e(t('stack.password')) ?> <span class="text-slate-400">(<?= e(t(!empty($r['config']['password']) ? 'stack.pw_set' : 'stack.pw_none')) ?>)</span></label>
        <select class="input" name="password_mode"><option value="keep"><?= e(t('stack.pw_keep')) ?></option><option value="generate"><?= e(t('stack.pw_generate')) ?></option><option value="remove"><?= e(t('stack.pw_remove')) ?></option></select></div>
      <button class="btn"><?= e(t('common.save')) ?></button>
      <p class="text-xs text-slate-500"><?= e(t('stack.redis_hint')) ?></p>
    </form>
    <p class="text-xs text-slate-500"><?= e(t('stack.redis_conn')) ?><?php if (!empty($r['php_ext'])): ?> <?= e(t('stack.redis_php', ['v' => implode(', ', $r['php_ext'])])) ?><?php endif; ?></p>
    <form method="post" action="/addons/redis/uninstall" class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 dark:border-slate-800" data-confirm="<?= e(t('stack.redis_remove_confirm')) ?>"><?= csrf_field() ?>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('stack.redis_purge')) ?></label>
      <button class="btn-danger"><?= e(t('stack.redis_remove')) ?></button></form>
    <?php endif; ?>
  </div>
