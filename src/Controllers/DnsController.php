<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response, Scope, Settings};
use Havoc\Services\DnsService;

final class DnsController extends Controller
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    private function domain(): array
    {
        $d = Scope::domain($this->user(), (int)$this->param('id')) ?? $this->abort(404);
        return in_array($d['type'], ['main', 'addon'], true) ? $d : $this->abort(404);     // aliases/subdomains live in the parent zone
    }

    public function index(): Response
    {
        $u = $this->user();
        $zones = array_values(array_filter(Scope::domains($u), fn($d) => in_array($d['type'], ['main', 'addon'], true)));
        return view('dns/index', ['zones' => $zones, 'ns' => DnsService::nameservers(), 'ip' => Settings::get('dns.server_ip'),
            'ns1' => Settings::get('dns.ns1'), 'ns2' => Settings::get('dns.ns2')]);
    }

    public function settings(): Response
    {
        $ns = [];
        foreach (['ns1', 'ns2'] as $k) {
            $v = strtolower($this->req->str($k));
            if ($v !== '' && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $v)) {
                $this->flash('error', t('domains.invalid'));
                return $this->to('/dns');
            }
            $ns[$k] = $v;
        }
        $ip = $this->req->str('server_ip');
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->flash('error', t('dns.bad_ip'));
            return $this->to('/dns');
        }
        foreach ($ns as $k => $v) {
            Settings::set("dns.$k", $v);
        }
        Settings::set('dns.server_ip', $ip);
        Audit::log('dns.settings');
        $this->flash('success', t('common.saved'));
        return $this->to('/dns');
    }

    public function zone(): Response
    {
        $d = $this->domain();
        return view('dns/zone', ['d' => $d, 'ns' => DnsService::nameservers(),
            'records' => DB::all('SELECT * FROM dns_records WHERE domain_id = ? ORDER BY type, name, id', [$d['id']]), 'types' => self::TYPES]);
    }

    public function enable(): Response
    {
        $d = $this->domain();
        try {
            DnsService::enable($this->user(), $d, (string)($_SERVER['SERVER_ADDR'] ?? ''));
            $this->flash('success', t('dns.enabled'));
        } catch (\DomainException) {
            $this->flash('error', t('dns.no_ns'));
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->to('/dns/' . $d['id']);
    }

    public function disable(): Response
    {
        $d = $this->domain();
        try {
            Ctl::call('dns.delete', ['domain' => $d['name']]);
            DB::update('domains', (int)$d['id'], ['dns_enabled' => 0]);
            Audit::log('dns.disable', $d['name']);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/dns/' . $d['id']);
    }

    /** Insert, rebuild the zone (strict validation + named-checkzone happen in the helper), roll back the row on failure. */
    public function recordAdd(): Response
    {
        $d = $this->domain();
        $type = strtoupper($this->req->str('type'));
        if (!$d['dns_enabled'] || !in_array($type, self::TYPES, true)) {
            $this->abort(422);
        }
        $id = DB::insert('dns_records', ['domain_id' => (int)$d['id'], 'type' => $type, 'name' => substr($this->req->str('name', '@'), 0, 200) ?: '@',
            'content' => substr($this->req->str('content'), 0, 2048), 'ttl' => max(60, min(604800, (int)$this->req->post('ttl', 3600))),
            'prio' => max(0, min(65535, (int)$this->req->post('prio', 0)))]);
        try {
            DnsService::sync($d);
            Audit::log('dns.record.add', $d['name'], ['type' => $type]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            DB::q('DELETE FROM dns_records WHERE id = ?', [$id]);
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/dns/' . $d['id']);
    }

    public function recordDelete(): Response
    {
        $d = $this->domain();
        DB::q('DELETE FROM dns_records WHERE id = ? AND domain_id = ?', [(int)$this->param('rid'), $d['id']]);
        try {
            if ($d['dns_enabled']) {
                DnsService::sync($d);
            }
            Audit::log('dns.record.delete', $d['name']);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/dns/' . $d['id']);
    }
}
