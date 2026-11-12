<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** Shared by the OpenLiteSpeed and LiteSpeed Enterprise drivers (both live in /usr/local/lsws). */
trait LiteSpeedCommon
{
    protected static string $root = '/usr/local/lsws';

    /** systemd unit installed by the package / installer (lsws for Enterprise, lshttpd for OpenLiteSpeed; both may exist). */
    public function service(): string
    {
        foreach (['lsws', 'lshttpd'] as $u) {
            [, $o] = Sys::run(['systemctl', 'show', $u, '-p', 'LoadState', '--value']);
            if (trim($o) === 'loaded') {
                return $u;
            }
        }
        return 'lsws';
    }

    public function version(): string
    {
        $v = @file_get_contents(self::$root . '/VERSION');
        return $v === false ? '' : trim($v);
    }

    public function reload(): void
    {
        Sys::ok([self::$root . '/bin/lswsctrl', 'restart'], null, 120);   // graceful restart: picks up new config, no dropped requests
    }

    public function start(): void
    {
        Sys::run(['systemctl', 'enable', $this->service()]);
        Sys::run([self::$root . '/bin/lswsctrl', 'stop'], null, 60);
        Sys::ok(['systemctl', 'restart', $this->service()], null, 120);
    }

    public function stop(): void
    {
        Sys::run(['systemctl', 'disable', '--now', $this->service()], null, 120);
        Sys::run([self::$root . '/bin/lswsctrl', 'stop'], null, 60);
    }

    protected static function vhName(string $domain): string
    {
        return 'havoc-' . $domain;
    }

    protected static function vhDir(string $domain): string
    {
        return self::$root . '/conf/vhosts/' . self::vhName($domain);
    }

    /** Fallback certificate for the HTTPS listener (sites use their own through SNI). */
    protected static function defaultCert(): array
    {
        $crt = '/etc/havoc/ssl/default.crt';
        $key = '/etc/havoc/ssl/default.key';
        if (!is_file($crt) || !is_file($key)) {
            is_dir('/etc/havoc/ssl') || mkdir('/etc/havoc/ssl', 0750, true);
            Sys::ok(['openssl', 'req', '-x509', '-nodes', '-newkey', 'rsa:2048', '-days', '3650', '-keyout', $key, '-out', $crt, '-subj', '/CN=localhost']);
            chmod($key, 0600);
        }
        return [$crt, $key];
    }

    /** Apache-style rewrite rules for suspension, Force HTTPS and the panel's redirects. */
    protected static function rewriteRules(array $vh, bool $ssl): array
    {
        $rules = [];
        if (!empty($vh['suspended'])) {
            return ['RewriteRule .* - [R=503,L]'];
        }
        if (!empty($vh['force_https']) && $ssl) {
            $rules[] = 'RewriteCond %{HTTPS} !on';
            $rules[] = 'RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/';
            $rules[] = 'RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]';
        }
        foreach ($vh['redirects'] ?? [] as $r) {
            $src = $r['source'];
            $c = (int)$r['code'];
            $tg = $r['target'];
            if ($src === '/') {
                $rules[] = 'RewriteRule ^(.*)$ ' . rtrim($tg, '/') . "/$1 [R=$c,L]";
            } elseif (str_ends_with($src, '/*')) {
                $rules[] = 'RewriteRule ^' . preg_quote(ltrim(substr($src, 0, -1), '/'), '/') . "(.*)$ $tg [R=$c,L]";
            } else {
                $rules[] = 'RewriteRule ^' . preg_quote(ltrim($src, '/'), '/') . "$ $tg [R=$c,L]";
            }
        }
        return $rules;
    }

    /** Domains of the vhosts this panel manages (each vhost dir keeps its server names in "names"). */
    protected static function managed(): array
    {
        $out = [];
        foreach (glob(self::$root . '/conf/vhosts/havoc-*/names') ?: [] as $f) {
            $names = array_values(array_filter(array_map('trim', explode(',', (string)file_get_contents($f)))));
            if ($names) {
                $out[basename(dirname($f))] = $names;
            }
        }
        ksort($out);
        return $out;
    }

    protected static function saveNames(array $vh): void
    {
        Sys::write(self::vhDir($vh['domain']) . '/names', implode(',', array_merge([$vh['domain']], $vh['aliases'] ?? [])) . "\n", 0644, 'lsadm', 'lsadm');
    }

    protected static function chownConf(): void
    {
        Sys::run(['chown', '-R', 'lsadm:lsadm', self::$root . '/conf']);
    }
}
