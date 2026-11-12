<?php $u = Havoc\Core\Auth::user(); ?>
<h1 class="h1"><?= e(t('nav.settings')) ?></h1>
<div class="grid gap-6 lg:grid-cols-2">
  <form method="post" action="/settings/profile" class="card space-y-4"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('settings.profile')) ?></h2>
    <div><label class="label">Email</label><input class="input" type="email" name="email" value="<?= e($u['email']) ?>" required></div>
    <div><label class="label"><?= e(t('common.language')) ?></label><select class="input" name="locale"><?php foreach (Havoc\Core\I18n::SUPPORTED as $c => $n): ?><option value="<?= e($c) ?>" <?= $u['locale'] === $c ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div><label class="label"><?= e(t('common.theme')) ?></label><select class="input" name="theme" id="theme-select"><?php foreach (['system', 'light', 'dark'] as $th): ?><option value="<?= $th ?>" <?= $u['theme'] === $th ? 'selected' : '' ?>><?= e(t('theme.' . $th)) ?></option><?php endforeach; ?></select></div>
    <button class="btn"><?= e(t('common.save')) ?></button></form>

  <form method="post" action="/settings/password" class="card space-y-4"><?= csrf_field() ?>
    <h2 class="h2"><?= e(t('settings.password')) ?></h2>
    <input class="input" type="password" name="current" placeholder="<?= e(t('settings.current')) ?>" required autocomplete="current-password">
    <input class="input" type="password" name="new" placeholder="<?= e(t('settings.new')) ?>" minlength="10" required autocomplete="new-password">
    <button class="btn"><?= e(t('common.save')) ?></button></form>

  <div class="card space-y-3">
    <h2 class="h2">2FA (TOTP)</h2>
    <?php if ($u['totp_enabled']): ?>
      <p class="text-sm text-emerald-600"><?= e(t('settings.2fa_on')) ?></p>
      <form method="post" action="/settings/2fa/disable" class="space-y-2"><?= csrf_field() ?>
        <input class="input" type="password" name="current" placeholder="<?= e(t('settings.current')) ?>" required>
        <button class="btn-danger"><?= e(t('settings.2fa_disable')) ?></button></form>
    <?php else: ?>
      <p class="text-sm text-slate-500"><?= e(t('settings.2fa_setup')) ?></p>
      <code class="block break-all rounded bg-slate-100 p-2 text-sm dark:bg-slate-800"><?= e($secret) ?></code>
      <code class="block break-all rounded bg-slate-100 p-2 text-xs dark:bg-slate-800"><?= e($otpuri) ?></code>
      <form method="post" action="/settings/2fa/enable" class="flex gap-2"><?= csrf_field() ?>
        <input class="input" name="code" inputmode="numeric" maxlength="7" placeholder="123456" required>
        <button class="btn"><?= e(t('settings.2fa_enable')) ?></button></form>
    <?php endif; ?>
  </div>

  <div class="card space-y-3">
    <h2 class="h2">API</h2>
    <?php if ($newToken): ?><div class="rounded bg-amber-100 p-3 text-sm text-amber-900"><?= e(t('settings.token_once')) ?><br><code class="break-all"><?= e($newToken) ?></code></div><?php endif; ?>
    <form method="post" action="/settings/tokens" class="flex gap-2"><?= csrf_field() ?><input class="input" name="name" placeholder="<?= e(t('common.name')) ?>" required><button class="btn"><?= e(t('common.add')) ?></button></form>
    <?php foreach ($tokens as $tk): ?>
      <form method="post" action="/settings/tokens/<?= (int)$tk['id'] ?>/delete" class="flex items-center justify-between text-sm"><?= csrf_field() ?>
        <span><?= e($tk['name']) ?> <span class="text-slate-400"><?= e((string)($tk['last_used'] ?? '—')) ?></span></span><button class="btn-ghost text-red-600"><?= e(t('common.delete')) ?></button></form>
    <?php endforeach; ?>
    <a class="text-sm text-brand hover:underline" href="/api/v1/openapi.json">OpenAPI spec</a>
  </div>
</div>
