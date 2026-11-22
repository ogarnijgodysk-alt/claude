<?php
declare(strict_types=1);

namespace App\Mail;

use App\Settings;

final class Templates
{
    /** @return array{0:string,1:string,2:string} */
    public static function loginLink(array $user, string $link, int $ttl): array
    {
        $site = Settings::get('site_name', 'Twoja Akademia');
        $name = trim((string)$user['first_name']);
        $hello = $name !== '' ? "Cześć $name," : 'Cześć,';
        $subject = "Link do logowania – $site";
        $text = "$hello\n\nKliknij link, żeby zalogować się do swoich kursów. Link jest ważny $ttl minut i działa tylko raz.\n\n$link\n\nJeśli to nie Ty prosiłeś o link, zignoruj tę wiadomość: bez kliknięcia nikt nie wejdzie na Twoje konto.\n\n$site\n";
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#13201C;line-height:1.5"><p>' . $e($hello) . '</p><p>Kliknij przycisk, żeby zalogować się do swoich kursów. Link jest ważny ' . $ttl . ' minut i działa tylko raz.</p>'
            . '<p><a href="' . $e($link) . '" style="display:inline-block;background:#0B6B5B;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Zaloguj się</a></p>'
            . '<p style="font-size:13px;color:#586964">Jeśli przycisk nie działa, skopiuj adres: ' . $e($link) . '</p>'
            . '<p style="font-size:13px;color:#586964">Jeśli to nie Ty prosiłeś o link, zignoruj tę wiadomość: bez kliknięcia nikt nie wejdzie na Twoje konto.</p><p>' . $e($site) . '</p></body></html>';
        return [$subject, $text, $html];
    }

    /** @return array{0:string,1:string,2:string} temat, tekst, html */
    public static function passwordLink(array $user, string $link, int $ttl, string $kind): array
    {
        $site = Settings::get('site_name', 'Twoja Akademia');
        $name = trim((string)$user['first_name']);
        $hello = $name !== '' ? "Cześć $name," : 'Cześć,';
        $subject = $kind === 'invite' ? "Ustaw hasło do platformy kursów – $site" : 'Ustaw hasło do platformy kursów';
        $intro = $kind === 'invite'
            ? "Przygotowaliśmy dla Ciebie konto na platformie kursów. Kliknij link, żeby ustawić hasło i wejść do swoich kursów."
            : 'Kliknij link, żeby ustawić hasło do swojego konta.';
        $text = "$hello\n\n$intro Link jest ważny $ttl minut i działa tylko raz.\n\n$link\n\nJeśli to nie Ty prosiłeś o link, zignoruj tę wiadomość.\n\n$site\n";
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#13201C;line-height:1.5"><p>' . $e($hello) . '</p><p>' . $e($intro) . ' Link jest ważny ' . $ttl . ' minut i działa tylko raz.</p>'
            . '<p><a href="' . $e($link) . '" style="display:inline-block;background:#0B6B5B;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Ustaw hasło</a></p>'
            . '<p style="font-size:13px;color:#586964">Jeśli przycisk nie działa, skopiuj adres: ' . $e($link) . '</p>'
            . '<p style="font-size:13px;color:#586964">Jeśli to nie Ty prosiłeś o link, zignoruj tę wiadomość.</p><p>' . $e($site) . '</p></body></html>';
        return [$subject, $text, $html];
    }
}
