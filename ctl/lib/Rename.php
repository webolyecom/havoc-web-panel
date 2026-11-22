<?php
declare(strict_types=1);

namespace Havoc\Ctl;

/**
 * Changing a site's domain name (Domain → Change domain name).
 * - move(): the site folder /home/<acct>/domains/<old> becomes .../<new> (moved by the account itself, so a planted symlink
 *   can never make root move something else), its log files are renamed, the old name's web server configuration is
 *   removed (domain.apply writes the new one) and an own certificate is kept when it also covers the new name.
 * - addresses(): replaces the old address in the site's configuration files and, for WordPress, in its database
 *   (WP-CLI search-replace, serialized data included): "//old" (also JSON "\/\/old" and "%2F%2Fold"), with www. and the
 *   renamed subdomains, and the old folder path. E-mail addresses and plain text mentioning the old name stay as they are.
 */
final class Rename
{
    private const LOGS = '/var/log/havoc/domains';

    private static function account(mixed $acct): string
    {
        $acct = Validator::account((string)$acct);
        $pw = posix_getpwnam($acct);
        if (!$pw || (int)$pw['uid'] === 0 || !is_dir("/home/$acct")) {
            throw new \RuntimeException('Hosting account does not exist');
        }
        return $acct;
    }

    /** @return array{0:string,1:string,2:string} account, old name, new name */
    private static function names(array $a): array
    {
        $old = Validator::domain($a['old'] ?? '');
        $new = Validator::domain($a['new'] ?? '');
        if ($old === $new) {
            throw new \InvalidArgumentException('The new name is the same as the old one');
        }
        return [self::account($a['account'] ?? ''), $old, $new];
    }

    public static function move(array $a): array
    {
        [$acct, $old, $new] = self::names($a);
        $base = "/home/$acct/domains";
        $from = "$base/$old";
        $to = "$base/$new";
        $moved = false;
        if (file_exists($to) || is_link($to)) {
            throw new \RuntimeException("The folder domains/$new already exists in this account; move or delete it first.");
        }
        if (is_link($from)) {
            throw new \RuntimeException("domains/$old is a symbolic link; the site folder cannot be moved.");
        }
        if (is_dir($from)) {
            Sys::ok([...Sys::asUser($acct), 'mv', '-n', '-T', '--', $from, $to]);
            if (!is_dir($to) || file_exists($from)) {
                throw new \RuntimeException('Moving the site folder failed');
            }
            $moved = true;
        }

        // access / error / PHP error logs, also the rotated ones (exact names: "a.com" never touches "a.com.tr")
        $logs = 0;
        $q = preg_quote($old, '/');
        foreach (scandir(self::LOGS) ?: [] as $f) {
            if (preg_match('/^' . $q . '\.((?:access|error|php-error)\.log(?:[.-][0-9]+)?(?:\.gz)?)$/D', $f, $m)
                && !file_exists(self::LOGS . "/$new.{$m[1]}") && !is_link(self::LOGS . "/$f")) {
                rename(self::LOGS . "/$f", self::LOGS . "/$new.{$m[1]}") && $logs++;
            }
        }

        // the old name's configuration on every installed web server (the new one is written by domain.apply)
        foreach (array_keys(WebServerFactory::TYPES) as $t) {
            $w = WebServerFactory::make($t);
            if ($w->installed()) {
                $w->remove($old);
            }
        }

        // an own (custom / Cloudflare) certificate moves along only when it is also valid for the new name
        $sslKept = false;
        $dir = "/etc/havoc/ssl/$old";
        if (is_file("$dir/fullchain.pem") && !file_exists("/etc/havoc/ssl/$new")) {
            [$c, $o] = Sys::run(['openssl', 'x509', '-in', "$dir/fullchain.pem", '-noout', '-checkhost', $new]);
            if ($c === 0 && str_contains($o, 'does match') && !str_contains($o, 'NOT match')) {
                $sslKept = rename($dir, "/etc/havoc/ssl/$new");
            }
        }
        return ['moved' => $moved, 'logs' => $logs, 'ssl_kept' => $sslKept];
    }

