<?php $on = ($d['active'] ?? '') === 'active'; $c = $d['config'] ?? []; $jailNames = ['sshd' => 'SSH / SFTP', 'pure-ftpd' => 'FTP', 'havoc-panel' => t('addon.f2b_jail_panel'), 'recidive' => t('addon.f2b_jail_recidive')]; ?>
<div class="card space-y-4">
  <div class="flex items-center justify-between"><h2 class="h2 mb-0">Fail2ban <?php if (!empty($d['version'])): ?><span class="text-sm font-normal text-slate-400">v<?= e($d['version']) ?></span><?php endif; ?></h2>
    <?php if (!empty($d['installed']) && !$busy): ?><span class="badge <?= $on ? 'badge-ok' : 'badge-warn' ?>"><?= e(t($on ? 'addon.running' : 'addon.stopped')) ?></span><?php endif; ?></div>
<?php if (empty($d['installed'])): ?>
  <p class="text-sm text-slate-500"><?= e(t('addon.f2b_install_hint')) ?></p>
  <?php if (!$busy): ?><form method="post" action="/addons/fail2ban/install"><?= csrf_field() ?><button class="btn"><?= e(t('addon.install', ['name' => 'Fail2ban'])) ?></button></form><?php endif; ?>
<?php elseif (!$busy): ?>
  <div class="flex flex-wrap gap-2">
    <form method="post" action="/addons/fail2ban/service/<?= $on ? 'deactivate' : 'activate' ?>" <?= $on ? 'data-confirm="' . e(t('addon.f2b_stop_confirm')) . '"' : '' ?>><?= csrf_field() ?><button class="<?= $on ? 'btn-danger' : 'btn' ?>"><?= e(t($on ? 'stack.deactivate' : 'stack.activate')) ?></button></form>
    <form method="post" action="/addons/fail2ban/service/restart"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('svc.restart')) ?></button></form>
  </div>
  <?php if ($on): ?>
  <div class="overflow-x-auto"><table class="table text-sm">
    <thead><tr><th><?= e(t('addon.f2b_jail')) ?></th><th class="text-right"><?= e(t('addon.f2b_failed')) ?></th><th class="text-right"><?= e(t('addon.f2b_banned_now')) ?></th><th class="text-right"><?= e(t('addon.f2b_banned_total')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($d['jails'] as $j => $s): ?>
      <tr><td><?= e($jailNames[$j] ?? $j) ?> <span class="font-mono text-xs text-slate-400"><?= e($j) ?></span></td><td class="text-right"><?= (int)$s['failed'] ?></td><td class="text-right font-medium"><?= (int)$s['banned'] ?></td><td class="text-right"><?= (int)$s['total'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$d['jails']): ?><tr><td colspan="4" class="text-slate-500"><?= e(t('addon.f2b_no_jails')) ?></td></tr><?php endif; ?>
    </tbody></table></div>
  <?php
    $banned = [];
    foreach ($d['jails'] as $j => $s) { foreach ($s['ips'] as $ip) { $banned[$ip][] = $j; } }
  ?>
  <div class="border-t border-slate-100 pt-4 dark:border-slate-800">
    <h3 class="mb-2 text-sm font-semibold"><?= e(t('addon.f2b_banned_list')) ?> <span class="font-normal text-slate-400">(<?= count($banned) ?>)</span></h3>
    <?php if ($banned): ?>
    <ul class="max-h-72 divide-y divide-slate-100 overflow-auto text-sm dark:divide-slate-800">
      <?php foreach ($banned as $ip => $js): ?>
      <li class="flex items-center justify-between gap-3 py-1.5"><span><span class="font-mono"><?= e($ip) ?></span> <span class="text-xs text-slate-400"><?= e(implode(', ', $js)) ?></span></span>
        <form method="post" action="/addons/fail2ban/unban"><?= csrf_field() ?><input type="hidden" name="ip" value="<?= e($ip) ?>"><button class="btn-ghost px-2 py-1 text-xs text-brand"><?= e(t('addon.f2b_unban')) ?></button></form></li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="text-sm text-slate-500"><?= e(t('addon.f2b_none_banned')) ?></p><?php endif; ?>
    <div class="mt-3 flex flex-wrap items-end gap-2">
      <form method="post" action="/addons/fail2ban/unban" class="flex items-end gap-2"><?= csrf_field() ?>
        <div><label class="label"><?= e(t('addon.f2b_ip')) ?></label><input class="input w-56 font-mono" name="ip" placeholder="203.0.113.10" required></div>
        <button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('addon.f2b_unban')) ?></button></form>
      <form method="post" action="/addons/fail2ban/ban" class="flex items-end gap-2"><?= csrf_field() ?>
        <div><label class="label"><?= e(t('addon.f2b_ip')) ?></label><input class="input w-56 font-mono" name="ip" placeholder="203.0.113.10" required></div>
        <select class="input w-auto" name="jail"><?php foreach ($d['jails'] as $j => $s): ?><option value="<?= e($j) ?>"><?= e($jailNames[$j] ?? $j) ?></option><?php endforeach; ?></select>
        <button class="btn-danger"><?= e(t('addon.f2b_ban')) ?></button></form>
    </div>
  </div>
  <?php endif; ?>
  <form method="post" action="/addons/fail2ban/settings" class="space-y-4 border-t border-slate-100 pt-4 dark:border-slate-800"><?= csrf_field() ?>
    <h3 class="text-sm font-semibold"><?= e(t('addon.settings')) ?></h3>
    <div class="grid gap-3 sm:grid-cols-3">
      <div><label class="label"><?= e(t('addon.f2b_maxretry')) ?></label><input class="input" type="number" name="maxretry" min="1" max="100" value="<?= (int)$c['maxretry'] ?>"></div>
      <div><label class="label"><?= e(t('addon.f2b_findtime')) ?></label><input class="input" type="number" name="findtime" min="1" max="10080" value="<?= (int)$c['findtime'] ?>"></div>
      <div><label class="label"><?= e(t('addon.f2b_bantime')) ?></label><input class="input" type="number" name="bantime" min="1" max="525600" value="<?= (int)$c['bantime'] ?>"></div>
    </div>
    <p class="text-xs text-slate-500"><?= e(t('addon.f2b_rule_hint')) ?></p>
    <div><p class="label"><?= e(t('addon.f2b_jails')) ?></p>
      <div class="grid gap-2 sm:grid-cols-2">
      <?php foreach ($jailNames as $j => $label): $avail = $j !== 'pure-ftpd' || !empty($d['ftp']); ?>
        <label class="flex items-start gap-2 text-sm <?= $avail ? '' : 'opacity-50' ?>"><input type="checkbox" class="mt-0.5" name="jails[<?= e($j) ?>]" value="1" <?= !empty($c['jails'][$j]) ? 'checked' : '' ?> <?= $avail ? '' : 'disabled' ?>>
          <span><?= e($label) ?><span class="block text-xs text-slate-500"><?= e(t('addon.f2b_jd_' . str_replace('-', '_', $j))) ?></span></span></label>
      <?php endforeach; ?>
      </div></div>
    <div><label class="label"><?= e(t('addon.f2b_whitelist')) ?></label>
      <textarea class="input font-mono text-sm" name="ignoreip" rows="3" placeholder="203.0.113.10&#10;198.51.100.0/24"><?= e(implode("\n", $c['ignoreip'] ?? [])) ?></textarea>
      <p class="mt-1 text-xs text-slate-500"><?= e(t('addon.f2b_whitelist_hint', ['ip' => $myIp])) ?>
        <?php if (!in_array($myIp, $c['ignoreip'] ?? [], true)): ?><button type="button" class="link" data-append-ip="<?= e($myIp) ?>"><?= e(t('addon.f2b_add_mine')) ?></button><?php endif; ?></p></div>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </form>
<?php endif; ?>
</div>
