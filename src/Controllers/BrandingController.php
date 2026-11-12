<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, DB, Response};
use Havoc\Ctl\Validator;

/** White-label: name, colour, logo and custom panel domain for an admin/reseller and their customers. */
final class BrandingController extends Controller
{
    private const DIR = '/var/lib/havoc/branding';

    public function index(): Response
    {
        $u = $this->user();
        return view('branding/index', ['b' => DB::one('SELECT * FROM branding WHERE reseller_id = ?', [$u['id']]) ?? []]);
    }

    public function save(): Response
    {
        $u = $this->user();
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $this->req->str('color')) ? $this->req->str('color') : '#0b7fa0';
        $domain = $this->req->str('panel_domain');
        try {
            $domain = $domain === '' ? null : Validator::domain($domain);
        } catch (\InvalidArgumentException) {
            $this->flash('error', t('domains.invalid'));
            return $this->to('/branding');
        }
        if ($domain && DB::one('SELECT reseller_id FROM branding WHERE panel_domain = ? AND reseller_id != ?', [$domain, $u['id']])) {
            $this->flash('error', t('domains.domain_exists'));
            return $this->to('/branding');
        }
        $logo = DB::q('SELECT logo_path FROM branding WHERE reseller_id = ?', [$u['id']])->fetchColumn() ?: null;
        $f = $_FILES['logo'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($f['tmp_name']);
            if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) || $f['size'] > 524288) {
                $this->flash('error', t('brand.logo_invalid'));
                return $this->to('/branding');
            }
            is_dir(self::DIR) || mkdir(self::DIR, 0750, true);
            $logo = $u['id'] . '.img';
            move_uploaded_file($f['tmp_name'], self::DIR . '/' . $logo);
        }
        DB::q('INSERT INTO branding (reseller_id, brand_name, logo_path, primary_color, panel_domain) VALUES (?,?,?,?,?)
               ON CONFLICT(reseller_id) DO UPDATE SET brand_name = excluded.brand_name, logo_path = excluded.logo_path,
               primary_color = excluded.primary_color, panel_domain = excluded.panel_domain',
            [$u['id'], substr($this->req->str('brand_name'), 0, 64) ?: null, $logo, $color, $domain]);
        Audit::log('branding.save');
        $this->flash('success', t('common.saved'));
        return $this->to('/branding');
    }

    /** Public (needed on the login page). Only serves validated raster images. */
    public function logo(): Response
    {
        $rid = (int)$this->param('id');
        $file = DB::q('SELECT logo_path FROM branding WHERE reseller_id = ?', [$rid])->fetchColumn();
        $path = $file ? self::DIR . '/' . basename((string)$file) : '';
        $info = $path && is_file($path) ? @getimagesize($path) : false;
        if (!$info) {
            $this->abort(404);
        }
        return new Response((string)file_get_contents($path), 200, ['Content-Type' => $info['mime'], 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
