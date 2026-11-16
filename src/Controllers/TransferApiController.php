<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{DB, RateLimiter, Response};
use Havoc\Services\{DomainService, TransferService, UpdateService, WebServerService};

/**
 * /transfer/v1/*: what another Havoc panel calls with a transfer key (Bearer). Read-only for this server:
 * list the allowed sites, build an export package, stream its files, remove it.
 */
final class TransferApiController extends Controller
{
    private const DIR = '/var/backups/havoc/transfer';

    private function key(): array
    {
        $ip = $this->req->ip();
        if (RateLimiter::locked($ip, '#transfer')) {
            throw new HttpStop(Response::json(['error' => 'Too many failed attempts, try again later'], 429));
        }
        $k = TransferService::authenticate($this->req->bearer(), $ip);
        if (!$k) {
            RateLimiter::hit($ip, '#transfer');
            throw new HttpStop(Response::json(['error' => 'Invalid or expired transfer key'], 401));
        }
        return $k;
    }

    private function guard(callable $fn): Response
    {
        try {
            return $fn($this->key());
        } catch (HttpStop $s) {
            return $s->response;
        } catch (\DomainException | \InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('transfer api: ' . $e->getMessage());
            return Response::json(['error' => 'Server error: ' . mb_substr($e->getMessage(), 0, 200)], 500);
        }
    }

    public function hello(): Response
    {
        return $this->guard(fn(array $k) => Response::json(['panel' => 'havoc', 'version' => UpdateService::current(), 'host' => gethostname() ?: '',
            'web_server' => WebServerService::active(), 'php' => DomainService::phpVersions(), 'expires_at' => (int)$k['expires_at'], 'label' => $k['label']]));
    }

    public function domains(): Response
    {
        return $this->guard(fn(array $k) => Response::json(['domains' => TransferService::listing($k)]));
    }

    public function create(): Response
    {
        return $this->guard(function (array $k) {
            $j = $this->req->json();
            $dbs = array_values(array_filter((array)($j['dbs'] ?? []), 'is_string'));
            return Response::json(['id' => TransferService::startExport($k, (int)($j['domain_id'] ?? 0), $dbs, !empty($j['ssl']))], 201);
        });
    }

    private function export(array $k): array
    {
        $x = DB::one('SELECT * FROM transfer_exports WHERE id = ? AND key_id = ?', [$this->param('export'), $k['id']]);
        if (!$x) {
            throw new HttpStop(Response::json(['error' => 'Export not found'], 404));
        }
        return $x;
    }

    public function status(): Response
    {
        return $this->guard(function (array $k) {
            $x = $this->export($k);
            return Response::json(['status' => $x['status'], 'error' => $x['error'], 'files' => json_decode((string)$x['files'], true),
                'manifest' => json_decode((string)$x['manifest'], true)]);
        });
    }

    /** One file of the package; HTTP Range so the other side downloads in resumable chunks. */
    public function file(): Response
    {
        return $this->guard(function (array $k) {
            $x = $this->export($k);
            $name = $this->param('name');
            if ($x['status'] !== 'ready' || !in_array($name, array_column(json_decode((string)$x['files'], true) ?: [], 'name'), true)) {
                return Response::json(['error' => 'File not found'], 404);
            }
            $path = self::DIR . '/' . $x['id'] . '/' . $name;
            $size = @filesize($path);
            $fh = $size === false ? false : fopen($path, 'rb');
            if (!$fh) {
                return Response::json(['error' => 'File not found'], 404);
            }
            $from = 0;
            $to = $size - 1;
            $partial = false;
            if (preg_match('/^bytes=(\d+)-(\d*)$/D', (string)$this->req->header('Range'), $m)) {
                $from = (int)$m[1];
                $to = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
                if ($from > $to || $from >= $size) {
                    return new Response('', 416, ['Content-Range' => "bytes */$size"]);
                }
                $partial = true;
            }
            set_time_limit(0);
            http_response_code($partial ? 206 : 200);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . ($to - $from + 1));
            header('Accept-Ranges: bytes');
            header('X-Accel-Buffering: no');
            if ($partial) {
                header("Content-Range: bytes $from-$to/$size");
            }
            fseek($fh, $from);
            $left = $to - $from + 1;
            while ($left > 0 && !feof($fh) && !connection_aborted()) {
                $buf = fread($fh, min(1048576, $left));
                if ($buf === false || $buf === '') {
                    break;
                }
                echo $buf;
                $left -= strlen($buf);
                flush();
            }
            fclose($fh);
            return new Response('');
        });
    }

    public function delete(): Response
    {
        return $this->guard(function (array $k) {
            $x = $this->export($k);
            try {
                \Havoc\Core\Ctl::call('transfer.export_delete', ['id' => $x['id']]);
            } catch (\Throwable) {
            }
            DB::q('DELETE FROM transfer_exports WHERE id = ?', [$x['id']]);
            return Response::json(['ok' => true]);
        });
    }
}

/** @internal early exit with a ready response */
final class HttpStop extends \Exception
{
    public function __construct(public Response $response)
    {
        parent::__construct('stop');
    }
}
