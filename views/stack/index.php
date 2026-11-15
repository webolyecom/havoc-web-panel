<?php
$badge = fn(array $s) => $s['active'] === 'active' ? 'badge-ok' : 'badge-warn';
?>
<h1 class="h1"><?= e(t('nav.redis')) ?></h1>
<?php if ($secret): ?>
<div class="mb-4 rounded-lg bg-amber-100 p-4 text-sm text-amber-900"><?= e(t('db.once')) ?><br><b><?= e($secret['label']) ?>:</b> <code class="break-all"><?= e($secret['value']) ?></code></div>
<?php endif; ?>
<p class="mb-4 text-sm text-slate-500"><?= e(t('stack.shared_hint')) ?></p>
<div class="grid gap-6 lg:grid-cols-2">

  <div class="card space-y-4">
    <?php $r = $redis; $rJob = in_array($r['job'] ?? '', ['active', 'activating'], true); $rOn = ($r['active'] ?? '') === 'active'; ?>
    <div class="flex items-center justify-between"><h2 class="h2">Redis <?php if (!empty($r['version'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($r['version']) ?></span><?php endif; ?></h2>
      <span class="badge <?= !empty($r['installed']) && $rOn && !$rJob ? 'badge-ok' : 'badge-warn' ?>"><?= e($rJob ? t('stack.working') : (!empty($r['installed']) ? ($r['active'] ?? '—') : t('stack.not_installed_short'))) ?></span></div>
    <?php if ($rJob): ?><div data-autorefresh></div><?php endif; ?>
    <?php if (!empty($r['log']) && ($rJob || ($r['job'] ?? '') === 'failed')): ?><pre class="max-h-48 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($r['log']) ?></pre><?php endif; ?>
    <?php if (empty($r['installed'])): ?>
      <p class="text-sm text-slate-500"><?= e(t('stack.redis_install_hint')) ?></p>
      <?php if (!$rJob): ?>
        <form method="post" action="/stack/redis/install" data-confirm="<?= e(t('stack.redis_confirm')) ?>"><?= csrf_field() ?><button class="btn"><?= e(t('stack.redis_install')) ?></button></form>
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
      <form method="post" action="/stack/svc/redis/<?= $rOn ? 'deactivate' : 'activate' ?>"><?= csrf_field() ?>
        <button class="<?= $rOn ? 'btn-danger' : 'btn' ?>"><?= e(t($rOn ? 'stack.deactivate' : 'stack.activate')) ?></button></form>
      <form method="post" action="/stack/svc/redis/restart"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('svc.restart')) ?></button></form>
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
    <form method="post" action="/stack/redis/uninstall" class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 dark:border-slate-800" data-confirm="<?= e(t('stack.redis_remove_confirm')) ?>"><?= csrf_field() ?>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('stack.redis_purge')) ?></label>
      <button class="btn-danger"><?= e(t('stack.redis_remove')) ?></button></form>
    <?php endif; ?>
  </div>

  <div class="card space-y-4">
    <?php $m = $meili; $running = in_array($m['job'] ?? '', ['active', 'activating'], true); ?>
    <div class="flex items-center justify-between"><h2 class="h2">Meilisearch <?php if (!empty($m['version'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($m['version']) ?></span><?php endif; ?></h2>
      <span class="badge <?= !empty($m['installed']) && ($m['active'] ?? '') === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e(!empty($m['installed']) ? ($m['active'] ?? '—') : ($running ? t('stack.installing') : t('stack.not_installed_short'))) ?></span></div>
    <?php if ($running): ?><div data-autorefresh></div><?php endif; ?>
    <?php if (!empty($m['log']) && (empty($m['installed']) || ($m['job'] ?? '') === 'failed')): ?><pre class="max-h-48 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($m['log']) ?></pre><?php endif; ?>
    <?php if (empty($m['installed'])): ?>
      <p class="text-sm text-slate-500"><?= e(t('stack.meili_hint')) ?></p>
      <?php if (!$running): ?>
        <form method="post" action="/stack/meili/install" data-confirm="<?= e(t('stack.meili_confirm')) ?>"><?= csrf_field() ?><button class="btn"><?= e(t('stack.meili_install')) ?></button></form>
      <?php endif; ?>
    <?php else: ?>
    <p class="text-sm text-slate-500"><?= e(t('stack.meili_health')) ?>: <span class="badge <?= !empty($m['health']) ? 'badge-ok' : 'badge-warn' ?>"><?= e(t(!empty($m['health']) ? 'stack.meili_ok' : 'stack.meili_down')) ?></span>
      · <?= e(t('stack.meili_data')) ?>: <?= e(bytes((int)($m['data_bytes'] ?? 0))) ?></p>
    <div class="flex flex-wrap gap-2">
      <form method="post" action="/stack/svc/meili/<?= ($m['active'] ?? '') === 'active' ? 'deactivate' : 'activate' ?>"><?= csrf_field() ?>
        <button class="<?= ($m['active'] ?? '') === 'active' ? 'btn-danger' : 'btn' ?>"><?= e(t(($m['active'] ?? '') === 'active' ? 'stack.deactivate' : 'stack.activate')) ?></button></form>
      <form method="post" action="/stack/svc/meili/restart"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('svc.restart')) ?></button></form>
      <form method="post" action="/stack/meili/key/show"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('stack.meili_key_show')) ?></button></form>
      <form method="post" action="/stack/meili/key/rotate" data-confirm="<?= e(t('stack.meili_key_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('stack.meili_key_rotate')) ?></button></form>
    </div>
    <form method="post" action="/stack/meili/configure" class="flex items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800"><?= csrf_field() ?>
      <div class="flex-1"><label class="label"><?= e(t('stack.meili_mem')) ?></label><input class="input" type="number" name="indexing_mb" min="128" max="<?= (int)max(128, intdiv((int)($m['mem_mb'] ?? 0), 2)) ?>" value="<?= (int)($m['indexing_mb'] ?? 0) ?>"></div>
      <button class="btn"><?= e(t('common.save')) ?></button></form>
    <p class="text-xs text-slate-500"><?= e(t('stack.meili_conn')) ?></p>
    <form method="post" action="/stack/meili/uninstall" class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 dark:border-slate-800" data-confirm="<?= e(t('stack.meili_remove_confirm')) ?>"><?= csrf_field() ?>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('stack.meili_purge')) ?></label>
      <button class="btn-danger"><?= e(t('stack.meili_remove')) ?></button></form>
    <?php endif; ?>
  </div>
</div>
