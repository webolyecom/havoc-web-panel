<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Crypt, Ctl, DB};

/**
 * One-click applications (currently WordPress). start() prepares everything the panel owns (database, credentials,
 * a row in `apps`) and returns at once; run() is the background job (bin/havoc app:install <id>) that calls the root
 * helper, which installs the files as the hosting account. Passwords are shown once and kept encrypted only until the
 * job has finished.
 */
final class AppService
{
    public const LOCALES = ['en_US' => 'English (US)', 'tr_TR' => 'Türkçe', 'de_DE' => 'Deutsch', 'fr_FR' => 'Français', 'es_ES' => 'Español',
        'it_IT' => 'Italiano', 'nl_NL' => 'Nederlands', 'pt_BR' => 'Português (Brasil)', 'ru_RU' => 'Русский', 'ar' => 'العربية', 'az' => 'Azərbaycan', 'en_GB' => 'English (UK)'];

    public static function forDomain(int $domainId): ?array
    {
        return DB::one('SELECT * FROM apps WHERE domain_id = ? ORDER BY id DESC LIMIT 1', [$domainId]);
    }

    /**
     * @param array{title?:string,admin_user?:string,admin_email?:string,locale?:string} $opt
     * @return array{id:int,admin_user:string,admin_password:string,admin_url:string,url:string,database:string}
     */
    public static function startWordPress(array $actor, array $d, array $opt, string $via = 'panel'): array
    {
        if (!in_array($d['type'], ['main', 'addon', 'sub'], true) || $d['status'] !== 'active' || empty($d['docroot'])) {
            throw new \DomainException('app.bad_domain');
        }
        $prev = self::forDomain((int)$d['id']);
        if ($prev && in_array($prev['status'], ['installing', 'installed'], true)) {
            throw new \DomainException($prev['status'] === 'installing' ? 'app.busy' : 'app.exists');
        }
        $owner = DB::one('SELECT * FROM users WHERE id = ?', [$d['user_id']]);
        $locale = (string)($opt['locale'] ?? 'en_US');
        if (!isset(self::LOCALES[$locale])) {
            $locale = 'en_US';
        }
        $user = trim((string)($opt['admin_user'] ?? '')) ?: 'wpadmin';
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D', $user)) {
            throw new \DomainException('app.bad_user');
        }
        $mail = trim((string)($opt['admin_email'] ?? '')) ?: (string)$owner['email'];
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('app.bad_email');
        }
        $title = mb_substr(trim((string)($opt['title'] ?? '')), 0, 120) ?: $d['name'];
        $db = DatabaseService::create($actor, (int)$owner['id'], DatabaseService::freeName((int)$owner['id'], 'wp'), null, (int)$d['id']);
        $pass = DatabaseService::password();
        $id = DB::insert('apps', ['domain_id' => (int)$d['id'], 'app' => 'wordpress', 'status' => 'installing', 'db_name' => $db['name'],
            'admin_user' => $user, 'via' => $via === 'claude' ? 'claude' : 'panel', 'updated_at' => gmdate('Y-m-d H:i:s'),
            'secret_enc' => Crypt::encrypt(json_encode(['db_password' => $db['password'], 'admin_password' => $pass, 'admin_email' => $mail,
                'title' => $title, 'locale' => $locale]))]);
        Audit::log('app.install', $d['name'], ['app' => 'wordpress', 'via' => $via], $actor);
        BackupService::spawn('app:install', (string)$id);
        $host = 'https://' . $d['name'];
        return ['id' => $id, 'admin_user' => $user, 'admin_password' => $pass, 'admin_url' => $host . '/wp-admin/', 'url' => $host,
            'database' => $db['name']];
    }

    public static function run(int $id): void
    {
        $a = DB::one('SELECT * FROM apps WHERE id = ?', [$id]);
        if (!$a || $a['status'] !== 'installing') {
            return;
        }
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$a['domain_id']]);
        $sec = json_decode((string)Crypt::decrypt((string)$a['secret_enc']), true);
        $done = function (array $f) use ($id): void {
            DB::update('apps', $id, $f + ['secret_enc' => null, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        };
        if (!$d || !is_array($sec)) {
            $done(['status' => 'failed', 'note' => 'Site or install data missing']);
            return;
        }
        // a certificate that is being issued right now decides whether WordPress gets an https:// address
        for ($i = 0; $i < 30 && in_array((string)DB::q('SELECT ssl_auto FROM domains WHERE id = ?', [$d['id']])->fetchColumn(), ['running'], true); $i++) {
            sleep(4);
        }
        $d = DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]);
        $https = in_array($d['ssl_mode'], ['letsencrypt', 'custom', 'cloudflare'], true) || $d['ssl_auto'] === 'issued';
        try {
            $r = Ctl::call('app.wordpress', ['account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'php' => $d['php_version'],
                'docroot' => $d['docroot'], 'https' => $https, 'locale' => $sec['locale'], 'title' => $sec['title'],
                'admin_user' => $a['admin_user'], 'admin_email' => $sec['admin_email'], 'admin_password' => $sec['admin_password'],
                'db_name' => $a['db_name'], 'db_password' => $sec['db_password']], 900);
            $done(['status' => 'installed', 'version' => $r['version'] ?? null, 'admin_url' => $r['admin_url'] ?? null,
                'note' => $https ? null : 'http']);
            Audit::log('app.installed', $d['name'], ['app' => 'wordpress', 'version' => $r['version'] ?? ''], null);
        } catch (\Throwable $e) {
            $done(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** Lets a failed install be retried (the database created for it is kept and listed under Databases). */
    public static function clearFailed(array $d): void
    {
        DB::q("DELETE FROM apps WHERE domain_id = ? AND status = 'failed'", [$d['id']]);
    }
}
