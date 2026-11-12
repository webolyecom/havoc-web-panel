<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * LiteSpeed Web Server Enterprise (6.x, XML configuration). httpd_config.xml is edited with DOM: the panel replaces only its own
 * listeners (havoc-http / havoc-https) and its own <virtualHost> entries (havoc-*), sets the server user to www-data and
 * leaves everything else (license, admin, modules, other vhosts) as LiteSpeed configured it. Each site gets a vhconf.xml that
 * passes PHP to the site's own PHP-FPM socket (extProcessor type fcgi). .htaccess is honoured.
 */
final class LswsDriver implements WebServer
{
    use LiteSpeedCommon;

    public function type(): string { return 'lsws'; }

    public function installed(): bool
    {
        return is_file(self::$root . '/bin/lshttpd') && is_file(self::$root . '/conf/httpd_config.xml') && !is_file(self::$root . '/bin/openlitespeed');
    }

    public function prepare(): void
    {
        if (!$this->installed()) {
            throw new \RuntimeException('LiteSpeed Enterprise is not installed');
        }
        $f = self::$root . '/conf/httpd_config.xml';
        is_file("$f.orig") || copy($f, "$f.orig");
        self::writeServer();
    }

    /** Appends <name>text</name> children in order; arrays become nested elements. */
    private static function build(\DOMDocument $doc, \DOMElement $parent, array $tree): void
    {
        foreach ($tree as $k => $v) {
            $name = is_int($k) ? null : preg_replace('/#\d+$/', '', $k);    // "rule#2" allows repeated element names
            if (is_array($v)) {
                $el = $doc->createElement($name);
                self::build($doc, $el, $v);
            } else {
                $el = $doc->createElement($name);
                $el->appendChild($doc->createTextNode((string)$v));
            }
            $parent->appendChild($el);
        }
    }

