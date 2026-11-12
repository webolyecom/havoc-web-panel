<?php
declare(strict_types=1);

use Havoc\Controllers\{ApiController, AuthController, BackupController, BrandingController, CronController, DashboardController, DatabaseController, DnsController, DomainController, FileController, FirewallController, FtpController, LimitsController, LogController, PackageController, ServerController, SettingsController, StackController, TrafficController, UserController, WafController, WebServerController};
use Havoc\Core\Router;

return static function (Router $r): void {
    // auth
    $r->get('/login', [AuthController::class, 'loginForm'], ['guest']);
    $r->post('/login', [AuthController::class, 'login'], ['guest']);
    $r->get('/login/2fa', [AuthController::class, 'twoFaForm'], ['guest']);
    $r->post('/login/2fa', [AuthController::class, 'twoFa'], ['guest']);
    $r->post('/logout', [AuthController::class, 'logout'], ['auth']);
    $r->get('/lang/{code}', [DashboardController::class, 'lang']);

    $r->get('/', [DashboardController::class, 'index'], ['auth']);

    // domains (all tenant-scoped in the controller via Scope)
    $r->get('/domains', [DomainController::class, 'index'], ['auth']);
    $r->post('/domains', [DomainController::class, 'store'], ['auth']);
    $r->post('/domains/settings', [DomainController::class, 'settings'], ['admin']);
    $r->get('/domains/{id}', [DomainController::class, 'show'], ['auth']);
    $r->post('/domains/{id}/php', [DomainController::class, 'php'], ['auth']);
    $r->post('/domains/{id}/security', [DomainController::class, 'security'], ['auth']);
    $r->post('/domains/{id}/php-ini', [DomainController::class, 'phpIni'], ['auth']);
    $r->post('/domains/{id}/nginx', [DomainController::class, 'nginxRules'], ['auth']);
    $r->post('/domains/{id}/ssl', [DomainController::class, 'ssl'], ['auth']);
    $r->post('/domains/{id}/redirects', [DomainController::class, 'redirectAdd'], ['auth']);
    $r->post('/domains/{id}/redirects/{rid}/delete', [DomainController::class, 'redirectDelete'], ['auth']);
    $r->post('/domains/{id}/delete', [DomainController::class, 'delete'], ['auth']);
    $r->get('/domains/{id}/logs', [DomainController::class, 'logs'], ['auth']);
    $r->get('/domains/{id}/logs/poll', [DomainController::class, 'logPoll'], ['auth']);

    // central log management
    $r->get('/logs', [LogController::class, 'index'], ['auth']);
    $r->get('/logs/view', [LogController::class, 'view'], ['auth']);
    $r->get('/logs/poll', [LogController::class, 'poll'], ['auth']);
    $r->get('/logs/download', [LogController::class, 'download'], ['auth']);
    $r->get('/logs/recent', [LogController::class, 'recent'], ['auth']);
    $r->post('/logs/clear', [LogController::class, 'clear'], ['auth']);

    // file manager (isolated to the domain's document root)
    $r->get('/files', [FileController::class, 'pick'], ['auth']);
    $r->get('/domains/{id}/files', [FileController::class, 'index'], ['auth']);
    $r->get('/domains/{id}/files/download', [FileController::class, 'download'], ['auth']);
    $r->post('/domains/{id}/files/api', [FileController::class, 'api'], ['auth']);

    // databases (MariaDB)
    $r->get('/databases', [DatabaseController::class, 'index'], ['auth']);
    $r->post('/databases', [DatabaseController::class, 'store'], ['auth']);
    $r->post('/databases/bind', [DatabaseController::class, 'bind'], ['admin']);
    $r->post('/databases/pma/{op}', [DatabaseController::class, 'pma'], ['admin']);
    $r->get('/databases/tuning', [DatabaseController::class, 'tuning'], ['admin']);
    $r->post('/databases/tuning', [DatabaseController::class, 'tuningSave'], ['admin']);
    $r->post('/databases/{id}/password', [DatabaseController::class, 'password'], ['auth']);
    $r->post('/databases/{id}/remote', [DatabaseController::class, 'remote'], ['auth']);
    $r->post('/databases/{id}/delete', [DatabaseController::class, 'delete'], ['auth']);

    // DNS
    $r->get('/dns', [DnsController::class, 'index'], ['auth']);
    $r->post('/dns/settings', [DnsController::class, 'settings'], ['admin']);
    $r->get('/dns/{id}', [DnsController::class, 'zone'], ['auth']);
    $r->post('/dns/{id}/enable', [DnsController::class, 'enable'], ['auth']);
    $r->post('/dns/{id}/disable', [DnsController::class, 'disable'], ['auth']);
    $r->post('/dns/{id}/records', [DnsController::class, 'recordAdd'], ['auth']);
    $r->post('/dns/{id}/records/{rid}/delete', [DnsController::class, 'recordDelete'], ['auth']);

    // FTP / SFTP accounts
    $r->get('/ftp', [FtpController::class, 'index'], ['auth']);
    $r->post('/ftp', [FtpController::class, 'store'], ['auth']);
    $r->post('/ftp/{id}', [FtpController::class, 'update'], ['auth']);
    $r->post('/ftp/{id}/password', [FtpController::class, 'password'], ['auth']);
    $r->post('/ftp/{id}/delete', [FtpController::class, 'delete'], ['auth']);

    // backups
    $r->get('/backups', [BackupController::class, 'index'], ['auth']);
    $r->post('/backups', [BackupController::class, 'store'], ['auth']);
    $r->post('/backups/targets', [BackupController::class, 'targetStore'], ['auth']);
    $r->post('/backups/targets/{id}/delete', [BackupController::class, 'targetDelete'], ['auth']);
    $r->post('/backups/jobs', [BackupController::class, 'jobStore'], ['auth']);
    $r->post('/backups/jobs/{id}/toggle', [BackupController::class, 'jobToggle'], ['auth']);
    $r->post('/backups/jobs/{id}/delete', [BackupController::class, 'jobDelete'], ['auth']);
    $r->post('/backups/{id}/restore', [BackupController::class, 'restore'], ['auth']);
    $r->post('/backups/{id}/delete', [BackupController::class, 'delete'], ['auth']);

    // cron jobs
    $r->get('/cron', [CronController::class, 'index'], ['auth']);
    $r->post('/cron', [CronController::class, 'store'], ['auth']);
    $r->get('/cron/preview', [CronController::class, 'preview'], ['auth']);
    $r->get('/cron/{id}/log', [CronController::class, 'log'], ['auth']);
    $r->post('/cron/{id}/run', [CronController::class, 'run'], ['auth']);
    $r->post('/cron/{id}', [CronController::class, 'update'], ['auth']);
    $r->post('/cron/{id}/toggle', [CronController::class, 'toggle'], ['auth']);
    $r->post('/cron/{id}/delete', [CronController::class, 'delete'], ['auth']);

    // packages + white-label (admin / reseller)
    $r->get('/packages', [PackageController::class, 'index'], ['reseller']);
    $r->post('/packages', [PackageController::class, 'store'], ['reseller']);
    $r->post('/packages/{id}/delete', [PackageController::class, 'delete'], ['reseller']);
    $r->get('/branding', [BrandingController::class, 'index'], ['reseller']);
    $r->post('/branding', [BrandingController::class, 'save'], ['reseller']);
    $r->get('/brand/{id}/logo', [BrandingController::class, 'logo']);

    // limits / quotas and traffic statistics
    $r->get('/limits', [LimitsController::class, 'index'], ['auth']);
    $r->post('/limits/scan', [LimitsController::class, 'scan'], ['admin']);
    $r->post('/limits/settings', [LimitsController::class, 'settings'], ['admin']);
    $r->get('/traffic', [TrafficController::class, 'index'], ['auth']);
    $r->get('/traffic/export', [TrafficController::class, 'export'], ['auth']);

    // WAF (ModSecurity)
    $r->get('/waf', [WafController::class, 'index'], ['auth']);
    $r->get('/waf/log', [WafController::class, 'log'], ['auth']);
    $r->post('/waf/mode', [WafController::class, 'mode'], ['admin']);
    $r->post('/waf/install', [WafController::class, 'install'], ['admin']);
    $r->post('/waf/domains/{id}', [WafController::class, 'domainToggle'], ['auth']);
    $r->post('/waf/rules', [WafController::class, 'ruleAdd'], ['auth']);
    $r->post('/waf/rules/{id}/toggle', [WafController::class, 'ruleToggle'], ['auth']);
    $r->post('/waf/rules/{id}/delete', [WafController::class, 'ruleDelete'], ['auth']);

    // Redis / Meilisearch / firewall (admin only). Specific routes first.
    $r->get('/stack', [StackController::class, 'index'], ['admin']);
    $r->post('/stack/redis', [StackController::class, 'redisSave'], ['admin']);
    $r->post('/stack/meili/install', [StackController::class, 'meiliInstall'], ['admin']);
    $r->post('/stack/meili/uninstall', [StackController::class, 'meiliUninstall'], ['admin']);
    $r->post('/stack/meili/configure', [StackController::class, 'meiliConfigure'], ['admin']);
    $r->post('/stack/meili/key/{op}', [StackController::class, 'meiliKey'], ['admin']);
    $r->post('/stack/svc/{name}/{op}', [StackController::class, 'toggle'], ['admin']);
    $r->get('/firewall', [FirewallController::class, 'index'], ['admin']);
    $r->post('/firewall/allow', [FirewallController::class, 'allow'], ['admin']);
    $r->post('/firewall/ban', [FirewallController::class, 'ban'], ['admin']);
    $r->post('/firewall/whitelist', [FirewallController::class, 'whitelist'], ['admin']);
    $r->post('/firewall/delete', [FirewallController::class, 'delete'], ['admin']);
    $r->post('/firewall/preset/{name}', [FirewallController::class, 'preset'], ['admin']);
    $r->post('/firewall/{op}', [FirewallController::class, 'toggle'], ['admin']);

    // server (admin only)
    $r->get('/server', [ServerController::class, 'monitor'], ['admin']);
    $r->get('/server/stats', [ServerController::class, 'stats'], ['admin']);
    $r->post('/server/update', [ServerController::class, 'update'], ['admin']);
    $r->get('/server/settings', [ServerController::class, 'settings'], ['admin']);
    $r->get('/server/webserver', [WebServerController::class, 'index'], ['admin']);
    $r->post('/server/webserver/{type}/install', [WebServerController::class, 'install'], ['admin']);
    $r->post('/server/webserver/{type}/activate', [WebServerController::class, 'activate'], ['admin']);
    $r->post('/server/port', [ServerController::class, 'portChange'], ['admin']);
    $r->post('/server/port/confirm', [ServerController::class, 'portConfirm'], ['admin']);
    $r->post('/server/port/revert', [ServerController::class, 'portRevert'], ['admin']);
    $r->get('/services', [ServerController::class, 'services'], ['admin']);
    $r->post('/services/{name}/{op}', [ServerController::class, 'control'], ['admin']);

    // users / resellers
    $r->get('/users', [UserController::class, 'index'], ['reseller']);
    $r->post('/users', [UserController::class, 'store'], ['reseller']);
    $r->post('/users/{id}/status', [UserController::class, 'status'], ['reseller']);
    $r->post('/users/{id}/package', [UserController::class, 'package'], ['reseller']);

    // settings / 2FA / API tokens
    $r->get('/settings', [SettingsController::class, 'index'], ['auth']);
    $r->post('/settings/profile', [SettingsController::class, 'profile'], ['auth']);
    $r->post('/settings/password', [SettingsController::class, 'password'], ['auth']);
    $r->post('/settings/2fa/enable', [SettingsController::class, 'enable2fa'], ['auth']);
    $r->post('/settings/2fa/disable', [SettingsController::class, 'disable2fa'], ['auth']);
    $r->post('/settings/tokens', [SettingsController::class, 'tokenCreate'], ['auth']);
    $r->post('/settings/tokens/{id}/delete', [SettingsController::class, 'tokenDelete'], ['auth']);

    // REST API v1 (Bearer token, no cookies, no CSRF)
    $r->get('/api/v1/openapi.json', [ApiController::class, 'openapi']);
    $r->get('/api/v1/me', [ApiController::class, 'me'], ['api']);
    $r->get('/api/v1/domains', [ApiController::class, 'domains'], ['api']);
    $r->post('/api/v1/domains', [ApiController::class, 'domainCreate'], ['api']);
    $r->get('/api/v1/domains/{id}', [ApiController::class, 'domain'], ['api']);
    $r->delete('/api/v1/domains/{id}', [ApiController::class, 'domainDelete'], ['api']);
    $r->get('/api/v1/server/stats', [ApiController::class, 'stats'], ['apiadmin']);
};
