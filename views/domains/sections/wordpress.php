<?php use Havoc\Core\I18n; ?>
<?php $id = (int)$d['id']; ?>
  <div class="card space-y-4" id="apps">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="h2"><?= e(t('app.title')) ?></h2>
      <?php if ($app): ?><span class="badge <?= ['installed' => 'badge-ok', 'installing' => 'badge-warn', 'failed' => 'badge-err'][$app['status']] ?? '' ?>"><?= e(t('app.st_' . $app['status'])) ?></span><?php endif; ?>
    </div>
    <?php if ($newApp): ?>
      <div class="space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-800 dark:bg-amber-950/40">
        <p class="font-semibold"><?= e(t('app.credentials')) ?></p>
        <p><?= e(t('app.admin_url')) ?>: <a class="link" href="<?= e($newApp['url']) ?>" target="_blank" rel="noopener"><?= e($newApp['url']) ?></a></p>
        <p><?= e(t('auth.username')) ?>: <code class="font-mono"><?= e($newApp['user']) ?></code> · <?= e(t('auth.password')) ?>: <code class="select-all font-mono"><?= e($newApp['password']) ?></code></p>
        <p class="text-xs text-slate-500"><?= e(t('app.credentials_once', ['db' => $newApp['db']])) ?></p>
      </div>
    <?php endif; ?>
    <?php if ($app && $app['status'] === 'installing'): ?>
      <p class="text-sm"><?= e(t('app.installing')) ?></p><div data-autorefresh></div>
    <?php elseif ($app && $app['status'] === 'installed'): ?>
      <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <span class="font-semibold">WordPress <?= e((string)$app['version']) ?></span>
        <span><?= e(t('auth.username')) ?>: <code class="font-mono"><?= e((string)$app['admin_user']) ?></code></span>
        <span><?= e(t('app.database')) ?>: <code class="font-mono"><?= e((string)$app['db_name']) ?></code></span>
        <?php if ($app['via'] === 'claude'): ?><span class="badge"><?= e(t('app.via_claude')) ?></span><?php endif; ?>
        <a class="btn ml-auto" href="<?= e((string)$app['admin_url']) ?>" target="_blank" rel="noopener"><?= e(t('app.open_admin')) ?></a>
      </div>
      <?php if ($app['note'] === 'http'): ?><p class="text-xs text-amber-700 dark:text-amber-300"><?= e(t('app.http_note')) ?></p><?php endif; ?>
    <?php else: ?>
      <?php if ($app && $app['status'] === 'failed'): ?>
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/40 dark:text-rose-200">
          <span><?= e(t('app.failed', ['msg' => (string)$app['note']])) ?></span>
          <form method="post" action="/domains/<?= $id ?>/apps/clear"><?= csrf_field() ?><button class="btn-ghost text-sm"><?= e(t('app.dismiss')) ?></button></form>
        </div>
      <?php else: ?>
      <form method="post" action="/domains/<?= $id ?>/apps/wordpress" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-confirm="<?= e(t('app.confirm', ['domain' => $d['name']])) ?>"><?= csrf_field() ?>
        <div class="flex items-center gap-3 lg:col-span-5"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-[#21759b] text-sm font-bold text-white">W</span>
          <div><p class="font-semibold">WordPress</p><p class="text-xs text-slate-500"><?= e(t('app.wp_hint')) ?></p></div></div>
        <div><label class="label" for="wp-title"><?= e(t('app.site_title')) ?></label><input class="input" id="wp-title" name="title" maxlength="120" placeholder="<?= e($d['name']) ?>"></div>
        <div><label class="label" for="wp-user"><?= e(t('app.admin_user')) ?></label><input class="input" id="wp-user" name="admin_user" pattern="[A-Za-z0-9_.\-]{3,40}" placeholder="wpadmin"></div>
        <div><label class="label" for="wp-mail"><?= e(t('app.admin_email')) ?></label><input class="input" id="wp-mail" type="email" name="admin_email" placeholder="<?= e((string)(Havoc\Core\DB::q('SELECT email FROM users WHERE id = ?', [$d['user_id']])->fetchColumn())) ?>"></div>
        <div><label class="label" for="wp-locale"><?= e(t('app.language')) ?></label><select class="input" id="wp-locale" name="locale">
          <?php foreach (Havoc\Services\AppService::LOCALES as $code => $name): ?><option value="<?= e($code) ?>" <?= $code === (I18n::locale() === 'tr' ? 'tr_TR' : 'en_US') ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
        </select></div>
        <div class="flex items-end"><button class="btn w-full"><?= e(t('app.install_wp')) ?></button></div>
      </form>
      <p class="text-xs text-slate-500"><?= e(t('app.empty_hint')) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
