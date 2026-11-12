<?php $cur = $st['ports'] ? (int)$st['ports'][0] : 0; $p = $st['pending']; ?>
<h1 class="h1"><?= e(t('nav.panel_settings')) ?></h1>
<div class="grid gap-6 lg:grid-cols-2">
  <div class="card space-y-4">
    <h2 class="h2"><?= e(t('panel.port')) ?></h2>
    <?php if ($p): ?>
      <div class="space-y-3 rounded-lg bg-amber-100 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">
        <p class="font-semibold"><?= e(t('panel.pending_title')) ?></p>
        <p><?= e(t('panel.pending_text', ['new' => $p['new'], 'old' => $p['old'], 'time' => date('H:i:s', (int)$p['deadline'])])) ?></p>
        <?php if ($via === (int)$p['new']): ?>
          <p><?= e(t('panel.confirm_here')) ?></p>
          <form method="post" action="/server/port/confirm"><?= csrf_field() ?><button class="btn"><?= e(t('panel.confirm_btn', ['port' => $p['new']])) ?></button></form>
        <?php else: ?>
          <a class="btn inline-flex" href="https://<?= e($host) ?>:<?= (int)$p['new'] ?>/server/settings"><?= e(t('panel.open_new')) ?> (https://<?= e($host) ?>:<?= (int)$p['new'] ?>)</a>
        <?php endif; ?>
      </div>
      <form method="post" action="/server/port/revert"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('panel.revert_btn', ['port' => $p['old']])) ?></button></form>
      <div data-autorefresh></div>
    <?php else: ?>
      <p class="text-sm"><?= e(t('panel.current_port', ['port' => $cur ?: '?'])) ?></p>
      <form method="post" action="/server/port" class="flex items-end gap-3" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?>
        <div class="flex-1"><label class="label" for="port"><?= e(t('panel.new_port')) ?></label><input class="input" id="port" type="number" name="port" min="1024" max="65535" required placeholder="8443"></div>
        <button class="btn"><?= e(t('panel.port_change')) ?></button></form>
      <p class="text-xs text-slate-500"><?= e(t('panel.port_hint')) ?></p>
    <?php endif; ?>
  </div>
  <div class="card space-y-3">
    <h2 class="h2"><?= e(t('panel.update_title')) ?></h2>
    <p class="text-sm text-slate-500"><?= e(t('panel.update_hint')) ?></p>
    <form method="post" action="/server/update" data-confirm="<?= e(t('server.update_confirm')) ?>"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('server.update')) ?></button></form>
  </div>
</div>
