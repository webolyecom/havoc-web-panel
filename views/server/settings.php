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
  <div class="card space-y-4" id="hostname">
    <h2 class="h2"><?= e(t('ph.title')) ?></h2>
    <div class="space-y-1 text-sm">
      <?php if ($ph['host'] !== '' && $ph['trusted']): ?>
        <p><?= e(t('ph.current')) ?> <a class="link font-medium" href="https://<?= e($ph['host']) ?>:<?= (int)$ph['port'] ?>/">https://<?= e($ph['host']) ?>:<?= (int)$ph['port'] ?></a>
          <?php if (!empty($ph['proxy'])): ?> · <a class="link font-medium" href="https://<?= e($ph['host']) ?>/">https://<?= e($ph['host']) ?></a><?php endif; ?></p>
        <p class="text-slate-500"><span class="badge badge-ok"><?= e($ph['issuer']) ?></span> <?= e(t('ph.expires', ['d' => $ph['expires']])) ?> · <?= e(t('ph.renews')) ?></p>
      <?php else: ?>
        <p><span class="badge badge-warn"><?= e(t('ph.selfsigned')) ?></span></p>
        <p class="text-slate-500"><?= e(t('ph.hint')) ?></p>
      <?php endif; ?>
    </div>
    <form method="post" action="/server/hostname" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-confirm="<?= e(t('common.confirm')) ?>"><?= csrf_field() ?>
      <div><label class="label" for="ph-host"><?= e(t('ph.hostname')) ?></label><input class="input" id="ph-host" name="hostname" required placeholder="panel.example.com" value="<?= e($ph['host']) ?>"></div>
      <div><label class="label" for="ph-mail"><?= e(t('ph.email')) ?></label><input class="input" id="ph-mail" type="email" name="email" placeholder="<?= e((string)Havoc\Core\Auth::user()['email']) ?>"></div>
      <button class="btn"><?= e(t('ph.issue')) ?></button>
    </form>
    <p class="text-xs text-slate-500"><?= e(t('ph.dns_hint')) ?><?php if ($ph['webserver'] !== '' && $ph['webserver'] !== 'nginx'): ?> <span class="text-amber-600"><?= e(t('ph.needs_nginx')) ?></span><?php endif; ?></p>
  </div>
  <div class="card space-y-3" id="claude">
    <h2 class="h2"><?= e(t('claude.title')) ?></h2>
    <p class="text-sm text-slate-500"><?= e(t('claude.admin_hint')) ?></p>
    <?php $mcpOn = Havoc\Services\OAuthService::enabled(); $mcpHost = $ph['trusted'] && $ph['host'] !== '' ? $ph['host'] . (!empty($ph['proxy']) ? '' : ':' . (int)$ph['port']) : ''; ?>
    <?php if ($mcpOn): ?>
      <div class="text-sm"><?= e(t('claude.url')) ?>
        <?php if ($mcpHost !== ''): ?><code class="select-all ml-1 rounded bg-slate-100 px-2 py-1 font-mono dark:bg-slate-800">https://<?= e($mcpHost) ?>/mcp</code>
        <?php else: ?><span class="text-amber-700 dark:text-amber-300"><?= e(t('claude.need_host')) ?></span><?php endif; ?></div>
      <ol class="list-decimal space-y-1 pl-5 text-xs text-slate-500">
        <li><?= e(t('claude.step1')) ?></li><li><?= e(t('claude.step2')) ?></li><li><?= e(t('claude.step3')) ?></li>
      </ol>
    <?php endif; ?>
    <form method="post" action="/server/claude" class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-3 dark:border-slate-700"><?= csrf_field() ?>
      <input type="hidden" name="enabled" value="0">
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" value="1" <?= $mcpOn ? 'checked' : '' ?>> <?= e(t('claude.enable')) ?></label>
      <button class="btn-ghost border border-slate-300 text-sm dark:border-slate-700"><?= e(t('common.save')) ?></button>
    </form>
  </div>
<?php
  $lat = $check['latest'] ?? null;
  $state = (string)($upd['state'] ?? '');
  $running = $state === 'running';
  $stBadge = ['running' => 'badge-warn', 'done' => 'badge-ok', 'failed' => 'badge-err', 'uptodate' => 'badge-ok'];
