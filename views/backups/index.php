<?php
$busy = (bool)array_filter($backups, fn($b) => in_array($b['status'], ['running', 'restoring'], true));
$scopeSel = fn(string $name = 'scope') => '<select class="input" name="' . $name . '"><option value="full">' . e(t('backups.scope_full')) . '</option><option value="files">' . e(t('backups.scope_files')) . '</option><option value="db">' . e(t('backups.scope_db')) . '</option></select>';
$ownerSel = function () use ($owners) {
    if (count($owners) < 2) return '';
    $o = '<div><label class="label">' . e(t('domains.owner')) . '</label><select class="input" name="owner">';
    foreach ($owners as $x) $o .= '<option value="' . (int)$x['id'] . '">' . e($x['username']) . '</option>';
    return $o . '</select></div>';
};
$targetSel = function () use ($targets) {
    $o = '<div><label class="label">' . e(t('backups.target')) . '</label><select class="input" name="target_id"><option value="0">' . e(t('backups.local_only')) . '</option>';
    foreach ($targets as $x) $o .= '<option value="' . (int)$x['id'] . '">' . e($x['label'] . ' (' . $x['type'] . ($x['username'] ? ' · ' . $x['username'] : '') . ')') . '</option>';
    return $o . '</select></div>';
};
?>
<div <?= $busy ? 'data-autorefresh' : '' ?>></div>
<h1 class="h1"><?= e(t('nav.backups')) ?></h1>

<div class="grid gap-6 lg:grid-cols-2">
  <form method="post" action="/backups" class="card space-y-3"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('backups.now')) ?></h2>
    <?= $ownerSel() ?>
    <div><label class="label"><?= e(t('backups.scope')) ?></label><?= $scopeSel() ?></div>
    <?= $targetSel() ?>
    <button class="btn"><?= e(t('backups.start')) ?></button>
    <p class="text-xs text-slate-500"><?= e(t('backups.hint')) ?></p>
  </form>
  <form method="post" action="/backups/jobs" class="card space-y-3"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('backups.schedule')) ?></h2>
    <?= $ownerSel() ?>
    <div class="grid grid-cols-3 gap-2">
      <div><label class="label"><?= e(t('backups.frequency')) ?></label><select class="input" name="frequency"><option value="daily"><?= e(t('backups.daily')) ?></option><option value="weekly"><?= e(t('backups.weekly')) ?></option></select></div>
      <div><label class="label"><?= e(t('backups.keep')) ?></label><input class="input" type="number" name="keep" min="1" max="60" value="7"></div>
      <div><label class="label"><?= e(t('backups.scope')) ?></label><?= $scopeSel() ?></div>
    </div>
    <?= $targetSel() ?>
    <button class="btn"><?= e(t('common.add')) ?></button>
    <?php foreach ($jobs as $j): ?>
      <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-sm dark:border-slate-800">
        <span><?= e($j['username']) ?> · <?= e(t('backups.' . $j['frequency'])) ?> · <?= e(t('backups.keep')) ?> <?= (int)$j['keep'] ?> · <?= e(t('backups.scope_' . $j['scope'])) ?></span>
        <span class="space-x-1">
          <button formaction="/backups/jobs/<?= (int)$j['id'] ?>/toggle" class="badge <?= $j['enabled'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($j['enabled'] ? 'cron.on' : 'cron.off')) ?></button>
          <button formaction="/backups/jobs/<?= (int)$j['id'] ?>/delete" class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></span></div>
    <?php endforeach; ?>
  </form>
</div>

