<?php
$id = (int)$d['id'];
$acct = 'hv' . (int)$d['user_id'];
$res = $renameResult;
?>
<div class="max-w-3xl space-y-6">
<?php if ($res): ?>
  <section class="card space-y-3 border-emerald-300/60 dark:border-emerald-500/30">
    <h2 class="h2 mb-0"><?= e(t('ren.result_title', ['old' => $res['old'], 'new' => $res['new']])) ?></h2>
    <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
      <li><?= e(t($res['ssl'] === 'kept' ? 'ren.r_ssl_kept' : 'ren.r_ssl_requested')) ?> <a class="link" href="/domains/<?= $id ?>/ssl"><?= e(t('dsec.ssl')) ?></a></li>
      <?php if ($res['backup']): ?><li><?= e(t('ren.r_backup')) ?> <a class="link" href="/backups"><?= e(t('nav.backups')) ?></a></li><?php endif; ?>
      <?php foreach ($res['addresses'] as $a): ?>
        <?php if ($a['config']): ?><li><?= e(t('ren.r_config', ['site' => $a['site'], 'files' => implode(', ', $a['config'])])) ?></li><?php endif; ?>
        <?php if ($a['wordpress']): ?><li><?= e($a['ok'] ? t('ren.r_wp', ['site' => $a['site'], 'n' => $a['replacements']]) : t('ren.r_wp_failed', ['site' => $a['site'], 'msg' => $a['note']])) ?></li><?php endif; ?>
      <?php endforeach; ?>
      <li><?= e(t('ren.r_dns', ['new' => $res['new']])) ?></li>
    </ul>
    <?php if ($res['warnings']): ?>
      <div class="rounded-lg border border-amber-300/60 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        <p class="font-medium"><?= e(t('ren.r_warnings')) ?></p>
        <ul class="mt-1 list-disc pl-5"><?php foreach ($res['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if (!in_array($d['type'], ['main', 'addon'], true)): ?>
  <div class="card text-sm text-slate-600 dark:text-slate-300"><?= e(t('ren.sub_note')) ?></div>
<?php else: ?>
  <form method="get" action="/domains/<?= $id ?>/rename" class="card space-y-3">
    <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t('ren.intro')) ?></p>
    <label class="label" for="ren-name"><?= e(t('ren.new_name')) ?></label>
    <div class="flex flex-wrap gap-2">
      <input class="input max-w-sm flex-1" id="ren-name" name="name" value="<?= e($newName) ?>" placeholder="<?= e(t('ren.placeholder')) ?>" required autocomplete="off" spellcheck="false">
      <button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('ren.check')) ?></button>
    </div>
    <p class="text-xs text-slate-500"><?= e(t('ren.current', ['name' => $d['name']])) ?></p>
  </form>

  <?php if ($planError): ?>
    <div class="rounded-xl border border-red-300/60 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200"><?= e($planError) ?></div>
  <?php endif; ?>

  <?php if ($plan): $okAll = $dnsCheck !== null && !empty($dnsCheck[$plan['new']]['ok']); ?>
  <section class="card space-y-4">
    <h2 class="h2 mb-0"><?= e(t('ren.plan_title', ['old' => $plan['old'], 'new' => $plan['new']])) ?></h2>
    <ul class="list-disc space-y-1.5 pl-5 text-sm text-slate-600 dark:text-slate-300">
      <li><?= e(t('ren.p_site')) ?></li>
      <li><?= e(t('ren.p_folder')) ?> <code class="break-all font-mono text-xs">/home/<?= e($acct) ?>/domains/<?= e($plan['old']) ?></code> → <code class="break-all font-mono text-xs">/home/<?= e($acct) ?>/domains/<?= e($plan['new']) ?></code></li>
      <?php foreach ($plan['subs'] as $s): ?><li><?= e(t('ren.p_sub', ['old' => $s['old'], 'new' => $s['new']])) ?></li><?php endforeach; ?>
      <?php $parked = array_column(array_filter($plan['aliases'], static fn($a) => !$a['alias_redirect']), 'name'); if ($parked): ?>
        <li><?= e(t('ren.p_aliases', ['names' => implode(', ', $parked)])) ?></li><?php endif; ?>
      <?php if ($plan['ftp']): ?><li><?= e(t('ren.p_ftp', ['n' => $plan['ftp']])) ?></li><?php endif; ?>
      <?php if ($plan['cron']): ?><li><?= e(t('ren.p_cron', ['n' => $plan['cron']])) ?></li><?php endif; ?>
      <?php if ($plan['dns']): ?><li><?= e(t('ren.p_dns', ['new' => $plan['new']])) ?></li><?php endif; ?>
      <li><?= e(t('ren.p_ssl')) ?></li>
      <li><?= e(t('ren.p_config')) ?></li>
      <li><?= e(t('ren.p_stays')) ?></li>
    </ul>

    <div>
      <h3 class="mb-2 text-sm font-semibold"><?= e(t('ren.dns_title')) ?></h3>
      <?php if ($dnsCheck === null): ?>
        <p class="text-sm text-slate-500"><?= e(t('ren.dns_unknown')) ?></p>
      <?php else: ?>
        <ul class="space-y-1 text-sm">
          <?php foreach ($dnsCheck as $n => $r): ?>
            <li class="flex flex-wrap items-center gap-2"><span class="font-mono text-xs"><?= e($n) ?></span>
              <?php if (!empty($r['ok'])): ?><span class="badge badge-ok"><?= e(t('ren.dns_ok')) ?></span>
              <?php else: ?><span class="badge badge-warn"><?= e(t('ssl.why_' . ($r['reason'] ?? 'http_fail'))) ?><?= isset($r['ip']) ? ' (' . e($r['ip']) . ')' : '' ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!$okAll): ?><p class="mt-2 text-sm text-amber-700 dark:text-amber-300"><?= e(t('ren.dns_warn', ['new' => $plan['new']])) ?></p><?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <form method="post" action="/domains/<?= $id ?>/rename" class="card space-y-4" data-confirm="<?= e(t('ren.confirm', ['old' => $plan['old'], 'new' => $plan['new']])) ?>"><?= csrf_field() ?>
    <input type="hidden" name="name" value="<?= e($plan['new']) ?>">
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="keep_old" value="1" checked>
      <span><?= e(t('ren.keep_old')) ?><span class="block text-xs text-slate-500"><?= e(t('ren.keep_old_hint', ['names' => implode(', ', array_merge($plan['keep'], array_column($plan['subs'], 'old'))), 'new' => $plan['new']])) ?></span></span></label>
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="wordpress" value="1" checked>
      <span><?= e(t($plan['wordpress'] ? 'ren.wp_found' : 'ren.wp')) ?><span class="block text-xs text-slate-500"><?= e(t('ren.wp_hint', ['old' => $plan['old'], 'new' => $plan['new']])) ?><?= $plan['dbs'] ? ' ' . e(t('ren.wp_backup')) : '' ?></span></span></label>
    <div class="flex flex-wrap items-center gap-3">
      <button class="btn"><?= e(t('ren.submit', ['new' => $plan['new']])) ?></button>
      <span class="text-xs text-slate-500"><?= e(t('ren.submit_hint')) ?></span>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
