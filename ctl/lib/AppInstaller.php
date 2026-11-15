<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * One-click applications. Everything that touches the site runs as the hosting account (setpriv), never as root:
 * root only provides WP-CLI (downloaded once, SHA-512 checked) and checks the inputs.
 * Secrets (database and admin passwords) go through stdin, so they never show up in the process list.
 */
final class AppInstaller
{
    private const WPCLI     = '/usr/local/lib/havoc/wp-cli.phar';
    private const WPCLI_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
    public const LOCALES    = ['en_US', 'tr_TR', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'nl_NL', 'pt_BR', 'ru_RU', 'ar', 'az', 'en_GB'];

    /** Files the panel itself puts into a new site; anything else means the folder is in use. */
    private const PLACEHOLDERS = ['index.html', '.well-known'];

    public static function wordpress(array $a): array
    {
        $acct   = Validator::account($a['account'] ?? '');
        $dom    = Validator::domain($a['domain'] ?? '');
        $php    = Validator::php($a['php'] ?? '');
        $root   = (string)($a['docroot'] ?? '');
        if (!preg_match('#^/home/' . $acct . '/[A-Za-z0-9._/-]+$#D', $root) || str_contains($root, '..') || !is_dir($root) || is_link($root)) {
            throw new \InvalidArgumentException('Invalid document root');
        }
        $locale = in_array($a['locale'] ?? '', self::LOCALES, true) ? (string)$a['locale'] : 'en_US';
        $url    = (!empty($a['https']) ? 'https://' : 'http://') . $dom;
        $title  = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($a['title'] ?? ''))), 0, 120) ?: $dom;
        $user   = (string)($a['admin_user'] ?? '');
        $mail   = filter_var($a['admin_email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pass   = (string)($a['admin_password'] ?? '');
        $db     = (string)($a['db_name'] ?? '');
        $dbPass = (string)($a['db_password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D', $user) || !$mail || strlen($pass) < 12 || preg_match('/[\r\n]/', $pass . $dbPass)) {
            throw new \InvalidArgumentException('Invalid administrator details');
        }
        if (!preg_match('/^' . $acct . '_[a-z0-9_]{1,16}$/D', $db) || strlen($dbPass) < 12) {
            throw new \InvalidArgumentException('Invalid database');
        }

        // the folder must be empty apart from what the panel created (never overwrite a site)
        $left = array_diff(scandir($root) ?: [], ['.', '..']);
        $extra = array_diff($left, self::PLACEHOLDERS);
        if ($extra) {
            throw new \RuntimeException('The site folder is not empty (' . implode(', ', array_slice(array_values($extra), 0, 5)) . '). Install into an empty site.');
        }
        $wp = self::wpcli();
        $bin = "/usr/bin/php$php";
        is_file($bin) || $bin = '/usr/bin/php';
        $home = "/home/$acct";
        $env = ['HOME' => $home, 'WP_CLI_CACHE_DIR' => "$home/.wp-cli/cache", 'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1'];
        $run = function (array $args, ?string $stdin = null, int $timeout = 300) use ($acct, $bin, $wp, $root, $env): string {
            // cwd inside the site: WP-CLI starts sub-processes, which fail in a directory the account cannot enter
            [$c, $o, $e] = Sys::run([...Sys::asUser($acct), '/usr/bin/env', '-C', $root, $bin, '-d', 'memory_limit=512M', $wp, '--path=' . $root, ...$args],
                $stdin, $timeout, $env);
            if ($c !== 0) {
                $msg = trim(preg_replace('/^(Warning|Notice|Deprecated):.*$/m', '', $e ?: $o));
                throw new \RuntimeException('WordPress: ' . mb_substr($msg !== '' ? $msg : "wp {$args[0]} failed", 0, 400));
            }
            return trim($o);
        };

        // the panel's welcome page would hide WordPress' index.php behind the "index" order
        if (is_file("$root/index.html") && str_contains((string)file_get_contents("$root/index.html", false, null, 0, 4096), 'Hosted with Havoc WEB Panel')) {
            Sys::run([...Sys::asUser($acct), 'rm', '-f', '--', "$root/index.html"]);
        } elseif (is_file("$root/index.html")) {
            throw new \RuntimeException('The site folder contains an index.html that was not created by the panel.');
        }
        $run(['core', 'download', '--locale=' . $locale], null, 600);
        $run(['config', 'create', '--dbname=' . $db, '--dbuser=' . $db, '--dbhost=localhost', '--locale=' . $locale, '--skip-check', '--prompt=dbpass'],
            $dbPass . "\n");
        $run(['config', 'set', 'FS_METHOD', 'direct']);                       // updates and plugins write as the account itself
        $run(['core', 'install', '--url=' . $url, '--title=' . $title, '--admin_user=' . $user, '--admin_email=' . $mail, '--skip-email',
            '--prompt=admin_password'], $pass . "\n");
        $run(['rewrite', 'structure', '/%postname%/']);
        // pretty permalinks for LiteSpeed / Apache-style servers (Nginx does not read .htaccess and blocks dot files)
        $ht = "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress\n";
        Sys::run([...Sys::asUser($acct), '/bin/sh', '-c', 'umask 022; [ -e "$1/.htaccess" ] || printf "%s" "$2" > "$1/.htaccess"', 'sh', $root, $ht]);
        return ['version' => $run(['core', 'version']), 'url' => $url, 'admin_url' => $url . '/wp-admin/', 'locale' => $locale];
    }

    /** WP-CLI phar, refreshed weekly, verified against the published SHA-512. */
    private static function wpcli(): string
    {
        if (is_file(self::WPCLI) && time() - filemtime(self::WPCLI) < 7 * 86400) {
            return self::WPCLI;
        }
        is_dir(dirname(self::WPCLI)) || mkdir(dirname(self::WPCLI), 0755, true);
        $tmp = self::WPCLI . '.new';
        try {
            Sys::ok(['curl', '-fsSL', '--proto', '=https', '--tlsv1.2', '--max-time', '120', '-o', $tmp, self::WPCLI_URL], null, 150);
            $sum = trim(Sys::ok(['curl', '-fsSL', '--proto', '=https', '--tlsv1.2', '--max-time', '30', self::WPCLI_URL . '.sha512'], null, 40));
            if (!preg_match('/^[a-f0-9]{128}/', $sum) || !hash_equals(substr($sum, 0, 128), hash_file('sha512', $tmp))) {
                throw new \RuntimeException('WP-CLI checksum mismatch');
            }
            chmod($tmp, 0755);
            rename($tmp, self::WPCLI);
        } catch (\Throwable $e) {
            @unlink($tmp);
            if (is_file(self::WPCLI)) {
                return self::WPCLI;                                  // keep using the copy we have
            }
            throw $e;
        }
        return self::WPCLI;
    }
}
