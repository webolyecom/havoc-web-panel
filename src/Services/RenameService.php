<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB};
use Havoc\Ctl\Validator;

/**
 * Domain → Change domain name (admins). The site keeps its id, files, databases, FTP accounts, PHP settings, cron jobs and
 * backups; everything that carries the name follows it:
 *  - the site folder, logs and web server / PHP configuration (helper domain.rename + domain.apply), its subdomains
 *    (blog.old.com → blog.new.com) the same way,
 *  - FTP mounts, cron jobs, the DNS zone (records naming the old domain), WAF rules, the WordPress admin link,
 *  - SSL: an own certificate stays only when it also covers the new name; otherwise a Let's Encrypt certificate is requested,
 *  - optionally the old names stay as parked names answering with a 301 to the new address,
 *  - optionally the old address is replaced in the WordPress database (after a database backup) and always in the site's
 *    configuration files (absolute folder paths would break otherwise).
 * When the new configuration cannot be applied, everything is put back.
 */
final class RenameService
{
    /** What a rename would do, for the confirmation page. */
    public static function plan(array $d, string $new): array
    {
        if ($d['type'] !== 'main' && $d['type'] !== 'addon') {
            throw new \DomainException(t('ren.e_type'));
        }
        if ($d['status'] !== 'active') {
            throw new \DomainException(t('ren.e_status'));
        }
        try {
            $new = Validator::domain($new);
        } catch (\InvalidArgumentException) {
            throw new \DomainException(t('ren.e_invalid'));
        }
        $old = $d['name'];
        if ($new === $old) {
            throw new \DomainException(t('ren.e_same'));
        }
        $subs = [];
        foreach (DB::all("SELECT * FROM domains WHERE parent_id = ? AND type = 'sub' ORDER BY name", [$d['id']]) as $s) {
            if (str_ends_with($s['name'], '.' . $old)) {
                $prefix = substr($s['name'], 0, -strlen($old));          // "blog."
                $subs[] = ['row' => $s, 'old' => $s['name'], 'new' => $prefix . $new, 'prefix' => $prefix];
            }
        }
        $taken = [$new];
        foreach ($subs as $s) {
            $taken[] = $s['new'];
        }
        foreach ($taken as $n) {
            $hit = DB::one('SELECT id, name, type FROM domains WHERE name = ?', [$n]);
            // an old name kept as a redirect on this very site may be taken back
            if ($hit && !(self::isOwnRedirect($hit, $d, $subs))) {
                throw new \DomainException(t('ren.e_exists', ['name' => $n]));
            }
        }
        $keep = [$old];
        if (!DB::one('SELECT id FROM domains WHERE name = ?', ['www.' . $old])) {
            $keep[] = 'www.' . $old;
        }
        $ids = array_merge([(int)$d['id']], array_map(static fn($s) => (int)$s['row']['id'], $subs));
        $in = implode(',', $ids);
        return [
            'old' => $old, 'new' => $new, 'subs' => $subs, 'keep' => $keep,
            'aliases' => DB::all("SELECT name, alias_redirect FROM domains WHERE parent_id = ? AND type = 'alias' ORDER BY name", [$d['id']]),
            'ftp' => (int)DB::q("SELECT COUNT(*) FROM ftp_accounts WHERE domain_id IN ($in)")->fetchColumn(),
            'cron' => (int)DB::q("SELECT COUNT(*) FROM cron_jobs WHERE domain_id IN ($in)")->fetchColumn(),
            'dns' => !empty($d['dns_enabled']),
            'wordpress' => (bool)DB::one("SELECT id FROM apps WHERE domain_id IN ($in) AND app = 'wordpress' AND status = 'installed'"),
            'dbs' => (int)DB::q('SELECT COUNT(*) FROM db_instances WHERE user_id = ?', [$d['user_id']])->fetchColumn(),
        ];
    }

