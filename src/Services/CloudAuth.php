<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\Config;

/**
 * Connects Google Drive, OneDrive and Dropbox as backup targets without any app secret in the panel: rclone's own
 * "authorize" runs on the server (as the panel user) and listens on 127.0.0.1:53682. The provider sends the browser
 * back to 127.0.0.1:53682 on the user's computer, which shows an error page whose address holds the one-time code;
 * the user pastes that address into the panel, which hands it to the waiting rclone. rclone exchanges the code and
 * prints the token. Only one connection can be in progress on the server at a time (fixed port).
 */
final class CloudAuth
{
    public const TYPES = ['gdrive' => ['drive', 'Google Drive'], 'onedrive' => ['onedrive', 'OneDrive'], 'dropbox' => ['dropbox', 'Dropbox']];
    /** Where each provider's consent page lives: anything else means the local port was not answered by our rclone. */
    private const HOSTS = ['gdrive' => ['accounts.google.com'], 'onedrive' => ['login.microsoftonline.com', 'login.live.com'], 'dropbox' => ['www.dropbox.com']];
    private const PORT = 53682;
    private const TIMEOUT = 900;

    private static function dir(): string
    {
        return dirname((string)Config::get('db.path'));
    }

    private static function stop(): void
    {
        // our own earlier attempts only (the panel user cannot signal anybody else's processes)
        $p = proc_open(['/usr/bin/pkill', '-f', '^/usr/bin/rclone --config /dev/null authorize '], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (is_resource($p)) {
            proc_close($p);
        }
        foreach (glob(self::dir() . '/cloud-auth-*.log') ?: [] as $f) {
            @unlink($f);
        }
        usleep(300000);
    }

    /** Starts rclone authorize and returns the provider's consent URL; the session keeps the state. */
    public static function start(string $type): string
    {
        $backend = self::TYPES[$type][0] ?? throw new \InvalidArgumentException('Unknown service');
        self::stop();
        $log = self::dir() . '/cloud-auth-' . bin2hex(random_bytes(8)) . '.log';
        $opts = $type === 'gdrive' ? ['scope' => 'drive.file'] : [];
        $opts += (array)Config::get('backup.oauth_override.' . $type, []);   // test hook: mock provider URLs (config file only)
        $args = ['/usr/bin/setsid', '-f', '/usr/bin/timeout', (string)self::TIMEOUT, '/usr/bin/rclone', '--config', '/dev/null', 'authorize', $backend];
        if ($opts) {
            $args[] = rtrim(strtr(base64_encode(json_encode($opts)), '+/', '-_'), '=');
        }
        $args[] = '--auth-no-open-browser';
        $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, null,
            ['PATH' => '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'XDG_CACHE_HOME' => sys_get_temp_dir()]);
        if (!is_resource($p)) {
            throw new \RuntimeException('rclone could not be started');
        }
        proc_close($p);
        $local = null;
        for ($i = 0; $i < 50 && !$local; $i++) {
            usleep(100000);
            if (preg_match('#http://127\.0\.0\.1:' . self::PORT . '/auth\?state=([A-Za-z0-9_-]+)#', (string)@file_get_contents($log), $m)) {
                $local = $m[0];
            }
        }
        if (!$local) {
            $err = trim((string)@file_get_contents($log));
            @unlink($log);
            throw new \RuntimeException('rclone did not start the authorization' . ($err !== '' ? ': ' . mb_substr($err, -300) : ''));
        }
        // rclone answers /auth with a redirect to the provider's consent page
        $ch = curl_init($local);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        curl_exec($ch);
        $url = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $override = (array)Config::get('backup.oauth_override.' . $type, []);
        $allowed = isset($override['auth_url']) ? [strtolower((string)parse_url((string)$override['auth_url'], PHP_URL_HOST))] : self::HOSTS[$type];
        if (!preg_match('#^https?://#', $url) || !in_array($host, $allowed, true) || (!$override && !str_starts_with($url, 'https://'))) {
            self::stop();
            throw new \RuntimeException('Unexpected authorization address; another program may be using port ' . self::PORT . ' on this server');
        }
        $_SESSION['cloud_auth'] = ['type' => $type, 'log' => basename($log), 'state' => $m[1], 'started' => time()];
        return $url;
    }

    /**
     * Finishes with the address the browser ended on (http://127.0.0.1:53682/?state=...&code=...).
     * @return string token JSON
     */
    public static function finish(string $pasted): string
    {
        $s = $_SESSION['cloud_auth'] ?? null;
        if (!$s || time() - (int)$s['started'] > self::TIMEOUT) {
            throw new \RuntimeException(t('cloud.e_expired'));
        }
        $u = parse_url(trim($pasted));
        if (!$u || !in_array($u['host'] ?? '', ['127.0.0.1', 'localhost'], true) || (int)($u['port'] ?? 0) !== self::PORT || empty($u['query'])) {
            throw new \InvalidArgumentException(t('cloud.e_address'));
        }
        parse_str($u['query'], $q);
        if (!empty($q['error'])) {
            throw new \RuntimeException(t('cloud.e_denied') . ' (' . mb_substr((string)$q['error'], 0, 60) . ')');
        }
        if (($q['state'] ?? '') !== $s['state'] || empty($q['code']) || !is_string($q['code'])) {
            throw new \InvalidArgumentException(t('cloud.e_address'));
        }
        $log = self::dir() . '/' . basename((string)$s['log']);
        $ch = curl_init('http://127.0.0.1:' . self::PORT . '/?' . http_build_query(['state' => $q['state'], 'code' => $q['code']] + array_intersect_key($q, array_flip(['scope']))));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        if (curl_exec($ch) === false) {
            throw new \RuntimeException(t('cloud.e_expired'));
        }
        $token = null;
        for ($i = 0; $i < 100 && $token === null; $i++) {
            $out = (string)@file_get_contents($log);
            if (preg_match('/--->\s*(\S+)\s*<---End paste/s', $out, $m)) {
                $token = self::decodeResult(trim($m[1]));
            } elseif (preg_match('/(Failed to get token|failed to get token|Error:|Fatal error:)(.*)$/m', $out, $m)) {
                break;
            }
            usleep(150000);
        }
        $out = (string)@file_get_contents($log);
        @unlink($log);
        unset($_SESSION['cloud_auth']);
        $j = $token !== null ? json_decode($token, true) : null;
        if (!is_array($j) || empty($j['access_token'])) {
            throw new \RuntimeException(t('cloud.e_token') . (preg_match('/(?:Fatal error|Error|Failed to get token)[: ]+(.*)$/m', $out, $m) ? ': ' . mb_substr(trim($m[1]), 0, 200) : ''));
        }
        return json_encode($j, JSON_UNESCAPED_SLASHES);
    }

    /**
     * rclone prints the token as JSON, or (when options were passed) as base64url JSON {"token": "<token JSON>"}.
     */
    private static function decodeResult(string $s): string
    {
        if (str_starts_with($s, '{')) {
            return $s;
        }
        $j = json_decode((string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true), true);
        return is_array($j) && is_string($j['token'] ?? null) ? $j['token'] : '';
    }

    /** OneDrive needs the id and type of the user's drive. */
    public static function oneDrive(string $token): array
    {
        $tok = json_decode($token, true);
        $base = rtrim((string)Config::get('backup.graph_api', 'https://graph.microsoft.com/v1.0'), '/');
        $ch = curl_init($base . '/me/drive');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ($tok['access_token'] ?? '')]]);
        $r = json_decode((string)curl_exec($ch), true);
        if (!is_array($r) || empty($r['id']) || empty($r['driveType'])) {
            throw new \RuntimeException('OneDrive: ' . ($r['error']['message'] ?? 'could not read the drive'));
        }
        return ['drive_id' => (string)$r['id'], 'drive_type' => (string)$r['driveType']];
    }
}