    public function apply(array $vh): void
    {
        $d    = $vh['domain'];
        $id   = (int)$vh['id'];
        $ssl  = !empty($vh['ssl']['cert']);
        $logs = '/var/log/havoc/domains';
        $rules = self::rewriteRules($vh, $ssl);
        $logging = ['log' => ['useServer' => 0, 'fileName' => "$logs/$d.error.log", 'logLevel' => 'WARN', 'rollingSize' => 0]];
        $logging['accessLog'] = !empty($vh['logs'])
            ? ['useServer' => 0, 'fileName' => "$logs/$d.access.log", 'rollingSize' => 0, 'logFormat' => '%h %l %u %t "%r" %>s %b "%{Referer}i" "%{User-agent}i"']
            : ['useServer' => 0, 'fileName' => '/dev/null'];
        $tree = [
            'docRoot' => $vh['docroot'] . '/',
            'enableGzip' => 1,
            'logging' => $logging,
            'index' => ['useServer' => 0, 'indexFiles' => 'index.php, index.html, index.htm'],
            'extProcessorList' => ['extProcessor' => ['type' => 'fcgi', 'name' => "havoc-fpm-$id", 'address' => "uds://run/havoc-fpm/$id.sock",
                'maxConns' => 64, 'initTimeout' => 60, 'retryTimeout' => 0, 'persistConn' => 1, 'respBuffer' => 0, 'autoStart' => 0]],
            'scriptHandlerList' => ['scriptHandler' => ['suffix' => 'php', 'type' => 'fcgi', 'handler' => "havoc-fpm-$id"]],
            'contextList' => ['context' => ['type' => 'NULL', 'uri' => '/.well-known/acme-challenge/',
                'location' => '/var/www/havoc-acme/.well-known/acme-challenge/', 'allowBrowse' => 1]],
            'rewrite' => ['enable' => 1, 'autoLoadHtaccess' => 1] + ($rules ? ['rules' => implode("\n", $rules)] : []),
        ];
        if ($ssl) {
            $tree['vhssl'] = ['keyFile' => $vh['ssl']['key'], 'certFile' => $vh['ssl']['cert'], 'certChain' => 1];
        }
        if ($ssl && !empty($vh['hsts'])) {
            $tree['contextList']['context#2'] = ['type' => 'NULL', 'uri' => '/', 'location' => $vh['docroot'] . '/', 'allowBrowse' => 1,
                'extraHeaders' => 'Strict-Transport-Security: max-age=31536000; includeSubDomains', 'rewrite' => ['enable' => 1, 'inherit' => 1]];
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('virtualHostConfig');
        $doc->appendChild($root);
        self::build($doc, $root, $tree);
        $dir = self::vhDir($d);
        is_dir($dir) || mkdir($dir, 0750, true);
        Sys::write("$dir/vhconf.xml", "<!-- Managed by Havoc WEB Panel - do not edit -->\n" . $doc->saveXML($root) . "\n", 0640, 'lsadm', 'lsadm');
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

    private static function child(\DOMDocument $doc, \DOMElement $parent, string $name): \DOMElement
    {
        foreach ($parent->childNodes as $c) {
            if ($c instanceof \DOMElement && $c->tagName === $name) {
                return $c;
            }
        }
        $el = $doc->createElement($name);
        $parent->appendChild($el);
        return $el;
    }

    private static function setText(\DOMDocument $doc, \DOMElement $parent, string $name, string $value): void
    {
        $el = self::child($doc, $parent, $name);
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($doc->createTextNode($value));
    }

    private static function writeServer(): void
    {
        $file = self::$root . '/conf/httpd_config.xml';
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        if (!@$doc->load($file)) {
            throw new \RuntimeException('Cannot read ' . $file);
        }
        $cfg = $doc->documentElement;
        self::setText($doc, $cfg, 'user', 'www-data');
        self::setText($doc, $cfg, 'group', 'www-data');
        [$crt, $key] = self::defaultCert();
        $vhosts = self::managed();

        // our virtual hosts: remove old havoc-* entries, add the current ones
        $vl = self::child($doc, $cfg, 'virtualHostList');
        foreach (iterator_to_array($vl->childNodes) as $c) {
            if ($c instanceof \DOMElement && str_starts_with((string)$c->getElementsByTagName('name')->item(0)?->textContent, 'havoc-')) {
                $vl->removeChild($c);
            }
        }
        foreach ($vhosts as $name => $names) {
            $v = $doc->createElement('virtualHost');
            self::build($doc, $v, ['name' => $name, 'vhRoot' => self::$root . "/conf/vhosts/$name", 'configFile' => self::$root . "/conf/vhosts/$name/vhconf.xml",
                'allowSymbolLink' => 1, 'enableScript' => 1, 'restrained' => 0, 'setUIDMode' => 0]);
            $vl->appendChild($v);
        }

        // listeners: ours replace anything else bound to :80 / :443
        $ll = self::child($doc, $cfg, 'listenerList');
        foreach (iterator_to_array($ll->childNodes) as $c) {
            if (!$c instanceof \DOMElement) {
                continue;
            }
            $name = (string)$c->getElementsByTagName('name')->item(0)?->textContent;
            $addr = (string)$c->getElementsByTagName('address')->item(0)?->textContent;
            if (str_starts_with($name, 'havoc-') || preg_match('/:(80|443)$/', $addr)) {
                $ll->removeChild($c);
            }
        }
        $maps = [];
        $i = 0;
        foreach ($vhosts as $name => $names) {
            $maps['vhostMap#' . $i++] = ['vhost' => $name, 'domain' => implode(', ', $names)];
        }
        foreach ([['havoc-http', '*:80', 0], ['havoc-https', '*:443', 1]] as [$name, $addr, $secure]) {
            $l = $doc->createElement('listener');
            $tree = ['name' => $name, 'address' => $addr, 'secure' => $secure];
            if ($secure) {
                $tree += ['keyFile' => $key, 'certFile' => $crt, 'certChain' => 1];
            }
            $tree['vhostMapList'] = $maps;
            self::build($doc, $l, $tree);
            $ll->appendChild($l);
        }
        Sys::write($file, (string)$doc->saveXML(), 0640, 'lsadm', 'lsadm');
    }
}
