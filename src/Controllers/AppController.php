<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Response, Scope};
use Havoc\Services\AppService;

/** One-click applications on a domain (WordPress). */
final class AppController extends Controller
{
    private function domain(): array
    {
        return Scope::domain($this->user(), (int)$this->param('id')) ?? $this->abort(404);
    }

    public function wordpress(): Response
    {
        $d = $this->domain();
        try {
            $r = AppService::startWordPress($this->user(), $d, ['title' => $this->req->str('title'), 'admin_user' => $this->req->str('admin_user'),
                'admin_email' => $this->req->str('admin_email'), 'locale' => $this->req->str('locale')]);
            $_SESSION['new_app'] = ['domain' => (int)$d['id'], 'user' => $r['admin_user'], 'password' => $r['admin_password'],
                'url' => $r['admin_url'], 'db' => $r['database']];                 // shown once
            $this->flash('success', t('app.started'));
        } catch (\DomainException $e) {
            $this->flash('error', str_starts_with($e->getMessage(), 'app.') || str_starts_with($e->getMessage(), 'db.') ? t($e->getMessage()) : $e->getMessage());
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/domains/' . (int)$d['id'] . '#apps');
    }

    public function clear(): Response
    {
        $d = $this->domain();
        AppService::clearFailed($d);
        return $this->to('/domains/' . (int)$d['id'] . '#apps');
    }
}
