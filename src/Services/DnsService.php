<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Settings};

final class DnsService
{
    public static function nameservers(): array
    {
        return array_values(array_filter([Settings::get('dns.ns1'), Settings::get('dns.ns2')]));
    }

    /** Rebuild and load the zone from the records stored in the panel. */
    public static function sync(array $d): void
    {
        Ctl::call('dns.apply', ['domain' => $d['name'], 'ns' => self::nameservers(),
            'records' => DB::all('SELECT type, name, content, ttl, prio FROM dns_records WHERE domain_id = ? ORDER BY id', [$d['id']])]);
    }

    public static function enable(array $actor, array $d, string $fallbackIp): void
    {
        if (!self::nameservers()) {
            throw new \DomainException('dns_no_ns');
        }
        $had = (int)DB::q('SELECT COUNT(*) FROM dns_records WHERE domain_id = ?', [$d['id']])->fetchColumn();
        $ip = Settings::get('dns.server_ip', $fallbackIp);
        if (!$had && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            DB::insert('dns_records', ['domain_id' => $d['id'], 'type' => 'A', 'name' => '@', 'content' => $ip, 'ttl' => 3600]);
            DB::insert('dns_records', ['domain_id' => $d['id'], 'type' => 'CNAME', 'name' => 'www', 'content' => '@', 'ttl' => 3600]);
        }
        try {
            self::sync($d);
        } catch (\Throwable $e) {
            if (!$had) {
                DB::q('DELETE FROM dns_records WHERE domain_id = ?', [$d['id']]);
            }
            throw $e;
        }
        DB::update('domains', (int)$d['id'], ['dns_enabled' => 1]);
        Audit::log('dns.enable', $d['name'], [], $actor);
    }
}
