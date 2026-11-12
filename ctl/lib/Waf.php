<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * ModSecurity v3 + OWASP CRS for Nginx. Global switch (off / detect / on) lives in /etc/nginx/modsec/main.conf and needs no vhost
 * re-render; domains opt in through the `modsecurity on` directive the Nginx driver already writes.
 * Rules are never free text: only CRS rule-ID exclusions and structured "block when X contains Y" rules are generated.
 */
final class Waf
{
    private const MAIN = '/etc/nginx/modsec/main.conf';
    private const DIR  = '/etc/havoc/waf';
    private const LOGDIR = '/var/log/havoc/waf';

    /** @return array{setup:?string,rules:?string} */
    private static function crs(): array
    {
        $setup = null;
        foreach (['/etc/modsecurity/crs/crs-setup.conf', '/etc/modsecurity-crs/crs-setup.conf', '/usr/share/modsecurity-crs/crs-setup.conf'] as $f) {
            if (is_file($f)) {
                $setup = $f;
                break;
            }
        }
        $rules = null;
        foreach (['/usr/share/modsecurity-crs/rules', '/etc/modsecurity/crs/rules', '/etc/modsecurity-crs/rules'] as $d) {
            if (is_dir($d) && glob("$d/*.conf")) {
                $rules = $d;
                break;
            }
        }
        return ['setup' => $setup, 'rules' => $rules];
    }

    public static function status(): array
    {
        $crs = self::crs();
        $mode = 'off';
        if (is_file(self::MAIN) && preg_match('/^SecRuleEngine (\w+)/m', (string)file_get_contents(self::MAIN), $m)) {
            $mode = ['On' => 'on', 'DetectionOnly' => 'detect'][$m[1]] ?? 'off';
        }
        return ['server' => WebServerFactory::detect()['type'], 'module' => (bool)(glob('/usr/lib/nginx/modules/ngx_http_modsecurity_module.so') ?: is_file('/usr/share/nginx/modules-available/mod-http-modsecurity.conf')),
            'crs' => $crs['rules'] !== null, 'mode' => $mode];
    }

    public static function install(): array
    {
        Sys::ok(['apt-get', 'install', '-y', 'libnginx-mod-http-modsecurity', 'modsecurity-crs'], null, 600, ['DEBIAN_FRONTEND' => 'noninteractive']);
        return [];
    }

