<?php
$status = ['new' => 'badge', 'deploying' => 'badge-warn', 'running' => 'badge-ok', 'stopped' => 'badge', 'failed' => 'badge-err'];
$rt = $s['runtime'];
$fmtB = static fn(?int $b) => $b === null ? '–' : bytes($b);
$fmtT = static function (?int $sec): string {
    if ($sec === null) {
        return '–';
    }
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    return $h ? "{$h}h {$m}m" : ($m ? "{$m}m" : "{$sec}s");
};
?>
<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
  <div>
    <a href="/apps" class="text-sm text-slate-500 hover:text-brand">← <?= e(t('app.title')) ?></a>
    <h1 class="h1 mb-0 mt-1"><?= e($app['name']) ?> <span class="badge <?= $status[$app['status']] ?? 'badge' ?> align-middle"><?= e(t('app.st_' . $app['status'])) ?></span></h1>
    <p class="mt-1 text-sm text-slate-500"><a class="link" href="https://<?= e($domain['name']) ?>/" target="_blank" rel="noopener"><?= e($domain['name']) ?></a>
      · <span class="font-mono text-xs">127.0.0.1:<?= (int)$app['port'] ?></span> · <span class="font-mono text-xs"><?= e($app['start_command']) ?></span></p>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php foreach (['start' => 'app.start', 'restart' => 'app.restart', 'stop' => 'app.stop'] as $act => $lbl): ?>
      <form method="post" action="/apps/<?= e($app['name']) ?>/control"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>">
        <button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t($lbl)) ?></button></form>
    <?php endforeach; ?>
  </div>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('app.k_state')) ?></p><p class="mt-1 font-semibold"><?= e($rt['active_state'] ?: '–') ?> <span class="text-xs font-normal text-slate-500"><?= e($rt['sub_state'] ?? '') ?></span></p>
    <p class="text-xs text-slate-500"><?= e(t('app.k_health')) ?>: <?= ($rt['health']['ok'] ?? false) ? '<span class="text-emerald-600 dark:text-emerald-400">200</span>' : '<span class="text-rose-600 dark:text-rose-400">' . (int)($rt['health']['status'] ?? 0) . '</span>' ?></p></div>
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('app.k_uptime')) ?></p><p class="mt-1 font-semibold"><?= e($fmtT($rt['uptime_sec'] ?? null)) ?></p>
    <p class="text-xs text-slate-500"><?= e(t('app.k_restarts')) ?>: <?= (int)($rt['restarts'] ?? 0) ?></p></div>
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('app.k_memory')) ?></p><p class="mt-1 font-semibold"><?= e($fmtB($rt['memory_bytes'] ?? null)) ?></p>
    <p class="text-xs text-slate-500">PID: <?= $rt['pid'] ? (int)$rt['pid'] : '–' ?></p></div>
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('app.k_release')) ?></p><p class="mt-1 font-mono text-sm font-semibold"><?= e($rt['current_release'] ?: '–') ?></p></div>
</div>

<section class="card mb-6">
  <h2 class="h2"><?= e(t('app.deploy')) ?></h2>
  <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t('app.deploy_intro')) ?></p>
  <div class="mt-3 flex flex-wrap items-center gap-3">
    <button class="btn-ghost border border-slate-300 dark:border-slate-700" data-upload-link="/apps/<?= e($app['name']) ?>/upload-link"><?= e(t('app.get_upload_link')) ?></button>
    <span data-upload-out class="text-xs text-slate-500"></span>
  </div>
  <?php if ($upload): ?>
    <div class="mt-4 overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
      <table class="table text-sm"><thead><tr><th><?= e(t('app.upload_file')) ?></th><th><?= e(t('app.upload_size')) ?></th><th><?= e(t('app.upload_when')) ?></th><th></th></tr></thead><tbody>
      <?php foreach ($upload as $up): ?>
        <tr><td class="font-mono text-xs"><?= e(basename($up['path'])) ?></td><td class="text-slate-500"><?= e(bytes((int)$up['bytes'])) ?></td><td class="text-xs text-slate-500"><?= e($up['uploaded']) ?></td>
          <td class="text-right"><form method="post" action="/apps/<?= e($app['name']) ?>/deploy" data-confirm="<?= e(t('app.deploy_confirm')) ?>"><?= csrf_field() ?>
            <input type="hidden" name="upload" value="<?= e($up['path']) ?>"><button class="btn px-3 py-1 text-xs"><?= e(t('app.deploy_btn')) ?></button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
  <?php else: ?>
    <p class="mt-3 text-xs text-slate-500"><?= e(t('app.no_upload')) ?></p>
  <?php endif; ?>
</section>

