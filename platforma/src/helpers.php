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
