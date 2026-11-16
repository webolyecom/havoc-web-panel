  <div class="card space-y-4">
    <?php $m = $st; $running = in_array($m['job'] ?? '', ['active', 'activating'], true); ?>
    <div class="flex items-center justify-between"><h2 class="h2">Meilisearch <?php if (!empty($m['version'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($m['version']) ?></span><?php endif; ?></h2>
      <span class="badge <?= !empty($m['installed']) && ($m['active'] ?? '') === 'active' ? 'badge-ok' : 'badge-warn' ?>"><?= e(!empty($m['installed']) ? ($m['active'] ?? '—') : ($running ? t('stack.installing') : t('stack.not_installed_short'))) ?></span></div>
    <?php if ($running): ?><div data-autorefresh></div><?php endif; ?>
    <?php if (!empty($m['log']) && (empty($m['installed']) || ($m['job'] ?? '') === 'failed')): ?><pre class="max-h-48 overflow-auto rounded bg-slate-900 p-3 text-xs text-green-300"><?= e($m['log']) ?></pre><?php endif; ?>
    <?php if (empty($m['installed'])): ?>
      <p class="text-sm text-slate-500"><?= e(t('stack.meili_hint')) ?></p>
      <?php if (!$running): ?>
        <form method="post" action="/addons/meilisearch/install" data-confirm="<?= e(t('stack.meili_confirm')) ?>"><?= csrf_field() ?><button class="btn"><?= e(t('stack.meili_install')) ?></button></form>
      <?php endif; ?>
    <?php else: ?>
    <p class="text-sm text-slate-500"><?= e(t('stack.meili_health')) ?>: <span class="badge <?= !empty($m['health']) ? 'badge-ok' : 'badge-warn' ?>"><?= e(t(!empty($m['health']) ? 'stack.meili_ok' : 'stack.meili_down')) ?></span>
      · <?= e(t('stack.meili_data')) ?>: <?= e(bytes((int)($m['data_bytes'] ?? 0))) ?></p>
    <div class="flex flex-wrap gap-2">
      <form method="post" action="/addons/meilisearch/service/<?= ($m['active'] ?? '') === 'active' ? 'deactivate' : 'activate' ?>"><?= csrf_field() ?>
        <button class="<?= ($m['active'] ?? '') === 'active' ? 'btn-danger' : 'btn' ?>"><?= e(t(($m['active'] ?? '') === 'active' ? 'stack.deactivate' : 'stack.activate')) ?></button></form>
      <form method="post" action="/addons/meilisearch/service/restart"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('svc.restart')) ?></button></form>
      <form method="post" action="/stack/meili/key/show"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('stack.meili_key_show')) ?></button></form>
      <form method="post" action="/stack/meili/key/rotate" data-confirm="<?= e(t('stack.meili_key_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('stack.meili_key_rotate')) ?></button></form>
    </div>
    <form method="post" action="/stack/meili/configure" class="flex items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800"><?= csrf_field() ?>
      <div class="flex-1"><label class="label"><?= e(t('stack.meili_mem')) ?></label><input class="input" type="number" name="indexing_mb" min="128" max="<?= (int)max(128, intdiv((int)($m['mem_mb'] ?? 0), 2)) ?>" value="<?= (int)($m['indexing_mb'] ?? 0) ?>"></div>
      <button class="btn"><?= e(t('common.save')) ?></button></form>
    <p class="text-xs text-slate-500"><?= e(t('stack.meili_conn')) ?></p>
    <form method="post" action="/addons/meilisearch/uninstall" class="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4 dark:border-slate-800" data-confirm="<?= e(t('stack.meili_remove_confirm')) ?>"><?= csrf_field() ?>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('stack.meili_purge')) ?></label>
      <button class="btn-danger"><?= e(t('stack.meili_remove')) ?></button></form>
    <?php endif; ?>
  </div>
