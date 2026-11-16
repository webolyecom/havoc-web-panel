<?php
declare(strict_types=1);

namespace Havoc\Controllers;

use Havoc\Core\{Audit, Ctl, DB, Response, Scope, Settings};
use Havoc\Ctl\Validator;
use Havoc\Services\MeiliService;
use Havoc\Services\{DomainService, SslService};

final class DomainController extends Controller
{
    private function domain(): array
    {
        return Scope::domain($this->user(), (int)$this->param('id')) ?? $this->abort(404);   // 404, never 403: do not leak existence
    }

    public function index(): Response
    {
        $u = $this->user();
        $owners = $u['role'] === 'user' ? [$u] : Scope::users($u);
        if ($u['role'] === 'reseller') {
            array_unshift($owners, ['id' => $u['id'], 'username' => $u['username']]);
        }
        $all = Scope::domains($u);
        return view('domains/index', ['domains' => $all, 'owners' => $owners, 'php' => DomainService::phpVersions(),
            'mains' => array_values(array_filter($all, fn($d) => in_array($d['type'], ['main', 'addon'], true))),
            'sslAuto' => SslService::enabled(), 'sslAutoHttps' => SslService::autoHttps()]);
    }

    /** Admin: automatic Let's Encrypt for new domains, and whether HTTPS is forced once a certificate exists. */
    public function settings(): Response
    {
        Settings::set('ssl.auto', $this->req->post('ssl_auto') ? '1' : '0');
        Settings::set('ssl.auto_https', $this->req->post('ssl_auto_https') ? '1' : '0');
        Audit::log('ssl.settings', '', ['auto' => Settings::get('ssl.auto'), 'https' => Settings::get('ssl.auto_https')]);
        $this->flash('success', t('common.saved'));
        return $this->to('/domains');
    }

    public function store(): Response
    {
        $u = $this->user();
        $owner = $u['role'] === 'user' ? (int)$u['id'] : (int)$this->req->post('owner', $u['id']);
        try {
            DomainService::create($u, $owner, (string)$this->req->post('name', ''), $this->req->str('php', '8.2'),
                $this->req->str('type', 'main'), (int)$this->req->post('parent', 0) ?: null);
            $this->flash('success', t('domains.created'));
        } catch (\InvalidArgumentException) {
            $this->flash('error', t('domains.invalid'));
        } catch (\DomainException $e) {
            $this->flash('error', t('domains.' . $e->getMessage()) );
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->backToSite('aliases') ?? $this->to('/domains');
    }

    /** The site pages under /domains/{id}/<section> (GET; the forms post to the same addresses where they exist). */
    public const SECTIONS = ['wordpress', 'php', 'php-ini', 'ssl', 'security', 'nginx', 'aliases', 'redirects', 'databases', 'search', 'delete'];

    /** Site overview: a short summary and one tile per settings page (cheap: no helper calls). */
    public function show(): Response
    {
        $d = $this->domain();
        if ($d['type'] === 'alias') {
            return $this->to('/domains/' . (int)$d['parent_id']);
        }
        $id = (int)$d['id'];
        return view('domains/show', ['d' => $d,
            'owner' => (string)DB::q('SELECT username FROM users WHERE id = ?', [$d['user_id']])->fetchColumn(),
            'app' => \Havoc\Services\AppService::forDomain($id),
            'counts' => [
                'dbs' => (int)DB::q('SELECT COUNT(*) FROM db_instances WHERE domain_id = ?', [$id])->fetchColumn(),
                'redirects' => (int)DB::q('SELECT COUNT(*) FROM redirects WHERE domain_id = ?', [$id])->fetchColumn(),
                'aliases' => (int)DB::q("SELECT COUNT(*) FROM domains WHERE parent_id = ? AND type = 'alias'", [$id])->fetchColumn(),
                'ini' => count(json_decode((string)($d['php_ini'] ?? ''), true) ?: []),
                'rules' => count(array_filter(explode("\n", (string)($d['nginx_custom'] ?? '')), static fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '#'))),
            ],
            'searchOn' => MeiliService::installed() ? (bool)MeiliService::site($id) : null]);
    }

