<?php
declare(strict_types=1);

namespace App;

/** Limity prób (per adres e-mail i per IP) oraz dziennik zdarzeń bezpieczeństwa. */
final class Security
{
    public static function hit(string $bucket, string $subject): void
    {
        Db::q('INSERT INTO rate_limits (bucket, subject, created_at) VALUES (?, ?, ?)', [$bucket, hash('sha256', mb_strtolower($subject)), time()]);
    }

    public static function count(string $bucket, string $subject, int $windowSec): int
    {
        return (int)Db::val('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND subject = ? AND created_at > ?', [$bucket, hash('sha256', mb_strtolower($subject)), time() - $windowSec]);
    }

    public static function tooMany(string $bucket, string $subject, int $max, int $windowSec): bool
    {
        return self::count($bucket, $subject, $windowSec) >= $max;
    }

    public static function clear(string $bucket, string $subject): void
    {
        Db::exec('DELETE FROM rate_limits WHERE bucket = ? AND subject = ?', [$bucket, hash('sha256', mb_strtolower($subject))]);
    }

    public static function purge(): void
    {
        Db::exec('DELETE FROM rate_limits WHERE created_at < ?', [time() - 86400]);
        Db::exec('DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL AND used_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 2), date('Y-m-d H:i:s', time() - 86400 * 2)]);
        Db::exec('DELETE FROM security_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 365)]);
    }

    public static function log(string $event, ?int $userId = null, ?string $email = null, ?string $ip = null, string $detail = ''): void
    {
        try {
            Db::insert('security_log', ['event' => $event, 'user_id' => $userId, 'email' => $email !== null ? mb_substr($email, 0, 190) : null, 'ip' => $ip, 'detail' => mb_substr($detail, 0, 500), 'created_at' => now()]);
        } catch (\Throwable) {
            // logowanie nie może przerwać żądania
        }
    }
}
