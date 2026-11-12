<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** ufw front-end. Refuses anything that would cut off SSH, the panel or the web ports, or ban the admin's own address. */
final class Firewall
{
    private const PRESETS = ['ftp' => [['21', 'tcp'], ['40000:40100', 'tcp']], 'dns' => [['53', 'tcp'], ['53', 'udp']], 'mail' => [['25', 'tcp'], ['465', 'tcp'], ['587', 'tcp'], ['993', 'tcp']]];

    private static function ufw(string ...$a): string
    {
        return Sys::ok(['ufw', ...$a]);
    }

    private static function protectedPorts(): array
    {
        $p = [80, 443];
        $files = array_merge(['/etc/ssh/sshd_config'], glob('/etc/ssh/sshd_config.d/*.conf') ?: []);
        $ssh = [];
        foreach ($files as $f) {
            if (is_readable($f) && preg_match_all('/^\s*Port\s+(\d{1,5})\s*$/mi', (string)file_get_contents($f), $m)) {
                $ssh = array_merge($ssh, array_map('intval', $m[1]));
            }
        }
        $p = array_merge($p, $ssh ?: [22]);
        $nginx = '/etc/havoc/nginx/nginx.conf';
        if (is_file($nginx) && preg_match('/listen\s+(\d{1,5})\s+ssl/', (string)file_get_contents($nginx), $m)) {
            $p[] = (int)$m[1];
        }
        return array_values(array_unique($p));
    }

    private static function port(mixed $v, mixed $proto): array
    {
        $v = (string)$v;
        $proto = (string)$proto;
        if (!preg_match('/^([0-9]{1,5})(?::([0-9]{1,5}))?$/', $v, $m)) {
            throw new \InvalidArgumentException('Invalid port');
        }
        $a = (int)$m[1];
        $b = isset($m[2]) ? (int)$m[2] : $a;
        if ($a < 1 || $b > 65535 || $a > $b) {
            throw new \InvalidArgumentException('Port out of range');
        }
        if (!in_array($proto, ['tcp', 'udp', 'any'], true)) {
            throw new \InvalidArgumentException('Invalid protocol');
        }
        if ($a !== $b && $proto === 'any') {
            throw new \InvalidArgumentException('A port range needs tcp or udp');
        }
        return [$v, $proto, $a, $b];
    }

    private static function ip(mixed $v): string
    {
        $v = trim((string)$v);
        [$addr, $mask] = array_pad(explode('/', $v, 2), 2, null);
        if (!filter_var($addr, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid IP address');
        }
        $max = str_contains($addr, ':') ? 128 : 32;
        if ($mask !== null && (!ctype_digit($mask) || (int)$mask > $max)) {
            throw new \InvalidArgumentException('Invalid network mask');
        }
        if ($mask !== null && (int)$mask < ($max === 32 ? 8 : 32)) {
            throw new \InvalidArgumentException('Network is too large');
        }
        return $v;
    }

    private static function covers(string $net, string $ip): bool
    {
        [$addr, $mask] = array_pad(explode('/', $net, 2), 2, null);
        if ($mask === null) {
            return @inet_pton($addr) === @inet_pton($ip);
        }
        $a = @inet_pton($addr);
        $b = @inet_pton($ip);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int)$mask;
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        if ($bits % 8 === 0) {
            return true;
        }
        $m = (0xff << (8 - $bits % 8)) & 0xff;
        return (ord($a[$full]) & $m) === (ord($b[$full]) & $m);
    }

    private static function parse(string $o): array
    {
        $rules = [];
        foreach (explode("\n", $o) as $l) {
            if (!preg_match('/^\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)(?:\s+(?:IN|OUT|FWD))?\s+(.+?)(?:\s+#\s*(.*))?\s*$/', $l, $m)) {
                continue;
            }
            $rules[] = ['n' => (int)$m[1], 'to' => trim($m[2]), 'action' => $m[3], 'from' => trim($m[4]), 'comment' => trim($m[5] ?? ''), 'raw' => preg_replace('/\s+/', ' ', trim($l))];
        }
        return $rules;
    }