?>
  <div class="card space-y-5 lg:col-span-2" id="update">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="h2"><?= e(t('update.title')) ?></h2>
        <?php if (!empty($check['repo'])): ?><p class="text-sm text-slate-500"><?= e(t('update.source')) ?> <a class="link" href="https://github.com/<?= e($check['repo']) ?>/releases" target="_blank" rel="noopener">github.com/<?= e($check['repo']) ?></a></p><?php endif; ?>
      </div>
      <?php if (!$running): ?>
      <form method="post" action="/server/update/check"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('update.check')) ?></button></form>
      <?php endif; ?>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
      <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700"><p class="text-xs text-slate-500"><?= e(t('update.installed')) ?></p><p class="mt-1 text-lg font-semibold">v<?= e(Havoc\Services\UpdateService::current()) ?></p></div>
      <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700"><p class="text-xs text-slate-500"><?= e(t('update.latest')) ?></p>
        <p class="mt-1 text-lg font-semibold"><?= $lat ? 'v' . e($lat['version']) : '–' ?></p>
        <?php if ($lat && $lat['published'] !== ''): ?><p class="text-xs text-slate-500"><?= e(substr($lat['published'], 0, 10)) ?></p><?php endif; ?></div>
      <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700"><p class="text-xs text-slate-500"><?= e(t('common.status')) ?></p>
        <p class="mt-1.5"><?php if ($running): ?><span class="badge badge-warn"><?= e(t('update.st_running')) ?></span>
        <?php elseif ($avail): ?><span class="badge badge-warn"><?= e(t('update.available')) ?></span>
        <?php elseif ($lat): ?><span class="badge badge-ok"><?= e(t('update.uptodate')) ?></span>
        <?php else: ?><span class="badge"><?= e(t('update.none_published')) ?></span><?php endif; ?></p></div>
    </div>
    <p class="text-xs text-slate-500"><?= e(t('update.checked', ['t' => $check['checked'] ? date('Y-m-d H:i', (int)$check['checked']) : '–'])) ?><?php if ($check['error'] !== ''): ?> · <span class="text-rose-600"><?= e(t('update.check_failed', ['msg' => $check['error']])) ?></span><?php endif; ?></p>

    <?php if ($avail && !$running): ?>
    <div class="space-y-3 rounded-lg border border-brand/30 bg-brand/5 p-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="font-semibold"><?= e($avail['name'] !== '' ? $avail['name'] : 'v' . $avail['version']) ?></p>
        <div class="flex items-center gap-2">
          <a class="btn-ghost text-sm" href="<?= e($avail['url']) ?>" target="_blank" rel="noopener"><?= e(t('update.github')) ?></a>
          <form method="post" action="/server/update" data-confirm="<?= e(t('update.confirm', ['v' => $avail['version']])) ?>"><?= csrf_field() ?>
            <input type="hidden" name="tag" value="<?= e($avail['tag']) ?>"><button class="btn"><?= e(t('update.install', ['v' => $avail['version']])) ?></button></form>
        </div>
      </div>
      <?php if (trim($avail['notes']) !== ''): ?>
      <details class="group"><summary class="cursor-pointer text-sm text-slate-600 dark:text-slate-300"><?= e(t('update.notes')) ?></summary>
        <div class="mt-2 max-h-72 overflow-y-auto whitespace-pre-wrap rounded-md bg-white p-3 text-sm dark:bg-slate-900"><?= e($avail['notes']) ?></div></details>
      <?php endif; ?>
      <p class="text-xs text-slate-500"><?= e(t('update.restart_note')) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($state !== ''): ?>
    <div class="space-y-2">
      <p class="text-sm font-medium"><?= e(t('update.log')) ?> <span class="badge <?= $stBadge[$state] ?? '' ?>"><?= e(t('update.st_' . $state)) ?></span>
        <?php if (!empty($upd['time'])): ?><span class="text-xs text-slate-500"><?= e(str_replace(['T', 'Z'], [' ', ' UTC'], (string)$upd['time'])) ?></span><?php endif; ?></p>
      <?php if ($running || $state === 'failed'): ?>
        <pre class="max-h-80 overflow-auto rounded-lg bg-ink-950 p-3 text-xs leading-relaxed text-slate-200"><?= e((string)$upd['log']) ?></pre>
      <?php else: ?>
        <details><summary class="cursor-pointer text-sm text-slate-500"><?= e(t('update.show_log')) ?></summary>
        <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-ink-950 p-3 text-xs leading-relaxed text-slate-200"><?= e((string)$upd['log']) ?></pre></details>
      <?php endif; ?>
      <?php if ($running): ?><div data-autorefresh></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="/server/update/auto" class="flex flex-wrap items-center gap-3 border-t border-slate-200 pt-4 dark:border-slate-700"><?= csrf_field() ?>
      <input type="hidden" name="auto" value="0">
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?>> <?= e(t('update.auto')) ?></label>
      <button class="btn-ghost border border-slate-300 text-sm dark:border-slate-700"><?= e(t('common.save')) ?></button>
      <p class="w-full text-xs text-slate-500"><?= e(t('update.auto_hint')) ?></p>
    </form>
  </div>
</div>
