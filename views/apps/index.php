<?php $status = ['new' => 'badge', 'deploying' => 'badge-warn', 'running' => 'badge-ok', 'stopped' => 'badge', 'failed' => 'badge-err']; ?>
<div class="mb-6"><h1 class="h1 mb-0"><?= e(t('app.title')) ?></h1><p class="mt-1 max-w-3xl text-sm text-slate-500"><?= e(t('app.intro')) ?></p></div>

<section class="card mb-6 p-0">
  <div class="p-4 pb-2"><h2 class="h2 mb-0"><?= e(t('app.list')) ?></h2></div>
  <?php if (!$apps): ?>
    <p class="px-4 pb-4 text-sm text-slate-500"><?= e(t('app.none')) ?></p>
  <?php else: ?>
    <div class="overflow-x-auto"><table class="table text-sm">
      <thead><tr><th><?= e(t('app.name')) ?></th><th><?= e(t('app.domain')) ?></th><th><?= e(t('app.port')) ?></th><th><?= e(t('app.status')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($apps as $w): ?>
        <tr>
          <td><a class="link font-medium" href="/apps/<?= e($w['name']) ?>"><?= e($w['name']) ?></a></td>
          <td><a class="link" href="<?= e($w['url']) ?>" target="_blank" rel="noopener"><?= e($w['domain']) ?></a></td>
          <td class="font-mono text-xs text-slate-500">127.0.0.1:<?= (int)$w['port'] ?></td>
          <td><span class="badge <?= $status[$w['status']] ?? 'badge' ?>"><?= e(t('app.st_' . $w['status'])) ?></span></td>
          <td class="whitespace-nowrap text-right">
            <?php foreach (['start' => 'app.start', 'restart' => 'app.restart', 'stop' => 'app.stop'] as $act => $lbl): ?>
              <form class="inline" method="post" action="/apps/<?= e($w['name']) ?>/control"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $act ?>">
                <button class="btn-ghost border border-slate-300 px-2 py-1 text-xs dark:border-slate-700"><?= e(t($lbl)) ?></button></form>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>

<section class="card">
  <h2 class="h2"><?= e(t('app.create')) ?></h2>
  <?php if (!$free): ?>
    <p class="text-sm text-slate-500"><?= e(t('app.no_free_domain')) ?></p>
  <?php else: ?>
    <form method="post" action="/apps" class="grid gap-4 sm:grid-cols-2" data-confirm="<?= e(t('app.create_confirm')) ?>"><?= csrf_field() ?>
      <div><label class="label" for="a-name"><?= e(t('app.name')) ?></label>
        <input class="input" id="a-name" name="name" required pattern="[a-z][a-z0-9-]{1,30}" placeholder="starminer" autocomplete="off" spellcheck="false">
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.name_hint')) ?></p></div>
      <div><label class="label" for="a-domain"><?= e(t('app.domain')) ?></label>
        <select class="input" id="a-domain" name="domain" required><?php foreach ($free as $f): ?><option><?= e($f) ?></option><?php endforeach; ?></select>
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.domain_hint')) ?></p></div>
      <div><label class="label" for="a-start"><?= e(t('app.start_command')) ?></label>
        <input class="input" id="a-start" name="start_command" required placeholder="./starminer-server" autocomplete="off" spellcheck="false">
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.start_hint')) ?></p></div>
      <div><label class="label" for="a-health"><?= e(t('app.health_path')) ?></label>
        <input class="input" id="a-health" name="health_path" value="/healthz" autocomplete="off" spellcheck="false">
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.health_hint')) ?></p></div>
      <div><label class="label" for="a-ws"><?= e(t('app.ws_paths')) ?></label>
        <input class="input" id="a-ws" name="ws_paths" placeholder="/ws" autocomplete="off" spellcheck="false">
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.ws_hint')) ?></p></div>
      <div><label class="label" for="a-shared"><?= e(t('app.shared_paths')) ?></label>
        <input class="input" id="a-shared" name="shared_paths" placeholder="data, packs, var" autocomplete="off" spellcheck="false">
        <p class="mt-1 text-xs text-slate-500"><?= e(t('app.shared_hint')) ?></p></div>
      <div class="sm:col-span-2"><button class="btn"><?= e(t('app.create_btn')) ?></button>
        <span class="ml-2 text-xs text-slate-500"><?= e(t('app.create_note')) ?></span></div>
    </form>
  <?php endif; ?>
</section>
