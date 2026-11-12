<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Per-domain Nginx include (rewrite rules etc.), placed inside the site's server block.
 *
 * Hosting users get a whitelist: the web server runs as www-data, which is a member of every hosting account's group, so
 * directives such as root/alias/include could read other customers' files, and *_pass, log or file directives reach beyond
 * the site. User content is parsed into statements and blocks and only the directives below are accepted.
 * Admins may write any directive. In both cases the result must pass "nginx -t" before it is activated.
 */
final class NginxCustom
{
    private const MAX = 32768;
    private const SIMPLE = ['rewrite', 'return', 'try_files', 'add_header', 'expires', 'allow', 'deny', 'index', 'error_page', 'autoindex',
        'charset', 'default_type', 'set', 'break', 'internal', 'etag', 'if_modified_since', 'add_trailer', 'rewrite_log',
        'limit_rate', 'limit_rate_after', 'gzip', 'gzip_types', 'gzip_vary', 'absolute_redirect', 'port_in_redirect',
        'server_name_in_redirect', 'merge_slashes', 'client_max_body_size', 'log_not_found', 'access_log',
        'sub_filter', 'sub_filter_once', 'sub_filter_types'];
    private const BLOCKS = ['location', 'if', 'limit_except'];

    /** Tokenize nginx syntax: words / quoted strings, ';', '{', '}' (comments removed). */
    private static function tokens(string $s): array
    {
        $out = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif ($c === '#') {
                while ($i < $n && $s[$i] !== "\n") {
                    $i++;
                }
            } elseif ($c === ';' || $c === '{' || $c === '}') {
                $out[] = $c;
                $i++;
            } elseif ($c === '"' || $c === "'") {
                $j = $i + 1;
                while ($j < $n && $s[$j] !== $c) {
                    $j += $s[$j] === '\\' ? 2 : 1;
                }
                if ($j >= $n) {
                    throw new \InvalidArgumentException('Unterminated quoted string');
                }
                $out[] = ['q', substr($s, $i + 1, $j - $i - 1)];
                $i = $j + 1;
            } else {
                $j = $i;
                while ($j < $n && !ctype_space($s[$j]) && !in_array($s[$j], [';', '{', '}', '"', "'"], true)) {
                    $j += $s[$j] === '\\' ? 2 : 1;
                }
                $out[] = ['w', substr($s, $i, $j - $i)];
                $i = $j;
            }
        }
        return $out;
    }

    /** Validates user content; throws with a readable reason. */
    public static function validateUser(string $s): void
    {
        if (strlen($s) > self::MAX) {
            throw new \InvalidArgumentException('Too long (max 32 KB)');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)) {
            throw new \InvalidArgumentException('Invalid control characters');
        }
        $t = self::tokens($s);
        $depth = 0;
        $stmt = [];
        foreach ($t as $tok) {
            if ($tok === ';' || $tok === '{') {
                if (!$stmt) {
                    throw new \InvalidArgumentException("Unexpected '$tok'");
                }
                $name = $stmt[0][0] === 'w' ? strtolower($stmt[0][1]) : '';
                if ($tok === '{') {
                    if (!in_array($name, self::BLOCKS, true)) {
                        throw new \InvalidArgumentException("Block \"$name\" is not allowed (allowed: " . implode(', ', self::BLOCKS) . ')');
                    }
                    if (++$depth > 5) {
                        throw new \InvalidArgumentException('Nested too deeply');
                    }
                } elseif (!in_array($name, self::SIMPLE, true)) {
                    throw new \InvalidArgumentException("Directive \"$name\" is not allowed here. Allowed: " . implode(', ', self::SIMPLE));
                }
                if ($name === 'access_log' && !(count($stmt) === 2 && $stmt[1][1] === 'off')) {
                    throw new \InvalidArgumentException('access_log: only "access_log off;" is allowed');
                }
                $stmt = [];
            } elseif ($tok === '}') {
                if ($stmt) {
                    throw new \InvalidArgumentException("Missing ';' before '}'");
                }
                if (--$depth < 0) {
                    throw new \InvalidArgumentException("Unexpected '}'");
                }
            } else {
                $stmt[] = $tok;
            }
        }
        if ($stmt) {
            throw new \InvalidArgumentException("Missing ';' at the end");
        }
        if ($depth !== 0) {
            throw new \InvalidArgumentException("Missing '}'");
        }
    }

    /** True when the content defines "location /" itself (then the default one is left out). */
    public static function definesRoot(string $s): bool
    {
        $t = self::tokens($s);
        foreach ($t as $i => $tok) {
            if (is_array($tok) && strtolower($tok[1]) === 'location' && isset($t[$i + 1], $t[$i + 2])
                && is_array($t[$i + 1]) && $t[$i + 1][1] === '/' && $t[$i + 2] === '{') {
                return true;
            }
        }
        return false;
    }
}
