<?php
$chk = static fn(string $v) => $v === '1' ? 'checked' : '';
?>
<h1 class="h1"><?= e(t('nav.notifications')) ?></h1>
<p class="mb-6 max-w-3xl text-sm text-slate-500"><?= e(t('notify.intro')) ?></p>

<?php if ($open): ?>
<div class="mb-6 rounded-xl border border-amber-300/60 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
  <p class="mb-1 font-semibold"><?= e(t('notify.open_problems')) ?></p>
  <ul class="list-inside list-disc space-y-0.5"><?php foreach ($open as $o): ?><li><?= e($o['message']) ?> <span class="text-xs opacity-70">(<?= e(date('Y-m-d H:i', (int)$o['since'])) ?>)</span></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="post" action="/server/notifications" class="grid gap-6 lg:grid-cols-2" autocomplete="off"><?= csrf_field() ?>
  <div class="card space-y-3">
    <div class="flex items-center justify-between"><h2 class="h2 mb-0"><?= e(t('notify.email')) ?></h2>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="email" value="1" <?= $chk($c['email']) ?>> <?= e(t('notify.enabled')) ?></label></div>
    <div class="grid gap-3 sm:grid-cols-3">
      <div class="sm:col-span-2"><label class="label"><?= e(t('notify.smtp_host')) ?></label><input class="input" name="smtp_host" value="<?= e($c['smtp_host']) ?>" placeholder="smtp.gmail.com"></div>
      <div><label class="label"><?= e(t('notify.smtp_port')) ?></label><input class="input" type="number" name="smtp_port" min="1" max="65535" value="<?= e($c['smtp_port']) ?>"></div>
    </div>
    <div><label class="label"><?= e(t('notify.smtp_secure')) ?></label>
      <select class="input" name="smtp_secure"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => t('notify.none_local')] as $k => $l): ?><option value="<?= $k ?>" <?= $c['smtp_secure'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label class="label"><?= e(t('notify.smtp_user')) ?></label><input class="input" name="smtp_user" value="<?= e($c['smtp_user']) ?>" autocomplete="off"></div>
      <div><label class="label"><?= e(t('notify.smtp_pass')) ?></label><input class="input" type="password" name="smtp_pass" placeholder="<?= e($c['smtp_pass_set'] ? t('notify.unchanged') : '') ?>" autocomplete="new-password">
        <?php if ($c['smtp_pass_set']): ?><label class="mt-1 flex items-center gap-1.5 text-xs text-slate-500"><input type="checkbox" name="smtp_pass_clear" value="1"> <?= e(t('notify.clear_pass')) ?></label><?php endif; ?></div>
    </div>
    <div><label class="label"><?= e(t('notify.smtp_from')) ?></label><input class="input" type="email" name="smtp_from" value="<?= e($c['smtp_from']) ?>" placeholder="panel@example.com"></div>
    <div><label class="label"><?= e(t('notify.email_to')) ?></label><input class="input" name="email_to" value="<?= e($c['email_to']) ?>" placeholder="you@example.com, team@example.com"></div>
    <p class="text-xs text-slate-500"><?= e(t('notify.email_hint')) ?></p>
  </div>

  <div class="card space-y-3" id="telegram">
    <div class="flex items-center justify-between"><h2 class="h2 mb-0">Telegram</h2>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="telegram" value="1" <?= $chk($c['telegram']) ?>> <?= e(t('notify.enabled')) ?></label></div>
    <ol class="list-inside list-decimal space-y-1 text-sm text-slate-600 dark:text-slate-300">
      <li><?= e(t('notify.tg_step1')) ?></li><li><?= e(t('notify.tg_step2')) ?></li><li><?= e(t('notify.tg_step3')) ?></li>
    </ol>
    <div><label class="label"><?= e(t('notify.tg_token')) ?></label><input class="input font-mono" type="password" name="tg_token" placeholder="<?= e($c['tg_token_set'] ? t('notify.unchanged') : '123456789:AA…') ?>" autocomplete="new-password"></div>
    <div><label class="label"><?= e(t('notify.tg_chat')) ?></label>
      <div class="flex gap-2"><input class="input font-mono" name="tg_chat" id="tg-chat" value="<?= e($c['tg_chat']) ?>" placeholder="123456789">
        <button class="btn-ghost shrink-0 border border-slate-300 dark:border-slate-700" formaction="/server/notifications/telegram-chats" formnovalidate><?= e(t('notify.tg_find')) ?></button></div></div>
    <?php if ($chats !== null): ?>
    <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-white/[0.03]">
      <?php if (!$chats): ?><p class="text-slate-500"><?= e(t('notify.tg_no_chats')) ?></p>
      <?php else: ?><p class="mb-1 text-xs text-slate-500"><?= e(t('notify.tg_pick')) ?></p>
        <?php foreach ($chats as $ch): ?><button type="button" class="link block" data-set-chat="<?= e($ch['id']) ?>"><?= e($ch['name'] ?: $ch['id']) ?> <span class="font-mono text-xs text-slate-400"><?= e($ch['id']) ?> · <?= e($ch['type']) ?></span></button><?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card space-y-4 lg:col-span-2">
    <h2 class="h2 mb-0"><?= e(t('notify.rules')) ?></h2>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div><label class="label"><?= e(t('notify.r_disk')) ?></label><input class="input" type="number" name="r_disk" min="0" max="99" value="<?= e($c['r_disk']) ?>"><p class="mt-1 text-xs text-slate-500"><?= e(t('notify.r_zero_off')) ?></p></div>
      <div><label class="label"><?= e(t('notify.r_mem')) ?></label><input class="input" type="number" name="r_mem" min="0" max="99" value="<?= e($c['r_mem']) ?>"><p class="mt-1 text-xs text-slate-500"><?= e(t('notify.r_zero_off')) ?></p></div>
      <div><label class="label"><?= e(t('notify.r_load')) ?></label><input class="input" type="number" name="r_load" min="0" max="50" step="0.5" value="<?= e($c['r_load']) ?>"><p class="mt-1 text-xs text-slate-500"><?= e(t('notify.r_load_hint')) ?></p></div>
      <div><label class="label"><?= e(t('notify.r_ssl')) ?></label><input class="input" type="number" name="r_ssl" min="0" max="60" value="<?= e($c['r_ssl']) ?>"><p class="mt-1 text-xs text-slate-500"><?= e(t('notify.r_zero_off')) ?></p></div>
    </div>
    <div class="grid gap-2 sm:grid-cols-3">
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="r_services" value="1" <?= $chk($c['r_services']) ?>><span><?= e(t('notify.r_services')) ?><span class="block text-xs text-slate-500"><?= e(t('notify.r_services_hint')) ?></span></span></label>
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="r_backup" value="1" <?= $chk($c['r_backup']) ?>><span><?= e(t('notify.r_backup')) ?></span></label>
      <label class="flex items-start gap-2 text-sm"><input type="checkbox" class="mt-0.5" name="r_update" value="1" <?= $chk($c['r_update']) ?>><span><?= e(t('notify.r_update')) ?></span></label>
    </div>
    <div class="max-w-xs"><label class="label"><?= e(t('notify.r_meili')) ?></label><input class="input" type="number" name="r_meili" min="0" value="<?= e($c['r_meili']) ?>"><p class="mt-1 text-xs text-slate-500"><?= e(t('notify.r_meili_hint')) ?></p></div>
    <div class="grid gap-4 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-3">
      <div><label class="label"><?= e(t('notify.repeat')) ?></label><input class="input" type="number" name="repeat" min="0" max="720" value="<?= e($c['repeat']) ?>"><p class="mt-1 text-xs text-slate-500"><?= e(t('notify.repeat_hint')) ?></p></div>
      <div><label class="label"><?= e(t('notify.lang')) ?></label><select class="input" name="lang"><?php foreach (Havoc\Core\I18n::SUPPORTED as $k => $l): ?><option value="<?= e($k) ?>" <?= $c['lang'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <label class="flex items-center gap-2 self-center text-sm"><input type="checkbox" name="recovery" value="1" <?= $chk($c['recovery']) ?>> <?= e(t('notify.recovery')) ?></label>
    </div>
    <p class="text-xs text-slate-500"><?= e(t('notify.how')) ?></p>
    <button class="btn"><?= e(t('common.save')) ?></button>
  </div>
</form>

<div class="mt-6 flex flex-wrap gap-2">
  <form method="post" action="/server/notifications/test/email"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('notify.test_email')) ?></button></form>
  <form method="post" action="/server/notifications/test/telegram"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('notify.test_telegram')) ?></button></form>
  <form method="post" action="/server/notifications/test/check"><?= csrf_field() ?><button class="btn-ghost border border-slate-300 dark:border-slate-700"><?= e(t('notify.check_now')) ?></button></form>
  <span class="self-center text-xs text-slate-500"><?= e(t('notify.test_hint')) ?></span>
</div>

<h2 class="h2 mt-8"><?= e(t('notify.recent')) ?></h2>
<div class="card overflow-x-auto p-0"><table class="table text-sm">
  <thead><tr><th><?= e(t('common.time')) ?></th><th><?= e(t('notify.channel')) ?></th><th><?= e(t('notify.subject')) ?></th><th><?= e(t('notify.result')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($log as $l): ?>
    <tr><td class="whitespace-nowrap"><?= e($l['created_at']) ?></td><td><?= e($l['channel'] === 'email' ? t('notify.email') : 'Telegram') ?></td><td><?= e($l['subject']) ?></td>
      <td><?php if ($l['ok']): ?><span class="badge badge-ok"><?= e(t('notify.sent')) ?></span><?php else: ?><span class="badge badge-warn" title="<?= e((string)$l['error']) ?>"><?= e(t('notify.failed')) ?></span> <span class="text-xs text-slate-500"><?= e(mb_substr((string)$l['error'], 0, 90)) ?></span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$log): ?><tr><td colspan="4" class="text-slate-500"><?= e(t('notify.none_yet')) ?></td></tr><?php endif; ?>
  </tbody></table></div>
