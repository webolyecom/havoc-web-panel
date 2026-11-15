<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Response, Scope};
use Havoc\Services\UploadService;

/** /upload/<token>: the one-time upload page and its chunk endpoint (no login: the link itself is the permission). */
final class UploadController extends Controller
{
    public function page(): Response
    {
        $token = (string)$this->param('token');
        $l = UploadService::find($token);
        $r = view('upload/page', ['link' => $l, 'url' => '/upload/' . $token, 'chunk' => UploadService::CHUNK, 'max' => UploadService::MAX], 'auth/layout');
        if (!$l) {
            $r->status = 404;
        }
        return $r;
    }

    public function chunk(): Response
    {
        $l = UploadService::find((string)$this->param('token'));
        if (!$l) {
            return Response::json(['error' => t('upload.invalid')], 404);
        }
        $data = (string)file_get_contents('php://input', false, null, 0, UploadService::CHUNK + 2048);
        try {
            return Response::json(UploadService::chunk($l, (string)($_GET['name'] ?? ''), (int)($_GET['offset'] ?? -1), $data, ($_GET['final'] ?? '') === '1'));
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /** "Upload link" button on the domain page (for uploads without Claude). */
    public function create(): Response
    {
        $d = Scope::domain($this->user(), (int)$this->param('id')) ?? $this->abort(404);
        $r = UploadService::create($this->user(), $d);
        $_SESSION['upload_link'] = ['domain' => (int)$d['id'], 'url' => $r['url'], 'expires' => $r['expires']];
        return $this->to('/domains/' . (int)$d['id']);
    }
}
