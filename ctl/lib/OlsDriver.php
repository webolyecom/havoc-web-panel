<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * OpenLiteSpeed. The panel owns conf/httpd_config.conf completely and regenerates it from the managed vhosts on every change
 * (listeners on 80/443, server user www-data). Each site's vhconf.conf passes PHP to that site's own PHP-FPM socket
 * (extprocessor type fcgi), so PHP version, extensions, php.ini and limits behave exactly as with Nginx.
 * .htaccess rewrite rules are honoured (autoLoadHtaccess).
 */
final class OlsDriver implements WebServer
{
    use LiteSpeedCommon;

    public function type(): string { return 'ols'; }

    public function installed(): bool
    {
        return is_file(self::$root . '/bin/openlitespeed');
    }

    public function prepare(): void
    {
        if (!$this->installed()) {
            throw new \RuntimeException('OpenLiteSpeed is not installed');
        }
        $f = self::$root . '/conf/httpd_config.conf';
        if (is_file($f) && !str_contains((string)file_get_contents($f), 'Managed by Havoc') && !is_file("$f.orig")) {
            copy($f, "$f.orig");                                  // keep the package default once
        }
        self::writeServer();
    }

    public function apply(array $vh): void
    {
        $d    = $vh['domain'];
        $id   = (int)$vh['id'];
        $dir  = self::vhDir($d);
        $ssl  = !empty($vh['ssl']['cert']);
        $logs = '/var/log/havoc/domains';
        $acc  = !empty($vh['logs'])
            ? "accesslog $logs/$d.access.log {\n  useServer               0\n  logFormat               \"%h %l %u %t \\\"%r\\\" %>s %b \\\"%{Referer}i\\\" \\\"%{User-agent}i\\\"\"\n  rollingSize             0\n}\n"
            : '';
        $rules = self::rewriteRules($vh, $ssl);
        $conf = "# Managed by Havoc WEB Panel - do not edit\n"
            . "docRoot                   {$vh['docroot']}/\n"
            . "vhDomain                  $d\n"
            . (empty($vh['aliases']) ? '' : 'vhAliases                 ' . implode(', ', $vh['aliases']) . "\n")
            . "enableGzip                1\n"
            . "errorlog $logs/$d.error.log {\n  useServer               0\n  logLevel                WARN\n  rollingSize             0\n}\n"
            . $acc
            . "index  {\n  useServer               0\n  indexFiles              index.php, index.html, index.htm\n}\n"
            . "extprocessor havoc-fpm-$id {\n  type                    fcgi\n  address                 uds://run/havoc-fpm/$id.sock\n"
            . "  maxConns                64\n  initTimeout             60\n  retryTimeout            0\n  persistConn             1\n"
            . "  respBuffer              0\n  autoStart               0\n}\n"
            . "scripthandler  {\n  add                     fcgi:havoc-fpm-$id php\n}\n"
            . "context /.well-known/acme-challenge/ {\n  location                /var/www/havoc-acme/.well-known/acme-challenge/\n  allowBrowse             1\n"
            . "  rewrite  {\n    enable                0\n  }\n}\n"
            . "rewrite  {\n  enable                  1\n  autoLoadHtaccess        1\n"
            . ($rules ? "  rules                   <<<END_rules\n" . implode("\n", $rules) . "\nEND_rules\n" : '')
            . "}\n";
        if ($ssl) {
            $conf .= "vhssl  {\n  keyFile                 {$vh['ssl']['key']}\n  certFile                {$vh['ssl']['cert']}\n  certChain               1\n}\n";
        }
        if (!empty($vh['hsts']) && $ssl) {
            $conf .= "context / {\n  location                {$vh['docroot']}/\n  allowBrowse             1\n"
                . "  extraHeaders            Strict-Transport-Security: max-age=31536000; includeSubDomains\n"
                . "  rewrite  {\n    enable                1\n    inherit               1\n  }\n}\n";
        }
        is_dir($dir) || mkdir($dir, 0750, true);
        Sys::write("$dir/vhconf.conf", $conf, 0640, 'lsadm', 'lsadm');
        self::saveNames($vh);
        self::writeServer();
    }

    public function remove(string $domain): void
    {
        Sys::run(['rm', '-rf', self::vhDir($domain)]);
        if ($this->installed()) {
            self::writeServer();
        }
    }

    /** Full httpd_config.conf: server settings, one virtualhost per managed site, listeners 80/443 mapping all names. */
    private static function writeServer(): void
    {
        [$crt, $key] = self::defaultCert();
        $vhosts = self::managed();
        $blocks = '';
        $maps = '';
        foreach ($vhosts as $name => $names) {
            $blocks .= "virtualhost $name {\n  vhRoot                  " . self::$root . "/conf/vhosts/$name\n  configFile              \$VH_ROOT/vhconf.conf\n"
                . "  allowSymbolLink         1\n  enableScript            1\n  restrained              0\n  setUIDMode              0\n}\n\n";
            $maps .= "  map                     $name " . implode(', ', $names) . "\n";
        }
        $conf = <<<CONF
# Managed by Havoc WEB Panel - regenerated on every site change. The package default is kept in httpd_config.conf.orig.
serverName                Havoc
user                      www-data
group                     www-data
priority                  0
autoRestart               1
chrootPath                /
enableChroot              0
inMemBufSize              60M
swappingDir               /tmp/lshttpd/swap
autoFix503                1
gracefulRestartTimeout    300
mime                      conf/mime.properties
showVersionNumber         0
adminEmails               root@localhost
indexFiles                index.php, index.html

errorlog /var/log/havoc/ols-error.log {
  logLevel                WARN
  debugLevel              0
  rollingSize             10M
  enableStderrLog         1
}

accesslog /var/log/havoc/ols-access.log {
  rollingSize             10M
  keepDays                7
  compressArchive         1
}

tuning  {
  maxConnections          10000
  maxSSLConnections       10000
  connTimeout             300
  maxKeepAliveReq         10000
  keepAliveTimeout        5
  maxReqURLLen            32768
  maxReqHeaderSize        65536
  maxReqBodySize          2047M
  maxDynRespHeaderSize    32768
  maxDynRespSize          2047M
  enableGzipCompress      1
  enableDynGzipCompress   1
  gzipCompressLevel       6
  compressibleTypes       text/*, application/x-javascript, application/xml, application/javascript, application/json, image/svg+xml
  gzipAutoUpdateStatic    1
  quicEnable              1
}

fileAccessControl  {
  followSymbolLink        1
  checkSymbolLink         0
  requiredPermissionMask  000
  restrictedPermissionMask 000
}

accessDenyDir  {
  dir                     /
  dir                     /etc/*
  dir                     /dev/*
  dir                     conf/*
  dir                     admin/conf/*
}

accessControl  {
  allow                   ALL
}

$blocks
listener HTTP {
  address                 *:80
  secure                  0
$maps}

listener HTTPS {
  address                 *:443
  secure                  1
  keyFile                 $key
  certFile                $crt
  certChain               1
$maps}

CONF;
        Sys::write(self::$root . '/conf/httpd_config.conf', $conf, 0640, 'lsadm', 'lsadm');
        is_dir('/tmp/lshttpd/swap') || @mkdir('/tmp/lshttpd/swap', 0750, true);
        @chown('/tmp/lshttpd/swap', 'www-data');                // workers run as www-data
    }
}
