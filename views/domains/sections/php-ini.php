<?php $id = (int)$d['id']; ?>
<?php
$iniDefs = Havoc\Ctl\PhpIni::USER;
$isAdmin = Havoc\Core\Auth::user()['role'] === 'admin';
?>
  <form method="post" action="/domains/<?= $id ?>/php-ini" class="card space-y-4" id="php-ini"><?= csrf_field() ?>
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="h2"><?= e(t('phpini.title')) ?> <span class="text-sm font-normal text-slate-400">PHP <?= e($d['php_version']) ?></span></h2>
      <button class="btn"><?= e(t('common.save')) ?></button></div>
    <p class="text-xs text-slate-500"><?= e(t('phpini.hint')) ?></p>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($iniDefs as $k => [$type, $x]): $name = 'ini_' . str_replace('.', '__', $k); $val = (string)($ini[$k] ?? ''); ?>
        <label class="block"><span class="label font-mono text-xs"><?= e($k) ?></span>
        <?php if ($type === 'bool' || $type === 'enum'): $opts = $type === 'bool' ? ['On', 'Off'] : $x; ?>
          <select class="input" name="<?= e($name) ?>"><option value=""><?= e(t('phpini.default')) ?></option>
            <?php foreach ($opts as $o): ?><option value="<?= e($o) ?>" <?= $val === $o ? 'selected' : '' ?>><?= e($o === '' ? '(empty)' : $o) ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input class="input" name="<?= e($name) ?>" value="<?= e($val) ?>" placeholder="<?= e(t('phpini.default')) ?>" <?= $type === 'tz' ? 'list="tz-list"' : '' ?>>
        <?php endif; ?></label>
      <?php endforeach; ?>
    </div>
    <datalist id="tz-list"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= e($tz) ?>"></option><?php endforeach; ?></datalist>
    <?php if ($isAdmin): ?>
      <label class="block"><span class="label"><?= e(t('phpini.admin_extra')) ?></span>
        <textarea class="input font-mono text-xs" name="ini_admin" rows="4" spellcheck="false" placeholder="opcache.jit = tracing&#10;max_input_nesting_level = 128"><?= e((string)($d['php_ini_admin'] ?? '')) ?></textarea></label>
    <?php endif; ?>
  </form>