    public static function apply(array $a): array
    {
        $engine = ['off' => 'Off', 'detect' => 'DetectionOnly', 'on' => 'On'][(string)($a['mode'] ?? '')] ?? throw new \InvalidArgumentException('Invalid mode');
        $pre = [];
        $post = [];
        foreach (array_slice((array)($a['rules'] ?? []), 0, 500) as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id < 1 || $id > 89999) {
                throw new \InvalidArgumentException('Rule id out of range');
            }
            $rid = 10000 + $id;
            $dom = !empty($r['domain']) ? Validator::domain($r['domain']) : null;
            if (($r['type'] ?? '') === 'exclude') {
                $x = (string)($r['value'] ?? '');
                if (!preg_match('/^[0-9]{3,7}$/', $x)) {
                    throw new \InvalidArgumentException('Invalid CRS rule id');
                }
                if ($dom === null) {
                    $post[] = "SecRuleRemoveById $x";                                    // config-time removal: after the CRS definitions
                } else {
                    $pre[] = "SecRule SERVER_NAME \"@streq $dom\" \"id:$rid,phase:1,pass,nolog,ctl:ruleRemoveById=$x\"";   // runtime: before the CRS
                }
            } elseif (($r['type'] ?? '') === 'block') {
                $target = ['uri' => 'REQUEST_URI', 'ua' => 'REQUEST_HEADERS:User-Agent', 'args' => 'ARGS'][(string)($r['target'] ?? '')] ?? throw new \InvalidArgumentException('Invalid target');
                $op = ['contains' => '@contains', 'beginswith' => '@beginsWith'][(string)($r['op'] ?? '')] ?? throw new \InvalidArgumentException('Invalid operator');
                $v = strtolower((string)($r['value'] ?? ''));
                if (!preg_match('#^[a-z0-9._~\-/%?=&:+@]{1,100}$#', $v)) {
                    throw new \InvalidArgumentException('Invalid match value');
                }
                $act = "id:$rid,phase:2,deny,status:403,log,msg:'Havoc custom rule $id'";
                if ($dom === null) {
                    $pre[] = "SecRule $target \"$op $v\" \"$act,t:none,t:lowercase\"";
                } else {
                    $pre[] = "SecRule SERVER_NAME \"@streq $dom\" \"$act,chain\"\n    SecRule $target \"$op $v\" \"t:none,t:lowercase\"";
                }
            } else {
                throw new \InvalidArgumentException('Invalid rule type');
            }
        }
        is_dir(self::LOGDIR) || mkdir(self::LOGDIR, 0750, true);
        chown(self::LOGDIR, 'www-data');
        is_dir(dirname(self::MAIN)) || mkdir(dirname(self::MAIN), 0755, true);
        if (!is_file('/etc/modsecurity/modsecurity.conf')) {
            is_dir('/etc/modsecurity') || mkdir('/etc/modsecurity', 0755, true);
            is_file('/etc/modsecurity/modsecurity.conf-recommended')
                ? copy('/etc/modsecurity/modsecurity.conf-recommended', '/etc/modsecurity/modsecurity.conf')
                : file_put_contents('/etc/modsecurity/modsecurity.conf', "SecRuleEngine DetectionOnly\nSecRequestBodyAccess On\n");
        }
        $files = [self::DIR . '/pre.conf' => implode("\n", $pre) . "\n", self::DIR . '/post.conf' => implode("\n", $post) . "\n"];
        if ($engine === 'Off') {
            $main = "# Managed by Havoc WEB Panel\nSecRuleEngine Off\n";                // no rules loaded: near-zero overhead
        } else {
            $crs = self::crs();
            $main = "# Managed by Havoc WEB Panel\nInclude /etc/modsecurity/modsecurity.conf\nSecRuleEngine $engine\nSecAuditEngine RelevantOnly\nSecAuditLogFormat JSON\n"
                . "SecAuditLogType Serial\nSecAuditLog " . self::LOGDIR . "/audit.log\nSecAuditLogParts ABFHZ\nInclude " . self::DIR . "/pre.conf\n"
                . ($crs['setup'] ? "Include {$crs['setup']}\n" : '') . ($crs['rules'] ? "Include {$crs['rules']}/*.conf\n" : '') . 'Include ' . self::DIR . "/post.conf\n";
        }
        $files[self::MAIN] = $main;
        $old = [];
        foreach ($files as $f => $c) {
            $old[$f] = is_file($f) ? (string)file_get_contents($f) : null;
            Sys::write($f, $c, 0644);
        }
        [$c, , $e] = Sys::run(['/usr/sbin/nginx', '-t']);
        if ($c !== 0) {
            foreach ($old as $f => $c0) {
                $c0 === null ? @unlink($f) : Sys::write($f, $c0, 0644);
            }
            throw new \RuntimeException('nginx config test failed: ' . trim($e));
        }
        Sys::ok(['systemctl', 'reload', 'nginx']);
        return [];
    }

    /** Newest-first WAF events from the JSON audit log, optionally restricted to a set of host names (tenant view). */
    public static function log(array $a): array
    {
        $file = self::LOGDIR . '/audit.log';
        if (!is_file($file)) {
            return ['entries' => []];
        }
        $hosts = isset($a['hosts']) && is_array($a['hosts']) ? array_flip(array_map(fn($h) => strtolower(Validator::domain($h)), array_slice($a['hosts'], 0, 2000))) : null;
        $only  = !empty($a['host']) ? strtolower(Validator::domain($a['host'])) : null;
        $limit = max(10, min(300, (int)($a['limit'] ?? 100)));
        $size = (int)filesize($file);
        $fh = fopen($file, 'rb');
        $from = max(0, $size - 2 * 1024 * 1024);
        fseek($fh, $from);
        $chunk = (string)stream_get_contents($fh);
        fclose($fh);
        if ($from > 0 && ($p = strpos($chunk, "\n")) !== false) {
            $chunk = substr($chunk, $p + 1);                    // drop the partial first line
        }
        $clip = static fn($s, int $n) => mb_substr(mb_convert_encoding((string)$s, 'UTF-8', 'UTF-8'), 0, $n);
        $out = [];
        foreach (array_reverse(explode("\n", $chunk)) as $line) {
            if ($line === '' || !($j = json_decode($line, true)) || !is_array($t = $j['transaction'] ?? null)) {
                continue;
            }
            $host = strtolower(explode(':', (string)($t['request']['headers']['Host'] ?? ''))[0]);
            if (($hosts !== null && !isset($hosts[$host])) || ($only !== null && $host !== $only)) {
                continue;
            }
            $rules = [];
            foreach (array_slice((array)($t['messages'] ?? []), 0, 8) as $m) {
                $rules[] = ['id' => $clip($m['details']['ruleId'] ?? '', 12), 'msg' => $clip($m['message'] ?? '', 160), 'sev' => $clip($m['details']['severity'] ?? '', 4)];
            }
            $out[] = ['time' => $clip($t['time_stamp'] ?? '', 40), 'ip' => $clip($t['client_ip'] ?? '', 45), 'method' => $clip($t['request']['method'] ?? '', 10),
                'uri' => $clip($t['request']['uri'] ?? '', 200), 'host' => $clip($host, 100), 'status' => (int)($t['response']['http_code'] ?? 0), 'rules' => $rules];
            if (count($out) >= $limit) {
                break;
            }
        }
        return ['entries' => $out];
    }
}
