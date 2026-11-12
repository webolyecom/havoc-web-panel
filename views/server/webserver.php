<?php
$active = $st['type'] ?? 'nginx';
$servers = $st['servers'] ?? [];
$jobRunning = in_array($st['job'] ?? '', ['active', 'activating'], true);
$busy = $jobRunning || $switching;
$cards = [
    'nginx' => ['ws.d_nginx', ['ws.f_waf', 'ws.f_rules', 'ws.f_no_htaccess']],
    'ols'   => ['ws.d_ols', ['ws.f_htaccess', 'ws.f_http3', 'ws.f_no_waf']],
    'lsws'  => ['ws.d_lsws', ['ws.f_htaccess', 'ws.f_license', 'ws.f_no_waf']],
];
?>
<?php if ($busy): ?><div data-autorefresh></div><?php endif; ?>
<h1 class="h1"><?= e(t('nav.webserver')) ?></h1>
<p class="mb-6 max-w-3xl text-sm text-slate-500"><?= e(t('ws.intro')) ?></p>

<?php if (!empty($sw['log'])): ?>
<div class="card mb-6 space-y-2">
  <div class="flex items-center justify-between gap-2">
    <h2 class="h2"><?= e(t('ws.switch_title', ['name' => Havoc\Services\WebServerService::NAMES[$sw['target']] ?? '?'])) ?></h2>
    <span class="badge <?= ($sw['status'] ?? '') === 'done' ? 'badge-ok' : 'badge-warn' ?>"><?= e(t('ws.st_' . ($sw['status'] ?? 'running'))) ?></span>
  </div>
  <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded bg-slate-900 p-3 font-mono text-xs text-green-300"><?= e(implode("\n", $sw['log'])) ?></pre>
</div>
<?php endif; ?>
<?php if ($st['job_log'] ?? ''): ?>
<div class="card mb-6 space-y-2">
  <div class="flex items-center justify-between gap-2"><h2 class="h2"><?= e(t('ws.install_log')) ?></h2>
    <span class="badge <?= $jobRunning ? 'badge-warn' : (($st['job'] ?? '') === 'failed' ? 'badge-warn' : 'badge-ok') ?>"><?= e($jobRunning ? t('stack.installing') : (($st['job'] ?? '') === 'failed' ? t('ws.st_failed') : t('ws.st_done'))) ?></span></div>
  <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded bg-slate-900 p-3 font-mono text-xs text-green-300"><?= e($st['job_log']) ?></pre>
</div>
<?php endif; ?>

<div class="grid gap-6 lg:grid-cols-3">
<?php foreach ($cards as $type => [$desc, $features]): $s = $servers[$type] ?? ['installed' => false, 'version' => '', 'running' => false]; $isActive = $active === $type; ?>
  <div class="card flex flex-col gap-4 <?= $isActive ? 'ring-2 ring-brand' : '' ?>">
    <div class="flex items-start justify-between gap-2">
      <div><h2 class="h2"><?= e(Havoc\Services\WebServerService::NAMES[$type]) ?></h2>
        <p class="text-xs text-slate-400"><?= $s['installed'] ? e(t('ws.version', ['v' => $s['version'] ?: '?'])) : e(t('stack.not_installed_short')) ?></p></div>
      <?php if ($isActive): ?><span class="badge badge-ok"><?= e(t('ws.active')) ?></span>
      <?php elseif ($s['installed']): ?><span class="badge"><?= e(t('ws.installed')) ?></span><?php endif; ?>
    </div>
    <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t($desc)) ?></p>
    <ul class="space-y-1 text-sm">
      <?php foreach ($features as $f): $neg = str_contains($f, '_no_'); ?><li class="flex gap-2"><span class="<?= $neg ? 'text-slate-400' : 'text-emerald-600' ?>"><?= $neg ? '–' : '✓' ?></span><span class="<?= $neg ? 'text-slate-500' : '' ?>"><?= e(t($f)) ?></span></li><?php endforeach; ?>
    </ul>
    <div class="mt-auto space-y-3">
      <?php if (!$s['installed']): ?>
        <form method="post" action="/server/webserver/<?= $type ?>/install" class="space-y-2" data-confirm="<?= e(t($type === 'nginx' ? 'ws.install_confirm' : 'ws.install_confirm_ls')) ?>"><?= csrf_field() ?>
          <?php if ($type === 'lsws'): ?>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="license_mode" value="trial" checked> <?= e(t('ws.trial')) ?></label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" name="license_mode" value="serial"> <?= e(t('ws.serial')) ?></label>
            <input class="input font-mono" name="serial" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off">
          <?php endif; ?>
          <button class="btn w-full" <?= $busy ? 'disabled' : '' ?>><?= e(t('ws.install')) ?></button>
        </form>
      <?php elseif (!$isActive): ?>
        <form method="post" action="/server/webserver/<?= $type ?>/activate" data-confirm="<?= e(t('ws.activate_confirm', ['name' => Havoc\Services\WebServerService::NAMES[$type]])) ?>"><?= csrf_field() ?>
          <button class="btn w-full" <?= $busy ? 'disabled' : '' ?>><?= e(t('ws.activate')) ?></button></form>
      <?php else: ?>
        <p class="text-center text-sm text-slate-500"><?= e(t('ws.serving')) ?></p>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<p class="mt-4 text-xs text-slate-500"><?= e(t('ws.note')) ?></p>
