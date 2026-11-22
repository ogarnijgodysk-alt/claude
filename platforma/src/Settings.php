<?php
declare(strict_types=1);

namespace App;

final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'login_mode' => 'email', // email | magic | password
        'login_notice_on' => '1',
        'login_notice' => 'Platforma kursów została przeniesiona. Żeby wejść do swoich kursów, wpisz adres e-mail, na który je kupiłeś. Hasło nie jest potrzebne.',
        'reset_ttl' => '60',
        'mail_from' => '',
        'mail_from_name' => 'Twoja Akademia',
        'site_name' => 'Twoja Akademia',
        'upload_max_mb' => '100',
        'mail_per_minute' => '60',
    ];

    private static function load(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (Db::all('SELECT k, v FROM settings') as $r) {
                self::$cache[$r['k']] = (string)$r['v'];
            }
        }
        return self::$cache;
    }

    public static function get(string $k, ?string $default = null): string
    {
        $all = self::load();
        return $all[$k] ?? ($default ?? '');
    }

    public static function set(string $k, string $v): void
    {
        Db::q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        self::$cache = null;
    }
}