    private static function isOwnRedirect(array $hit, array $d, array $subs): bool
    {
        if ($hit['type'] !== 'alias') {
            return false;
        }
        $row = DB::one('SELECT parent_id, alias_redirect FROM domains WHERE id = ?', [$hit['id']]);
        $parents = array_merge([(int)$d['id']], array_map(static fn($s) => (int)$s['row']['id'], $subs));
        return $row && (int)$row['alias_redirect'] === 1 && in_array((int)$row['parent_id'], $parents, true);
    }

    /**
     * @param bool $keepOld  old names answer with a 301 to the new address
     * @param bool $wp       replace the old address in the WordPress database (a database backup is taken first)
     * @return array{warnings:list<string>, backup:?int, ssl:string, addresses:list<array>}
     */
    public static function rename(array $actor, array $d, string $newName, bool $keepOld, bool $wp): array
    {
        if (($actor['role'] ?? '') !== 'admin') {
            throw new \DomainException('Forbidden');
        }
        $p = self::plan($d, $newName);
        $old = $p['old'];
        $new = $p['new'];
        $acct = 'hv' . $d['user_id'];
        $out = ['warnings' => [], 'backup' => null, 'ssl' => '', 'addresses' => []];

        // 0. a database backup before WordPress' database is changed (restorable under Backups)
        if ($wp && $p['dbs'] > 0) {
            $bid = DB::insert('backups', ['user_id' => $d['user_id'], 'target_id' => null, 'scope' => 'db', 'status' => 'running', 'domain_id' => $d['id']]);
            BackupService::run($bid);
            $b = DB::one('SELECT status, note FROM backups WHERE id = ?', [$bid]);
            if (($b['status'] ?? '') !== 'done') {
                throw new \RuntimeException(t('ren.e_backup', ['msg' => (string)($b['note'] ?? '')]));
            }
            $out['backup'] = $bid;
        }

        // the site and its subdomains: [row, old, new, prefix]
        $items = [['row' => $d, 'old' => $old, 'new' => $new, 'prefix' => '']];
        foreach ($p['subs'] as $s) {
            $items[] = $s;
        }

        // 1. folders, logs, old web server configuration, own certificates
        $moved = [];
        $sslKept = [];
        try {
            foreach ($items as $it) {
                $r = Ctl::call('domain.rename', ['account' => $acct, 'old' => $it['old'], 'new' => $it['new']], 300);
                $moved[] = $it;
                $sslKept[(int)$it['row']['id']] = !empty($r['ssl_kept']);
            }
        } catch (\Throwable $e) {
            self::moveBack($acct, $moved);
            throw $e;
        }

        // 2. panel records (the previous values are kept to put them back)
        $undo = [];
        $added = [];
        $ownRedirects = ['www.' . $new];                          // renaming back: the kept old names are taken back
        foreach ($items as $it) {
            $ownRedirects[] = $it['new'];
        }
        $in = implode(',', array_fill(0, count($ownRedirects), '?'));
        $parents = implode(',', array_map(static fn($it) => (int)$it['row']['id'], $items));
        $taken = DB::all("SELECT * FROM domains WHERE type = 'alias' AND alias_redirect = 1 AND parent_id IN ($parents) AND name IN ($in)", $ownRedirects);
        try {
            foreach ($taken as $t) {                                    // a kept old name taken back (renaming back)
                DB::q('DELETE FROM domains WHERE id = ?', [$t['id']]);
            }
            foreach ($items as $it) {
                $row = $it['row'];
                $id = (int)$row['id'];
                $f = ['name' => $it['new'], 'docroot' => "/home/$acct/domains/{$it['new']}/public_html"];
                if (!$sslKept[$id]) {
                    $f += ['ssl_mode' => 'none', 'ssl_auto' => '', 'ssl_auto_note' => null, 'ssl_auto_attempts' => 0, 'ssl_transferred' => 0];
                }
                $undo[$id] = array_intersect_key($row, $f);
                DB::update('domains', $id, $f);
            }
            if ($keepOld) {
                $names = [[$d, $p['keep']]];
                foreach ($p['subs'] as $s) {
                    $names[] = [$s['row'], [$s['old']]];
                }
                foreach ($names as [$parent, $list]) {
                    foreach ($list as $n) {
                        if (!DB::one('SELECT id FROM domains WHERE name = ?', [$n])) {
                            $added[] = DB::insert('domains', ['user_id' => $d['user_id'], 'name' => $n, 'type' => 'alias', 'parent_id' => $parent['id'],
                                'php_version' => $parent['php_version'], 'status' => 'active', 'alias_redirect' => 1]);
                        }
                    }
                }
            }
            // 3. the new configuration (vhost + PHP-FPM); tested by the web server before it is loaded
            foreach ($items as $it) {
                DomainService::reapply(DB::one('SELECT * FROM domains WHERE id = ?', [$it['row']['id']]));
            }
        } catch (\Throwable $e) {
            foreach ($added as $aid) {
                DB::q('DELETE FROM domains WHERE id = ?', [$aid]);
            }
            foreach ($undo as $id => $f) {
                DB::update('domains', $id, $f);
            }
            foreach ($taken as $t) {
                DB::insert('domains', $t);
            }
            self::moveBack($acct, $moved);
            foreach ($items as $it) {
                try {
                    DomainService::reapply(DB::one('SELECT * FROM domains WHERE id = ?', [$it['row']['id']]));
                } catch (\Throwable $e2) {
                    error_log('rename rollback: ' . $e2->getMessage());
                }
            }
            throw $e;
        }

        // 4. everything else that carries the name: problems are reported, the rename stays
        $ids = array_map(static fn($it) => (int)$it['row']['id'], $items);
        $idIn = implode(',', $ids);
        $step = static function (string $what, callable $fn) use (&$out): void {
            try {
                $fn();
            } catch (\Throwable $e) {
                $out['warnings'][] = t('ren.w_step', ['what' => $what, 'msg' => mb_substr($e->getMessage(), 0, 200)]);
            }
        };
        $byId = [];
        foreach ($items as $it) {
            $byId[(int)$it['row']['id']] = $it;
        }
        // FTP: the accounts' folders are bind mounts of the site folder
        foreach (DB::all("SELECT * FROM ftp_accounts WHERE domain_id IN ($idIn)") as $f) {
            $it = $byId[(int)$f['domain_id']];
            $step('FTP ' . $f['login'], static function () use ($f, $it, $acct): void {
                DB::update('ftp_accounts', (int)$f['id'], ['home' => $it['new'] . ($f['subdir'] !== '' ? '/' . $f['subdir'] : '')]);
                Ctl::call('ftp.update', ['protocol' => $f['protocol'], 'account' => $acct, 'login' => $f['login'], 'domain' => $it['new'],
                    'subdir' => $f['subdir'], 'quota_mb' => (int)$f['quota_mb'], 'ips' => array_values(array_filter(explode(',', (string)$f['allowed_ips'])))], 60);
            });
        }
        // cron jobs: commands that use the old folder (/home/hvN/domains/old or ~/domains/old) or address (//old, //www.old …)
        $step('cron', static function () use ($d, $items, $acct): void {
            $rx = [];
            foreach ($items as $it) {
                $q = preg_quote($it['old'], '#');
                $rx['#(?<=/home/' . $acct . '/domains/|~/domains/)' . $q . '(?![a-z0-9-]|\.[a-z0-9])#i'] = $it['new'];
                $rx['#(?<=//)(www\.)?' . $q . '(?![a-z0-9-]|\.[a-z0-9])#i'] = '${1}' . $it['new'];
            }
            foreach (DB::all('SELECT id, command FROM cron_jobs WHERE user_id = ?', [$d['user_id']]) as $j) {
                $c = preg_replace(array_keys($rx), array_values($rx), $j['command']);
                if ($c !== null && $c !== $j['command']) {
                    DB::q('UPDATE cron_jobs SET command = ? WHERE id = ?', [$c, $j['id']]);
                }
            }
            CronService::sync((int)$d['user_id']);
        });
        // WordPress admin link shown in the panel
        foreach (DB::all("SELECT id, admin_url, domain_id FROM apps WHERE domain_id IN ($idIn)") as $a) {
            $it = $byId[(int)$a['domain_id']];
            $u = preg_replace('#//(www\.)?' . preg_quote($it['old'], '#') . '(?=[/:]|$)#i', '//${1}' . $it['new'], (string)$a['admin_url']);
            DB::q('UPDATE apps SET admin_url = ? WHERE id = ?', [$u, $a['id']]);
        }
        // DNS zone of the site (records are relative to the zone; names written out in full follow the new name)
        if (!empty($d['dns_enabled'])) {
            $step('DNS', static function () use ($d, $old, $new): void {
                $rx = '/(^|\.)' . preg_quote($old, '/') . '(\.?)$/i';
                foreach (DB::all('SELECT id, name, content FROM dns_records WHERE domain_id = ?', [$d['id']]) as $r) {
                    $n = preg_replace($rx, '${1}' . $new . '${2}', (string)$r['name']);
                    $c = preg_replace('/(^|[\s.@])' . preg_quote($old, '/') . '(\.?)(?=\s|$)/i', '${1}' . $new . '${2}', (string)$r['content']);
                    if ($n !== $r['name'] || $c !== $r['content']) {
                        DB::q('UPDATE dns_records SET name = ?, content = ? WHERE id = ?', [$n, $c, $r['id']]);
                    }
                }
                try {
                    Ctl::call('dns.delete', ['domain' => $old]);
                } catch (\Throwable $e) {
                    error_log('dns delete: ' . $e->getMessage());
                }
                DnsService::sync(DB::one('SELECT * FROM domains WHERE id = ?', [$d['id']]));
            });
        }
        // WAF rules of the site match the host name
        if (DB::one("SELECT id FROM waf_rules WHERE domain_id IN ($idIn)")) {
            $step('WAF', static fn() => WafService::sync());
        }
        // addresses in configuration files (always: folder paths) and, when chosen, in WordPress' database
        foreach ($items as $it) {
            $row = DB::one('SELECT * FROM domains WHERE id = ?', [$it['row']['id']]);
            $prefixes = $it['prefix'] === '' ? array_map(static fn($s) => $s['prefix'], $p['subs']) : [];
            $step(t('ren.addresses_of', ['site' => $it['new']]), static function () use (&$out, $acct, $it, $row, $prefixes, $wp): void {
                $r = Ctl::call('domain.rename_addresses', ['account' => $acct, 'old' => $it['old'], 'new' => $it['new'], 'prefixes' => $prefixes,
                    'wordpress' => $wp, 'php' => $row['php_version']], 3600);
                $out['addresses'][] = ['site' => $it['new']] + $r;
                if (!empty($r['wordpress']) && empty($r['ok'])) {
                    throw new \RuntimeException('WordPress: ' . $r['note']);
                }
            });
        }
        // SSL for the new names (own certificates that also cover the new name stay)
        foreach ($items as $it) {
            $row = DB::one('SELECT * FROM domains WHERE id = ?', [$it['row']['id']]);
            if (!$sslKept[(int)$row['id']]) {
                $step('SSL ' . $it['new'], static fn() => SslService::queue($row));
            }
        }
        $out['ssl'] = $sslKept[(int)$d['id']] ? 'kept' : 'requested';

        Audit::log('domain.rename', $old, ['new' => $new, 'subdomains' => array_map(static fn($s) => $s['old'] . ' -> ' . $s['new'], $p['subs']),
            'keep_old' => $keepOld, 'wordpress' => $wp, 'backup' => $out['backup'], 'warnings' => count($out['warnings'])], $actor);
        return $out;
    }

    /** Puts folders and logs back (also removes the new names' web server configuration). */
    private static function moveBack(string $acct, array $moved): void
    {
        foreach (array_reverse($moved) as $it) {
            try {
                Ctl::call('domain.rename', ['account' => $acct, 'old' => $it['new'], 'new' => $it['old']], 300);
            } catch (\Throwable $e) {
                error_log('rename rollback: ' . $e->getMessage());
            }
        }
    }
}
