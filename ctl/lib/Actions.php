<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/** All privileged operations. Method name = action with '.' replaced by '_'. Allowed list is explicit. */
final class Actions
{
    public const ALLOWED = [
        'webserver.detect', 'webserver.install', 'webserver.prepare', 'webserver.activate', 'webserver.tuning', 'webserver.tune', 'webserver.cache_purge', 'server.resources', 'php.list', 'php.extensions', 'service.list', 'service.control',
        'account.ensure', 'account.delete', 'domain.apply', 'domain.delete',
        'ssl.letsencrypt', 'ssl.precheck', 'ssl.install', 'mariadb.status', 'mariadb.tune', 'pma.status', 'pma.install', 'pma.uninstall', 'pma.ticket', 'pma.cleanup', 'pma.sso_setup', 'panel.port_status', 'panel.port_change', 'panel.port_confirm', 'panel.port_revert', 'log.read', 'log.list', 'log.clear', 'log.tail_many', 'log.cat', 'panel.update', 'panel.update_status', 'panel.update_check', 'app.wordpress', 'panel.hostname_ssl', 'panel.hostname_status', 'panel.proxy_apply', 'proc.list', 'proc.signal', 'transfer.sizes', 'transfer.export', 'transfer.export_delete', 'transfer.stage', 'transfer.import_files', 'transfer.import_db', 'transfer.patch', 'transfer.wp_replace', 'transfer.check', 'domain.rename', 'domain.rename_addresses', 'os.check', 'os.status', 'os.upgrade', 'preview.ensure', 'php.overview', 'php.install', 'php.remove',
        'app.provision', 'app.deploy', 'app.rollback', 'app.control', 'app.status', 'app.logs', 'app.http', 'app.exec', 'app.env', 'app.read_file', 'app.write_file', 'app.destroy',
        'fs.cat', 'cron.apply', 'cron.run', 'cron.log', 'usage.scan', 'usage.fpm', 'traffic.scan', 'waf.status', 'waf.install', 'waf.apply', 'waf.log', 'redis.status', 'redis.set', 'redis.install', 'redis.uninstall', 'redis.key', 'addon.overview', 'addon.status', 'addon.install', 'addon.uninstall', 'memcached.set', 'memcached.flush', 'fail2ban.apply', 'fail2ban.ban', 'node.globals', 'meili.site_enable', 'meili.site_info', 'meili.site_rotate', 'meili.site_disable', 'meili.site_sizes', 'meili.site_import', 'meili.status', 'meili.install', 'meili.uninstall', 'meili.configure', 'meili.key',
        'fw.status', 'fw.enable', 'fw.disable', 'fw.allow', 'fw.ban', 'fw.whitelist', 'fw.preset', 'fw.delete', 'dns.apply', 'dns.delete', 'ftp.create', 'ftp.update', 'ftp.password', 'ftp.delete', 'backup.create', 'backup.restore', 'backup.delete', 'backup.test', 'backup.about', 'fs.exec', 'db.create', 'db.drop', 'db.password', 'db.remote', 'db.bind', 'db.info', 'db.processlist', 'db.kill',
    ];

    public function webserver_detect(array $a): array
    {
        $r = WebServerFactory::detect();
        $job = trim(Sys::run(['systemctl', 'show', 'havoc-ws-install', '-p', 'ActiveState', '--value'])[1]);
        $r['job'] = $job;
        $r['job_log'] = in_array($job, ['active', 'activating', 'failed'], true)
            ? trim(Sys::run(['journalctl', '-u', 'havoc-ws-install', '--no-pager', '-n', '20', '-o', 'cat'])[1]) : '';
        return $r;
    }

    /** Background install (bin/havoc-ws-install). LiteSpeed Enterprise takes a serial number or "TRIAL". */
    /** Server → Web server → Settings: the active server's values, their limits and the page cache. */
    public function webserver_tuning(array $a): array
    {
        $type = WebServerFactory::active();
        $ws = WebServerFactory::make($type);
        $dir = $type === 'nginx' ? WebTuning::NGINX_CACHE_DIR : ($type === 'ols' ? WebTuning::LS_CACHE_DIR : null);
        $spec = [];
        foreach (WebTuning::SPEC[$type] as $k => [$def, $min, $max]) {
            $spec[$k] = ['default' => $def, 'min' => $min, 'max' => $max];
        }
        return ['type' => $type, 'version' => $ws->installed() ? $ws->version() : '', 'values' => WebTuning::get($type), 'spec' => $spec,
            'sites' => WebTuning::all()['sites'], 'saved' => is_file(WebTuning::FILE), 'cache_dir' => $dir, 'cache_size' => $dir ? WebTuning::dirSize($dir) : null];
    }

    /** Saves and applies the active server's values and the sites' page cache times; nothing changes when the test fails. */
    public function webserver_tune(array $a): array
    {
        $type = WebServerFactory::active();
        $all = WebTuning::all();
        $old = is_file(WebTuning::FILE) ? (string)file_get_contents(WebTuning::FILE) : null;
        $all[$type] = WebTuning::validate($type, (array)($a['values'] ?? []));
        if (isset($a['sites'])) {
            $all['sites'] = WebTuning::validateSites((array)$a['sites']);
        }
        WebTuning::save($all);
        $ws = WebServerFactory::make($type);
        try {
            $ws->tune($all[$type]);
        } catch (\Throwable $e) {
            $old === null ? @unlink(WebTuning::FILE) : Sys::write(WebTuning::FILE, $old, 0644);
            throw $e;
        }
        if (!empty($ws->needsRestart)) {
            Sys::ok(['systemctl', 'restart', $ws->service()], null, 120);
        } else {
            $ws->reload();
        }
        return ['type' => $type, 'restarted' => !empty($ws->needsRestart)];
    }

    /** Empties the page cache of the active server. */
    public function webserver_cache_purge(array $a): array
    {
        $type = WebServerFactory::active();
        $dir = $type === 'nginx' ? WebTuning::NGINX_CACHE_DIR : ($type === 'ols' ? WebTuning::LS_CACHE_DIR : null);
        if ($dir === null) {
            throw new \RuntimeException('LiteSpeed Enterprise keeps its cache where WebAdmin says (Server > Cache); purge it there or with the LiteSpeed Cache plugin of the site.');
        }
        $before = WebTuning::dirSize($dir);
        WebTuning::purge($dir);
        // new workers: Nginx's open file cache would otherwise keep serving deleted entries for a while
        WebServerFactory::make($type)->reload();
        return ['freed' => $before - WebTuning::dirSize($dir)];
    }

    /** Server → Resources: memory and CPU per service (each site's PHP is one), page cache vs. real use, swap. */
    public function server_resources(array $a): array
    {
        return Resources::snapshot();
    }

    public function webserver_install(array $a): array
    {
        $t = WebServerFactory::type($a['type'] ?? '');
        $lic = (string)($a['license'] ?? 'TRIAL');
        if ($t === 'lsws' && $lic !== 'TRIAL' && !preg_match('/^[A-Za-z0-9\/+=-]{8,64}$/D', $lic)) {
            throw new \InvalidArgumentException('Invalid LiteSpeed serial number');
        }
        if ($t !== 'nginx' && WebServerFactory::active() !== 'nginx' && WebServerFactory::active() !== $t) {
            throw new \RuntimeException('Activate Nginx first: OpenLiteSpeed and LiteSpeed Enterprise replace each other and the active one cannot be removed');
        }
        $job = trim(Sys::run(['systemctl', 'show', 'havoc-ws-install', '-p', 'ActiveState', '--value'])[1]);
        if (in_array($job, ['active', 'activating'], true)) {
            throw new \RuntimeException('An installation is already running');
        }
        Sys::run(['systemctl', 'reset-failed', 'havoc-ws-install']);
        $cmd = ['systemd-run', '--no-block', '--unit=havoc-ws-install', '/opt/havoc/bin/havoc-ws-install', $t];
        if ($t === 'lsws') {
            $cmd[] = $lic;
        }
        Sys::ok($cmd);
        return [];
    }

