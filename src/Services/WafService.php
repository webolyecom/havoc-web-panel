<?php
declare(strict_types=1);

namespace Havoc\Services;

use Havoc\Core\{Ctl, DB, Settings};

final class WafService
{
    public static function mode(): string
    {
        $m = Settings::get('waf.mode', 'off');
        return in_array($m, ['off', 'detect', 'on'], true) ? $m : 'off';
    }

    /** Rebuild the ModSecurity config (global mode + all enabled rules) and reload Nginx. */
    public static function sync(): void
    {
        $rules = DB::all('SELECT r.id, r.type, r.target, r.op, r.value, d.name AS domain FROM waf_rules r LEFT JOIN domains d ON d.id = r.domain_id WHERE r.enabled = 1 ORDER BY r.id');
        Ctl::call('waf.apply', ['mode' => self::mode(), 'rules' => $rules], 90);
    }
}