<section class="card mb-6">
  <div class="mb-2 flex items-center justify-between"><h2 class="h2 mb-0"><?= e(t('app.releases')) ?></h2></div>
  <?php if (!$s['releases']): ?><p class="text-sm text-slate-500"><?= e(t('app.no_release')) ?></p>
  <?php else: ?>
    <div class="overflow-x-auto"><table class="table text-sm"><thead><tr><th><?= e(t('app.release')) ?></th><th><?= e(t('app.health')) ?></th><th><?= e(t('app.upload_when')) ?></th><th></th></tr></thead><tbody>
    <?php foreach ($s['releases'] as $r): ?>
      <tr><td class="font-mono text-xs"><?= e($r['ref']) ?><?php if ($r['active']): ?> <span class="badge badge-ok"><?= e(t('app.active')) ?></span><?php endif; ?></td>
        <td><?= $r['health'] === 'ok' ? '<span class="badge badge-ok">ok</span>' : ($r['health'] === 'failed' ? '<span class="badge badge-err">' . e(t('app.failed')) . '</span>' : '–') ?></td>
        <td class="text-xs text-slate-500"><?= e($r['at']) ?></td>
        <td class="text-right"><?php if (!$r['active']): ?><form method="post" action="/apps/<?= e($app['name']) ?>/rollback" data-confirm="<?= e(t('app.rollback_confirm', ['ref' => $r['ref']])) ?>"><?= csrf_field() ?>
          <input type="hidden" name="release" value="<?= e($r['ref']) ?>"><button class="btn-ghost border border-slate-300 px-3 py-1 text-xs dark:border-slate-700"><?= e(t('app.rollback')) ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<section class="card mb-6" id="env">
  <h2 class="h2"><?= e(t('app.env')) ?></h2>
  <p class="text-sm text-slate-500"><?= e(t('app.env_intro')) ?></p>
  <form method="post" action="/apps/<?= e($app['name']) ?>/env" class="mt-3 space-y-3"><?= csrf_field() ?>
    <?php if ($env_keys): ?>
      <div><p class="mb-1 text-xs font-medium text-slate-500"><?= e(t('app.env_current')) ?></p>
        <div class="flex flex-wrap gap-2"><?php foreach ($env_keys as $k): ?>
          <label class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2 py-1 text-xs dark:border-slate-700">
            <span class="font-mono"><?= e($k) ?></span> = <span class="text-slate-400">••••</span>
            <?php if (!in_array($k, ['PORT', 'ADDR'], true)): ?><input type="checkbox" name="unset[]" value="<?= e($k) ?>" title="<?= e(t('app.env_remove')) ?>"><?php endif; ?>
          </label>
        <?php endforeach; ?></div>
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.env_unset_hint')) ?></p></div>
    <?php endif; ?>
    <div data-env-rows class="space-y-2">
      <div class="flex gap-2"><input class="input font-mono" name="env[0][key]" placeholder="KEY" pattern="[A-Z_][A-Z0-9_]*" autocomplete="off" spellcheck="false">
        <input class="input" name="env[0][value]" placeholder="<?= e(t('app.env_value')) ?>" autocomplete="off" spellcheck="false"></div>
    </div>
    <button type="button" class="link text-xs" data-env-add><?= e(t('app.env_add')) ?></button>
    <div class="flex flex-wrap items-center gap-3 pt-2">
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="restart" value="1"> <?= e(t('app.env_restart')) ?></label>
      <button class="btn"><?= e(t('common.save')) ?></button>
    </div>
  </form>
</section>

<section class="card mb-6">
  <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
    <h2 class="h2 mb-0"><?= e(t('app.logs')) ?></h2>
    <label class="flex items-center gap-2 text-xs text-slate-500"><input type="checkbox" id="app-log-auto" checked> <?= e(t('app.logs_auto')) ?></label>
  </div>
  <pre id="app-log" data-url="/apps/<?= e($app['name']) ?>/logs" class="max-h-96 overflow-auto rounded-lg bg-ink-950 p-3 text-xs leading-relaxed text-slate-200"><?= e(t('app.logs_loading')) ?></pre>
</section>

<section class="card border-rose-300/60 dark:border-rose-500/30">
  <h2 class="h2"><?= e(t('app.danger')) ?></h2>
  <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t('app.destroy_intro')) ?></p>
  <form method="post" action="/apps/<?= e($app['name']) ?>/destroy" class="mt-3 flex flex-wrap items-end gap-3" data-confirm="<?= e(t('app.destroy_confirm', ['name' => $app['name']])) ?>"><?= csrf_field() ?>
    <div><label class="label" for="a-del"><?= e(t('app.destroy_type', ['name' => $app['name']])) ?></label>
      <input class="input" id="a-del" name="confirm" autocomplete="off" spellcheck="false" placeholder="<?= e($app['name']) ?>"></div>
    <button class="btn-danger"><?= e(t('app.destroy_btn')) ?></button>
  </form>
</section>

<script src="<?= e(asset('apps.js')) ?>" defer></script>