    /** One settings page of a site. */
    public function section(): Response
    {
        $d = $this->domain();
        if ($d['type'] === 'alias') {
            return $this->to('/domains/' . (int)$d['parent_id']);
        }
        $s = basename($this->req->path);
        in_array($s, self::SECTIONS, true) || $this->abort(404);
        $data = ['d' => $d, 'section' => $s, 'searchOn' => MeiliService::installed()];
        switch ($s) {
            case 'wordpress':
                $data += ['app' => \Havoc\Services\AppService::forDomain((int)$d['id']), 'newApp' => $this->pullNewApp((int)$d['id'])];
                break;
            case 'php':
                $ext = ['available' => [], 'default' => []];
                try {
                    $ext = Ctl::call('php.extensions', ['version' => $d['php_version']]);
                } catch (\Throwable) {
                }
                $data += ['php' => DomainService::phpVersions(), 'ext' => $ext,
                    'enabled' => $d['php_extensions'] ? json_decode($d['php_extensions'], true) : $ext['default']];
                break;
            case 'php-ini':
                $formIni = $_SESSION['form_ini'] ?? null;
                unset($_SESSION['form_ini']);
                $data['ini'] = $formIni ?? (json_decode((string)($d['php_ini'] ?? ''), true) ?: []);
                break;
            case 'nginx':
                $formRules = $_SESSION['form_rules'] ?? null;
                unset($_SESSION['form_rules']);
                $data['rules'] = $formRules ?? (string)($d['nginx_custom'] ?? '');
                break;
            case 'aliases':
                $data['aliases'] = DB::all("SELECT id, name FROM domains WHERE parent_id = ? AND type = 'alias' ORDER BY name", [$d['id']]);
                break;
            case 'redirects':
                $data['redirects'] = DB::all('SELECT * FROM redirects WHERE domain_id = ? ORDER BY id', [$d['id']]);
                break;
            case 'databases':
                $data += $this->dbCard($d);
                break;
            case 'search':
                $data += $this->searchCard($d);
                $data['search'] !== null || $this->abort(404);
                break;
        }
        return view('domains/section', $data);
    }

    /** The site's databases (sizes from MariaDB) and the owner's databases that belong to no site yet. */
    private function dbCard(array $d): array
    {
        $dbs = DB::all('SELECT * FROM db_instances WHERE domain_id = ? ORDER BY name', [$d['id']]);
        $info = [];
        if ($dbs) {
            try {
                $info = Ctl::call('db.info', ['names' => array_column($dbs, 'name')])['databases'] ?? [];
            } catch (\Throwable) {
            }
        }
        return ['dbs' => $dbs, 'dbInfo' => $info, 'pmaOn' => is_file('/opt/havoc-pma/index.php'),
            'freeDbs' => DB::all('SELECT id, name FROM db_instances WHERE user_id = ? AND domain_id IS NULL ORDER BY name', [$d['user_id']])];
    }

    private function searchCard(array $d): array
    {
        if (!MeiliService::installed()) {
            return ['search' => null];
        }
        $site = MeiliService::site((int)$d['id']);
        $info = null;
        if ($site) {
            try {
                $info = MeiliService::info($site);
            } catch (\Throwable $e) {
                $info = ['error' => $e->getMessage()];
            }
        }
        return ['search' => ['site' => $site, 'info' => $info, 'prefix' => MeiliService::prefix($d['name'])]];
    }

