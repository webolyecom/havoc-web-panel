<h1 class="h1"><?= e(t('nav.branding')) ?></h1>
<form method="post" action="/branding" enctype="multipart/form-data" class="card max-w-xl space-y-4"><?= csrf_field() ?>
  <div><label class="label"><?= e(t('brand.name')) ?></label><input class="input" name="brand_name" maxlength="64" value="<?= e($b['brand_name'] ?? '') ?>"></div>
  <div><label class="label"><?= e(t('brand.color')) ?></label><input type="color" name="color" value="<?= e($b['primary_color'] ?? '#0b7fa0') ?>" class="h-10 w-20 cursor-pointer rounded border border-slate-300 dark:border-slate-700"></div>
  <div><label class="label"><?= e(t('brand.logo')) ?> (PNG/JPG/WebP, ≤ 512 KB)</label><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="text-sm">
    <?php if (!empty($b['logo_path'])): ?><img src="/brand/<?= (int)$b['reseller_id'] ?>/logo" alt="" class="mt-2 h-12 rounded"><?php endif; ?></div>
  <div><label class="label"><?= e(t('brand.domain')) ?></label><input class="input" name="panel_domain" placeholder="panel.example.com" value="<?= e($b['panel_domain'] ?? '') ?>">
    <p class="mt-1 text-xs text-slate-500"><?= e(t('brand.domain_hint')) ?></p></div>
  <button class="btn"><?= e(t('common.save')) ?></button>
</form>
