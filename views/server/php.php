<?php
/** @var array $o overview (installed, available, panel, cli), $s update job status (state, finished, log) */
$running = $s['state'] === 'running';
$badge = static function (array $sp): string {
    $cls = ['active' => 'badge-ok', 'security' => 'badge', 'soon' => 'badge-warn', 'ended' => 'badge-err', 'unknown' => 'badge'][$sp['state']];
    return '<span class="badge ' . $cls . '">' . e(t('phpv.st_' . $sp['state'])) . '</span>';
};
$until = static fn(array $sp): string => $sp['security'] ? t('phpv.until', ['d' => $sp['security']]) : '';
?>
<?php if ($running && preg_match('/^Update \(php-/', $s['log'])): ?><div data-autorefresh></div><?php endif; ?>
<div class="mb-6"><h1 class="h1 mb-0"><?= e(t('phpv.title')) ?></h1><p class="mt-1 max-w-3xl text-sm text-slate-500"><?= e(t('phpv.intro')) ?></p></div>

<?php $phpJob = (bool)preg_match('/^Update \(php-(install|remove)/', $s['log']); if ($phpJob && ($running || $s['log'] !== '')): ?>
  <section class="card mb-6">
    <p class="mb-2 text-sm font-medium"><?= e(t('osu.log')) ?>
      <span class="badge <?= $running ? 'badge-warn' : ($s['state'] === 'done' ? 'badge-ok' : 'badge-err') ?>"><?= e(t('osu.st_' . ($running ? 'running' : ($s['state'] === 'done' ? 'done' : 'failed')))) ?></span></p>
    <?php if ($running): ?><p class="mb-2 text-xs text-slate-500"><?= e(t('phpv.running_hint')) ?></p><?php endif; ?>
    <details<?= $running ? ' open' : '' ?>><summary class="cursor-pointer text-sm text-slate-500"><?= e(t('osu.show_log')) ?></summary>
      <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-ink-950 p-3 text-xs leading-relaxed text-slate-200"><?= e($s['log']) ?></pre></details>
  </section>
<?php endif; ?>

<section class="card mb-6 p-0">
  <div class="p-4 pb-2"><h2 class="h2 mb-0"><?= e(t('phpv.installed')) ?></h2></div>
  <div class="overflow-x-auto"><table class="table text-sm">
    <thead><tr><th><?= e(t('phpv.version')) ?></th><th><?= e(t('phpv.support')) ?></th><th><?= e(t('phpv.sites')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($o['installed'] as $i): $v = $i['version']; $sp = $i['support']; ?>
      <tr>
        <td><span class="font-semibold">PHP <?= e($v) ?></span> <span class="font-mono text-xs text-slate-500"><?= e($i['package']) ?></span>
          <?php if ($v === $o['panel']): ?> <span class="badge" title="<?= e(t('phpv.panel_hint')) ?>"><?= e(t('phpv.panel')) ?></span><?php endif; ?>
          <?php if ($v === $o['cli']): ?> <span class="badge" title="<?= e(t('phpv.cli_hint')) ?>"><?= e(t('phpv.cli')) ?></span><?php endif; ?></td>
        <td><?= $badge($sp) ?> <span class="text-xs text-slate-500"><?= e($until($sp)) ?></span></td>
        <td><?php if ($i['sites']): ?>
            <details><summary class="cursor-pointer"><?= e(t('phpv.n_sites', ['n' => count($i['sites'])])) ?></summary>
              <ul class="mt-1 space-y-0.5 text-xs"><?php foreach ($i['sites'] as $d): ?><li><a class="link" href="/domains/<?= (int)$d['id'] ?>/php"><?= e($d['name']) ?></a></li><?php endforeach; ?></ul></details>
          <?php else: ?><span class="text-slate-400"><?= e(t('phpv.no_sites')) ?></span><?php endif; ?></td>
        <td class="text-right">
          <?php if ($i['blocked'] === ''): ?>
            <form method="post" action="/server/php/remove" data-confirm="<?= e(t('phpv.confirm_remove', ['v' => $v])) ?>"><?= csrf_field() ?><input type="hidden" name="version" value="<?= e($v) ?>">
              <button class="btn-danger px-3 py-1 text-xs"<?= $running ? ' disabled' : '' ?>><?= e(t('phpv.remove')) ?></button></form>
          <?php else: ?><span class="text-xs text-slate-500"><?= e(t('phpv.keep_' . $i['blocked'])) ?></span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="p-4 pt-2 text-xs text-slate-500"><?= e(t('phpv.remove_hint')) ?></p>
</section>

<section class="card">
  <h2 class="h2"><?= e(t('phpv.add')) ?></h2>
  <?php if (!$o['available']): ?>
    <p class="text-sm text-slate-500"><?= e(t('phpv.none_available')) ?></p>
  <?php else: ?>
    <form method="post" action="/server/php/install" class="flex flex-wrap items-end gap-3" data-confirm="<?= e(t('phpv.confirm_install')) ?>"><?= csrf_field() ?>
      <div><label class="label" for="php-new"><?= e(t('phpv.version')) ?></label>
        <select class="input" id="php-new" name="version">
          <?php foreach (array_reverse($o['available']) as $a): $sp = $a['support']; ?>
            <option value="<?= e($a['version']) ?>">PHP <?= e($a['version']) ?> — <?= e(t('phpv.st_' . $sp['state'])) ?><?= $sp['security'] ? ' (' . e($until($sp)) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn"<?= $running ? ' disabled' : '' ?>><?= e(t('phpv.install')) ?></button>
    </form>
    <p class="mt-3 text-xs text-slate-500"><?= e(t('phpv.install_hint')) ?></p>
  <?php endif; ?>
</section>
