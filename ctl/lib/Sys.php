<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** Process + filesystem helpers. Never goes through a shell. */
final class Sys
{
    /** @return array{0:int,1:string,2:string} */
    public static function run(array $cmd, ?string $stdin = null, int $timeout = 120, array $env = []): array
    {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) {
            throw new \RuntimeException('proc_open failed');
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $end = time() + $timeout;
        while (true) {
            $out .= (string)fread($pipes[1], 65536);
            $err .= (string)fread($pipes[2], 65536);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $out .= (string)stream_get_contents($pipes[1]);
                $err .= (string)stream_get_contents($pipes[2]);
                break;
            }
            if (time() > $end) {
                proc_terminate($p, 9);
                throw new \RuntimeException('Command timed out: ' . $cmd[0]);
            }
            usleep(20000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return [$st['exitcode'] >= 0 ? $st['exitcode'] : $code, $out, $err];
    }

    public static function ok(array $cmd, ?string $stdin = null, int $timeout = 120, array $env = []): string
    {
        [$c, $o, $e] = self::run($cmd, $stdin, $timeout, $env);
        if ($c !== 0) {
            throw new \RuntimeException(basename($cmd[0]) . ' failed: ' . trim($e ?: $o));
        }
        return $o;
    }

    public static function write(string $path, string $content, int $mode = 0644, string $owner = 'root', string $group = 'root'): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        chmod($tmp, $mode);
        chown($tmp, $owner);
        chgrp($tmp, $group);
        rename($tmp, $path);
    }

    public static function systemctl(string ...$args): array
    {
        return self::run(['systemctl', ...$args]);
    }

    /** Run with stdin/stdout bound to files (no memory buffering): dumps, imports, big archives. @return array{0:int,1:string} */
    public static function runIO(array $cmd, ?string $in, ?string $out, array $env = [], int $timeout = 7200): array
    {
        $d = [0 => ['file', $in ?? '/dev/null', 'r'], 1 => ['file', $out ?? '/dev/null', 'w'], 2 => ['pipe', 'w']];
        $p = proc_open($cmd, $d, $pipes, null, $env + ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) {
            throw new \RuntimeException('proc_open failed');
        }
        stream_set_blocking($pipes[2], false);
        $err = '';
        $end = time() + $timeout;
        while (true) {
            $err .= (string)fread($pipes[2], 8192);
            $err = substr($err, -65536);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $err .= (string)stream_get_contents($pipes[2]);
                break;
            }
            if (time() > $end) {
                proc_terminate($p, 9);
                throw new \RuntimeException('Command timed out: ' . $cmd[0]);
            }
            usleep(50000);
        }
        fclose($pipes[2]);
        $code = proc_close($p);
        return [$st['exitcode'] >= 0 ? $st['exitcode'] : $code, substr($err, -4000)];
    }

    /**
     * Prefix that runs a command as a hosting account (never root): uid/gid of the account, no supplementary groups, no new privileges.
     * Use for anything that creates, reads or writes inside /home/<account>, where the tenant controls every path component.
     */
    public static function asUser(string $acct): array
    {
        $pw = posix_getpwnam($acct);
        if (!$pw || (int)$pw['uid'] === 0) {
            throw new \RuntimeException('Hosting account not found');
        }
        return ['setpriv', '--reuid', (string)$pw['uid'], '--regid', (string)$pw['gid'], '--clear-groups', '--no-new-privs'];
    }
}
