<h1 class="h1"><?= e(t('nav.ftp')) ?></h1>
<?php if ($new): ?>
<div class="mb-4 rounded-lg bg-amber-100 p-4 text-sm text-amber-900"><?= e(t('db.once')) ?><br>
  <b><?= e(t('ftp.host')) ?>:</b> <code><?= e($host) ?></code> &nbsp; <b><?= e(t('ftp.port')) ?>:</b> <code><?= $new['protocol'] === 'sftp' ? '22 (SFTP)' : '21 (FTPS, explicit TLS)' ?></code><br>
  <b><?= e(t('auth.username')) ?>:</b> <code><?= e($new['login']) ?></code> &nbsp; <b><?= e(t('auth.password')) ?>:</b> <code class="break-all"><?= e($new['password']) ?></code></div>
<?php endif; ?>
<form method="post" action="/ftp" class="card mb-6 grid gap-3 sm:grid-cols-6"><?= csrf_field() ?>
  <div><label class="label"><?= e(t('ftp.protocol')) ?></label><select class="input" name="protocol"><option value="ftp">FTP (TLS)</option><option value="sftp">SFTP</option></select></div>
  <div><label class="label"><?= e(t('auth.username')) ?></label><input class="input" name="login" required pattern="[a-z0-9]{1,16}" placeholder="deploy"></div>
  <div><label class="label"><?= e(t('auth.password')) ?></label><input class="input" name="password" placeholder="<?= e(t('db.auto')) ?>" autocomplete="new-password"></div>
  <div class="sm:col-span-2"><label class="label"><?= e(t('nav.domains')) ?></label>
    <select class="input" name="domain_id" required><?php foreach ($domains as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <div><label class="label"><?= e(t('ftp.subdir')) ?></label><input class="input" name="subdir" placeholder="wp-content"></div>
  <div><label class="label"><?= e(t('ftp.quota_mb')) ?></label><input class="input" type="number" min="0" name="quota_mb" value="0"></div>
  <div class="sm:col-span-3"><label class="label"><?= e(t('ftp.ips')) ?></label><input class="input" name="ips" placeholder="203.0.113.5, 198.51.100.0/24"></div>
  <?php if (count($owners) > 1): ?><div><label class="label"><?= e(t('domains.owner')) ?></label><select class="input" name="owner"><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['username']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="flex items-end"><button class="btn w-full"><?= e(t('common.add')) ?></button></div>
  <p class="text-xs text-slate-500 sm:col-span-6"><?= e(t('ftp.hint')) ?></p>
</form>
<div class="card overflow-x-auto p-0"><table class="table">
  <thead><tr><th><?= e(t('auth.username')) ?></th><th><?= e(t('ftp.protocol')) ?></th><th><?= e(t('ftp.folder')) ?></th><th><?= e(t('ftp.quota_mb')) ?></th><th><?= e(t('ftp.ips')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="font-mono font-medium"><?= e($r['login']) ?><div class="text-xs font-sans text-slate-400"><?= e($r['username']) ?></div></td>
      <td><span class="badge badge-ok"><?= e(strtoupper($r['protocol'])) ?></span></td>
      <td>/<?= e((string)$r['home']) ?></td>
      <td><?= $r['protocol'] === 'ftp' ? ($r['quota_mb'] ? (int)$r['quota_mb'] . ' MB' : '∞') : '—' ?></td>
      <td class="max-w-[10rem] break-all text-xs"><?= e($r['allowed_ips'] ?: '—') ?></td>
      <td class="space-y-1 text-right">
        <details class="inline-block text-left"><summary class="btn-ghost cursor-pointer border border-slate-300 text-xs dark:border-slate-700"><?= e(t('common.edit')) ?></summary>
          <form method="post" action="/ftp/<?= (int)$r['id'] ?>" class="mt-2 w-64 space-y-2"><?= csrf_field() ?>
            <?php if ($r['protocol'] === 'ftp'): ?><input class="input" type="number" min="0" name="quota_mb" value="<?= (int)$r['quota_mb'] ?>" placeholder="<?= e(t('ftp.quota_mb')) ?>"><?php endif; ?>
            <input class="input" name="ips" value="<?= e($r['allowed_ips']) ?>" placeholder="<?= e(t('ftp.ips')) ?>">
            <button class="btn"><?= e(t('common.save')) ?></button></form></details>
        <form method="post" action="/ftp/<?= (int)$r['id'] ?>/password" class="inline"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700"><?= e(t('db.reset')) ?></button></form>
        <form method="post" action="/ftp/<?= (int)$r['id'] ?>/delete" class="inline" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