    public static function status(): array
    {
        if (!is_file('/usr/sbin/ufw')) {
            return ['installed' => false, 'active' => false, 'rules' => [], 'protected' => [], 'default' => ''];
        }
        [, $o] = Sys::run(['ufw', 'status', 'numbered']);
        [, $v] = Sys::run(['ufw', 'status', 'verbose']);
        return ['installed' => true, 'active' => str_contains($o, 'Status: active'), 'rules' => self::parse($o), 'protected' => self::protectedPorts(),
            'default' => preg_match('/^Default:\s*(.+)$/m', $v, $m) ? trim($m[1]) : ''];
    }

    public static function enable(): array
    {
        self::ufw('default', 'deny', 'incoming');
        self::ufw('default', 'allow', 'outgoing');
        foreach (self::protectedPorts() as $p) {
            self::ufw('allow', "$p/tcp", 'comment', 'havoc:port');          // never enable without the lifelines
        }
        self::ufw('--force', 'enable');
        return [];
    }

    public static function disable(): array
    {
        self::ufw('disable');
        return [];
    }

    public static function allow(array $a): array
    {
        [$port, $proto] = self::port($a['port'] ?? '', $a['proto'] ?? 'tcp');
        $from = trim((string)($a['from'] ?? ''));
        $cmd = ['allow'];
        if ($proto !== 'any') {
            array_push($cmd, 'proto', $proto);
        }
        array_push($cmd, 'from', $from === '' ? 'any' : self::ip($from), 'to', 'any', 'port', $port, 'comment', $from === '' ? 'havoc:port' : 'havoc:allow');
        self::ufw(...$cmd);
        return [];
    }

    public static function ban(array $a): array
    {
        $ip = self::ip($a['ip'] ?? '');
        $me = (string)($a['actor_ip'] ?? '');
        if ($me !== '' && self::covers($ip, $me)) {
            throw new \InvalidArgumentException('Refusing to ban your own address');
        }
        self::ufw('insert', '1', 'deny', 'from', $ip, 'to', 'any', 'comment', 'havoc:ban');
        return [];
    }

    public static function whitelist(array $a): array
    {
        self::ufw('insert', '1', 'allow', 'from', self::ip($a['ip'] ?? ''), 'to', 'any', 'comment', 'havoc:whitelist');
        return [];
    }

    public static function preset(array $a): array
    {
        $set = self::PRESETS[(string)($a['name'] ?? '')] ?? throw new \InvalidArgumentException('Unknown preset');
        foreach ($set as [$port, $proto]) {
            self::ufw('allow', 'proto', $proto, 'from', 'any', 'to', 'any', 'port', $port, 'comment', 'havoc:port');
        }
        return [];
    }

    /** Delete by number, but only if the rule at that number is still the one the admin saw. */
    public static function delete(array $a): array
    {
        $n = (int)($a['n'] ?? 0);
        $raw = preg_replace('/\s+/', ' ', trim((string)($a['raw'] ?? '')));
        $rule = null;
        foreach (self::parse(Sys::run(['ufw', 'status', 'numbered'])[1]) as $r) {
            if ($r['n'] === $n) {
                $rule = $r;
            }
        }
        if (!$rule || $rule['raw'] !== $raw) {
            throw new \RuntimeException('The firewall changed in the meantime. Reload the page.');
        }
        if ($rule['action'] === 'ALLOW' && preg_match('/^(\d+)(?:\/\w+)?(?:\s+\(v6\))?$/', $rule['to'], $m) && in_array((int)$m[1], self::protectedPorts(), true)) {
            throw new \InvalidArgumentException('This rule keeps SSH, the panel or the web server reachable and cannot be removed here');
        }
        $me = (string)($a['actor_ip'] ?? '');
        if ($rule['action'] === 'ALLOW' && $me !== '' && preg_match('#^[0-9a-f.:/]+$#i', $rule['from']) && self::covers($rule['from'], $me)) {
            throw new \InvalidArgumentException('This rule allows your own address and cannot be removed here');
        }
        self::ufw('--force', 'delete', (string)$n);
        return [];
    }
}
