<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Ctl, DB};
use Havoc\Ctl\CronExpr;

final class CronService
{
    /** Ready-made schedules shown as one-click chips (value => translation key). */
    public const PRESETS = [
        '* * * * *' => 'cron.p_1m', '*/2 * * * *' => 'cron.p_2m', '*/5 * * * *' => 'cron.p_5m', '*/10 * * * *' => 'cron.p_10m',
        '*/15 * * * *' => 'cron.p_15m', '*/30 * * * *' => 'cron.p_30m', '0 * * * *' => 'cron.p_1h', '0 */2 * * *' => 'cron.p_2h',
        '0 */6 * * *' => 'cron.p_6h', '0 */12 * * *' => 'cron.p_12h', '0 0 * * *' => 'cron.p_midnight', '0 3 * * *' => 'cron.p_daily3',
        '0 3 * * 0' => 'cron.p_weekly', '0 3 1 * *' => 'cron.p_monthly',
    ];

    /** Push all enabled jobs of a user to the system crontab of its Linux account. */
    public static function sync(int $userId): void
    {
        $jobs = DB::all('SELECT j.id, j.schedule, j.command, j.log_output AS log, d.name AS domain FROM cron_jobs j LEFT JOIN domains d ON d.id = j.domain_id
                         WHERE j.user_id = ? AND j.enabled = 1 ORDER BY j.id', [$userId]);
        Ctl::call('cron.apply', ['account' => 'hv' . $userId, 'jobs' => $jobs]);
    }

    /** The time zone cron uses: the system's (/etc/localtime), not PHP's default. */
    public static function timezone(): \DateTimeZone
    {
        static $tz = null;
        if ($tz === null) {
            $link = @readlink('/etc/localtime');
            $name = $link && preg_match('#zoneinfo/(.+)$#', $link, $m) ? $m[1] : trim((string)@file_get_contents('/etc/timezone'));
            try {
                $tz = new \DateTimeZone($name ?: 'UTC');
            } catch (\Throwable) {
                $tz = new \DateTimeZone('UTC');
            }
        }
        return $tz;
    }

    /** @return array{text:string,next:string[]} human description + next run times, both in the server's time zone */
    public static function explain(string $expr, int $count = 3): array
    {
        try {
            $e = CronExpr::parse($expr);
        } catch (\InvalidArgumentException $x) {
            return ['text' => $x->getMessage(), 'next' => []];
        }
        [$kind, $p] = $e->describe();
        if (isset($p['days'])) {
            $p['days'] = implode(', ', array_map(static fn($d) => t('cron.dow_' . $d), $p['days']));
        }
        $text = $kind === 'custom' ? t('cron.d_custom') : t('cron.d_' . $kind, $p);
        $next = array_map(static fn($d) => $d->format('D d.m H:i'), $e->next(new \DateTimeImmutable('now', self::timezone()), $count));
        return ['text' => $text, 'next' => $next];
    }
}
