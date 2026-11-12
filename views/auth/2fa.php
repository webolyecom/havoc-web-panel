<form method="post" action="/login/2fa" class="space-y-4">
  <?= csrf_field() ?>
  <p class="text-sm text-slate-500"><?= e(t('auth.2fa_hint')) ?></p>
  <input class="input text-center text-2xl tracking-widest" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code">
  <button class="btn w-full"><?= e(t('auth.verify')) ?></button>
</form>
