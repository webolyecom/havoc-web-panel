<?php
/** @var array $c cached check, $s helper status (state, finished, log, reboot) */
$running = $s['state'] === 'running';
$pk = $c['packages'];
$imp = array_values(array_filter($pk, static fn($p) => $p['security'] || $p['php']));
$other = count($pk) - count($imp);
$reboot = $s['reboot']['needed'] ?? ($c['reboot']['needed'] ?? false);
$rebootPk = $s['reboot']['packages'] ?? ($c['reboot']['packages'] ?? []);
$when = static fn(int $ts) => $ts ? date('Y-m-d H:i', $ts) : '–';
?>
<?php if ($running): ?><div data-autorefresh></div><?php endif; ?>
<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
  <div><h1 class="h1 mb-0"><?= e(t('osu.title')) ?></h1><p class="mt-1 max-w-3xl text-sm text-slate-500"><?= e(t('osu.intro')) ?></p></div>
  <form method="post" action="/server/updates/check"><?= csrf_field() ?>
    <button class="btn-ghost border border-slate-300 dark:border-slate-700"<?= $running ? ' disabled' : '' ?>><?= e(t('osu.check')) ?></button></form>
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-3">
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('osu.k_important')) ?></p>
    <p class="mt-1 text-2xl font-bold <?= $imp ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' ?>"><?= count($imp) ?></p>
    <p class="text-xs text-slate-500"><?= e(t('osu.k_important_hint')) ?></p></div>
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('osu.k_other')) ?></p><p class="mt-1 text-2xl font-bold"><?= $other ?></p>
    <p class="text-xs text-slate-500"><?= e(t('osu.k_other_hint')) ?></p></div>
  <div class="card"><p class="text-xs text-slate-500"><?= e(t('osu.k_checked')) ?></p><p class="mt-1 text-lg font-semibold"><?= e($when((int)$c['checked'])) ?></p>
    <p class="text-xs text-slate-500"><?= e(t('osu.k_checked_hint')) ?></p></div>
</div>

<?php if ($c['error'] !== ''): ?>
  <div class="mb-6 rounded-xl border border-red-300/60 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><?= e(t('osu.check_failed', ['msg' => $c['error']])) ?></div>
<?php endif; ?>
<?php if ($reboot): ?>
  <div class="mb-6 rounded-xl border border-amber-300/60 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
    <?= e(t('osu.reboot')) ?><?= $rebootPk ? ' (' . e(implode(', ', array_slice($rebootPk, 0, 8))) . ')' : '' ?></div>
<?php endif; ?>

<?php if ($running || $s['log'] !== ''): ?>
  <section class="card mb-6">
    <p class="mb-2 text-sm font-medium"><?= e(t('osu.log')) ?>
      <span class="badge <?= $running ? 'badge-warn' : ($s['state'] === 'done' ? 'badge-ok' : 'badge-err') ?>"><?= e(t('osu.st_' . ($running ? 'running' : ($s['state'] === 'done' ? 'done' : 'failed')))) ?></span>
      <?php if (!$running && $s['finished'] !== ''): ?><span class="text-xs text-slate-500"><?= e(str_replace(['T', 'Z'], [' ', ' UTC'], $s['finished'])) ?></span><?php endif; ?></p>
    <?php if ($running): ?>
      <p class="mb-2 text-xs text-slate-500"><?= e(t('osu.running_hint')) ?></p>
      <pre class="max-h-96 overflow-auto rounded-lg bg-ink-950 p-3 text-xs leading-relaxed text-slate-200"><?= e($s['log']) ?></pre>
    <?php else: ?>
      <details><summary class="cursor-pointer text-sm text-slate-500"><?= e(t('osu.show_log')) ?></summary>
        <pre class="mt-2 max-h-96 overflow-auto rounded-lg bg-ink-950 p-3 text-xs leading-relaxed text-slate-200"><?= e($s['log']) ?></pre></details>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($c['software']): ?>
