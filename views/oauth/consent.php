<form method="post" action="/oauth/authorize" class="space-y-4"><?= csrf_field() ?>
  <input type="hidden" name="rid" value="<?= e($rid) ?>">
  <div class="flex items-center gap-3">
    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand" aria-hidden="true"><svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M8 12 12 8M6.5 9.5 4.8 11.2a2.5 2.5 0 0 0 3.5 3.5L10 13M13.5 10.5l1.7-1.7a2.5 2.5 0 0 0-3.5-3.5L10 7"/></svg></span>
    <div><h2 class="text-lg font-semibold leading-tight"><?= e(t('oauth.connect', ['client' => $client['name']])) ?></h2>
      <p class="text-xs text-slate-500"><?= e(t('oauth.from', ['host' => $redirectHost])) ?></p></div>
  </div>
  <p class="text-sm"><?= e(t('oauth.as', ['user' => $user['username'], 'role' => t('role.' . $user['role'])])) ?></p>
  <ul class="space-y-2 text-sm">
    <li class="flex gap-2"><span class="text-emerald-600">✓</span><span><?= e(t('oauth.scope_read')) ?></span></li>
    <?php if (in_array('write', $scopes, true)): ?>
    <li><label class="flex gap-2"><input type="checkbox" name="write" value="1" checked class="mt-0.5"><span><?= e(t('oauth.scope_write')) ?></span></label></li>
    <?php endif; ?>
    <li class="flex gap-2 text-slate-500"><span>✕</span><span><?= e(t('oauth.no_delete')) ?></span></li>
  </ul>
  <p class="text-xs text-slate-500"><?= e(t('oauth.revoke_hint')) ?></p>
  <div class="flex gap-2">
    <button class="btn flex-1" name="decision" value="allow"><?= e(t('oauth.allow')) ?></button>
    <button class="btn-ghost flex-1 border border-slate-300 dark:border-slate-700" name="decision" value="deny"><?= e(t('oauth.deny')) ?></button>
  </div>
</form>
