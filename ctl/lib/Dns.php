<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Authoritative DNS with BIND9. The panel owns the records; here every record is re-validated, the zone file is regenerated,
 * checked with named-checkzone and only then swapped in. A broken record can therefore never take the zone down.
 */
final class Dns
{
    private const DIR = '/etc/bind/havoc';
    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];
    private const HOST = '/^[a-z0-9_][a-z0-9_-]{0,62}(\.[a-z0-9_][a-z0-9_-]{0,62})*\.?$/';

    private static function name(string $n, string $dom): string
    {
        $n = rtrim(strtolower(trim($n)), '.');
        if ($n === '' || $n === '@' || $n === $dom) {
            return '@';
        }
        if (str_ends_with($n, '.' . $dom)) {
            $n = substr($n, 0, -strlen($dom) - 1);
        }
        if (strlen($n) > 200 || !preg_match('/^(\*|[a-z0-9_][a-z0-9_-]{0,62})(\.[a-z0-9_][a-z0-9_-]{0,62})*$/', $n)) {
            throw new \InvalidArgumentException("Invalid record name: $n");
        }
        return $n;
    }

    /** A name with a dot is treated as fully qualified, a single label is relative to the zone, '@' is the zone itself. */
    private static function target(string $h): string
    {
        $h = strtolower(trim($h));
        if ($h === '@') {
            return '@';
        }
        if (strlen($h) > 253 || !preg_match(self::HOST, $h)) {
            throw new \InvalidArgumentException("Invalid host name: $h");
        }
        return str_ends_with($h, '.') || !str_contains($h, '.') ? $h : $h . '.';
    }

    private static function fqdn(mixed $h): string
    {
        $h = strtolower(trim((string)$h));
        if (!str_contains($h, '.') || strlen($h) > 253 || !preg_match(self::HOST, $h)) {
            throw new \InvalidArgumentException('Invalid nameserver host name');
        }
        return rtrim($h, '.') . '.';
    }

    private static function txt(string $t): string
    {
        if ($t === '' || strlen($t) > 2048 || !preg_match('/^[\x20-\x7e]+$/', $t)) {
            throw new \InvalidArgumentException('TXT value must be printable ASCII (max 2048 chars)');
        }
        return implode(' ', array_map(static fn($c) => '"' . addcslashes($c, '"\\') . '"', str_split($t, 250)));
    }

    private static function rr(array $r, string $dom): string
    {
        $type = strtoupper((string)($r['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported record type');
        }
        $name = self::name((string)($r['name'] ?? ''), $dom);
        $ttl  = max(60, min(604800, (int)($r['ttl'] ?? 3600)));
        $c    = trim((string)($r['content'] ?? ''));
        $prio = (int)($r['prio'] ?? 0);
        if ($prio < 0 || $prio > 65535) {
            throw new \InvalidArgumentException('Invalid priority');
        }
        switch ($type) {
            case 'A':
                if (!filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new \InvalidArgumentException('Invalid IPv4 address');
                }
                $d = $c;
                break;
            case 'AAAA':
                if (!filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new \InvalidArgumentException('Invalid IPv6 address');
                }
                $d = $c;
                break;
            case 'CNAME':
                if ($name === '@') {
                    throw new \InvalidArgumentException('CNAME is not allowed at the zone apex');
                }
                $d = self::target($c);
                break;
            case 'MX':
                $d = $prio . ' ' . self::target($c);
                break;
            case 'TXT':
                $d = self::txt($c);
                break;
            case 'SRV':
                if (!preg_match('/^([0-9]{1,5}) ([0-9]{1,5}) (\S+)$/', preg_replace('/\s+/', ' ', $c), $m)) {
                    throw new \InvalidArgumentException('SRV content: "weight port target"');
                }
                $d = "$prio {$m[1]} {$m[2]} " . self::target($m[3]);
                break;
            case 'CAA':
                if (!preg_match('/^([0-9]{1,3}) (issue|issuewild|iodef) (\S{1,255})$/', preg_replace('/\s+/', ' ', $c), $m) || !preg_match('#^[A-Za-z0-9.\-;:@/_=]+$#', $m[3])) {
                    throw new \InvalidArgumentException('CAA content: "flags tag value", e.g. 0 issue letsencrypt.org');
                }
                $d = "{$m[1]} {$m[2]} \"{$m[3]}\"";
                break;
        }
        return sprintf('%s %d IN %s %s', $name, $ttl, $type, $d);
    }

    public static function apply(array $a): array
    {
        $dom = Validator::domain($a['domain'] ?? '');
        $ns  = array_map([self::class, 'fqdn'], array_slice((array)($a['ns'] ?? []), 0, 4));
        if (!$ns) {
            throw new \InvalidArgumentException('Nameservers are not configured');
        }
        $lines = [];
        foreach (array_slice((array)($a['records'] ?? []), 0, 2000) as $r) {
            $lines[] = self::rr((array)$r, $dom);
        }
        $file = self::DIR . "/db.$dom";
        $serial = time();
        if (is_file($file) && preg_match('/(\d+)\s*;\s*serial/', (string)file_get_contents($file), $m)) {
            $serial = max($serial, (int)$m[1] + 1);
        }
        $txt = "; Managed by Havoc WEB Panel - changes are overwritten\n\$TTL 3600\n@ IN SOA {$ns[0]} hostmaster.$dom. ($serial ; serial\n    7200 3600 1209600 3600)\n";
        foreach ($ns as $n) {
            $txt .= "@ IN NS $n\n";
        }
        $txt .= implode("\n", $lines) . "\n";

        is_dir(self::DIR) || mkdir(self::DIR, 0750, true);
        $tmp = self::DIR . "/new.$dom";
        Sys::write($tmp, $txt, 0640, 'root', 'bind');
        [$c, $o, $e] = Sys::run(['named-checkzone', $dom, $tmp]);
        if ($c !== 0) {
            @unlink($tmp);
            throw new \RuntimeException('Zone check failed: ' . trim($o . ' ' . $e));
        }
        rename($tmp, $file);
        self::zones();
        self::reload($dom);
        return ['serial' => $serial];
    }

    public static function delete(array $a): array
    {
        $dom = Validator::domain($a['domain'] ?? '');
        @unlink(self::DIR . "/db.$dom");
        self::zones();
        self::reload(null);
        return [];
    }

    /** Regenerate the include file from the zone files on disk. */
    private static function zones(): void
    {
        $out = "// Managed by Havoc WEB Panel\n";
        foreach (glob(self::DIR . '/db.*') ?: [] as $f) {
            $d = substr(basename($f), 3);
            try {
                Validator::domain($d);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $out .= "zone \"$d\" { type master; file \"$f\"; allow-transfer { none; }; notify no; };\n";
        }
        Sys::write(self::DIR . '/zones.conf', $out, 0640, 'root', 'bind');
        $loc = '/etc/bind/named.conf.local';
        $inc = 'include "' . self::DIR . '/zones.conf";';
        if (is_file($loc) && !str_contains((string)file_get_contents($loc), $inc)) {
            file_put_contents($loc, "\n$inc\n", FILE_APPEND);
        }
    }

    private static function reload(?string $dom): void
    {
        [$c, , $e] = Sys::run(['named-checkconf']);
        if ($c !== 0) {
            throw new \RuntimeException('named configuration check failed: ' . trim($e));
        }
        if (Sys::run(['rndc', 'reconfig'])[0] !== 0) {
            Sys::ok(['systemctl', 'restart', 'named']);
            return;
        }
        if ($dom !== null) {
            Sys::run(['rndc', 'reload', $dom]);
        }
    }
}