<section class="card mb-6 p-0" id="software">
  <div class="p-4 pb-2"><h2 class="h2 mb-1"><?= e(t('osu.software')) ?></h2><p class="text-xs text-slate-500"><?= e(t('osu.software_hint')) ?></p></div>
  <div class="overflow-x-auto"><table class="table text-sm">
    <thead><tr><th><?= e(t('osu.sw_name')) ?></th><th><?= e(t('osu.installed')) ?></th><th><?= e(t('osu.new')) ?></th><th><?= e(t('osu.sw_source')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($c['software'] as $k => $sw):
      $has = $sw['source'] === 'apt' ? (bool)$sw['packages'] : $sw['available'] !== '';
      $unknown = $sw['source'] !== 'apt' && ($sw['latest'] ?? '') === '';     // the latest version could not be looked up ?>
      <tr>
        <td class="font-medium"><?= e($sw['label']) ?><?php if ($sw['security']): ?> <span class="badge badge-err"><?= e(t('osu.t_security')) ?></span><?php endif; ?>
          <?php if ($sw['source'] === 'apt' && count($sw['packages']) > 1): ?><div class="text-xs font-normal text-slate-500"><?= e(t('osu.sw_pkgs', ['n' => count($sw['packages'])])) ?></div><?php endif; ?></td>
        <td class="break-all font-mono text-xs text-slate-500"><?= e($sw['installed'] ?: '?') ?></td>
        <td class="break-all font-mono text-xs"><?= $has ? e($sw['available'] ?: t('osu.sw_newer')) : ($unknown ? '<span class="font-sans text-slate-500" title="' . e(t('osu.sw_unknown_hint')) . '">' . e(t('osu.sw_unknown')) . '</span>' : '<span class="font-sans text-emerald-600 dark:text-emerald-400">' . e(t('osu.sw_current')) . '</span>') ?></td>
        <td class="text-xs text-slate-500"><?= e(t('osu.src_' . str_replace('.', '_', $sw['source']))) ?></td>
        <td class="text-right"><?php if ($has || $unknown): ?>
          <form method="post" action="/server/updates" data-confirm="<?= e(t('osu.sw_confirm_' . ($k === 'meilisearch' ? 'meili' : ($k === 'lsws' || $k === 'nginx' || $k === 'ols' ? 'web' : 'default')), ['name' => $sw['label']])) ?>"><?= csrf_field() ?>
            <input type="hidden" name="mode" value="component"><input type="hidden" name="component" value="<?= e($k) ?>">
            <button class="<?= $has ? 'btn' : 'btn-ghost border border-slate-300 dark:border-slate-700' ?> px-3 py-1 text-xs"<?= $running ? ' disabled' : '' ?>><?= e(t($has ? 'osu.sw_update' : 'osu.sw_check_update')) ?></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<section class="card p-0">
  <div class="flex flex-wrap items-center justify-between gap-3 p-4">
    <h2 class="h2 mb-0"><?= e(t('osu.packages')) ?></h2>
    <?php if ($pk && !$running): ?>
      <div class="flex flex-wrap gap-2">
        <?php if ($imp): ?>
          <form method="post" action="/server/updates" data-confirm="<?= e(t('osu.confirm_security', ['n' => count($imp)])) ?>"><?= csrf_field() ?><input type="hidden" name="mode" value="security">
            <button class="btn"><?= e(t('osu.install_security', ['n' => count($imp)])) ?></button></form>
        <?php endif; ?>
        <form method="post" action="/server/updates" data-confirm="<?= e(t('osu.confirm_all', ['n' => count($pk)])) ?>"><?= csrf_field() ?><input type="hidden" name="mode" value="all">
          <button class="<?= $imp ? 'btn-ghost border border-slate-300 dark:border-slate-700' : 'btn' ?>"><?= e(t('osu.install_all', ['n' => count($pk)])) ?></button></form>
      </div>
    <?php endif; ?>
  </div>
  <?php if (!$pk): ?>
    <p class="px-4 pb-4 text-sm text-emerald-700 dark:text-emerald-300"><?= e(t($c['checked'] ? 'osu.none' : 'osu.never')) ?></p>
  <?php else: ?>
    <div class="overflow-x-auto"><table class="table text-sm">
      <thead><tr><th><?= e(t('osu.package')) ?></th><th><?= e(t('osu.installed')) ?></th><th><?= e(t('osu.new')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pk as $p): ?>
        <tr><td class="font-mono text-xs"><?= e($p['name']) ?></td><td class="break-all font-mono text-xs text-slate-500"><?= e($p['from']) ?></td><td class="break-all font-mono text-xs"><?= e($p['to']) ?></td>
          <td class="whitespace-nowrap text-right"><?php if ($p['security']): ?><span class="badge badge-err"><?= e(t('osu.t_security')) ?></span><?php elseif ($p['php']): ?><span class="badge badge-warn">PHP</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p class="p-4 pt-3 text-xs text-slate-500"><?= e(t('osu.how')) ?></p>
</section>

<section class="card mt-6">
  <h2 class="h2"><?= e(t('osu.hardening')) ?></h2>
  <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t('osu.hardening_text')) ?></p>
</section>
