<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Changing the panel's HTTPS port without locking anybody out:
 *  1. change():  the panel nginx listens on the old AND the new port, the firewall opens the new one, and a revert is
 *                scheduled in 5 minutes (systemd-run timer).
 *  2. confirm(): called from a request that arrived on the new port: keep only the new port, close the old one, cancel the revert.
 *  3. revert():  the timer fired (or the admin cancelled): back to the old port only.
 */
final class PanelPort
{
    private const CONF = '/etc/havoc/nginx/nginx.conf';
    private const PENDING = '/etc/havoc/port-pending.json';
    private const TIMER = 'havoc-port-revert';
    private const RESERVED = [21, 22, 25, 53, 80, 110, 143, 443, 465, 587, 993, 995, 3306, 6379, 7700, 8080, 9200];
    public const WINDOW = 300;

    /** @return int[] ports in the panel's listen directives */
    private static function ports(string $conf): array
    {
        preg_match_all('/^\s*listen\s+(?:\[::\]:)?(\d{1,5})\s+ssl\b/m', $conf, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** Rewrites the listen block: one IPv4 + one IPv6 line per port, keeping the flags (ssl / http2) of the original lines. */
    private static function withPorts(string $conf, array $ports): string
    {
        if (!preg_match('/^(\s*)listen\s+(\d{1,5})(\s+ssl[^;]*);\s*$/m', $conf, $m)) {
            throw new \RuntimeException('Cannot find the listen directive in the panel configuration');
        }
        [$indent, $flags] = [$m[1], $m[3]];
        $block = '';
        foreach ($ports as $p) {
            $block .= "{$indent}listen $p$flags;\n{$indent}listen [::]:$p$flags;\n";
        }
        $conf = preg_replace('/^\s*listen\s+(?:\[::\]:)?\d{1,5}\s+ssl[^;]*;\s*\n/m', '', $conf, -1, $n);
        if ($n < 1) {
            throw new \RuntimeException('Cannot rewrite the listen directive');
        }
        // put the new block where the server block starts
        return preg_replace('/(server\s*\{\s*\n)/', '$1' . $block, $conf, 1);
    }

    private static function apply(string $conf): void
    {
        $old = (string)file_get_contents(self::CONF);
        Sys::write(self::CONF, $conf, 0644);
        [$c, , $e] = Sys::run(['/usr/sbin/nginx', '-t', '-c', self::CONF]);
        if ($c !== 0) {
            Sys::write(self::CONF, $old, 0644);
            throw new \RuntimeException('Panel web server configuration test failed: ' . trim($e));
        }
        Sys::ok(['systemctl', 'reload', 'havoc-panel-nginx']);
    }

    private static function ufw(string ...$a): void
    {
        if (is_file('/usr/sbin/ufw')) {
            Sys::run(['ufw', ...$a]);
        }
    }

    public static function status(): array
    {
        $ports = self::ports((string)@file_get_contents(self::CONF));
        $pending = is_file(self::PENDING) ? json_decode((string)file_get_contents(self::PENDING), true) : null;
        return ['ports' => $ports, 'pending' => is_array($pending) ? $pending : null];
    }

    public static function change(array $a): array
    {
        $new = (int)($a['port'] ?? 0);
        if ($new < 1024 || $new > 65535 || in_array($new, self::RESERVED, true)) {
            throw new \InvalidArgumentException('Choose a port between 1024 and 65535 that no other service uses');
        }
        if (is_file(self::PENDING)) {
            throw new \RuntimeException('A port change is already waiting for confirmation');
        }
        $conf = (string)file_get_contents(self::CONF);
        $cur = self::ports($conf);
        if (count($cur) !== 1) {
            throw new \RuntimeException('Unexpected panel configuration (listen ports: ' . implode(', ', $cur) . ')');
        }
        $old = $cur[0];
        if ($new === $old) {
            throw new \InvalidArgumentException('This is already the panel port');
        }
        [, $ss] = Sys::run(['ss', '-Hltn']);
        if (preg_match('/:' . $new . '\s/', $ss)) {
            throw new \RuntimeException("Port $new is already in use on this server");
        }
        self::ufw('allow', "$new/tcp", 'comment', 'havoc:port');
        self::apply(self::withPorts($conf, [$old, $new]));
        $deadline = time() + self::WINDOW;
        Sys::write(self::PENDING, json_encode(['old' => $old, 'new' => $new, 'deadline' => $deadline]), 0640, 'root', 'havoc');
        Sys::run(['systemctl', 'stop', self::TIMER . '.timer']);
        Sys::run(['systemctl', 'reset-failed', self::TIMER . '.service']);
        Sys::ok(['systemd-run', '--unit=' . self::TIMER, '--on-active=' . self::WINDOW, '--timer-property=AccuracySec=1s',
            '/opt/havoc/ctl/havocctl', 'panel.port_revert']);
        return ['old' => $old, 'new' => $new, 'deadline' => $deadline];
    }

    public static function confirm(array $a): array
    {
        $p = self::status()['pending'] ?? throw new \RuntimeException('No port change is pending');
        if ((int)($a['via_port'] ?? 0) !== (int)$p['new']) {
            throw new \RuntimeException('Confirm from the new port, so we know it works for you');
        }
        Sys::run(['systemctl', 'stop', self::TIMER . '.timer']);
        self::apply(self::withPorts((string)file_get_contents(self::CONF), [(int)$p['new']]));
        self::ufw('--force', 'delete', 'allow', $p['old'] . '/tcp');
        @unlink(self::PENDING);
        return ['port' => (int)$p['new']];
    }

    public static function revert(array $a): array
    {
        $p = self::status()['pending'];
        Sys::run(['systemctl', 'stop', self::TIMER . '.timer']);
        if (!$p) {
            return [];
        }
        self::apply(self::withPorts((string)file_get_contents(self::CONF), [(int)$p['old']]));
        self::ufw('--force', 'delete', 'allow', $p['new'] . '/tcp');
        @unlink(self::PENDING);
        return ['port' => (int)$p['old']];
    }
}
