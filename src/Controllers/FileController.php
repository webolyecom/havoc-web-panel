<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, Response, Scope};

final class FileController extends Controller
{
    private const OPS = ['list', 'read', 'write', 'mkdir', 'touch', 'delete', 'rename', 'chmod', 'zip', 'unzip', 'upload',
        'copy', 'move', 'search', 'chmodr', 'targz', 'untar', 'chunk', 'preview'];
    private const MUTATING = ['write', 'mkdir', 'touch', 'delete', 'rename', 'chmod', 'zip', 'unzip', 'upload', 'copy', 'move', 'chmodr', 'targz', 'untar', 'chunk'];

    private function domain(): array
    {
        return Scope::domain($this->user(), (int)$this->param('id')) ?? $this->abort(404);
    }

    /** Entry point from the menu: pick a site (goes straight to it when there is only one). */
    public function pick(): Response
    {
        $doms = array_values(array_filter(Scope::domains($this->user()), fn($d) => $d['type'] !== 'alias' && $d['status'] === 'active'));
        if (count($doms) === 1) {
            return $this->to('/domains/' . (int)$doms[0]['id'] . '/files');
        }
        return view('files/pick', ['domains' => $doms]);
    }

    public function index(): Response
    {
        $d = $this->domain();
        $sites = array_values(array_filter(Scope::domains($this->user()), fn($x) => $x['type'] !== 'alias' && $x['status'] === 'active'));
        return view('files/index', ['d' => $d, 'sites' => $sites]);
    }

    /** Streams a file of any size to the browser without loading it into memory. */
    public function download(): Response
    {
        $d = $this->domain();
        $path = $this->req->str('path');
        set_time_limit(0);
        try {
            Ctl::stream('fs.cat', ['account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'args' => ['path' => $path]],
                static function (int $size, string $name, $pipe): void {
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/octet-stream');
                    header('Content-Length: ' . $size);
                    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: no-store');
                    while (!feof($pipe)) {
                        $buf = fread($pipe, 262144);
                        if ($buf === false) {
                            break;
                        }
                        echo $buf;
                        flush();
                    }
                });
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
        Audit::log('file.download', $d['name'], ['path' => $path]);
        return new Response('', 200, []);                  // body already streamed
    }

    /** Single JSON endpoint. CSRF is enforced by the router (X-CSRF-Token header). */
    public function api(): Response
    {
        $d  = $this->domain();
        $b  = $this->req->json();
        $op = (string)($b['op'] ?? '');
        if (!in_array($op, self::OPS, true)) {
            return Response::json(['error' => 'Unknown operation'], 422);
        }
        $args = is_array($b['args'] ?? null) ? $b['args'] : [];
        try {
            set_time_limit(660);
            $data = Ctl::call('fs.exec', ['account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'op' => $op, 'args' => $args], 600);
            if (in_array($op, self::MUTATING, true) && ($op !== 'chunk' || !empty($args['final']))) {
                Audit::log('file.' . $op, $d['name'], ['path' => mb_substr((string)($args['path'] ?? $args['from'] ?? $args['dest'] ?? ''), 0, 300)]);
            }
            return Response::json($data ?: new \stdClass());
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }
}