    public function search(): Response
    {
        $d = $this->domain();
        if (!MeiliService::installed()) {
            $this->abort(404);
        }
        try {
            match ($this->param('op')) {
                'enable' => MeiliService::enable($this->user(), $d),
                'rotate' => ($s = MeiliService::site((int)$d['id'])) ? MeiliService::rotate($this->user(), $d, $s) : null,
                'disable' => MeiliService::disable($this->user(), $d, (bool)$this->req->post('delete_indexes')),
                default => $this->abort(404),
            };
            $this->flash('success', t('search.done_' . $this->param('op')));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/domains/' . $d['id'] . '/search');
    }

    private function pullNewApp(int $id): ?array
    {
        $n = $_SESSION['new_app'] ?? null;
        if ($n && (int)$n['domain'] === $id) {
            unset($_SESSION['new_app']);
            return $n;
        }
        return null;
    }

    public function php(): Response
    {
        $d = $this->domain();
        $fields = ['php_version' => Validator::php($this->req->str('php')),
            'opcache_enabled' => (int)(bool)$this->req->post('opcache'),
            'opcache_memory' => max(32, min(1024, (int)$this->req->post('opcache_memory', 128)))];
        if ($this->req->post('ext_form')) {
            $sel = array_values(array_filter((array)$this->req->post('ext', []), fn($e) => is_string($e) && preg_match('/^[a-z0-9_]{2,32}$/D', $e)));
            $fields['php_extensions'] = json_encode($sel);
        }
        return $this->apply($d, $fields, '/domains/' . $d['id'] . '/php');
    }

    public function security(): Response
    {
        $d = $this->domain();
        $fields = ['force_https' => (int)(bool)$this->req->post('force_https'), 'hsts' => (int)(bool)$this->req->post('hsts'),
            'waf' => (int)(bool)$this->req->post('waf'), 'logs_enabled' => (int)(bool)$this->req->post('logs_enabled')];
        if (($fields['force_https'] || $fields['hsts']) && $d['ssl_mode'] === 'none') {
            $this->flash('error', t('ssl.need_cert'));
            return $this->to('/domains/' . $d['id'] . '/security');
        }
        return $this->apply($d, $fields, '/domains/' . $d['id'] . '/security');
    }

    public function ssl(): Response
    {
        $d = $this->domain();
        $u = $this->user();
        $mode = $this->req->str('mode');
        if ($mode === 'letsencrypt') {
            // same path as automatic issuance: background job with an HTTP pre-check of every name
            if ($d['type'] === 'alias' || $d['status'] !== 'active') {
                $this->abort(422);
            }
            SslService::queue($d, true, true);
            Audit::log('ssl.request', $d['name']);
            $this->flash('success', t('ssl.auto_started'));
            return $this->to('/domains/' . $d['id'] . '/ssl');
        }
        try {
            if (in_array($mode, ['custom', 'cloudflare'], true)) {
                Ctl::call('ssl.install', ['domain' => $d['name'], 'cert' => (string)$this->req->post('cert', ''),
                    'key' => (string)$this->req->post('key', ''), 'chain' => (string)$this->req->post('chain', '')]);
            } else {
                $this->abort(422);
            }
            DomainService::update($u, $d, ['ssl_mode' => $mode]);
            $this->flash('success', t('ssl.installed'));
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->to('/domains/' . $d['id'] . '/ssl');
    }

    /** Forms on a site page send from_domain: go back to that page of the site (if the user may see it). */
    private function backToSite(string $section): ?Response
    {
        $from = (int)$this->req->post('from_domain', 0);
        return $from && Scope::domain($this->user(), $from) ? $this->to("/domains/$from/$section") : null;
    }

    private function apply(array $d, array $fields, string $back): Response
    {
        try {
            DomainService::update($this->user(), $d, $fields);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->to($back);
    }

    public function delete(): Response
    {
        $d = $this->domain();
        try {
            DomainService::delete($this->user(), $d, (bool)$this->req->post('purge'));
            $this->flash('success', t('common.deleted'));
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->backToSite('aliases') ?? $this->to('/domains');
    }

    /** Old per-domain log page: now part of the central log viewer. */
    public function logs(): Response
    {
        return $this->to('/logs/view?domain_id=' . (int)$this->domain()['id'] . '&type=access');
    }

    public function logPoll(): Response
    {
        $d = $this->domain();
        $type = $this->req->str('type', 'access');
        $off = $this->req->str('offset', '');
        try {
            return Response::json(Ctl::call('log.read', ['domain' => $d['name'], 'type' => $type,
                'offset' => $off === '' ? null : (int)$off, 'lines' => 200], 10));
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }

    public function redirectAdd(): Response
    {
        $d = $this->domain();
        try {
            $row = ['domain_id' => (int)$d['id'], 'source' => Validator::redirectSource($this->req->str('source')),
                'target' => Validator::redirectTarget($this->req->str('target')), 'code' => Validator::redirectCode($this->req->post('code', 301))];
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->to('/domains/' . $d['id'] . '/redirects');
        }
        $id = DB::insert('redirects', $row);
        try {
            DomainService::reapply($d);
            Audit::log('redirect.add', $d['name'], ['source' => $row['source']]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            DB::q('DELETE FROM redirects WHERE id = ?', [$id]);
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
        }
        return $this->to('/domains/' . $d['id'] . '/redirects');
    }

    public function redirectDelete(): Response
    {
        $d = $this->domain();
        DB::q('DELETE FROM redirects WHERE id = ? AND domain_id = ?', [(int)$this->param('rid'), $d['id']]);
        try {
            DomainService::reapply($d);
            Audit::log('redirect.delete', $d['name']);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        return $this->to('/domains/' . $d['id'] . '/redirects');
    }

    /** Per-domain php.ini: whitelisted directives for everyone, extra lines for admins. */
    public function phpIni(): Response
    {
        $d = $this->domain();
        $u = $this->user();
        $in = [];
        foreach (array_keys(\Havoc\Ctl\PhpIni::USER) as $k) {
            $v = $this->req->post('ini_' . str_replace('.', '__', $k));
            if (is_string($v) && trim($v) !== '') {
                $in[$k] = trim($v);
            }
        }
        try {
            $fields = ['php_ini' => json_encode(\Havoc\Ctl\PhpIni::userSettings($in))];
            if ($u['role'] === 'admin') {
                $extra = (string)$this->req->post('ini_admin', '');
                \Havoc\Ctl\PhpIni::adminLines($extra);
                $fields['php_ini_admin'] = $extra;
            }
            DomainService::update($u, $d, $fields);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
            $_SESSION['form_ini'] = $in;
        }
        return $this->to('/domains/' . $d['id'] . '/php-ini');
    }

    /** Per-domain Nginx rules. Users: directive whitelist; admins: free, but every version must pass nginx -t. */
    public function nginxRules(): Response
    {
        $d = $this->domain();
        $u = $this->user();
        $rules = str_replace("\r\n", "\n", (string)$this->req->post('rules', ''));
        $trusted = $u['role'] === 'admin';
        try {
            if (!$trusted && trim($rules) !== '') {
                \Havoc\Ctl\NginxCustom::validateUser($rules);
            }
            DomainService::update($u, $d, ['nginx_custom' => $rules, 'nginx_custom_trusted' => (int)$trusted]);
            $this->flash('success', t('common.saved'));
        } catch (\Throwable $e) {
            $this->flash('error', t('common.failed') . ': ' . $e->getMessage());
            $_SESSION['form_rules'] = $rules;
        }
        return $this->to('/domains/' . $d['id'] . '/nginx');
    }
}
