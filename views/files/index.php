<?php
$keys = ['new_folder', 'new_file', 'upload', 'search_ph', 'copy', 'move', 'compress', 'perms', 'download', 'delete', 'rename', 'edit', 'extract',
    'open', 'preview', 'name', 'size', 'modified', 'permissions', 'empty', 'confirm_delete', 'folder_name', 'file_name', 'target_folder',
    'archive_name', 'format', 'file_mode', 'dir_mode', 'recursive', 'apply', 'cancel', 'save', 'saved', 'unsaved', 'close_unsaved', 'loading',
    'uploading', 'uploaded', 'upload_failed', 'drop_here', 'selected', 'results', 'results_limited', 'no_results', 'back_to_folder',
    'done', 'too_big_edit', 'select_hint', 'copy_done', 'move_done', 'root'];
$tr = [];
foreach ($keys as $k) {
    $tr[$k] = t('fm.' . $k);
}
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
  <div class="flex min-w-0 items-center gap-3">
    <h1 class="h1 mb-0"><?= e(t('files.title')) ?></h1>
    <?php if (count($sites) > 1): ?>
      <select class="input w-auto py-1.5" id="fm-site" aria-label="<?= e(t('domains.name')) ?>">
        <?php foreach ($sites as $s): ?><option value="/domains/<?= (int)$s['id'] ?>/files" <?= (int)$s['id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select>
    <?php else: ?><span class="text-slate-500"><?= e($d['name']) ?></span><?php endif; ?>
  </div>
  <a class="text-sm text-brand hover:underline" href="/domains/<?= (int)$d['id'] ?>">← <?= e($d['name']) ?></a>
</div>

<div id="fm" data-api="/domains/<?= (int)$d['id'] ?>/files/api" data-dl="/domains/<?= (int)$d['id'] ?>/files/download" data-editor="<?= e(asset('editor.js')) ?>" data-t="<?= e(json_encode($tr, JSON_UNESCAPED_UNICODE)) ?>">
  <div class="card mb-3 flex flex-wrap items-center gap-2 p-3">
    <button type="button" class="btn" data-act="mkdir">+ <?= e($tr['new_folder']) ?></button>
    <button type="button" class="btn-ghost border border-slate-300 dark:border-slate-700" data-act="touch">+ <?= e($tr['new_file']) ?></button>
    <label class="btn-ghost cursor-pointer border border-slate-300 dark:border-slate-700">⬆ <?= e($tr['upload']) ?><input type="file" multiple class="hidden" id="fm-upload"></label>
    <div class="ml-auto flex items-center gap-2">
      <input type="search" class="input w-48 py-1.5 sm:w-64" id="fm-search" placeholder="<?= e($tr['search_ph']) ?>" autocomplete="off">
    </div>
  </div>

  <div class="mb-3 hidden flex-wrap items-center gap-2 rounded-lg border border-brand/40 bg-brand/5 px-3 py-2 text-sm" id="fm-selbar">
    <span id="fm-selcount" class="font-medium"></span>
    <button type="button" class="btn-ghost border border-slate-300 py-1 text-xs dark:border-slate-700" data-act="copy"><?= e($tr['copy']) ?></button>
    <button type="button" class="btn-ghost border border-slate-300 py-1 text-xs dark:border-slate-700" data-act="move"><?= e($tr['move']) ?></button>
    <button type="button" class="btn-ghost border border-slate-300 py-1 text-xs dark:border-slate-700" data-act="compress"><?= e($tr['compress']) ?></button>
    <button type="button" class="btn-ghost border border-slate-300 py-1 text-xs dark:border-slate-700" data-act="perms"><?= e($tr['perms']) ?></button>
    <button type="button" class="btn-danger py-1 text-xs" data-act="delete"><?= e($tr['delete']) ?></button>
  </div>

  <nav class="mb-2 flex flex-wrap items-center gap-1 text-sm text-slate-500" id="fm-crumbs" aria-label="breadcrumb"></nav>

  <div class="card relative overflow-x-auto p-0" id="fm-drop">
    <div class="pointer-events-none absolute inset-0 z-10 hidden items-center justify-center rounded-xl border-2 border-dashed border-brand bg-brand/10 text-lg font-semibold text-brand" id="fm-dropmask"><?= e($tr['drop_here']) ?></div>
    <table class="table">
      <thead><tr>
        <th class="w-8"><input type="checkbox" id="fm-all" aria-label="select all"></th>
        <th><button type="button" data-sort="name"><?= e($tr['name']) ?></button></th>
        <th class="hidden sm:table-cell"><button type="button" data-sort="size"><?= e($tr['size']) ?></button></th>
        <th class="hidden md:table-cell"><button type="button" data-sort="mtime"><?= e($tr['modified']) ?></button></th>
        <th class="hidden md:table-cell"><?= e($tr['permissions']) ?></th>
        <th></th>
      </tr></thead>
      <tbody id="fm-list"></tbody>
    </table>
  </div>
  <p class="mt-2 text-xs text-slate-500"><?= e($tr['select_hint']) ?></p>

  <div class="fixed bottom-4 right-4 z-40 w-80 max-w-[calc(100vw-2rem)] space-y-2" id="fm-uploads"></div>
  <div class="fixed bottom-4 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2 text-sm shadow-lg" id="fm-toast" role="status"></div>

  <div class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4" id="fm-modal">
    <div class="card w-full max-w-md space-y-4" role="dialog" aria-modal="true">
      <h2 class="h2" id="fm-modal-title"></h2>
      <div id="fm-modal-body" class="space-y-3"></div>
      <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" id="fm-modal-cancel"></button><button type="button" class="btn" id="fm-modal-ok"></button></div>
    </div>
  </div>

  <div class="fixed inset-0 z-50 hidden flex-col bg-white dark:bg-slate-950" id="fm-editor">
    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-3 py-2 dark:border-slate-800">
      <span class="min-w-0 truncate font-mono text-sm"><span id="fm-edit-name"></span> <span class="text-amber-600" id="fm-edit-dirty"></span></span>
      <span class="flex shrink-0 gap-2"><button type="button" class="btn gap-1.5" id="fm-save"><?= e($tr['save']) ?> <span class="hidden text-xs opacity-75 sm:inline">Ctrl+S</span></button><button type="button" class="btn-ghost" id="fm-close" aria-label="close">✕</button></span>
    </div>
    <div class="min-h-0 flex-1 overflow-hidden" id="fm-edit-area"></div>
  </div>
</div>
<script src="<?= e(asset('files.js')) ?>" defer></script>
