<?php
declare(strict_types=1);

namespace Havoc\Core;

/**
 * Client for the privileged helper. The panel (unprivileged user "havoc") never runs shell
 * commands itself: it calls `sudo -n havocctl <action>` with a JSON payload on stdin.
 * havocctl re-validates every argument against strict whitelists.
 */
final class Ctl
{
    public static function call(string $action, array $args = [], int $timeout = 120): array
    {
        if (!preg_match('/^[a-z]+\.[a-z_]+$/', $action)) {
            throw new \InvalidArgumentException('Bad action');
        }
        $cmd  = [Config::get('ctl.sudo'), '-n', Config::get('ctl.bin'), $action];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start helper');
        }
        fwrite($pipes[0], json_encode($args));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $out = '';
        $start = time();
        while (!feof($pipes[1]) && time() - $start < $timeout) {
            $out .= (string)fread($pipes[1], 65536);
            usleep(20000);
        }
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $res = json_decode($out, true);
        if (!is_array($res)) {
            throw new \RuntimeException('Helper error: ' . trim($err ?: $out));
        }
        if (empty($res['ok'])) {
            throw new \RuntimeException((string)($res['error'] ?? 'Unknown helper error'));
        }
        return $res['data'] ?? [];
    }

    /**
     * Streaming call (fs.cat): reads the "OK <size> <name>" / "ERR <msg>" header, then hands the open pipe to $sink.
     * The caller sends headers from the header line and copies the rest to the client without buffering it.
     */
    public static function stream(string $action, array $args, callable $sink): void
    {
        $cmd  = [Config::get('ctl.sudo'), '-n', Config::get('ctl.bin'), $action];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start helper');
        }
        fwrite($pipes[0], json_encode($args));
        fclose($pipes[0]);
        $head = (string)fgets($pipes[1], 4096);
        try {
            if (!preg_match('/^OK (\d+) (\S+)$/', rtrim($head), $m)) {
                throw new \RuntimeException(str_starts_with($head, 'ERR ') ? trim(substr($head, 4)) : 'Download failed');
            }
            $sink((int)$m[1], rawurldecode($m[2]), $pipes[1]);
        } finally {
            fclose($pipes[1]);
            proc_close($proc);
        }
    }
}
