<?php $id = (int)$d['id']; ?>
<div class="max-w-4xl">
  <?php if ($search !== null): $ms = $search['site']; $mi = $search['info']; ?>
  <div class="card space-y-3" id="search">
    <div class="flex items-center justify-between gap-2"><h2 class="h2 mb-0"><?= e(t('search.title')) ?></h2><?php if ($ms): ?><span class="badge badge-ok"><?= e(t('search.on')) ?></span><?php endif; ?></div>
    <?php if (!$ms): ?>
      <p class="text-sm text-slate-500"><?= e(t('search.intro', ['prefix' => $search['prefix']])) ?></p>
      <form method="post" action="/domains/<?= $id ?>/search/enable"><?= csrf_field() ?><button class="btn"><?= e(t('search.enable')) ?></button></form>
    <?php elseif (!empty($mi['error'])): ?>
      <p class="text-sm text-red-600"><?= e($mi['error']) ?></p>
    <?php else: ?>
      <dl class="grid gap-2 text-sm">
        <div class="grid grid-cols-[8rem_1fr] items-center gap-2"><dt class="text-slate-500"><?= e(t('search.address')) ?></dt><dd class="font-mono">http://127.0.0.1:7700</dd></div>
        <div class="grid grid-cols-[8rem_1fr] items-center gap-2"><dt class="text-slate-500"><?= e(t('search.prefix')) ?></dt><dd class="font-mono"><?= e($ms['prefix']) ?>…</dd></div>
        <?php foreach (['admin' => 'search.admin_key', 'search' => 'search.search_key'] as $k => $label): ?>
        <div class="grid grid-cols-[8rem_1fr] items-center gap-2"><dt class="text-slate-500"><?= e(t($label)) ?></dt>
          <dd class="flex gap-1"><input class="input py-1 font-mono text-xs" type="password" readonly value="<?= e((string)($mi['keys'][$k] ?? '')) ?>" id="mk-<?= $k ?>-<?= $id ?>" data-select-all>
            <button type="button" class="btn-ghost border border-slate-300 px-2 text-xs dark:border-slate-700" data-reveal="#mk-<?= $k ?>-<?= $id ?>">👁</button>
            <button type="button" class="btn-ghost border border-slate-300 px-2 text-xs dark:border-slate-700" data-copy="#mk-<?= $k ?>-<?= $id ?>"><?= e(t('transfer.copy')) ?></button></dd></div>
        <?php endforeach; ?>
      </dl>
      <p class="text-xs text-slate-500"><?= e(t('search.keys_hint', ['prefix' => $ms['prefix']])) ?></p>
      <div class="overflow-x-auto"><table class="table text-sm">
        <thead><tr><th><?= e(t('search.index')) ?></th><th class="text-right"><?= e(t('search.documents')) ?></th><th class="text-right"><?= e(t('db.size')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($mi['indexes'] ?? [] as $ix): ?><tr><td class="font-mono"><?= e($ix['uid']) ?><?= $ix['indexing'] ? ' <span class="badge badge-warn">' . e(t('search.indexing')) . '</span>' : '' ?></td><td class="text-right"><?= number_format((int)$ix['documents']) ?></td><td class="text-right"><?= e(bytes((int)$ix['size'])) ?></td></tr><?php endforeach; ?>
        <?php if (empty($mi['indexes'])): ?><tr><td colspan="3" class="text-slate-400"><?= e(t('search.no_indexes', ['prefix' => $ms['prefix']])) ?></td></tr><?php endif; ?>
        </tbody></table></div>
      <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
        <form method="post" action="/domains/<?= $id ?>/search/rotate" data-confirm="<?= e(t('search.rotate_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('search.rotate')) ?></button></form>
        <form method="post" action="/domains/<?= $id ?>/search/disable" class="flex items-center gap-2" data-confirm="<?= e(t('search.disable_confirm')) ?>"><?= csrf_field() ?>
          <label class="flex items-center gap-1.5 text-xs"><input type="checkbox" name="delete_indexes" value="1"> <?= e(t('search.delete_indexes')) ?></label>
          <button class="btn-danger"><?= e(t('search.disable')) ?></button></form>
      </div>
      <p class="text-xs text-slate-500"><?= e(t('search.backup_hint')) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
