<?php $id = (int)$d['id']; ?>
<form method="post" action="/domains/<?= $id ?>/delete" class="card max-w-2xl space-y-4 border-red-200 dark:border-red-900/50" data-confirm="<?= e(t('dsec.delete_confirm', ['domain' => $d['name']])) ?>"><?= csrf_field() ?>
  <h2 class="h2 mb-0 text-red-700 dark:text-red-400"><?= e(t('dsec.delete_title', ['domain' => $d['name']])) ?></h2>
  <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t('dsec.delete_hint')) ?></p>
  <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="purge" value="1"> <?= e(t('domains.purge')) ?></label>
  <button class="btn-danger"><?= e(t('common.delete')) ?></button>
</form>