    /** Server-level configuration for a (possibly not yet active) web server. */
    public function webserver_prepare(array $a): array
    {
        $t = WebServerFactory::type($a['target'] ?? '');
        $d = WebServerFactory::make($t);
        if (!$d->installed()) {
            throw new \RuntimeException(WebServerFactory::TYPES[$t] . ' is not installed');
        }
        $d->prepare();
        return [];
    }

    /**
     * Make $target the web server for all sites: stop the current one, start the target, check that it answers on port 80.
     * On failure the previous server is started again and the selection is left unchanged.
     */
    public function webserver_activate(array $a): array
    {
        $t = WebServerFactory::type($a['target'] ?? '');
        $new = WebServerFactory::make($t);
        if (!$new->installed()) {
            throw new \RuntimeException(WebServerFactory::TYPES[$t] . ' is not installed');
        }
        $prev = WebServerFactory::active();
        $old = WebServerFactory::make($prev);
        foreach (WebServerFactory::TYPES as $x => $_) {             // free ports 80/443 from every other server
            $o = WebServerFactory::make($x);
            if ($x !== $t && $o->installed()) {
                $o->stop();
            }
        }
        $ok = false;
        $why = '';
        try {
            $new->start();
            for ($i = 0; $i < 20 && !$ok; $i++) {
                usleep(500000);
                $ok = trim(Sys::run(['systemctl', 'is-active', $new->service()])[1]) === 'active'
                    && preg_match('/:80\s/', Sys::run(['ss', '-Hltn'])[1]) === 1;
            }
            if ($ok) {
                $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0]]);
                $ok = @file_get_contents('http://127.0.0.1/', false, $ctx, 0, 1) !== false || !empty($http_response_header);
                $why = $ok ? '' : 'no HTTP answer on port 80';
            } else {
                $why = 'the service did not start or does not listen on port 80';
            }
        } catch (\Throwable $e) {
            $why = $e->getMessage();
        }
        if (!$ok) {
            $log = trim(Sys::run(['journalctl', '-u', $new->service(), '--no-pager', '-n', '10', '-o', 'cat'])[1]);
            $new->stop();
            if ($prev !== $t && $old->installed()) {
                $old->start();
            }
            throw new \RuntimeException(WebServerFactory::TYPES[$t] . " could not be activated ($why); " . WebServerFactory::TYPES[$prev] . " was started again.\n" . mb_substr($log, -1500));
        }
        WebServerFactory::setActive($t);
        try { PanelHost::apply(); } catch (\Throwable) {}              // the 443 forwarding exists only with Nginx
        return ['active' => $t, 'previous' => $prev];
    }

    public function php_list(array $a): array
    {
        return ['versions' => Fpm::installedVersions()];
    }

    public function php_extensions(array $a): array
    {
        $v = Validator::php($a['version'] ?? '');
        return ['available' => array_keys(Fpm::availableExtensions($v)), 'default' => Fpm::defaultExtensions($v)];
    }

    public function service_list(array $a): array
    {
        $out = [];
        foreach (Validator::SERVICES as $s) {
            [, $o] = Sys::run(['systemctl', 'show', $s, '-p', 'LoadState', '-p', 'ActiveState', '-p', 'UnitFileState']);
            $p = [];
            foreach (explode("\n", trim($o)) as $line) {
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $p[$k] = $v;
            }
            if (($p['LoadState'] ?? '') === 'loaded') {
                $out[] = ['name' => $s, 'active' => $p['ActiveState'], 'enabled' => $p['UnitFileState'], 'protected' => in_array($s, Validator::PROTECTED_SERVICES, true)];
            }
        }
        return ['services' => $out];
    }

    public function service_control(array $a): array
    {
        $s  = Validator::service($a['name'] ?? '');
        $op = $a['op'] ?? '';
        if (!in_array($op, ['start', 'stop', 'restart', 'reload', 'enable', 'disable'], true)) {
            throw new \InvalidArgumentException('Bad operation');
        }
        if (in_array($s, Validator::PROTECTED_SERVICES, true) && !in_array($op, ['restart', 'reload'], true)) {
            throw new \InvalidArgumentException('Panel services can only be restarted');
        }
        Sys::ok(['systemctl', $op, $s]);
        return [];
    }

    public function account_ensure(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        [$c] = Sys::run(['id', '-u', $acct]);
        if ($c !== 0) {
            Sys::ok(['useradd', '--create-home', '--home-dir', "/home/$acct", '--shell', '/usr/sbin/nologin', '--user-group', $acct]);
        }
        Sys::ok(['usermod', '-aG', $acct, 'www-data']);          // web server can traverse, other tenants cannot
        chmod("/home/$acct", 0710);
        // The tenant controls the content of its home. Never follow a symlink here as root: mkdir() does not follow one,
        // lchown() changes the link itself, and a symlink in place of these directories is refused.
        foreach (["/home/$acct/domains", "/home/$acct/tmp"] as $d) {
            if (is_link($d)) {
                throw new \RuntimeException(basename($d) . ' in the account home must be a directory, not a symbolic link');
            }
            if (!is_dir($d)) {
                mkdir($d, 0750);
            }
            lchown($d, $acct);
            lchgrp($d, $acct);
        }
        return [];
    }

    public function account_delete(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        Sys::run(['pkill', '-u', $acct]);
        Sys::run(['userdel', '--remove', $acct]);
        return [];
    }

    private function vh(array $a): array
    {
        $dom  = Validator::domain($a['domain'] ?? '');
        $acct = Validator::account($a['account'] ?? '');
        $rel  = isset($a['docroot_rel']) ? Validator::relPath($a['docroot_rel']) : "domains/$dom/public_html";
        $vh = [
            'id' => Validator::id($a['id'] ?? ''), 'domain' => $dom, 'account' => $acct,
            'aliases' => array_map([Validator::class, 'domain'], $a['aliases'] ?? []),
            // old names kept after a rename: answered with a 301 to the domain (they stay in aliases for listeners/certificates)
            'redirect_hosts' => array_values(array_intersect(array_map([Validator::class, 'domain'], $a['redirect_hosts'] ?? []), array_map([Validator::class, 'domain'], $a['aliases'] ?? []))),
            'docroot' => "/home/$acct/$rel", 'php' => Validator::php($a['php'] ?? ''),
            'extensions' => $a['extensions'] ?? null,
            'opcache' => ['enable' => (int)!empty($a['opcache']['enable']), 'memory' => max(32, min(1024, (int)($a['opcache']['memory'] ?? 128)))],
            'limits' => ['ram_mb' => (int)($a['limits']['ram_mb'] ?? 1024), 'cpu_percent' => (int)($a['limits']['cpu_percent'] ?? 100)],
            'force_https' => !empty($a['force_https']), 'hsts' => !empty($a['hsts']), 'suspended' => !empty($a['suspended']),
            'waf' => !empty($a['waf']), 'logs' => !empty($a['logs']), 'ssl' => [],
            'php_ini' => PhpIni::userSettings(is_array($a['php_ini'] ?? null) ? $a['php_ini'] : []),
            'php_ini_admin' => (string)($a['php_ini_admin'] ?? ''),
            'nginx_custom' => self::customRules($a),
            'redirects' => array_map(static fn($r) => [
                'source' => Validator::redirectSource($r['source'] ?? ''), 'target' => Validator::redirectTarget($r['target'] ?? ''),
                'code' => Validator::redirectCode($r['code'] ?? 301)], array_slice((array)($a['redirects'] ?? []), 0, 200)),
            // reverse proxy to a bound application (set whenever the domain has one); makes render() always emit the proxy
            // variant, so the plain site template can never overwrite it on an SSL renewal, PHP change, etc.
            'app' => self::appProxy($a['app'] ?? null),
        ];
        $cert = "/etc/havoc/ssl/$dom/fullchain.pem";
        $le   = "/etc/letsencrypt/live/$dom/fullchain.pem";
        $mode = $a['ssl_mode'] ?? 'none';
        if ($mode === 'letsencrypt' && is_file($le)) {
            $vh['ssl'] = ['cert' => $le, 'key' => "/etc/letsencrypt/live/$dom/privkey.pem"];
        } elseif (in_array($mode, ['custom', 'cloudflare'], true) && is_file($cert)) {
            $vh['ssl'] = ['cert' => $cert, 'key' => "/etc/havoc/ssl/$dom/privkey.pem"];
        }
        return $vh;
    }

    /** Validate the reverse-proxy spec the panel sends (name / local port / ws paths), or null when the domain has no app. */
    private function appProxy(mixed $app): ?array
    {
        if (!is_array($app) || empty($app['name']) || empty($app['port'])) {
            return null;
        }
        $name = (string)$app['name'];
        $port = (int)$app['port'];
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $name) || $port < 1 || $port > 65535) {
            return null;
        }
        $ws = [];
        foreach ((array)($app['ws_paths'] ?? []) as $p) {
            $p = '/' . ltrim((string)$p, '/');
            if (preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]{0,200}$#', $p)) {
                $ws[] = rtrim($p, '/') ?: $p;
            }
        }
        return ['name' => $name, 'port' => $port, 'ws_paths' => array_values(array_unique($ws))];
    }

    /**
     * Idempotent: creates and updates. Used for create, PHP change, SSL change, WAF/log toggles.
     * target: render for a web server that is not (yet) active (used while switching); web_only: skip the PHP-FPM unit.
     */
    public function domain_apply(array $a): array
    {
        $vh = $this->vh($a);
        $target = isset($a['target']) ? WebServerFactory::type($a['target']) : null;
        $this->account_ensure(['account' => $vh['account']]);
        if (!is_dir($vh['docroot'])) {
            // created by the tenant itself, so a planted symlink can never make root write outside the account
            $html = "<!doctype html><meta charset=utf-8><title>{$vh['domain']}</title><h1>{$vh['domain']}</h1><p>Hosted with Havoc WEB Panel.</p>\n";
            Sys::ok([...Sys::asUser($vh['account']), '/bin/sh', '-c', 'umask 022; mkdir -p -- "$1" && { [ -e "$1/index.html" ] || printf "%s" "$2" > "$1/index.html"; }',
                'sh', $vh['docroot'], $html]);
        }
        foreach (['/var/log/havoc/domains', '/var/www/havoc-acme/.well-known/acme-challenge'] as $d) {
            is_dir($d) || mkdir($d, 0755, true);
        }
        if (empty($a['web_only'])) {
            Fpm::apply($vh);
        }
        $active = WebServerFactory::active();
        $ws = WebServerFactory::make($target ?? $active);
        $ws->apply($vh);
        if (($target ?? $active) === $active) {
            $ws->reload();
        }
        return ['docroot' => $vh['docroot'], 'webserver' => $ws->type()];
    }

    public function domain_delete(array $a): array
    {
        $dom = Validator::domain($a['domain'] ?? '');
        $id  = Validator::id($a['id'] ?? '');
        foreach (array_keys(WebServerFactory::TYPES) as $t) {          // drop it from every installed server's configuration
            $w = WebServerFactory::make($t);
            if ($w->installed()) {
                $w->remove($dom);
            }
        }
        WebServerFactory::make()->reload();
        Fpm::remove($id);
        if (!empty($a['purge'])) {
            $acct = Validator::account($a['account'] ?? '');
            // as the account user: the tenant controls this path (a symlinked "domains" must not make root delete elsewhere)
            Sys::run([...Sys::asUser($acct), 'rm', '-rf', '--', "/home/$acct/domains/$dom"]);
        }
        return [];
    }

    public function ssl_letsencrypt(array $a): array
    {
        $dom   = Validator::domain($a['domain'] ?? '');
        $names = array_values(array_unique(array_merge([$dom], array_map([Validator::class, 'domain'], array_slice((array)($a['aliases'] ?? []), 0, 90)))));
        $email = filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new \InvalidArgumentException('Valid email required');
        }
        // --cert-name keeps one lineage per domain; a changed name list re-issues that lineage (renewal: certbot.timer + deploy hook)
        $cmd = ['certbot', 'certonly', '--webroot', '-w', '/var/www/havoc-acme', '--non-interactive', '--agree-tos',
                '-m', $email, '--cert-name', $dom, '--keep-until-expiring', '--expand'];
        foreach ($names as $n) {
            array_push($cmd, '-d', $n);
        }
        [$c, $o, $e] = Sys::run($cmd, null, 240);
        if ($c !== 0) {
            $msg = trim($e ?: $o);
            if (preg_match('/(Detail|Error):\s*(.+)$/m', $msg, $m)) {
                $msg = trim($m[2]);                               // the useful line of certbot's output
            }
            throw new \RuntimeException("Let's Encrypt: " . mb_substr($msg, 0, 400));
        }
        return ['cert' => "/etc/letsencrypt/live/$dom/fullchain.pem", 'names' => $names];
    }

    /**
     * Checks, before asking Let's Encrypt, that http://<name>/.well-known/acme-challenge/ reaches THIS server for each name:
     * a random token is published in the ACME webroot and fetched through every A and AAAA address of the name.
     * This avoids burning Let's Encrypt's failed-validation limit on domains whose DNS does not point here yet.
     * Private/reserved addresses are never contacted, and each IP is connected to directly (no second DNS lookup).
     */
    public function ssl_precheck(array $a): array
    {
        $names = array_values(array_unique(array_map([Validator::class, 'domain'], array_slice((array)($a['names'] ?? []), 0, 50))));
        $dir = '/var/www/havoc-acme/.well-known/acme-challenge';
        is_dir($dir) || mkdir($dir, 0755, true);
        $tok = 'havoc-' . bin2hex(random_bytes(16));
        file_put_contents("$dir/$tok", $tok);
        chmod("$dir/$tok", 0644);
        $out = [];
        try {
            foreach ($names as $n) {
                $ips = [];
                foreach (@dns_get_record($n, DNS_A | DNS_AAAA) ?: [] as $r) {
                    if (isset($r['ip'])) {
                        $ips[] = $r['ip'];
                    } elseif (isset($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
                if (!$ips) {
                    $out[$n] = ['ok' => false, 'reason' => 'no_dns'];
                    continue;
                }
                $res = ['ok' => true, 'reason' => ''];
                foreach (array_slice(array_unique($ips), 0, 8) as $ip) {
                    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        $res = ['ok' => false, 'reason' => 'private_ip', 'ip' => $ip];
                        break;
                    }
                    $host = str_contains($ip, ':') ? "[$ip]" : $ip;
                    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 8, 'follow_location' => 0, 'ignore_errors' => true,
                        'header' => "Host: $n\r\nUser-Agent: Havoc-SSL-Precheck\r\nConnection: close\r\n"]]);
                    $body = @file_get_contents("http://$host/.well-known/acme-challenge/$tok", false, $ctx, 0, 256);
                    if (trim((string)$body) !== $tok) {
                        $res = ['ok' => false, 'reason' => self::cloudflare($ip) ? 'cloudflare' : (str_contains($ip, ':') ? 'ipv6_fail' : 'http_fail'), 'ip' => $ip];
                        break;
                    }
                }
                $out[$n] = $res;
            }
        } finally {
            @unlink("$dir/$tok");
        }
        return ['results' => $out];
    }

    /** Cloudflare's published proxy ranges (https://www.cloudflare.com/ips/). */
    private const CLOUDFLARE = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14',
        '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32'];

    private static function cloudflare(string $ip): bool
    {
        $bin = @inet_pton($ip);
        foreach (self::CLOUDFLARE as $cidr) {
            [$net, $len] = explode('/', $cidr);
            $nb = inet_pton($net);
            if ($bin === false || strlen($nb) !== strlen($bin)) {
                continue;
            }
            $bytes = intdiv((int)$len, 8);
            $bits = (int)$len % 8;
            if (substr($bin, 0, $bytes) === substr($nb, 0, $bytes)
                && ($bits === 0 || ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $bits)) & 0xFF) === 0)) {
                return true;
            }
        }
        return false;
    }

    /** Custom certificate or Cloudflare Origin Certificate (both are plain PEM pairs). */
    public function ssl_install(array $a): array
    {
        $dom  = Validator::domain($a['domain'] ?? '');
        $cert = (string)($a['cert'] ?? '');
        $key  = (string)($a['key'] ?? '');
        $x = @openssl_x509_read($cert);
        $k = @openssl_pkey_get_private($key);
        if (!$x || !$k || !openssl_x509_check_private_key($x, $k)) {
            throw new \InvalidArgumentException('Certificate and private key are invalid or do not match');
        }
        $chain = (string)($a['chain'] ?? '');
        $dir = "/etc/havoc/ssl/$dom";
        is_dir($dir) || mkdir($dir, 0750, true);
        Sys::write("$dir/fullchain.pem", rtrim($cert) . "\n" . ($chain !== '' ? rtrim($chain) . "\n" : ''), 0644);
        Sys::write("$dir/privkey.pem", rtrim($key) . "\n", 0600);
        return [];
    }

    /** Tail or follow a domain or server log. offset=null -> last N lines; offset=int -> bytes appended since. */
    public function log_read(array $a): array
    {
        $file = Logs::path($a);
        if (!is_file($file)) {
            return ['data' => '', 'offset' => 0];
        }
        clearstatcache(true, $file);
        $size = (int)filesize($file);
        if (!isset($a['offset']) || $a['offset'] === null) {
            return ['data' => Logs::tail($file, max(10, min(2000, (int)($a['lines'] ?? 200)))), 'offset' => $size];
        }
        $off = (int)$a['offset'];
        if ($off > $size) {
            $off = 0;                                           // rotated or cleared
        }
        $fh = fopen($file, 'rb');
        fseek($fh, max($off, $size - 128 * 1024));
        $data = (string)stream_get_contents($fh, $size - max($off, $size - 128 * 1024));
        fclose($fh);
        return ['data' => mb_convert_encoding($data, 'UTF-8', 'UTF-8'), 'offset' => $size];
    }

    public function log_list(array $a): array { return Logs::list($a); }
    public function log_clear(array $a): array { return Logs::clear($a); }
    public function log_tail_many(array $a): array { return Logs::tailMany($a); }
    public function log_cat(array $a): array { throw new \LogicException('log.cat is a streaming action'); }

    /** WordPress into an empty site, installed as the hosting account (see AppInstaller). */
    public function app_wordpress(array $a): array
    {
        return AppInstaller::wordpress($a);
    }

    /** Running processes (ps), newest data first by CPU; kernel threads are marked so the panel can hide them. */
    public function proc_list(array $a): array
    {
        [$c, $o] = Sys::run(['ps', '-eo', 'pid=,ppid=,uid=,user:32=,pcpu=,pmem=,rss=,etimes=,stat=,args=', '--sort=-pcpu'], null, 20, ['LC_ALL' => 'C']);
        if ($c !== 0) {
            throw new \RuntimeException('ps failed');
        }
        $out = [];
        foreach (explode("\n", trim($o)) as $line) {
            $f = preg_split('/\s+/', trim($line), 10);
            if (count($f) < 10 || !ctype_digit($f[0])) {
                continue;
            }
            [$pid, $ppid, $uid, $user, $cpu, $mem, $rss, $et, $st, $args] = $f;
            if ((int)$pid === getmypid()) {
                continue;
            }
            $out[] = ['pid' => (int)$pid, 'ppid' => (int)$ppid, 'uid' => (int)$uid, 'user' => $user, 'cpu' => (float)$cpu, 'mem' => (float)$mem,
                'rss' => (int)$rss * 1024, 'time' => (int)$et, 'state' => $st, 'cmd' => mb_substr($args, 0, 400),
                'kernel' => (int)$pid === 2 || (int)$ppid === 2];
            if (count($out) >= 2000) {
                break;
            }
        }
        return ['processes' => $out, 'at' => time()];
    }

    /** TERM or KILL for a process of a hosting account (hvN) only: system services cannot be stopped from the panel. */
    public function proc_signal(array $a): array
    {
        $pid = (int)($a['pid'] ?? 0);
        $sig = ($a['signal'] ?? 'TERM') === 'KILL' ? 9 : 15;
        if ($pid < 2 || !is_dir("/proc/$pid")) {
            throw new \RuntimeException('Process not found (it may have ended already)');
        }
        $uid = preg_match('/^Uid:\s+(\d+)/m', (string)@file_get_contents("/proc/$pid/status"), $m) ? (int)$m[1] : 0;
        $name = (string)(posix_getpwuid($uid)['name'] ?? '');
        if (!preg_match('/^hv\d+$/D', $name)) {
            throw new \RuntimeException('Only processes of hosting accounts can be ended here (this one belongs to ' . ($name ?: "uid $uid") . ')');
        }
        if (!posix_kill($pid, $sig)) {
            throw new \RuntimeException('Could not signal the process');
        }
        return ['pid' => $pid, 'user' => $name, 'signal' => $sig === 9 ? 'KILL' : 'TERM'];
    }

    private const PANEL_CRT = '/etc/havoc/ssl/panel.crt';
    private const PANEL_KEY = '/etc/havoc/ssl/panel.key';
    private const PANEL_HOOK = '/etc/letsencrypt/renewal-hooks/deploy/havoc-panel';

    /**
     * Let's Encrypt certificate for the panel's own host name (HTTP-01 through the ACME webroot that the catch-all
     * Nginx server answers on port 80). The certificate is copied over the panel's certificate files, so the panel
     * Nginx configuration does not change; a deploy hook repeats the copy on every renewal.
     */
    public function panel_hostname_ssl(array $a): array
    {
        $host = Validator::domain($a['host'] ?? '');
        $email = filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new \InvalidArgumentException('Valid email required');
        }
        if (WebServerFactory::active() !== 'nginx') {
            throw new \RuntimeException('The panel certificate is issued through Nginx on port 80; activate Nginx as the web server first.');
        }
        (new NginxDriver())->prepare();                                  // makes sure the catch-all (ACME) server exists
        Sys::run(['systemctl', 'reload', 'nginx']);
        $pre = $this->ssl_precheck(['names' => [$host]])['results'][$host] ?? ['ok' => false, 'reason' => 'http_fail'];
        if (empty($pre['ok'])) {
            $why = ['no_dns' => 'has no A/AAAA record', 'private_ip' => 'points to a private address', 'cloudflare' => 'goes through the Cloudflare proxy (use "DNS only")',
                'ipv6_fail' => 'has an AAAA record that does not reach this server', 'http_fail' => 'does not point to this server'][$pre['reason']] ?? 'is not reachable';
            throw new \RuntimeException("$host $why.");
        }
        [$c, $o, $e] = Sys::run(['certbot', 'certonly', '--webroot', '-w', '/var/www/havoc-acme', '--non-interactive', '--agree-tos', '-m', $email,
            '--cert-name', 'havoc-panel', '-d', $host, '--keep-until-expiring'], null, 300);
        if ($c !== 0) {
            $msg = preg_match('/(Detail|Error): (.+)/', $e . $o, $m) ? $m[2] : trim($e ?: $o);
            throw new \RuntimeException('Let\'s Encrypt: ' . mb_substr($msg, 0, 300));
        }
        Sys::write(self::PANEL_HOOK, "#!/bin/sh\n# Havoc WEB Panel: copy the renewed panel certificate and reload the panel web server\n"
            . "[ \"\$RENEWED_LINEAGE\" = /etc/letsencrypt/live/havoc-panel ] || exit 0\n"
            . "install -m 644 /etc/letsencrypt/live/havoc-panel/fullchain.pem " . self::PANEL_CRT . "\n"
            . "install -m 600 /etc/letsencrypt/live/havoc-panel/privkey.pem " . self::PANEL_KEY . "\n"
            . "systemctl reload havoc-panel-nginx\n"
            . "systemctl is-active --quiet nginx && systemctl reload nginx || true\n", 0755);
        $cur = is_file(self::PANEL_CRT) ? @openssl_x509_parse((string)file_get_contents(self::PANEL_CRT)) : false;
        if (!is_file(self::PANEL_CRT . '.selfsigned') && $cur && !str_contains((string)($cur['issuer']['O'] ?? ''), "Let's Encrypt")) {
            copy(self::PANEL_CRT, self::PANEL_CRT . '.selfsigned');
            copy(self::PANEL_KEY, self::PANEL_KEY . '.selfsigned');
        }
        Sys::ok(['/bin/sh', self::PANEL_HOOK], null, 60, ['RENEWED_LINEAGE' => '/etc/letsencrypt/live/havoc-panel']);
        Sys::write(PanelHost::HOST_FILE, $host . "\n", 0644);
        PanelHost::apply();                                              // also on https://<host>/ (port 443)
        return $this->panel_hostname_status([]);
    }

    public function panel_hostname_status(array $a): array
    {
        $host = PanelHost::host();
        $x = @openssl_x509_parse((string)@file_get_contents(self::PANEL_CRT));
        $port = 0;
        if (preg_match('/listen\s+(\d+)\s+ssl/', (string)@file_get_contents('/etc/havoc/nginx/nginx.conf'), $m)) {
            $port = (int)$m[1];
        }
        return ['host' => $host, 'port' => $port, 'issuer' => (string)($x['issuer']['O'] ?? $x['issuer']['CN'] ?? ''),
            'subject' => (string)($x['subject']['CN'] ?? ''), 'expires' => isset($x['validTo_time_t']) ? gmdate('Y-m-d', (int)$x['validTo_time_t']) : '',
            'trusted' => isset($x['issuer']['O']) && str_contains((string)$x['issuer']['O'], "Let's Encrypt"),
            'webserver' => WebServerFactory::active(), 'proxy' => PanelHost::active()];
    }

    /** Re-creates the port 443 forwarding for the panel host (after updates, port or web server changes). */
    public function panel_proxy_apply(array $a): array
    {
        return PanelHost::apply();
    }

    private const UPDATE_UNIT = 'havoc-update';

    private static function updateRepo(): string
    {
        $repo = 'webolyecom/havoc-web-panel';
        foreach (@file('/etc/havoc/update.conf', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (preg_match('/^\s*HAVOC_UPDATE_REPO=["\']?([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)["\']?\s*$/D', $l, $m)) {
                $repo = $m[1];
            }
        }
        return $repo;
    }

    /** Starts bin/havoc-update in its own systemd unit (it restarts the panel). */
    public function panel_update(array $a): array
    {
        $args = [];
        if (($a['tag'] ?? '') !== '') {
            $tag = (string)$a['tag'];
            if (!preg_match('/^v?\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $tag)) {
                throw new \InvalidArgumentException('Invalid version');
            }
            $args[] = $tag;
        }
        if (!empty($a['force'])) {
            $args[] = '--force';
        }
        $state = trim(Sys::run(['systemctl', 'show', self::UPDATE_UNIT, '-p', 'ActiveState', '--value'])[1]);
        if (in_array($state, ['active', 'activating'], true)) {
            throw new \RuntimeException('An update is already running');
        }
        Sys::run(['systemctl', 'reset-failed', self::UPDATE_UNIT]);      // a failed transient unit would block the name
        Sys::ok(['systemd-run', '--no-block', '--unit=' . self::UPDATE_UNIT, '--description=Havoc WEB Panel update',
            '--property=TimeoutStartSec=1800', '/opt/havoc/bin/havoc-update', ...$args]);
        return [];
    }

    /** State and log of the last update run (bin/havoc-update keeps its own log, the transient unit is gone afterwards). */
    public function panel_update_status(array $a): array
    {
        $unit = trim(Sys::run(['systemctl', 'show', self::UPDATE_UNIT, '-p', 'ActiveState', '--value'])[1]);
        $st = preg_split('/\s+/', trim((string)@file_get_contents('/var/log/havoc/update.status'))) ?: [];
        $state = in_array($unit, ['active', 'activating', 'deactivating'], true) ? 'running' : ($st[0] ?? '');
        if ($state === 'running' && !in_array($unit, ['active', 'activating', 'deactivating'], true)) {
            $state = 'failed';                                          // killed (reboot, OOM) before it could finish
        }
        $log = (string)@file_get_contents('/var/log/havoc/update.log', false, null, 0, 200000);
        return ['state' => in_array($state, ['running', 'done', 'failed', 'uptodate'], true) ? $state : '', 'time' => $st[1] ?? '',
            'log' => mb_substr(trim(preg_replace('/\e\[[0-9;]*m/', '', $log)), -20000),
            'repo' => self::updateRepo(), 'version' => trim((string)@file_get_contents('/opt/havoc/VERSION'))];
    }

    /** Tag of the latest release from the redirect of github.com/<repo>/releases/latest; null = no release, false = failed. */
    private static function latestTagByRedirect(string $repo): string|null|false
    {
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 15, 'follow_location' => 0, 'ignore_errors' => true,
            'header' => "User-Agent: Havoc-WEB-Panel\r\n"]]);
        if (@file_get_contents("https://github.com/$repo/releases/latest", false, $ctx) === false && empty($http_response_header)) {
            return false;
        }
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^Location:\s*\S*/releases/tag/(v?\d{1,4}\.\d{1,4}\.\d{1,4})\s*$#iD', $h, $m)) {
                return $m[1];
            }
            if (preg_match('#^Location:\s*\S*/releases/?\s*$#iD', $h)) {
                return null;
            }
        }
        return false;
    }

    /** Latest published release of the configured repository (GitHub API, no token). */
    public function panel_update_check(array $a): array
    {
        $repo = self::updateRepo();
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true,
            'header' => "Accept: application/vnd.github+json\r\nUser-Agent: Havoc-WEB-Panel\r\n"]]);
        $body = @file_get_contents("https://api.github.com/repos/$repo/releases/latest", false, $ctx, 0, 2000000);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        if ($body === false || $code === 0 || $code === 403 || $code === 429) {
            // API unreachable or rate limited (60 requests/hour per IP): the release page redirect still names the latest tag
            $tag = self::latestTagByRedirect($repo);
            if ($tag === false) {
                throw new \RuntimeException($code ? 'GitHub answered ' . $code : 'GitHub could not be reached');
            }
            return ['repo' => $repo, 'latest' => $tag === null ? null : ['tag' => $tag, 'version' => ltrim($tag, 'v'), 'name' => $tag, 'notes' => '',
                'published' => '', 'url' => "https://github.com/$repo/releases/tag/" . rawurlencode($tag)]];
        }
        if ($code === 404) {
            return ['repo' => $repo, 'latest' => null];                  // no release published yet
        }
        $j = json_decode($body, true);
        if ($code !== 200 || !is_array($j)) {
            throw new \RuntimeException('GitHub answered ' . $code . (is_array($j) && isset($j['message']) ? ': ' . mb_substr((string)$j['message'], 0, 200) : ''));
        }
        $tag = (string)($j['tag_name'] ?? '');
        if (!preg_match('/^v?\d{1,4}\.\d{1,4}\.\d{1,4}$/D', $tag)) {
            return ['repo' => $repo, 'latest' => null];
        }
        return ['repo' => $repo, 'latest' => [
            'tag' => $tag, 'version' => ltrim($tag, 'v'), 'name' => mb_substr((string)($j['name'] ?? $tag), 0, 200),
            'notes' => mb_substr((string)($j['body'] ?? ''), 0, 20000), 'published' => (string)($j['published_at'] ?? ''),
            'url' => "https://github.com/$repo/releases/tag/" . rawurlencode($tag),
        ]];
    }

    /** @return array{0:array,1:string} [command, JSON payload] for the file worker of a domain */
    private function fsWorker(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        $dom  = Validator::domain($a['domain'] ?? '');
        $root = "/home/$acct/domains/$dom/public_html";
        if (!is_dir($root)) {
            throw new \RuntimeException('Account or document root not found');
        }
        $payload = json_encode(['root' => $root, 'op' => (string)($a['op'] ?? ''), 'args' => is_array($a['args'] ?? null) ? $a['args'] : []]);
        return [[...Sys::asUser($acct), '/usr/bin/php', '-d', 'memory_limit=256M', __DIR__ . '/../fsworker'], $payload];
    }

    /** File manager: executed as the tenant's own user, confined to the domain's document root. */
    public function fs_exec(array $a): array
    {
        [$cmd, $payload] = $this->fsWorker($a);
        [, $o, $e] = Sys::run($cmd, $payload, 600);
        $res = json_decode($o, true);
        if (!is_array($res)) {
            throw new \RuntimeException('File worker failed: ' . trim($e));
        }
        if (empty($res['ok'])) {
            throw new \RuntimeException((string)($res['error'] ?? 'File operation failed'));
        }
        return $res['data'] ?? [];
    }

    /**
     * Streaming download (no buffering): the worker writes "OK <size> <name>" or "ERR <msg>" and then the raw bytes
     * straight to our stdout. Called by havocctl outside the JSON protocol.
     */
    public function fsCatPassthrough(array $a): int
    {
        $a['op'] = 'cat';
        [$cmd, $payload] = $this->fsWorker($a);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => ['file', '/dev/null', 'w']], $pipes, null,
            ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);
        if (!is_resource($p)) {
            echo "ERR cannot start worker\n";
            return 2;
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        return proc_close($p);
    }

    public function fs_cat(array $a): array
    {
        throw new \LogicException('fs.cat is a streaming action');
    }

    // ---- MariaDB ---------------------------------------------------------------------------------------------

    private function sql(string $sql): string
    {
        $bin = is_file('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '/usr/bin/mysql';
        return Sys::ok([$bin, '--batch', '--skip-column-names'], $sql);   // root via unix_socket, SQL on stdin
    }

    /** MariaDB threads that must never be killed from the panel. */
    private const DB_SYSTEM_USERS = ['system user', 'event_scheduler', 'unauthenticated user'];
    private const DB_SYSTEM_COMMANDS = ['Daemon', 'Binlog Dump', 'Binlog Dump GTID', 'Slave_IO', 'Slave_SQL', 'Slave_worker', 'Connect'];

    /** One JSON object per line (no escaping in --raw; JSON_OBJECT escapes newlines inside values). */
    private function sqlJson(string $sql): array
    {
        $bin = is_file('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '/usr/bin/mysql';
        $out = [];
        foreach (explode("\n", Sys::ok([$bin, '--batch', '--raw', '--skip-column-names'], $sql, 20)) as $line) {
            if ($line !== '' && is_array($r = json_decode($line, true))) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Connections and running statements (information_schema.PROCESSLIST) and a few server counters. */
    public function db_processlist(array $a): array
    {
        $rows = $this->sqlJson("SELECT JSON_OBJECT('id', ID, 'user', USER, 'host', HOST, 'db', DB, 'command', COMMAND, 'time', TIME, 'time_ms', TIME_MS,
            'state', STATE, 'info', LEFT(INFO, 8000), 'info_len', CHAR_LENGTH(INFO), 'rows_examined', EXAMINED_ROWS, 'memory', MEMORY_USED)
            FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() ORDER BY (COMMAND = 'Sleep'), TIME_MS DESC LIMIT 2000;\n");
        foreach ($rows as &$r) {
            $r['system'] = in_array($r['user'], self::DB_SYSTEM_USERS, true) || in_array($r['command'], self::DB_SYSTEM_COMMANDS, true);
            $r['host'] = (string)preg_replace('/:\d+$/', '', (string)$r['host']);     // client port is noise
        }
        unset($r);
        $st = [];
        foreach ($this->sqlJson("SELECT JSON_OBJECT('k', VARIABLE_NAME, 'v', VARIABLE_VALUE) FROM information_schema.GLOBAL_STATUS
            WHERE VARIABLE_NAME IN ('THREADS_CONNECTED','THREADS_RUNNING','MAX_USED_CONNECTIONS','SLOW_QUERIES','UPTIME','QUESTIONS','ABORTED_CONNECTS');\n") as $v) {
            $st[strtolower((string)$v['k'])] = (int)$v['v'];
        }
        $vars = $this->sqlJson("SELECT JSON_OBJECT('max_connections', @@max_connections, 'long_query_time', @@long_query_time, 'slow_log', IF(@@slow_query_log, 1, 0));\n")[0] ?? [];
        return ['threads' => $rows, 'status' => $st, 'vars' => $vars, 'at' => time()];
    }

    /**
     * Stops statements (KILL QUERY: the connection stays) or closes connections (KILL CONNECTION).
     * Either given ids, or a rule: {"rule": "long", "seconds": N} = statements running N+ seconds,
     * {"rule": "sleep", "seconds": N} = connections idle N+ seconds; optionally only in one database ("db").
     * System threads and the helper's own connection are never touched.
     */
    public function db_kill(array $a): array
    {
        $mode = ($a['mode'] ?? 'query') === 'connection' ? 'CONNECTION' : 'QUERY';
        $list = $this->db_processlist([])['threads'];
        $byId = array_column($list, null, 'id');
        $targets = [];
        if (isset($a['rule'])) {
            $sec = max(1, min(86400, (int)($a['seconds'] ?? 60)));
            $db = isset($a['db']) && $a['db'] !== '' ? (string)$a['db'] : null;
            foreach ($list as $t) {
                if ($t['system'] || ($db !== null && $t['db'] !== $db) || (int)$t['time'] < $sec) {
                    continue;
                }
                if (($a['rule'] === 'long' && $t['command'] === 'Query') || ($a['rule'] === 'sleep' && $t['command'] === 'Sleep')) {
                    $targets[] = (int)$t['id'];
                }
            }
            $mode = $a['rule'] === 'sleep' ? 'CONNECTION' : 'QUERY';
        } else {
            foreach (array_slice((array)($a['ids'] ?? []), 0, 500) as $id) {
                $id = (int)$id;
                if (!isset($byId[$id])) {
                    continue;                                         // ended in the meantime
                }
                if ($byId[$id]['system']) {
                    throw new \RuntimeException("Thread $id is a MariaDB system thread and cannot be stopped here");
                }
                $targets[] = $id;
            }
        }
        $done = [];
        foreach ($targets as $id) {
            try {
                $this->sql("KILL $mode $id;\n");
                $done[] = ['id' => $id, 'user' => $byId[$id]['user'] ?? '', 'db' => $byId[$id]['db'] ?? null, 'time' => (int)($byId[$id]['time'] ?? 0),
                    'info' => mb_substr((string)($byId[$id]['info'] ?? ''), 0, 200)];
            } catch (\Throwable) {
                // it ended between listing and killing
            }
        }
        return ['mode' => strtolower($mode), 'killed' => $done];
    }

    private function dbName(mixed $n): string
    {
        if (!is_string($n) || !preg_match('/^hv[0-9]{1,9}_[a-z0-9_]{1,16}$/D', $n)) {
            throw new \InvalidArgumentException('Invalid database name');
        }
        return $n;
    }

    private function dbPass(mixed $p): string
    {
        if (!is_string($p) || !preg_match('/^[A-Za-z0-9!@#%^*_+=.\-]{12,64}$/D', $p)) {
            throw new \InvalidArgumentException('Password must be 12-64 characters: letters, digits and ! @ # % ^ * _ + = . -');
        }
        return $p;
    }

    public function db_create(array $a): array
    {
        $n = $this->dbName($a['name'] ?? '');
        $p = $this->dbPass($a['password'] ?? '');
        $g = str_replace('_', '\\_', $n);
        $this->sql("CREATE DATABASE `$n` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            . "CREATE USER '$n'@'localhost' IDENTIFIED BY '$p';\n"
            . "GRANT ALL PRIVILEGES ON `$g`.* TO '$n'@'localhost';\nFLUSH PRIVILEGES;\n");
        return [];
    }

    public function db_drop(array $a): array
    {
        $n = $this->dbName($a['name'] ?? '');
        $this->sql("DROP DATABASE IF EXISTS `$n`;\nDROP USER IF EXISTS '$n'@'localhost';\nDROP USER IF EXISTS '$n'@'%';\nFLUSH PRIVILEGES;\n");
        return [];
    }

    public function db_password(array $a): array
    {
        $n = $this->dbName($a['name'] ?? '');
        $p = $this->dbPass($a['password'] ?? '');
        $this->sql("ALTER USER IF EXISTS '$n'@'localhost' IDENTIFIED BY '$p';\nALTER USER IF EXISTS '$n'@'%' IDENTIFIED BY '$p';\nFLUSH PRIVILEGES;\n");
        return [];
    }

    /** Remote MySQL for one account: clones the local user's hash to '%' (or drops it). */
    public function db_remote(array $a): array
    {
        $n = $this->dbName($a['name'] ?? '');
        $g = str_replace('_', '\\_', $n);
        if (empty($a['enable'])) {
            $this->sql("DROP USER IF EXISTS '$n'@'%';\nFLUSH PRIVILEGES;\n");
            return [];
        }
        $grants = $this->sql("SHOW GRANTS FOR '$n'@'localhost';\n");
        if (!preg_match("/IDENTIFIED BY PASSWORD '(\\*[A-F0-9]{40})'/", $grants, $m)) {
            throw new \RuntimeException('Cannot copy credentials; set a new password first');
        }
        $this->sql("DROP USER IF EXISTS '$n'@'%';\nCREATE USER '$n'@'%' IDENTIFIED BY PASSWORD '{$m[1]}';\n"
            . "GRANT ALL PRIVILEGES ON `$g`.* TO '$n'@'%';\nFLUSH PRIVILEGES;\n");
        return [];
    }

    /** Admin: make MariaDB listen on all interfaces (needed for remote access) or only on localhost. */
    public function db_bind(array $a): array
    {
        $addr = !empty($a['enable']) ? '0.0.0.0' : '127.0.0.1';
        Sys::write('/etc/mysql/mariadb.conf.d/99-havoc.cnf', "[mysqld]\nbind-address = $addr\n");
        Sys::ok(['systemctl', 'restart', 'mariadb']);
        return ['bind' => $addr];
    }

    public function db_info(array $a): array
    {
        $names = array_map(fn($n) => $this->dbName($n), array_slice((array)($a['names'] ?? []), 0, 200));
        $out = [];
        foreach ($names as $n) {
            $out[$n] = ['tables' => 0, 'size' => 0];
        }
        if ($names) {
            $in = "'" . implode("','", $names) . "'";
            $rows = $this->sql("SELECT table_schema, COUNT(*), COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema IN ($in) GROUP BY table_schema;\n");
            foreach (explode("\n", trim($rows)) as $line) {
                [$s, $t, $z] = array_pad(explode("\t", $line), 3, 0);
                if (isset($out[$s])) {
                    $out[$s] = ['tables' => (int)$t, 'size' => (int)$z];
                }
            }
        }
        return ['databases' => $out];
    }

    private static function sq(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";     // POSIX single-quote a value for /bin/sh
    }

    private function cronDir(string $acct, ?string $domain): string
    {
        return $domain ? "/home/$acct/domains/" . Validator::domain($domain) . '/public_html' : "/home/$acct";
    }

    /**
     * Replace the account's whole crontab with the panel-managed list. Jobs run as the tenant user (crontab -u).
     * With output logging, the job runs through havoc-cronwrap, which appends a header, the output and the exit code to
     * ~/logs/cron/<id>.log and keeps that file under 1 MB. The wrapper also runs as the tenant.
     */
    public function cron_apply(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        $this->account_ensure(['account' => $acct]);
        $lines = ['# Managed by Havoc WEB Panel - edit in the panel', 'SHELL=/bin/sh', 'PATH=/usr/local/bin:/usr/bin:/bin', 'MAILTO=""'];
        foreach (array_slice((array)($a['jobs'] ?? []), 0, 100) as $j) {
            $sched = Validator::cronSchedule($j['schedule'] ?? '');
            $cmd   = Validator::cronCommand($j['command'] ?? '');
            $dir   = $this->cronDir($acct, !empty($j['domain']) ? (string)$j['domain'] : null);
            $id    = Validator::id($j['id'] ?? 0);
            $line  = 'cd ' . self::sq($dir) . ' && ';
            $line .= !empty($j['log']) ? '/opt/havoc/bin/havoc-cronwrap ' . self::sq("/home/$acct/logs/cron/$id.log") . ' ' . self::sq($cmd) : $cmd;
            $lines[] = '# job ' . $id;
            $lines[] = $sched . ' ' . str_replace('%', '\\%', $line);      // '%' means newline in crontab
        }
        Sys::ok(['crontab', '-u', $acct, '-'], implode("\n", $lines) . "\n");
        return [];
    }

    /** "Run now": executes the command once as the tenant, in the job's directory, with a time and output-size limit. */
    public function cron_run(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        $cmd  = Validator::cronCommand($a['command'] ?? '');
        $dir  = $this->cronDir($acct, !empty($a['domain']) ? (string)$a['domain'] : null);
        $out  = tempnam('/var/tmp', 'havoc-cronrun-');
        try {
            $t0 = microtime(true);
            [$code] = Sys::runIO(['prlimit', '--fsize=4194304', '--', ...Sys::asUser($acct), '/bin/sh', '-c',
                'cd "$1" || exit 1; shift; exec timeout -k 5 40 /bin/sh -c "$1" 2>&1', 'sh', $dir, $cmd],
                null, $out, ['HOME' => "/home/$acct", 'USER' => $acct, 'LOGNAME' => $acct, 'SHELL' => '/bin/sh', 'PATH' => '/usr/local/bin:/usr/bin:/bin'], 60);
            $data = (string)file_get_contents($out, false, null, 0, 65536);
            return ['code' => $code, 'timeout' => $code === 124 || $code === 137, 'seconds' => round(microtime(true) - $t0, 1),
                'output' => mb_convert_encoding($data, 'UTF-8', 'UTF-8'), 'truncated' => filesize($out) > 65536];
        } finally {
            @unlink($out);
        }
    }

    /** Last lines of a job's output log, read as the tenant (the file is in the tenant's home, so it may be a symlink). */
    public function cron_log(array $a): array
    {
        $acct = Validator::account($a['account'] ?? '');
        $id   = Validator::id($a['id'] ?? 0);
        $file = "/home/$acct/logs/cron/$id.log";
        [$c, $o] = Sys::run([...Sys::asUser($acct), '/bin/sh', '-c', '[ -f "$1" ] || exit 3; stat -c %Y "$1"; tail -n 300 -- "$1"', 'sh', $file], null, 20);
        if ($c === 3) {
            return ['exists' => false, 'mtime' => null, 'data' => ''];
        }
        [$mtime, $data] = array_pad(explode("\n", $o, 2), 2, '');
        return ['exists' => true, 'mtime' => (int)$mtime, 'data' => mb_convert_encoding(mb_substr($data, -200000), 'UTF-8', 'UTF-8')];
    }

    public function backup_create(array $a): array { return Backup::create($a); }
    public function backup_restore(array $a): array { return Backup::restore($a); }
    public function backup_delete(array $a): array { return Backup::delete($a); }
    public function backup_test(array $a): array { return Backup::test((array)($a['target'] ?? [])); }
    public function backup_about(array $a): array { return Backup::about((array)($a['target'] ?? [])); }

    public function ftp_create(array $a): array { return Ftp::save($a, true); }
    public function ftp_update(array $a): array { return Ftp::save($a, false); }
    public function ftp_password(array $a): array { return Ftp::password($a); }
    public function ftp_delete(array $a): array { return Ftp::delete($a); }

    public function dns_apply(array $a): array { return Dns::apply($a); }
    public function dns_delete(array $a): array { return Dns::delete($a); }

    public function redis_status(array $a): array { return Stack::redisStatus(); }
    public function redis_set(array $a): array { return Stack::redisSet($a); }
    public function redis_install(array $a): array { return Stack::redisInstall(); }
    public function redis_uninstall(array $a): array { return Stack::redisUninstall($a); }
    public function redis_key(array $a): array { return Stack::redisKey(); }
    public function transfer_sizes(array $a): array { return Transfer::sizes($a); }
    public function transfer_export(array $a): array { return Transfer::export($a); }
    public function transfer_export_delete(array $a): array { return Transfer::exportDelete($a); }
    public function transfer_stage(array $a): array { return Transfer::stage($a); }
    public function transfer_import_files(array $a): array { return Transfer::importFiles($a); }
    public function transfer_import_db(array $a): array { return Transfer::importDb($a); }
    public function transfer_patch(array $a): array { return Transfer::patch($a); }
    public function transfer_wp_replace(array $a): array { return Transfer::wpReplace($a); }
    public function transfer_check(array $a): array { return Transfer::check($a); }
    public function domain_rename(array $a): array { return Rename::move($a); }
    public function os_check(array $a): array { return OsUpdates::check(); }
    public function preview_ensure(array $a): array { return Preview::ensure(); }
    public function app_provision(array $a): array { return Apps::provision($a); }
    public function app_deploy(array $a): array { return Apps::deploy($a); }
    public function app_rollback(array $a): array { return Apps::rollback($a); }
    public function app_control(array $a): array { return Apps::control($a); }
    public function app_status(array $a): array { return Apps::status($a); }
    public function app_logs(array $a): array { return Apps::logs($a); }
    public function app_http(array $a): array { return Apps::http($a); }
    public function app_exec(array $a): array { return Apps::exec($a); }
    public function app_env(array $a): array { return Apps::env($a); }
    public function app_read_file(array $a): array { return Apps::readFile($a); }
    public function app_write_file(array $a): array { return Apps::writeFile($a); }
    public function app_destroy(array $a): array { return Apps::destroy($a); }
    public function php_overview(array $a): array { return PhpVersions::overview(); }
    public function php_install(array $a): array { return PhpVersions::install($a); }
    public function php_remove(array $a): array { return PhpVersions::remove($a); }
    public function os_status(array $a): array { return OsUpdates::status(); }
    public function os_upgrade(array $a): array { return OsUpdates::upgrade($a); }
    public function domain_rename_addresses(array $a): array { return Rename::addresses($a); }
    public function addon_overview(array $a): array { return Addons::overview(); }
    public function addon_status(array $a): array { return Addons::status($a); }
    public function addon_install(array $a): array { return Addons::install($a); }
    public function addon_uninstall(array $a): array { return Addons::uninstall($a); }
    public function memcached_set(array $a): array { return Addons::memcachedSet($a); }
    public function memcached_flush(array $a): array { return Addons::memcachedFlush(); }
    public function fail2ban_apply(array $a): array { return Addons::f2bApply($a); }
    public function fail2ban_ban(array $a): array { return Addons::f2bBan($a); }
    public function node_globals(array $a): array { return Addons::nodeGlobals($a); }
    public function meili_site_enable(array $a): array { return MeiliSites::enable($a); }
    public function meili_site_info(array $a): array { return MeiliSites::info($a); }
    public function meili_site_rotate(array $a): array { return MeiliSites::rotate($a); }
    public function meili_site_disable(array $a): array { return MeiliSites::disable($a); }
    public function meili_site_sizes(array $a): array { return MeiliSites::sizes($a); }
    public function meili_site_import(array $a): array { return MeiliSites::importArchive($a); }
    public function meili_status(array $a): array { return Stack::meiliStatus(); }
    public function meili_install(array $a): array { return Stack::meiliInstall(); }
    public function meili_uninstall(array $a): array { return Stack::meiliUninstall($a); }
    public function meili_configure(array $a): array { return Stack::meiliConfigure($a); }
    public function meili_key(array $a): array { return Stack::meiliKey($a); }
    public function fw_status(array $a): array { return Firewall::status(); }
    public function fw_enable(array $a): array { return Firewall::enable(); }
    public function fw_disable(array $a): array { return Firewall::disable(); }
    public function fw_allow(array $a): array { return Firewall::allow($a); }
    public function fw_ban(array $a): array { return Firewall::ban($a); }
    public function fw_whitelist(array $a): array { return Firewall::whitelist($a); }
    public function fw_preset(array $a): array { return Firewall::preset($a); }
    public function fw_delete(array $a): array { return Firewall::delete($a); }

    public function waf_status(array $a): array { return Waf::status(); }
    public function waf_install(array $a): array { return Waf::install(); }
    public function waf_apply(array $a): array { return Waf::apply($a); }
    public function waf_log(array $a): array { return Waf::log($a); }

    public function usage_scan(array $a): array { return Usage::scan($a); }
    public function usage_fpm(array $a): array { return Usage::fpm($a); }
    public function traffic_scan(array $a): array { return Usage::traffic($a); }

    public function panel_port_status(array $a): array { return PanelPort::status(); }
    public function panel_port_change(array $a): array { return PanelPort::change($a); }
    public function panel_port_confirm(array $a): array
    {
        $r = PanelPort::confirm($a);
        try { PanelHost::apply(); } catch (\Throwable) {}
        return $r;
    }

    public function panel_port_revert(array $a): array
    {
        $r = PanelPort::revert($a);
        try { PanelHost::apply(); } catch (\Throwable) {}
        return $r;
    }

    public function pma_status(array $a): array { return Pma::status(); }
    public function pma_install(array $a): array { return Pma::install(); }
    public function pma_uninstall(array $a): array { return Pma::uninstall(); }
    public function pma_ticket(array $a): array { return Pma::ticket($a); }
    public function pma_cleanup(array $a): array { return Pma::cleanup(); }
    public function pma_sso_setup(array $a): array { return Pma::ssoSetup(); }

    /** Validates the per-domain Nginx include. Users get the directive whitelist; admin content is only checked by nginx -t. */
    private static function customRules(array $a): string
    {
        $c = str_replace("\r\n", "\n", (string)($a['nginx_custom'] ?? ''));
        if (trim($c) === '') {
            return '';
        }
        if (strlen($c) > 65536) {
            throw new \InvalidArgumentException('Custom Nginx rules are too long');
        }
        if (empty($a['nginx_custom_trusted'])) {
            NginxCustom::validateUser($c);
        }
        return $c;
    }

    public function mariadb_status(array $a): array { return MariaTune::status(); }
    public function mariadb_tune(array $a): array { return MariaTune::apply($a); }
}
