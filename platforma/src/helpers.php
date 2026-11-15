<?php
declare(strict_types=1);

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Polska odmiana: pl(1,'kurs','kursy','kursów') */
function pl(int $n, string $a, string $b, string $c): string
{
    if ($n === 1) {
        return $a;
    }
    $m10 = $n % 10;
    $m100 = $n % 100;
    return ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $b : $c;
}

function nf(int|float $n): string
{
    return number_format((float)$n, 0, ',', "\u{00A0}");
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function fmt_date(?string $d): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date('d.m.Y', $t) : '';
}

function fmt_size(int $b): string
{
    if ($b >= 1048576) {
        return number_format($b / 1048576, 1, ',', '') . ' MB';
    }
    return max(1, (int)round($b / 1024)) . ' KB';
}

function valid_email(string $e): bool
{
    return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}

function url(string $path = '/'): string
{
    $base = rtrim((string)\App\Config::get('base_path', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function abs_url(string $path = '/'): string
{
    $u = rtrim((string)\App\Config::get('app_url', ''), '/');
    if ($u === '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $u = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $u . url($path);
}

function asset(string $p): string
{
    $f = APP_ROOT . '/public/assets/' . $p;
    return url('assets/' . $p) . (is_file($f) ? '?v=' . filemtime($f) : '');
}

/** Ikony SVG z makiety (obrys, 24x24). */
function icon(string $name, string $extra = ''): string
{
    static $I = [
        'grip' => '<g fill="currentColor" stroke="none"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></g>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
        'video' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z"/>',
        'play' => '<path d="M7 4.5v15l13-7.5z"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
        'book' => '<path d="M5 4h12a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2z"/><path d="M5 18a2 2 0 0 1 2-2h12"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
        'back' => '<path d="M15 6l-6 6 6 6"/>',
        'next' => '<path d="M9 6l6 6-6 6"/>',
        'file' => '<path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M4 18l5-5 4 4 3-3 4 4"/>',
        'clip' => '<path d="M20 11l-8 8a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7-7"/>',
        'quiz' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>',
        'gear' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" ' . $extra . '>' . ($I[$name] ?? '') . '</svg>';
}