<h2 class="h2 mb-3 mt-8"><?= e(t('backups.list')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('common.time')) ?></th><th><?= e(t('domains.owner')) ?></th><th><?= e(t('backups.scope')) ?></th><th><?= e(t('db.size')) ?></th><th><?= e(t('backups.stored')) ?></th><th><?= e(t('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($backups as $b): $ok = $b['status'] === 'done'; ?>
    <tr><td class="whitespace-nowrap"><?= e($b['created_at']) ?> <span class="text-xs text-slate-400">UTC</span></td><td><?= e($b['username']) ?></td>
      <td><?= e(t('backups.scope_' . $b['scope'])) ?></td><td><?= $b['size'] ? e(bytes((int)$b['size'])) : '—' ?></td>
      <td><?= e(t('backups.local')) ?><?= $b['remote'] ? ' + ' . e(t('backups.remote')) : '' ?></td>
      <td><span class="badge <?= $ok ? 'badge-ok' : 'badge-warn' ?>"><?= e(t('backups.st_' . $b['status'])) ?></span>
        <?php if ($b['note']): ?><div class="mt-1 max-w-xs break-words text-xs text-slate-500"><?= e($b['note']) ?></div><?php endif; ?></td>
      <td class="whitespace-nowrap text-right">
        <?php if ($ok && $b['path']): ?>
          <form method="post" action="/backups/<?= (int)$b['id'] ?>/restore" class="inline-flex items-center gap-1" data-confirm="<?= e(t('backups.restore_confirm')) ?>"><?= csrf_field() ?>
            <?= str_replace('class="input"', 'class="input w-auto py-1 text-xs"', $scopeSel()) ?><button class="btn text-xs"><?= e(t('backups.restore')) ?></button></form>
        <?php endif; ?>
        <?php if (!in_array($b['status'], ['running', 'restoring'], true)): ?>
          <form method="post" action="/backups/<?= (int)$b['id'] ?>/delete" class="inline" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$backups): ?><tr><td colspan="7" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>

<h2 class="h2 mb-1 mt-8" id="targets"><?= e(t('backups.targets')) ?></h2>
<p class="mb-3 max-w-3xl text-sm text-slate-500"><?= e(t('cloud.targets_intro')) ?></p>
<?php $typeNames = ['gdrive' => 'Google Drive', 'onedrive' => 'OneDrive', 'dropbox' => 'Dropbox', 's3' => 'S3', 'ftp' => 'FTP', 'sftp' => 'SFTP']; ?>
<div class="card mb-4 divide-y divide-slate-100 p-0 dark:divide-slate-800">
  <?php foreach ($targets as $x): ?>
    <div class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
      <span><span class="font-medium"><?= e($x['label']) ?></span> <span class="badge badge-ok"><?= e($typeNames[$x['type']] ?? $x['type']) ?></span>
        <?php if ($x['encrypted']): ?><span class="badge bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">🔒 <?= e(t('cloud.encrypted')) ?></span><?php endif; ?>
        <?php if ($x['path'] !== ''): ?><span class="font-mono text-xs text-slate-400"><?= e($x['path']) ?></span><?php endif; ?>
        <span class="text-slate-400"><?= e($x['username']) ?></span></span>
      <span class="flex gap-1">
        <form method="post" action="/backups/targets/<?= (int)$x['id'] ?>/about"><?= csrf_field() ?><button class="btn-ghost text-xs"><?= e(t('cloud.check_space')) ?></button></form>
        <form method="post" action="/backups/targets/<?= (int)$x['id'] ?>/delete" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form>
      </span></div>
  <?php endforeach; ?>
  <?php if (!$targets): ?><p class="px-5 py-4 text-sm text-slate-400"><?= e(t('common.empty')) ?></p><?php endif; ?>
</div>
<?php $cryptField = '<div><label class="label">' . e(t('cloud.crypt')) . ' <span class="text-slate-400">(' . e(t('backups.optional')) . ')</span></label><input class="input" type="password" name="crypt" minlength="8" autocomplete="new-password" placeholder="' . e(t('cloud.crypt_ph')) . '"><p class="mt-1 text-xs text-slate-500">' . e(t('cloud.crypt_hint')) . '</p></div>'; ?>
<div class="mb-4 grid gap-4 lg:grid-cols-3">
<?php foreach (['gdrive' => ['Google Drive', 'cloud.free_gdrive'], 'onedrive' => ['OneDrive', 'cloud.free_onedrive'], 'dropbox' => ['Dropbox', 'cloud.free_dropbox']] as $type => [$title, $free]): ?>
  <details class="card"><summary class="cursor-pointer font-semibold">+ <?= e($title) ?> <span class="ml-1 text-xs font-normal text-slate-500"><?= e(t($free)) ?></span></summary>
    <form method="post" action="/backups/cloud" class="mt-3 space-y-2"><?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
      <?= $ownerSel() ?>
      <div><label class="label"><?= e(t('common.name')) ?></label><input class="input" name="label" value="<?= e($title) ?>" required></div>
      <div><label class="label"><?= e(t('cloud.folder')) ?></label><input class="input" name="path" value="Havoc Backups"></div>
      <?= $cryptField ?>
      <button class="btn"><?= e(t('cloud.connect', ['name' => $title])) ?></button>
    </form></details>
<?php endforeach; ?>
</div>
<div class="grid gap-4 lg:grid-cols-3">
<?php foreach (['sftp' => 'SFTP', 'ftp' => 'FTP', 's3' => 'S3'] as $type => $title): ?>
  <details class="card"><summary class="cursor-pointer font-semibold">+ <?= e($title) ?><?php if ($type === 's3'): ?> <span class="ml-1 text-xs font-normal text-slate-500"><?= e(t('cloud.s3_hint')) ?></span><?php endif; ?></summary>
    <form method="post" action="/backups/targets" class="mt-3 space-y-2"><?= csrf_field() ?><input type="hidden" name="type" value="<?= $type ?>">
      <?= $ownerSel() ?>
      <input class="input" name="label" placeholder="<?= e(t('common.name')) ?>" required>
      <?php if ($type === 's3'): ?>
        <input class="input" name="bucket" placeholder="bucket" required>
        <input class="input" name="path" placeholder="<?= e(t('backups.prefix')) ?>">
        <input class="input" name="endpoint" placeholder="https://s3.example.com (<?= e(t('backups.optional')) ?>)">
        <input class="input" name="region" placeholder="us-east-1">
        <input class="input" name="access_key" placeholder="Access key" required autocomplete="off">
        <input class="input" type="password" name="secret_key" placeholder="Secret key" required autocomplete="new-password">
      <?php else: ?>
        <div class="grid grid-cols-3 gap-2"><input class="input col-span-2" name="host" placeholder="host" required><input class="input" name="port" placeholder="<?= $type === 'sftp' ? 22 : 21 ?>"></div>
        <input class="input" name="user" placeholder="<?= e(t('auth.username')) ?>" required autocomplete="off">
        <input class="input" type="password" name="password" placeholder="<?= e(t('auth.password')) ?>" required autocomplete="new-password">
        <input class="input" name="path" placeholder="/backups">
        <?php if ($type === 'ftp'): ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="tls" value="1"> <?= e(t('backups.tls')) ?></label><?php endif; ?>
      <?php endif; ?>
      <?= $cryptField ?>
      <button class="btn"><?= e(t('backups.test_save')) ?></button>
    </form></details>
<?php endforeach; ?>
</div>
