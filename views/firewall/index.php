<h1 class="h1"><?= e(t('nav.firewall')) ?></h1>
<?php if (empty($st['installed'])): ?><p class="card text-slate-500"><?= e(t('fw.not_installed')) ?></p><?php else: ?>
<div class="card mb-6 flex flex-wrap items-center justify-between gap-3">
  <div><span class="badge <?= $st['active'] ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($st['active'] ? 'fw.active' : 'fw.inactive')) ?></span>
    <span class="ml-2 text-sm text-slate-500"><?= e($st['default']) ?></span>
    <p class="mt-1 text-xs text-slate-500"><?= e(t('fw.protected', ['ports' => implode(', ', $st['protected'])])) ?> · <?= e(t('fw.you', ['ip' => $me])) ?></p></div>
  <form method="post" action="/firewall/<?= $st['active'] ? 'disable' : 'enable' ?>" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?>
    <button class="<?= $st['active'] ? 'btn-danger' : 'btn' ?>"><?= e(t($st['active'] ? 'fw.disable' : 'fw.enable')) ?></button></form>
</div>
<div class="grid gap-6 lg:grid-cols-3">
  <form method="post" action="/firewall/allow" class="card space-y-2"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('fw.open_port')) ?></h2>
    <div class="grid grid-cols-3 gap-2"><input class="input col-span-2" name="port" placeholder="8080 · 40000:40100" required>
      <select class="input" name="proto"><option>tcp</option><option>udp</option><option>any</option></select></div>
    <input class="input" name="from" placeholder="<?= e(t('fw.from_hint')) ?>">
    <button class="btn"><?= e(t('common.add')) ?></button></form>
  <form method="post" action="/firewall/ban" class="card space-y-2"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('fw.ban')) ?></h2>
    <input class="input" name="ip" placeholder="203.0.113.7 · 198.51.100.0/24" required>
    <button class="btn-danger"><?= e(t('fw.ban_btn')) ?></button></form>
  <form method="post" action="/firewall/whitelist" class="card space-y-2"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('fw.whitelist')) ?></h2>
    <input class="input" name="ip" placeholder="203.0.113.5" required>
    <button class="btn"><?= e(t('common.add')) ?></button></form>
</div>
<div class="mt-4 flex flex-wrap items-center gap-2 text-sm"><span class="text-slate-500"><?= e(t('fw.presets')) ?>:</span>
  <?php foreach (['ftp' => 'FTP', 'dns' => 'DNS', 'mail' => 'Mail'] as $k => $label): ?>
    <form method="post" action="/firewall/preset/<?= $k ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 text-xs dark:border-slate-700">+ <?= e($label) ?></button></form>
  <?php endforeach; ?></div>
<div class="card mt-6 overflow-x-auto p-0"><table class="table">
  <thead><tr><th>#</th><th><?= e(t('fw.to')) ?></th><th><?= e(t('common.action')) ?></th><th><?= e(t('fw.from')) ?></th><th><?= e(t('fw.type')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($st['rules'] as $r): $tag = str_starts_with($r['comment'], 'havoc:') ? substr($r['comment'], 6) : ''; ?>
    <tr><td><?= (int)$r['n'] ?></td><td class="font-mono"><?= e($r['to']) ?></td>
      <td><span class="badge <?= $r['action'] === 'ALLOW' ? 'badge-ok' : 'badge-warn' ?>"><?= e($r['action']) ?></span></td>
      <td class="font-mono text-xs"><?= e($r['from']) ?></td><td class="text-xs text-slate-500"><?= e($tag) ?></td>
      <td class="text-right"><form method="post" action="/firewall/delete" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?>
        <input type="hidden" name="n" value="<?= (int)$r['n'] ?>"><input type="hidden" name="raw" value="<?= e($r['raw']) ?>">
        <button class="btn-ghost text-xs text-red-600"><?= e(t('common.delete')) ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$st['rules']): ?><tr><td colspan="6" class="py-8 text-center text-slate-400"><?= e(t('common.empty')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
<p class="mt-3 text-xs text-slate-500"><?= e(t('fw.hint')) ?></p>
<?php endif; ?>
