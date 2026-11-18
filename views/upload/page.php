<?php if (!$link): ?>
  <h2 class="mb-2 text-lg font-semibold"><?= e(t('upload.title')) ?></h2>
  <p class="text-sm text-slate-600 dark:text-slate-300"><?= e(t('upload.invalid')) ?></p>
<?php else: ?>
<div id="upload" class="space-y-4" data-url="<?= e($url) ?>" data-chunk="<?= (int)$chunk ?>" data-max="<?= (int)$max ?>"
     data-msg-type="<?= e(t('upload.bad_type')) ?>" data-msg-big="<?= e(t('upload.too_big')) ?>" data-msg-done="<?= e(t('upload.done')) ?>" data-msg-fail="<?= e(t('upload.failed')) ?>" data-msg-copied="<?= e(t('upload.copied')) ?>">
  <div>
    <h2 class="text-lg font-semibold"><?= e(t('upload.title')) ?></h2>
    <p class="text-sm text-slate-500"><?= e(t('upload.to', ['domain' => $link['domain']])) ?></p>
  </div>
  <label data-drop class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500 transition hover:border-brand hover:text-brand dark:border-slate-600">
    <svg viewBox="0 0 20 20" class="h-8 w-8" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 13V3m0 0L6.5 6.5M10 3l3.5 3.5M3.5 13v2.5A1.5 1.5 0 0 0 5 17h10a1.5 1.5 0 0 0 1.5-1.5V13"/></svg>
    <span><?= e(t('upload.drop')) ?></span>
    <span class="text-xs"><?= e(t('upload.limits')) ?></span>
    <input type="file" class="sr-only" accept=".zip,.tar,.tgz,.gz,application/zip,application/gzip,application/x-tar">
  </label>
  <div data-progress class="hidden space-y-1">
    <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"><div data-bar class="h-2 w-0 rounded-full bg-brand transition-all"></div></div>
    <p data-status class="text-xs text-slate-500"></p>
  </div>
  <div data-result class="hidden">
    <p data-result-text class="min-w-0 flex-1 break-words"></p>
    <button type="button" data-upload-copy class="hidden shrink-0 rounded-md border border-current px-2.5 py-1 text-xs font-medium hover:bg-white/60 dark:hover:bg-white/10"><?= e(t('upload.copy')) ?></button>
  </div>
  <p class="text-xs text-slate-400"><?= e(t('upload.note')) ?></p>
</div>
<script src="<?= e(asset('upload.js')) ?>" defer></script>
<?php endif; ?>
