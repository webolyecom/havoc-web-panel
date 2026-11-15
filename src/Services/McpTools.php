<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Audit, Ctl, DB, Scope};
use Havoc\Ctl\Validator;

/**
 * The tools the Claude connector offers. Every call runs as the panel user who approved the connection and goes through
 * the same services and Scope checks as the web interface. Nothing here can delete sites, databases or files.
 */
final class McpTools
{
    private const MAX_READ  = 262144;          // 256 KB of text per read_file
    private const MAX_WRITE = 8388608;         // 8 MB per write_file

    public function __construct(private array $user, private array $scopes)
    {
    }

    /** Tool definitions for tools/list. */
    public static function definitions(): array
    {
        $domain = ['type' => 'string', 'description' => 'Domain name as shown in list_domains, e.g. example.com'];
        $path = ['type' => 'string', 'description' => 'Path relative to the website root (public_html), e.g. "index.html", "assets/css/site.css". Empty = the root.'];
        $t = static fn(string $name, string $title, string $desc, array $props, array $req, bool $write, bool $idem = true) => [
            'name' => $name, 'title' => $title, 'description' => $desc,
            'inputSchema' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req, 'additionalProperties' => false],
            'annotations' => ['title' => $title, 'readOnlyHint' => !$write, 'destructiveHint' => false, 'idempotentHint' => $idem, 'openWorldHint' => false],
        ];
        return [
            $t('whoami', 'Current panel account', 'The Havoc WEB Panel account this connection acts as, its role and what it may do.', [], [], false),
            $t('list_domains', 'List websites', 'All websites (domains and subdomains) this account manages, with PHP version, SSL state and installed application.', [], [], false),
            $t('get_domain', 'Website details', 'Details of one website: PHP version, SSL/HTTPS, aliases, redirects, installed application.', ['domain' => $domain], ['domain'], false),
            $t('list_php_versions', 'PHP versions', 'PHP versions available on this server.', [], [], false),
            $t('list_files', 'List files', 'Files and folders in a website folder.', ['domain' => $domain, 'path' => $path], ['domain'], false),
            $t('read_file', 'Read file', 'Text of a file, at most 800 lines per call; the answer says total_lines. To save tokens find the place with search_files first and read only those lines (start_line/end_line).', ['domain' => $domain, 'path' => $path,
                'start_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'First line (1-based). Default 1.'],
                'end_line' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Last line (inclusive).']], ['domain', 'path'], false),
            $t('search_files', 'Search in files', 'Finds text in the website\'s files and returns "path:line: text" matches. Skips binary files, files over 2 MB, .git and node_modules.', ['domain' => $domain,
                'query' => ['type' => 'string', 'description' => 'Text to find (or a regular expression when regex is true)'],
                'path' => ['type' => 'string', 'description' => 'Folder to search in, relative to the website root. Default: whole site.'],
                'file_pattern' => ['type' => 'string', 'description' => 'Only file names matching this pattern, e.g. *.php'],
                'regex' => ['type' => 'boolean', 'default' => false], 'case_insensitive' => ['type' => 'boolean', 'default' => false],
                'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 300, 'default' => 100]], ['domain', 'query'], false),
            $t('list_file_history', 'File history', 'Earlier versions Claude\'s changes kept of a file (or, without path, which files have versions). Use restore_file to go back.', ['domain' => $domain,
                'path' => ['type' => 'string', 'description' => 'File path. Empty: list the files that have versions.']], ['domain'], false),
            $t('read_log', 'Read website log', 'Last lines of a website log.', ['domain' => $domain,
                'type' => ['type' => 'string', 'enum' => ['access', 'error', 'php'], 'description' => 'access, error (web server) or php (PHP errors)'],
                'lines' => ['type' => 'integer', 'minimum' => 10, 'maximum' => 500, 'default' => 100]], ['domain', 'type'], false),
            $t('list_databases', 'List databases', 'MariaDB databases of this account.', [], [], false),
            $t('get_app_status', 'Application status', 'Installation state of the application (WordPress) on a website.', ['domain' => $domain], ['domain'], false),

            $t('add_domain', 'Add website', 'Adds a website (domain) or a subdomain. Its DNS must point to this server for SSL. A Let\'s Encrypt certificate is requested automatically.', [
                'domain' => ['type' => 'string', 'description' => 'New domain, e.g. example.com or blog.example.com'],
                'php_version' => ['type' => 'string', 'description' => 'PHP version (see list_php_versions). Default: the newest.'],
                'parent_domain' => ['type' => 'string', 'description' => 'For a subdomain: the existing domain it belongs to, e.g. example.com'],
                'owner' => ['type' => 'string', 'description' => 'Administrators and resellers only: username of the customer account that will own the site. Default: this account.'],
            ], ['domain'], true, false),
            $t('install_wordpress', 'Install WordPress', 'Installs the latest WordPress on an EMPTY website (new database, admin account, pretty permalinks). Returns the admin login; installation finishes in about a minute (check get_app_status).', [
                'domain' => $domain,
                'site_title' => ['type' => 'string', 'description' => 'Site title. Default: the domain.'],
                'admin_user' => ['type' => 'string', 'description' => 'Administrator username (3-40 characters). Default: wpadmin'],
                'admin_email' => ['type' => 'string', 'description' => 'Administrator e-mail. Default: the account e-mail.'],
                'language' => ['type' => 'string', 'enum' => array_keys(AppService::LOCALES), 'description' => 'WordPress language. Default: en_US'],
            ], ['domain'], true, false),
            $t('edit_file', 'Edit file', 'Changes part of a text file by exact text replacement: the cheapest way to update code. Each "old" must match the file exactly (spaces, line breaks) and occur once, unless replace_all is true. All edits are applied or none. The previous version is kept (restore_file).', [
                'domain' => $domain, 'path' => $path,
                'edits' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => ['type' => 'object', 'properties' => (object)[
                    'old' => ['type' => 'string', 'description' => 'Exact current text, with enough surrounding lines to be unique'],
                    'new' => ['type' => 'string', 'description' => 'Replacement text'],
                    'replace_all' => ['type' => 'boolean', 'default' => false]], 'required' => ['old', 'new'], 'additionalProperties' => false]],
            ], ['domain', 'path', 'edits'], true, false),
            $t('write_file', 'Write file', 'Creates or replaces one whole file (folders are created). For changes to an existing file use edit_file instead. Use encoding "base64" for binary files; for large files or images prefer download_file or extract_archive. The previous version is kept.', [
                'domain' => $domain, 'path' => $path,
                'content' => ['type' => 'string', 'description' => 'File content (text, or base64 when encoding is base64). Max 8 MB.'],
                'encoding' => ['type' => 'string', 'enum' => ['text', 'base64'], 'default' => 'text'],
            ], ['domain', 'path', 'content'], true),
            $t('write_files', 'Write several files', 'Creates or replaces up to 200 files in one call (16 MB in total); all paths are checked before anything is written. Previous versions are kept.', [
                'domain' => $domain,
                'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 200, 'items' => ['type' => 'object', 'properties' => (object)[
                    'path' => ['type' => 'string'], 'content' => ['type' => 'string'], 'encoding' => ['type' => 'string', 'enum' => ['text', 'base64'], 'default' => 'text']],
                    'required' => ['path', 'content'], 'additionalProperties' => false]],
            ], ['domain', 'files'], true),
            $t('extract_archive', 'Extract archive', 'Extracts a .zip, .tar.gz, .tgz or .tar that is already in the website (e.g. uploaded by the user in the panel\'s file manager). Files are not sent through Claude, so this costs almost no tokens. Existing files are only replaced with overwrite=true (their previous versions are kept). Archives with links or unsafe paths are refused.', [
                'domain' => $domain,
                'path' => ['type' => 'string', 'description' => 'Archive path relative to the website root, e.g. "site.zip"'],
                'destination' => ['type' => 'string', 'description' => 'Folder to extract into, relative to the website root. Default: the website root.'],
                'strip_top_folder' => ['type' => 'boolean', 'default' => true, 'description' => 'If everything is inside one top folder (e.g. "mysite/"), extract its contents directly'],
                'overwrite' => ['type' => 'boolean', 'default' => false],
            ], ['domain', 'path'], true, false),
            $t('download_file', 'Download file into website', 'Downloads an https:// URL (for example a private, time-limited link to an archive) straight into the website, without passing the content through Claude. Follow with extract_archive for archives. Max 1 GB; private and internal addresses are refused.', [
                'domain' => $domain,
                'url' => ['type' => 'string', 'description' => 'https:// address'],
                'path' => ['type' => 'string', 'description' => 'Target file path relative to the website root, e.g. "release.zip"'],
                'overwrite' => ['type' => 'boolean', 'default' => false],
            ], ['domain', 'url', 'path'], true, false),
            $t('restore_file', 'Restore file version', 'Puts back an earlier version of a file (see list_file_history). The current content is kept as a version too, so this can be undone.', [
                'domain' => $domain, 'path' => $path, 'version' => ['type' => 'string', 'description' => 'Version id from list_file_history'],
            ], ['domain', 'path', 'version'], true, false),
            $t('create_directory', 'Create folder', 'Creates a folder (and missing parent folders) in a website.', ['domain' => $domain, 'path' => $path], ['domain', 'path'], true),
            $t('update_domain', 'Change website settings', 'Changes the PHP version and/or HTTPS redirection of a website.', ['domain' => $domain,
                'php_version' => ['type' => 'string', 'description' => 'New PHP version'],
                'force_https' => ['type' => 'boolean', 'description' => 'Redirect http:// to https:// (needs a certificate)'],
            ], ['domain'], true),
            $t('issue_ssl', 'Request SSL certificate', 'Requests (or retries) a free Let\'s Encrypt certificate for a website. The domain must point to this server.', ['domain' => $domain], ['domain'], true),
            $t('create_database', 'Create database', 'Creates a MariaDB database with its own user and returns the credentials (shown only once).', [
                'name' => ['type' => 'string', 'description' => 'Short name: 1-16 lowercase letters, digits or _. The full name gets the account prefix (e.g. hv3_shop).'],
            ], ['name'], true, false),
        ];
    }

    public static function isWrite(string $tool): bool
    {
        foreach (self::definitions() as $d) {
            if ($d['name'] === $tool) {
                return !$d['annotations']['readOnlyHint'];
            }
        }
        return true;
    }

    /** @return array structured result; throws \Throwable with a user-facing message on errors */
    public function call(string $tool, array $a): array
    {
        if (!method_exists($this, 't_' . $tool) || !in_array($tool, array_column(self::definitions(), 'name'), true)) {
            throw new \InvalidArgumentException("Unknown tool: $tool");
        }
        if (self::isWrite($tool) && !in_array('write', $this->scopes, true)) {
            throw new \DomainException('This connection was approved for reading only. Reconnect and allow changes to use ' . $tool . '.');
        }
        $res = $this->{'t_' . $tool}($a);
        if (self::isWrite($tool)) {
            $target = (string)($a['domain'] ?? $a['name'] ?? '');
            $meta = array_intersect_key($a, array_flip(['path', 'php_version', 'force_https', 'language', 'parent_domain', 'owner', 'encoding', 'destination', 'overwrite', 'url', 'version']));
            if (isset($a['files']) && is_array($a['files'])) {
                $meta['files'] = count($a['files']);
            }
            Audit::log('claude.' . $tool, $target, $meta, $this->user);
        }
        return $res;
    }

    private function d(array $a): array
    {
        $name = strtolower(trim((string)($a['domain'] ?? '')));
        $row = $name === '' ? null : DB::one('SELECT * FROM domains WHERE name = ?', [$name]);
        $d = $row ? Scope::domain($this->user, (int)$row['id']) : null;
        if (!$d) {
            throw new \DomainException("Website $name was not found in this account (see list_domains).");
        }
        if ($d['type'] === 'alias') {
            $d = Scope::domain($this->user, (int)$d['parent_id']) ?? throw new \DomainException("Website $name was not found.");
        }
        return $d;
    }

    private function fs(array $d, string $op, array $args): array
    {
        return Ctl::call('fs.exec', ['account' => 'hv' . $d['user_id'], 'domain' => $d['name'], 'op' => $op, 'args' => $args], 300) ?: [];
    }

    private static function rel(mixed $p, bool $allowRoot = true): string
    {
        $p = trim(str_replace('\\', '/', (string)$p), '/');
        if ($p === '' && $allowRoot) {
            return '';
        }
        if ($p === '' || str_contains($p, "\0") || preg_match('#(^|/)\.\.?(/|$)#', $p) || strlen($p) > 1024) {
            throw new \InvalidArgumentException('Invalid path: use a path inside the website folder without "." or ".." parts.');
        }
        return $p;
    }

    private function present(array $d): array
    {
        $app = AppService::forDomain((int)$d['id']);
        $https = in_array($d['ssl_mode'], ['letsencrypt', 'custom', 'cloudflare'], true) || $d['ssl_auto'] === 'issued';
        return ['domain' => $d['name'], 'type' => $d['type'], 'owner' => $d['username'] ?? null, 'status' => $d['status'],
            'php_version' => $d['php_version'], 'url' => ($https ? 'https://' : 'http://') . $d['name'],
            'ssl' => ['certificate' => $d['ssl_mode'], 'automatic' => $d['ssl_auto'] ?: null, 'note' => $d['ssl_auto_note'] ?: null],
            'force_https' => (bool)$d['force_https'],
            'application' => $app ? ['app' => $app['app'], 'status' => $app['status'], 'version' => $app['version']] : null];
    }

    private function t_whoami(array $a): array
    {
        $u = $this->user;
        $pkg = $u['package_id'] ? DB::one('SELECT name, max_domains, max_databases, disk_mb FROM packages WHERE id = ?', [$u['package_id']]) : null;
        return ['username' => $u['username'], 'role' => $u['role'], 'email' => $u['email'], 'permissions' => $this->scopes, 'package' => $pkg,
            'panel' => 'Havoc WEB Panel ' . UpdateService::current(),
            'can_not' => 'delete websites, databases or files (do that in the panel)'];
    }

    private function t_list_domains(array $a): array
    {
        return ['websites' => array_map($this->present(...), array_values(array_filter(Scope::domains($this->user), fn($d) => $d['type'] !== 'alias')))];
    }

    private function t_get_domain(array $a): array
    {
        $d = $this->d($a);
        return $this->present($d) + [
            'aliases' => array_column(DB::all("SELECT name FROM domains WHERE parent_id = ? AND type = 'alias' ORDER BY name", [$d['id']]), 'name'),
            'redirects' => DB::all('SELECT source, target, code FROM redirects WHERE domain_id = ? ORDER BY id', [$d['id']]),
            'document_root' => 'public_html (paths in the file tools are relative to it)',
        ];
    }

    private function t_list_php_versions(array $a): array
    {
        return ['versions' => DomainService::phpVersions()];
    }

    private function t_list_files(array $a): array
    {
        $d = $this->d($a);
        $rel = self::rel($a['path'] ?? '');
        $items = $this->fs($d, 'list', ['path' => $rel]);
        $items = $items['items'] ?? $items;
        return ['domain' => $d['name'], 'path' => $rel === '' ? '/' : $rel, 'entries' => array_map(static fn($i) => [
            'name' => $i['name'], 'type' => $i['dir'] ? 'folder' : ($i['link'] ? 'link' : 'file'), 'size' => $i['size'],
            'modified' => gmdate('Y-m-d H:i', (int)$i['mtime']) . ' UTC'], array_slice((array)$items, 0, 1000))];
    }

    private function t_read_file(array $a): array
    {
        $d = $this->d($a);
        $rel = self::rel($a['path'] ?? '', false);
        $from = max(1, (int)($a['start_line'] ?? 1));
        $to = isset($a['end_line']) ? max($from, (int)$a['end_line']) : 0;
        return $this->fs($d, 'readrange', ['path' => $rel, 'from' => $from, 'to' => $to]);
    }

    private function t_search_files(array $a): array
    {
        $d = $this->d($a);
        return $this->fs($d, 'grep', ['path' => self::rel($a['path'] ?? ''), 'q' => (string)($a['query'] ?? ''), 'regex' => !empty($a['regex']),
            'icase' => !empty($a['case_insensitive']), 'glob' => (string)($a['file_pattern'] ?? ''), 'max' => (int)($a['max_results'] ?? 100)]);
    }

    private function t_list_file_history(array $a): array
    {
        $d = $this->d($a);
        $p = (string)($a['path'] ?? '');
        return $this->fs($d, 'hist_list', ['path' => $p === '' ? '' : self::rel($p, false)]);
    }

    private function t_edit_file(array $a): array
    {
        $d = $this->d($a);
        return $this->fs($d, 'edit', ['path' => self::rel($a['path'] ?? '', false), 'edits' => array_values((array)($a['edits'] ?? []))]);
    }

    private function t_write_files(array $a): array
    {
        $d = $this->d($a);
        $files = [];
        foreach ((array)($a['files'] ?? []) as $f) {
            $files[] = ['path' => self::rel($f['path'] ?? '', false), 'content' => (string)($f['content'] ?? ''), 'encoding' => ($f['encoding'] ?? 'text') === 'base64' ? 'base64' : 'text'];
        }
        return $this->fs($d, 'putmany', ['files' => $files]);
    }

    private function t_extract_archive(array $a): array
    {
        $d = $this->d($a);
        return $this->fs($d, 'extract', ['path' => self::rel($a['path'] ?? '', false), 'dest' => self::rel($a['destination'] ?? ''),
            'overwrite' => !empty($a['overwrite']), 'strip' => ($a['strip_top_folder'] ?? true) !== false]);
    }

    private function t_download_file(array $a): array
    {
        $d = $this->d($a);
        return $this->fs($d, 'fetch', ['url' => (string)($a['url'] ?? ''), 'path' => self::rel($a['path'] ?? '', false), 'overwrite' => !empty($a['overwrite'])]);
    }

    private function t_restore_file(array $a): array
    {
        $d = $this->d($a);
        return $this->fs($d, 'hist_restore', ['path' => self::rel($a['path'] ?? '', false), 'version' => (string)($a['version'] ?? '')]);
    }

    private function t_read_log(array $a): array
    {
        $d = $this->d($a);
        $type = (string)($a['type'] ?? 'error');
        if (!in_array($type, ['access', 'error', 'php'], true)) {
            throw new \InvalidArgumentException('type must be access, error or php');
        }
        $r = Ctl::call('log.read', ['domain' => $d['name'], 'type' => $type, 'lines' => max(10, min(500, (int)($a['lines'] ?? 100)))], 20);
        return ['domain' => $d['name'], 'log' => $type, 'lines' => (string)($r['data'] ?? '')];
    }

    private function t_list_databases(array $a): array
    {
        $ids = Scope::userIds($this->user);
        $rows = $ids === null ? DB::all('SELECT name, user_id FROM db_instances ORDER BY name')
            : ($ids ? DB::all('SELECT name, user_id FROM db_instances WHERE user_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY name') : []);
        return ['databases' => array_map(static fn($r) => ['name' => $r['name'], 'user' => $r['name'], 'host' => 'localhost'], $rows)];
    }

    private function t_get_app_status(array $a): array
    {
        $d = $this->d($a);
        $app = AppService::forDomain((int)$d['id']);
        if (!$app) {
            return ['domain' => $d['name'], 'application' => null];
        }
        return ['domain' => $d['name'], 'application' => $app['app'], 'status' => $app['status'], 'version' => $app['version'],
            'admin_user' => $app['admin_user'], 'admin_url' => $app['admin_url'], 'database' => $app['db_name'],
            'error' => $app['status'] === 'failed' ? $app['note'] : null,
            'note' => $app['note'] === 'http' ? 'Installed with http:// because the site had no certificate yet; change the WordPress and site address to https:// after SSL is issued.' : null];
    }

    private function t_add_domain(array $a): array
    {
        $name = strtolower(trim((string)($a['domain'] ?? '')));
        $ownerId = (int)$this->user['id'];
        if (!empty($a['owner']) && $this->user['role'] !== 'user') {
            $o = DB::one('SELECT * FROM users WHERE username = ?', [(string)$a['owner']]);
            if (!$o || !Scope::canManageUser($this->user, $o)) {
                throw new \DomainException('Account ' . $a['owner'] . ' was not found or is not managed by you.');
            }
            $ownerId = (int)$o['id'];
        }
        $versions = DomainService::phpVersions();
        $php = (string)($a['php_version'] ?? '') ?: (string)end($versions);
        $type = 'main';
        $parentId = null;
        if (!empty($a['parent_domain'])) {
            $p = $this->d(['domain' => $a['parent_domain']]);
            $type = 'sub';
            $parentId = (int)$p['id'];
            $ownerId = (int)$p['user_id'];
        }
        try {
            $d = DomainService::create($this->user, $ownerId, $name, $php, $type, $parentId);
        } catch (\DomainException $e) {
            throw new \DomainException(match ($e->getMessage()) {
                'domain_exists' => "$name already exists on this server.",
                'quota_domains' => 'The website limit of the account package is reached.',
                'sub_mismatch' => "$name is not a subdomain of {$a['parent_domain']}.",
                'parent_invalid' => 'The parent domain cannot have subdomains.',
                default => $e->getMessage(),
            });
        }
        $d = Scope::domain($this->user, (int)$d['id']) ?? $d;
        return $this->present($d) + ['next' => 'Point the domain\'s A record to this server if it does not yet. SSL is requested automatically; check with get_domain.'];
    }

    private function t_install_wordpress(array $a): array
    {
        $d = $this->d($a);
        try {
            $r = AppService::startWordPress($this->user, $d, ['title' => (string)($a['site_title'] ?? ''), 'admin_user' => (string)($a['admin_user'] ?? ''),
                'admin_email' => (string)($a['admin_email'] ?? ''), 'locale' => (string)($a['language'] ?? 'en_US')], 'claude');
        } catch (\DomainException $e) {
            throw new \DomainException(t($e->getMessage()) !== $e->getMessage() ? t($e->getMessage()) : $e->getMessage());
        }
        return ['domain' => $d['name'], 'status' => 'installing', 'admin_url' => $r['admin_url'], 'admin_user' => $r['admin_user'],
            'admin_password' => $r['admin_password'], 'database' => $r['database'],
            'important' => 'Give the user this password now: it is not stored and cannot be shown again. The site folder had to be empty; installation takes about a minute (get_app_status).'];
    }

    private function t_write_file(array $a): array
    {
        $d = $this->d($a);
        $rel = self::rel($a['path'] ?? '', false);
        $b64 = ($a['encoding'] ?? 'text') === 'base64';
        $content = (string)($a['content'] ?? '');
        if (strlen($content) > ($b64 ? 11500000 : self::MAX_WRITE)) {
            throw new \InvalidArgumentException('File too large (max 8 MB); use download_file or extract_archive');
        }
        return $this->fs($d, 'put', ['path' => $rel, 'content' => $content, 'encoding' => $b64 ? 'base64' : 'text']);
    }

    private function t_create_directory(array $a): array
    {
        $d = $this->d($a);
        return $this->fs($d, 'mkdirp', ['path' => self::rel($a['path'] ?? '', false)]);
    }

    private function t_update_domain(array $a): array
    {
        $d = $this->d($a);
        $f = [];
        if (isset($a['php_version']) && $a['php_version'] !== '') {
            $f['php_version'] = Validator::php((string)$a['php_version']);
        }
        if (isset($a['force_https'])) {
            $f['force_https'] = (int)(bool)$a['force_https'];
        }
        if (!$f) {
            throw new \InvalidArgumentException('Nothing to change: give php_version and/or force_https.');
        }
        DomainService::update($this->user, $d, $f);
        return $this->present(Scope::domain($this->user, (int)$d['id']) ?? $d);
    }

    private function t_issue_ssl(array $a): array
    {
        $d = $this->d($a);
        if ($d['ssl_auto'] === 'running') {
            return ['domain' => $d['name'], 'status' => 'already running'];
        }
        SslService::queue($d, true, false);
        return ['domain' => $d['name'], 'status' => 'requested', 'next' => 'Takes up to a minute; check with get_domain (ssl.automatic becomes "issued", or a note explains what to fix).'];
    }

    private function t_create_database(array $a): array
    {
        try {
            $db = DatabaseService::create($this->user, (int)$this->user['id'], (string)($a['name'] ?? ''));
        } catch (\DomainException $e) {
            throw new \DomainException(match ($e->getMessage()) {
                'db.invalid' => 'Name: 1-16 lowercase letters, digits or _.',
                'db.quota' => 'The database limit of the account package is reached.',
                'db.exists' => 'A database with this name already exists.',
                default => $e->getMessage(),
            });
        }
        return ['database' => $db['name'], 'user' => $db['user'], 'password' => $db['password'], 'host' => 'localhost',
            'important' => 'Give the user this password now: it is not stored and cannot be shown again.'];
    }
}
