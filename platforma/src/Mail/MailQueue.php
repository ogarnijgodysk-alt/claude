<?php
declare(strict_types=1);

namespace App\Mail;

use App\Db;
use App\Settings;

final class MailQueue
{
    public static function enqueue(?int $userId, string $to, string $kind, string $subject, string $text, ?string $html = null, ?string $sendAfter = null): int
    {
        return Db::insert('mail_queue', [
            'user_id' => $userId, 'to_email' => $to, 'kind' => $kind, 'subject' => $subject,
            'body_text' => $text, 'body_html' => $html, 'status' => 'pending', 'attempts' => 0,
            'send_after' => $sendAfter ?? now(), 'created_at' => now(),
        ]);
    }

    public static function from(): array
    {
        $from = Settings::get('mail_from');
        if ($from === '') {
            $from = (string)\App\Config::get('mail.from', '');
        }
        if ($from === '') {
            $host = (string)parse_url(abs_url('/'), PHP_URL_HOST);
            $from = 'kursy@' . ($host ?: 'localhost');
        }
        return [$from, Settings::get('mail_from_name', 'Twoja Akademia')];
    }

    /**
     * Wysyła do $limit wiadomości. $interactive = true: krótka wysyłka w trakcie żądania WWW
     * (tylko wiadomości z tego żądania: priorytet dla najnowszej zakolejkowanej).
     * @return array{sent:int,failed:int}
     */
    public static function flush(int $limit, bool $interactive = false, array $only = []): array
    {
        [$from, $fromName] = self::from();
        // kolejka jednego rodzaju na raz nie blokuje resetów: najpierw wiadomości transakcyjne
        $onlySql = $only ? ' AND id IN (' . implode(',', array_map('intval', $only)) . ')' : '';
        $rows = Db::all("SELECT * FROM mail_queue WHERE status = 'pending' AND send_after <= ?" . $onlySql . " ORDER BY (kind IN ('reset','login')) DESC, " . ($interactive ? 'id DESC' : 'id ASC') . ' LIMIT ' . (int)$limit, [now()]);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $m) {
            // „zajmij” wiadomość, żeby równoległe procesy jej nie wysłały drugi raz
            if (Db::exec("UPDATE mail_queue SET attempts = attempts + 1, send_after = ? WHERE id = ? AND status = 'pending' AND attempts = ?", [date('Y-m-d H:i:s', time() + 300), $m['id'], $m['attempts']]) !== 1) {
                continue;
            }
            if ($m['user_id'] && in_array($m['kind'], ['reset', 'invite', 'login'], true)) {
                $cur = Db::val('SELECT email FROM users WHERE id = ?', [$m['user_id']]);
                if ($cur === null || mb_strtolower((string)$cur) !== mb_strtolower($m['to_email'])) {
                    Db::update('mail_queue', ['status' => 'failed', 'last_error' => 'Adres konta zmienił się po zakolejkowaniu'], 'id = ?', [$m['id']]);
                    continue;
                }
            }
            try {
                $text = $m['body_text'];
                $html = $m['body_html'];
                if (str_contains($text, '{{LINK}}') && $m['user_id']) {
                    // token ważny od chwili wysyłki, nie od zakolejkowania
                    $link = abs_url('/haslo/' . \App\Auth::issueToken((int)$m['user_id']));
                    $text = str_replace('{{LINK}}', $link, $text);
                    $html = $html !== null ? str_replace('{{LINK}}', htmlspecialchars($link, ENT_QUOTES, 'UTF-8'), $html) : null;
                }
                Smtp::send($m['to_email'], $m['subject'], $text, $html, $from, $fromName);
                Db::update('mail_queue', ['status' => 'sent', 'sent_at' => now(), 'last_error' => null], 'id = ?', [$m['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $attempts = (int)$m['attempts'] + 1;
                Db::update('mail_queue', [
                    'status' => $attempts >= 5 ? 'failed' : 'pending',
                    'last_error' => mb_substr($e->getMessage(), 0, 480),
                    'send_after' => date('Y-m-d H:i:s', time() + 300 * $attempts),
                ], 'id = ?', [$m['id']]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function stats(): array
    {
        $r = Db::all('SELECT status, COUNT(*) n FROM mail_queue GROUP BY status');
        $o = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($r as $x) {
            $o[$x['status']] = (int)$x['n'];
        }
        return $o;
    }
}
