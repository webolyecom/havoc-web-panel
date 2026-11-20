<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Performance and cache settings of the web servers (Server → Web server → Settings), one section per server type, plus
 * the page cache of each site (domain id => seconds, shared by all three servers). Stored in a root-owned JSON file; the
 * drivers read it whenever they write a configuration, so the values survive site changes and switching servers.
 */
final class WebTuning
{
    public const FILE = '/etc/havoc/webserver-tuning.json';
    public const NGINX_CACHE_DIR = '/var/cache/havoc-nginx';   // one level under /var/cache (0755): www-data must reach it
    public const LS_CACHE_DIR = '/usr/local/lsws/cachedata';

    /** key => [default, min, max] (min/max null = boolean; 'auto' allowed where the default is 'auto') */
    public const SPEC = [
        'nginx' => [
            'worker_processes' => ['auto', 1, 128],
            'worker_connections' => [2048, 256, 65535],
            'keepalive_timeout' => [15, 0, 300],
            'keepalive_requests' => [1000, 10, 100000],
            'client_max_body_size' => [128, 1, 10240],          // MB
            'timeout' => [60, 5, 600],                           // client_body_timeout / send_timeout
            'fastcgi_read_timeout' => [300, 30, 3600],
            'open_file_cache' => [true, null, null],
            'gzip' => [true, null, null],
            'gzip_level' => [5, 1, 9],
            'static_expires' => [0, 0, 365],                     // days, 0 = off (opt-in: unversioned CSS/JS would stay cached)
            'cache' => [false, null, null],                      // page cache (FastCGI cache) available
            'cache_zone_mb' => [64, 8, 1024],
            'cache_max_mb' => [2048, 100, 204800],
            'cache_inactive' => [60, 1, 10080],                  // minutes
        ],
        'ols' => [
            'max_connections' => [10000, 100, 100000],
            'max_ssl_connections' => [10000, 100, 100000],
            'conn_timeout' => [300, 10, 3600],
            'keepalive_timeout' => [5, 0, 300],
            'max_keepalive_req' => [10000, 10, 100000],
            'max_req_body' => [2047, 1, 2047],                   // MB
            'php_conns' => [64, 1, 1000],                        // connections to one site's PHP-FPM
            'gzip' => [true, null, null],
            'gzip_level' => [6, 1, 9],
            'dyn_gzip' => [true, null, null],
            'quic' => [true, null, null],
            'static_expires' => [0, 0, 365],
            'cache' => [true, null, null],                       // LSCache module
            'cache_max_obj' => [10, 1, 1024],                    // MB
        ],
        'lsws' => [
            'max_connections' => [10000, 100, 100000],
            'max_ssl_connections' => [10000, 100, 100000],
            'conn_timeout' => [300, 10, 3600],
            'keepalive_timeout' => [5, 0, 300],
            'max_keepalive_req' => [10000, 10, 100000],
            'max_req_body' => [2047, 1, 2047],
            'php_conns' => [64, 1, 1000],
            'gzip' => [true, null, null],
            'gzip_level' => [6, 1, 9],
            'dyn_gzip' => [true, null, null],
            'static_expires' => [0, 0, 365],
            'cache' => [true, null, null],                       // per-site page cache rules (engine: LiteSpeed WebAdmin)
        ],
    ];

    /** Requests that must never be cached or served from the cache: logged-in users, carts, sessions, admin areas. */
    public const NOCACHE_COOKIES = 'wordpress_logged_in|wp-postpass|comment_author|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session|PHPSESSID|laravel_session|XSRF-TOKEN|joomla_user_state|SESS';
    public const NOCACHE_URIS = '/wp-admin/|/wp-login\.php|/xmlrpc\.php|/wp-json/|/wp-cron\.php|/cart|/checkout|/my-account|/admin|/login|/logout|/register|ajax\.php|preview=true|/feed';

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $raw = is_file(self::FILE) ? json_decode((string)file_get_contents(self::FILE), true) : null;
            $out = ['sites' => []];
            foreach (self::SPEC as $type => $spec) {
                $out[$type] = [];
                foreach ($spec as $k => [$def]) {
                    $out[$type][$k] = $raw[$type][$k] ?? $def;
                }
            }
            foreach ((array)($raw['sites'] ?? []) as $id => $ttl) {
                if ((int)$id > 0 && (int)$ttl > 0) {
                    $out['sites'][(string)(int)$id] = (int)$ttl;
                }
            }
            self::$cache = $out;
        }
        return self::$cache;
    }

    public static function get(string $type): array
    {
        return self::all()[$type];
    }

    /** Page cache lifetime of a site in seconds (0 = off). */
    public static function siteTtl(int $id): int
    {
        return (int)(self::all()['sites'][(string)$id] ?? 0);
    }

    /** Validates one server's values; unknown keys are ignored, missing ones keep their current value. */
    public static function validate(string $type, array $in): array
    {
        $spec = self::SPEC[$type] ?? throw new \InvalidArgumentException('Unknown web server');
        $cur = self::get($type);
        $out = [];
        foreach ($spec as $k => [$def, $min, $max]) {
            $v = array_key_exists($k, $in) ? $in[$k] : $cur[$k];
            if ($min === null) {
                $out[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            } elseif ($def === 'auto' && ($v === 'auto' || $v === '' || $v === null)) {
                $out[$k] = 'auto';
            } else {
                if (!is_numeric($v) || (int)$v != $v || (int)$v < $min || (int)$v > $max) {
                    throw new \InvalidArgumentException("$k: a whole number from $min to $max is needed");
                }
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    /** @param array<int|string,int> $sites domain id => seconds (0 = off) */
    public static function validateSites(array $sites): array
    {
        $out = [];
        foreach ($sites as $id => $ttl) {
            if ((int)$id <= 0 || !is_numeric($ttl) || (int)$ttl < 0 || (int)$ttl > 86400 * 7) {
                throw new \InvalidArgumentException('Page cache time: 0 to 604800 seconds');
            }
            if ((int)$ttl > 0) {
                $out[(string)(int)$id] = (int)$ttl;
            }
        }
        return $out;
    }

    public static function save(array $all): void
    {
        Sys::write(self::FILE, json_encode($all, JSON_PRETTY_PRINT) . "\n", 0644);
        self::$cache = null;
    }

    /** Size in bytes of a cache folder (0 when missing). */
    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        [$c, $o] = Sys::run(['du', '-sb', $dir], null, 60);
        return $c === 0 ? (int)$o : 0;
    }

    /** Empties a cache folder (the folder itself stays, so the web server keeps writing to it). */
    public static function purge(string $dir): void
    {
        if (is_dir($dir) && !is_link($dir)) {
            Sys::run(['find', $dir, '-mindepth', '1', '-delete'], null, 300);
        }
    }
}
