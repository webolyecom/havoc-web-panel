<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response, Scope, Settings};
use Havoc\Services\{DomainService, WafService};

final class WafController extends Controller
{
    private function domains(): array
    {
        return array_values(array_filter(Scope::domains($this->user()), fn($d) => $d['type'] !== 'alias'));
    }

    public function index(): Response
    {
        $u = $this->user();
        $status = ['server' => '', 'module' => false, 'crs' => false, 'mode' => 'off'];
        try {
            $status = Ctl::call('waf.status');
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        $doms = $this->domains();
        $ids = array_map('intval', array_column($doms, 'id'));
        $in = $ids ? implode(',', $ids) : '0';
        $rules = DB::all("SELECT r.*, d.name AS domain FROM waf_rules r LEFT JOIN domains d ON d.id = r.domain_id
                          WHERE " . ($u['role'] === 'admin' ? '1=1' : "r.domain_id IN ($in)") . ' ORDER BY r.id DESC');
        return view('waf/index', ['status' => $status, 'mode' => WafService::mode(), 'domains' => $doms, 'rules' => $rules]);
    }

    public function mode(): Response
    {
        $new = $this->req->str('mode');
        if (!in_array($new, ['off', 'detect', 'on'], true)) {
            $this->abort(422);
        }
        $old = WafService::mode();
        Settings::set('waf.mode', $new);
        try {
            WafService::sync();
            Audit::log('waf.mode', $new);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            Settings::set('waf.mode', $old);
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/waf');
    }

    public function install(): Response
    {
        try {
            Ctl::call('waf.install', [], 600);
            Audit::log('waf.install');
            $this->flash('success', t('waf.installed'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/waf');
    }

    public function domainToggle(): Response
    {
        $u = $this->user();
        $d = Scope::domain($u, (int)$this->param('id')) ?? $this->abort(404);
        if ($d['type'] === 'alias') {
            $this->abort(404);
        }
        $on = $d['waf'] ? 0 : 1;
        try {
            if ($on && !(Ctl::call('waf.status')['module'] ?? false)) {
                throw new \RuntimeException(t('waf.no_module'));
            }
            DomainService::update($u, $d, ['waf' => $on]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/waf');
    }

    private function rule(): array
    {
        $r = DB::one('SELECT * FROM waf_rules WHERE id = ?', [(int)$this->param('id')]) ?? $this->abort(404);
        if ($r['domain_id'] === null) {
            $this->user()['role'] === 'admin' || $this->abort(404);
        } elseif (!Scope::domain($this->user(), (int)$r['domain_id'])) {
            $this->abort(404);
        }
        return $r;
    }

    public function ruleAdd(): Response
    {
        $u = $this->user();
        $domainId = (int)$this->req->post('domain_id', 0) ?: null;
        if ($domainId) {
            $d = Scope::domain($u, $domainId);
            if (!$d || $d['type'] === 'alias') {
                $this->abort(404);
            }
        } elseif ($u['role'] !== 'admin') {
            $this->abort(403);
        }
        $type = $this->req->str('type');
        $value = $this->req->str('value');
        $target = '';
        $op = '';
        if ($type === 'exclude') {
            if (!preg_match('/^[0-9]{3,7}$/', $value)) {
                $this->flash('error', t('waf.bad_id'));
                return $this->to('/waf');
            }
        } elseif ($type === 'block') {
            $target = $this->req->str('target');
            $op = $this->req->str('op');
            $value = strtolower($value);
            if (!in_array($target, ['uri', 'ua', 'args'], true) || !in_array($op, ['contains', 'beginswith'], true)
                || !preg_match('#^[a-z0-9._~\-/%?=&:+@]{1,100}$#', $value)) {
                $this->flash('error', t('waf.bad_value'));
                return $this->to('/waf');
            }
        } else {
            $this->abort(422);
        }
        $id = DB::insert('waf_rules', ['user_id' => $u['id'], 'domain_id' => $domainId, 'type' => $type, 'target' => $target, 'op' => $op, 'value' => $value]);
        try {
            WafService::sync();
            Audit::log('waf.rule.add', (string)$id, ['type' => $type]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            DB::q('DELETE FROM waf_rules WHERE id = ?', [$id]);
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/waf');
    }

    public function ruleToggle(): Response
    {
        $r = $this->rule();
        DB::update('waf_rules', (int)$r['id'], ['enabled' => $r['enabled'] ? 0 : 1]);
        try {
            WafService::sync();
        } catch (\Throwable $e) {
            DB::update('waf_rules', (int)$r['id'], ['enabled' => (int)$r['enabled']]);
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/waf');
    }

    public function ruleDelete(): Response
    {
        $r = $this->rule();
        DB::q('DELETE FROM waf_rules WHERE id = ?', [$r['id']]);
        Audit::log('waf.rule.delete', (string)$r['id']);
        try {
            WafService::sync();
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/waf');
    }

    public function log(): Response
    {
        $u = $this->user();
        $doms = $this->domains();
        $only = null;
        $fid = (int)$this->req->str('domain_id', '0');
        if ($fid) {
            $d = Scope::domain($u, $fid) ?? $this->abort(404);
            $only = $d['name'];
        }
        $entries = [];
        $args = ['limit' => 150, 'host' => $only];
        if ($u['role'] !== 'admin') {
            $args['hosts'] = array_column(Scope::domains($u), 'name');          // aliases included: they share the vhost
        }
        try {
            if ($u['role'] === 'admin' || $args['hosts']) {
                $entries = Ctl::call('waf.log', $args, 30)['entries'] ?? [];
            }
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return view('waf/log', ['entries' => $entries, 'domains' => $doms, 'fid' => $fid, 'live' => (bool)$this->req->str('live')]);
    }
}