    /** Regular expression (no delimiters, case-insensitive) matching the old addresses; group 1 keeps the prefix. */
    private static function pattern(string $acct, string $old, array $prefixes): string
    {
        $pre = ['www.'];
        foreach ($prefixes as $p) {
            if (is_string($p) && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+$/D', $p)) {
                $pre[] = $p;
            }
        }
        $pre = array_unique($pre);
        usort($pre, static fn($x, $y) => strlen($y) <=> strlen($x));      // longest first: "shop.eu." before "eu."
        $alt = implode('|', array_map(static fn($p) => preg_quote($p, '#'), $pre));
        // after "//", JSON-escaped "\/\/", URL-encoded "%2F%2F" or the account's domains folder; not followed by more name
        return '(?<=//|\\\\/\\\\/|%2F%2F|/home/' . $acct . '/domains/)((?:' . $alt . ')?)' . preg_quote($old, '#') . '(?![a-z0-9-]|\.[a-z0-9])';
    }

    public static function addresses(array $a): array
    {
        [$acct, $old, $new] = self::names($a);
        $pattern = self::pattern($acct, $old, (array)($a['prefixes'] ?? []));
        $repl = '${1}' . $new;
        $base = "/home/$acct/domains/$new";
        $root = "$base/public_html";
        $out = ['config' => [], 'wordpress' => false, 'ok' => true, 'replacements' => 0, 'note' => ''];

        $files = [];
        foreach (Transfer::CONFIG_FILES as $f) {
            $files[] = "$root/$f";
            $files[] = "$base/$f";
        }
        $code = <<<'PHP'
$in = json_decode(stream_get_contents(STDIN), true);
$changed = [];
foreach ($in['files'] as $f) {
    if (!is_file($f) || is_link($f) || filesize($f) > 2097152) continue;
    $s = file_get_contents($f);
    $n = preg_replace('#' . $in['pattern'] . '#i', $in['repl'], $s);
    if ($n !== null && $n !== $s && file_put_contents($f, $n) !== false) $changed[] = $f;
}
echo json_encode($changed);
PHP;
        [$c, $o, $e] = Sys::run([...Sys::asUser($acct), '/usr/bin/php', '-n', '-r', $code],
            json_encode(['files' => $files, 'pattern' => $pattern, 'repl' => $repl]), 60);
        if ($c !== 0) {
            throw new \RuntimeException('Updating configuration files failed: ' . mb_substr($e, 0, 200));
        }
        $out['config'] = array_map(static fn($f) => substr($f, strlen($base) + 1), json_decode($o, true) ?: []);

        if (!empty($a['wordpress']) && is_file("$root/wp-config.php") && is_file("$root/wp-includes/version.php")) {
            $out['wordpress'] = true;
            $wp = AppInstaller::wpcliPath();
            $ver = isset($a['php']) ? Validator::php($a['php']) : '';
            $php = $ver !== '' && is_file("/usr/bin/php$ver") ? "/usr/bin/php$ver" : '/usr/bin/php';
            $home = "/home/$acct";
            $env = ['HOME' => $home, 'WP_CLI_CACHE_DIR' => "$home/.wp-cli/cache", 'WP_CLI_DISABLE_AUTO_CHECK_UPDATE' => '1'];
            $cmd = [...Sys::asUser($acct), '/usr/bin/env', '-C', $root, $php, '-d', 'memory_limit=512M', $wp, '--path=' . $root, '--skip-plugins', '--skip-themes'];
            [$c, $o, $e] = Sys::run([...$cmd, 'search-replace', $pattern, $repl, '--regex', '--regex-flags=i', '--regex-delimiter=#',
                '--all-tables-with-prefix', '--skip-columns=guid', '--format=count'], null, 3600, $env);
            if ($c === 0) {
                $out['replacements'] = (int)trim($o);
                Sys::run([...$cmd, 'cache', 'flush'], null, 120, $env);
            } else {
                $out['ok'] = false;
                $out['note'] = mb_substr(trim($e ?: $o), 0, 400);
            }
        }
        return $out;
    }
}
