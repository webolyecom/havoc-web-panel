<?php $h = $info['hello'] ?? []; ?>
<a href="/transfers" class="mb-2 inline-block text-sm text-slate-500 hover:text-brand">&larr; <?= e(t('nav.transfers')) ?></a>
<h1 class="h1"><?= e(t('transfer.select_title')) ?></h1>
<div class="card mb-6 grid gap-3 text-sm sm:grid-cols-4">
  <div><p class="text-xs text-slate-500"><?= e(t('transfer.source')) ?></p><p class="font-mono"><?= e($t['source_url']) ?></p></div>
  <div><p class="text-xs text-slate-500"><?= e(t('transfer.server')) ?></p><p><?= e(($h['host'] ?? '') . ' · Havoc ' . ($h['version'] ?? '?')) ?></p></div>
  <div><p class="text-xs text-slate-500"><?= e(t('transfer.connection')) ?></p><p><?= e($t['fingerprint'] ? t('transfer.pinned') : t('transfer.verified')) ?></p></div>
  <div><p class="text-xs text-slate-500"><?= e(t('transfer.free_here')) ?></p><p><?= e(bytes($free)) ?></p></div>
</div>
<form method="post" action="/transfers/<?= (int)$t['id'] ?>/start" class="space-y-4"><?= csrf_field() ?>
<?php foreach ($domains as $d): $p = $pre[$d['name']] ?? ['block' => null, 'notes' => [], 'owner_exists' => false]; $key = 'sites[' . e($d['name']) . ']'; ?>
  <div class="card <?= $p['block'] ? 'opacity-60' : '' ?>">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <label class="flex items-start gap-3"><input type="checkbox" class="mt-1" name="<?= $key ?>[on]" value="1" <?= $p['block'] ? 'disabled' : 'checked' ?>>
        <span><span class="text-base font-semibold"><?= e($d['name']) ?></span>
          <span class="ml-1 text-xs text-slate-500"><?= e(t('transfer.type_' . $d['type'])) ?><?= $d['parent'] ? ' · ' . e($d['parent']) : '' ?> · PHP <?= e($d['php']) ?> · <?= e(bytes((int)$d['size'])) ?></span>
          <?php if ($d['aliases']): ?><span class="block text-xs text-slate-500"><?= e(t('transfer.aliases')) ?>: <?= e(implode(', ', $d['aliases'])) ?></span><?php endif; ?></span></label>
      <div class="flex items-center gap-2 text-sm"><span class="text-slate-500"><?= e(t('transfer.owner')) ?></span>
        <select class="input w-auto py-1" name="<?= $key ?>[owner]">
          <option value="0"><?= e($p['owner_exists'] ? t('transfer.owner_same', ['u' => $d['owner']]) : t('transfer.owner_create', ['u' => $d['owner']])) ?></option>
          <?php foreach ($users as $u): if (strcasecmp($u['username'], $d['owner']) === 0) continue; ?><option value="<?= (int)$u['id'] ?>"><?= e($u['username']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <?php if ($p['block']): ?><p class="mt-2 text-sm text-red-600"><?= e($p['block']) ?></p><?php endif; ?>
    <?php foreach ($p['notes'] as $n): ?><p class="mt-2 text-sm text-amber-700 dark:text-amber-300">⚠ <?= e($n) ?></p><?php endforeach; ?>
    <?php if (!$p['block']): ?>
    <div class="mt-3 grid gap-3 border-t border-slate-100 pt-3 text-sm dark:border-slate-800 sm:grid-cols-2">
      <div><p class="label"><?= e(t('transfer.databases')) ?></p>
        <?php foreach ($d['dbs'] as $db): ?>
          <label class="flex items-center gap-2"><input type="checkbox" name="<?= $key ?>[dbs][<?= e($db['name']) ?>]" value="1" <?= $db['used'] || count($d['dbs']) === 1 ? 'checked' : '' ?>>
            <span class="font-mono"><?= e($db['name']) ?></span> <span class="text-xs text-slate-500"><?= e(bytes((int)$db['size'])) ?><?= in_array($db['name'], $d['assigned'] ?? [], true) ? ' · ' . e(t('transfer.db_site')) : ($db['used'] ? ' · ' . e(t('transfer.db_used')) : '') ?></span></label>
        <?php endforeach; ?>
        <?php if (!$d['dbs']): ?><p class="text-slate-400"><?= e(t('transfer.no_dbs')) ?></p><?php endif; ?></div>
      <div><p class="label"><?= e(t('transfer.also')) ?></p>
        <label class="flex items-center gap-2 <?= $d['ssl'] === 'none' ? 'opacity-50' : '' ?>"><input type="checkbox" name="<?= $key ?>[ssl]" value="1" <?= $d['ssl'] !== 'none' ? 'checked' : 'disabled' ?>> <?= e(t('transfer.copy_ssl')) ?></label>
        <?php if (!empty($d['search'])): ?><label class="flex items-center gap-2"><input type="checkbox" name="<?= $key ?>[search]" value="1" checked> <?= e(t('transfer.copy_search')) ?></label><?php endif; ?>
        <p class="mt-1 text-xs text-slate-500"><?= e(t('transfer.always')) ?></p></div>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$domains): ?><p class="card text-sm text-slate-500"><?= e(t('transfer.no_sites')) ?></p><?php endif; ?>
  <div class="flex items-center gap-3"><button class="btn"><?= e(t('transfer.start')) ?></button><span class="text-xs text-slate-500"><?= e(t('transfer.start_hint')) ?></span></div>
</form>
