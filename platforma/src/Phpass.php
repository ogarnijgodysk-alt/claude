<?php
declare(strict_types=1);

namespace App;

/** Weryfikacja haseł WordPress: phpass ($P$ / $H$) oraz bcrypt ($2y$), opcjonalnie $wp$2y$. Tylko do odczytu starych hashy. */
final class Phpass
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function check(string $password, string $hash): bool
    {
        if (str_starts_with($hash, '$wp$')) {
            $hash = substr($hash, 3);
            $password = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
            return password_verify($password, $hash);
        }
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return password_verify($password, $hash);
        }
        if (strlen($hash) === 34 && (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$'))) {
            return hash_equals($hash, self::crypt($password, $hash));
        }
        return false;
    }

    private static function crypt(string $password, string $setting): string
    {
        $id = substr($setting, 0, 3);
        $countLog2 = strpos(self::ITOA64, $setting[3]);
        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return '*0';
        }
        $count = 1 << $countLog2;
        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return '*0';
        }
        $h = md5($salt . $password, true);
        do {
            $h = md5($h . $password, true);
        } while (--$count);
        return substr($setting, 0, 12) . self::encode64($h, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3f];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3f];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3f];
        } while ($i < $count);
        return $output;
    }
}
