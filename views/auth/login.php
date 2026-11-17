<form method="post" action="/login" class="space-y-4" autocomplete="on">
  <?= csrf_field() ?>
  <div><label class="label" for="username"><?= e(t('auth.username')) ?></label>
    <input class="input" id="username" name="username" required autofocus autocomplete="username"></div>
  <div><label class="label" for="password"><?= e(t('auth.password')) ?></label>
    <input class="input" type="password" id="password" name="password" required autocomplete="current-password"></div>
  <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><input type="checkbox" name="remember" value="1"> <?= e(t('auth.remember')) ?></label>
  <button class="btn w-full"><?= e(t('auth.login')) ?></button>
</form>
