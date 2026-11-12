<?php
declare(strict_types=1);

namespace Havoc\Core;

/** White-label: resolved by panel domain, otherwise by the logged-in user's reseller. */
final class Branding
{
    public static function resolve(Request $req, ?array $user): array
    {
        $default = ['name' => Config::get('app.name'), 'logo' => null, 'color' => '#0b7fa0', 'rid' => null];
        $row = DB::one('SELECT * FROM branding WHERE panel_domain = ?', [$req->host()]);
        if (!$row && $user) {
            $rid = in_array($user['role'], ['admin', 'reseller'], true) ? $user['id'] : $user['parent_id'];
            $row = $rid ? DB::one('SELECT * FROM branding WHERE reseller_id = ?', [$rid]) : null;
        }
        if (!$row) {
            return $default + ['rgb' => self::rgb($default['color'])];
        }
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string)$row['primary_color']) ? $row['primary_color'] : $default['color'];
        return ['name' => $row['brand_name'] ?: $default['name'], 'logo' => $row['logo_path'], 'rid' => (int)$row['reseller_id'], 'color' => $color, 'rgb' => self::rgb($color)];
    }

    private static function rgb(string $hex): string
    {
        return hexdec(substr($hex, 1, 2)) . ' ' . hexdec(substr($hex, 3, 2)) . ' ' . hexdec(substr($hex, 5, 2));
    }
}
