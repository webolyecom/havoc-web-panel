<h1 class="h1"><?= e(t('nav.transfers')) ?></h1>
<p class="mb-6 max-w-3xl text-sm text-slate-500"><?= e(t('transfer.intro')) ?></p>

<?php if ($newKey): ?>
<div class="mb-6 rounded-xl border border-amber-300/70 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
  <p class="mb-2 font-semibold"><?= e(t('transfer.key_once')) ?></p>
  <div class="flex gap-2"><input class="input font-mono text-xs" readonly value="<?= e($newKey) ?>" id="tkey" data-select-all>
    <button type="button" class="btn shrink-0" data-copy="#tkey"><?= e(t('transfer.copy')) ?></button></div>
  <p class="mt-2 text-xs"><?= e(t('transfer.key_hint')) ?></p>
</div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-2">
  <form method="post" action="/transfers/connect" class="card space-y-3"><?= csrf_field() ?>
    <h2 class="h2 mb-0"><?= e(t('transfer.in_title')) ?></h2>
    <p class="text-sm text-slate-500"><?= e(t('transfer.in_text')) ?></p>
    <div><label class="label"><?= e(t('transfer.key')) ?></label><textarea class="input font-mono text-xs" name="key" rows="3" required placeholder="hvt1.eyJ1Ijoi…" autocomplete="off"></textarea></div>
    <button class="btn"><?= e(t('transfer.connect')) ?></button>
  </form>

  <form method="post" action="/transfers/keys" class="card space-y-3"><?= csrf_field() ?>
    <h2 class="h2 mb-0"><?= e(t('transfer.out_title')) ?></h2>
    <p class="text-sm text-slate-500"><?= e(t('transfer.out_text')) ?></p>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label class="label"><?= e(t('common.name')) ?></label><input class="input" name="label" placeholder="<?= e(t('transfer.label_ph')) ?>"></div>
      <div><label class="label"><?= e(t('transfer.valid')) ?></label><select class="input" name="hours">
        <?php foreach ([1 => '1 h', 6 => '6 h', 24 => '24 h', 72 => '3 d', 168 => '7 d'] as $h => $l): ?><option value="<?= $h ?>" <?= $h === 24 ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div><p class="label"><?= e(t('transfer.sites')) ?></p>
      <label class="flex items-center gap-2 text-sm"><input type="radio" name="scope" value="all" checked> <?= e(t('transfer.all_sites', ['n' => count($domains)])) ?></label>
      <label class="flex items-center gap-2 text-sm"><input type="radio" name="scope" value="some"> <?= e(t('transfer.some_sites')) ?></label>
      <div class="mt-2 grid max-h-40 grid-cols-2 gap-1 overflow-auto rounded-lg border border-slate-200 p-2 text-sm dark:border-slate-700">
        <?php foreach ($domains as $d): ?><label class="flex items-center gap-2"><input type="checkbox" name="domains[]" value="<?= (int)$d['id'] ?>"> <?= e($d['name']) ?></label><?php endforeach; ?>
        <?php if (!$domains): ?><span class="text-slate-400"><?= e(t('common.empty')) ?></span><?php endif; ?>
      </div></div>
    <div><label class="label"><?= e(t('transfer.only_ip')) ?> <span class="text-slate-400">(<?= e(t('backups.optional')) ?>)</span></label><input class="input font-mono" name="allowed_ip" placeholder="203.0.113.20">
      <p class="mt-1 text-xs text-slate-500"><?= e(t('transfer.only_ip_hint')) ?></p></div>
    <button class="btn"><?= e(t('transfer.create_key')) ?></button>
    <p class="text-xs text-slate-500"><?= e(t('transfer.address', ['url' => $base])) ?></p>
  </form>
</div>

<?php if ($keys): ?>
<h2 class="h2 mb-3 mt-8"><?= e(t('transfer.active_keys')) ?></h2>
<div class="card divide-y divide-slate-100 p-0 dark:divide-slate-800">
  <?php foreach ($keys as $k): $ids = $k['domains'] === null ? null : json_decode($k['domains'], true); ?>
    <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
      <span><span class="font-medium"><?= e($k['label']) ?></span>
        <span class="text-slate-500">· <?= e($ids === null ? t('transfer.all') : t('transfer.n_sites', ['n' => count($ids)])) ?> · <?= e(t('transfer.until', ['t' => date('Y-m-d H:i', (int)$k['expires_at'])])) ?><?= $k['allowed_ip'] ? ' · ' . e($k['allowed_ip']) : '' ?></span>
        <?php if ($k['last_used']): ?><span class="text-xs text-slate-400">· <?= e(t('transfer.used', ['t' => $k['last_used'], 'ip' => $k['last_ip']])) ?></span><?php endif; ?></span>
      <form method="post" action="/transfers/keys/<?= (int)$k['id'] ?>/revoke" data-confirm="<?= e(t('transfer.revoke_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('transfer.revoke')) ?></button></form>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="h2 mb-3 mt-8"><?= e(t('transfer.history')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table text-sm">
  <thead><tr><th><?= e(t('common.time')) ?></th><th><?= e(t('transfer.source')) ?></th><th><?= e(t('transfer.sites')) ?></th><th><?= e(t('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($transfers as $t): ?>
    <tr><td class="whitespace-nowrap"><?= e($t['created_at']) ?></td><td class="font-mono text-xs"><?= e($t['source_url']) ?></td>
      <td><?= (int)$t['n'] ?: '—' ?></td>
      <td><?php if (!$t['n']): ?><span class="badge badge-warn"><?= e(t('transfer.st_select')) ?></span>
        <?php elseif ($t['busy']): ?><span class="badge badge-warn"><?= e(t('transfer.st_running')) ?></span>
        <?php elseif ($t['bad']): ?><span class="badge bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300"><?= e(t('transfer.st_some_failed', ['n' => $t['bad']])) ?></span>
        <?php else: ?><span class="badge badge-ok"><?= e(t('transfer.st_done')) ?></span><?php endif; ?></td>
      <td class="text-right"><a class="link" href="/transfers/<?= (int)$t['id'] . ($t['n'] ? '' : '/select') ?>"><?= e(t('transfer.open')) ?></a></td></tr>
  <?php endforeach; ?>
  <?php if (!$transfers): ?><tr><td colspan="5" class="py-6 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
