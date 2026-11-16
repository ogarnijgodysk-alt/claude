<?php
declare(strict_types=1);

namespace App;

/** Logika instalatora (używana przez public/instalator.php i bin/instaluj.php). */
final class Installer
{
    /** @return array<int,array{ok:bool,label:string,detail:string}> */
    public static function requirements(): array
    {
        $r = [];
        $r[] = ['ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'label' => 'PHP 8.1 lub nowszy', 'detail' => 'wersja ' . PHP_VERSION];
        foreach (['pdo_mysql', 'mbstring', 'dom', 'fileinfo', 'zip', 'json', 'openssl'] as $ext) {
            $r[] = ['ok' => extension_loaded($ext), 'label' => 'Rozszerzenie ' . $ext, 'detail' => extension_loaded($ext) ? 'jest' : 'brak'];
        }
        $st = Config::storage();
        foreach (['', '/uploads', '/logs', '/sessions', '/imports', '/reports', '/tmp'] as $sub) {
            @mkdir($st . $sub, 0750, true);
        }
        $r[] = ['ok' => is_writable($st), 'label' => 'Katalog storage/ zapisywalny', 'detail' => $st];
        $r[] = ['ok' => is_writable(APP_ROOT . '/config'), 'label' => 'Katalog config/ zapisywalny', 'detail' => APP_ROOT . '/config'];
        $r[] = ['ok' => true, 'label' => 'Limit wgrywania plików (PHP)', 'detail' => 'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size')];
        return $r;
    }

    public static function testDb(array $db): ?string
    {
        try {
            Config::load(['db' => $db]);
            Db::reset();
            Db::pdo()->query('SELECT 1');
            return null;
        } catch (\Throwable $e) {
            return 'Nie można połączyć z bazą: ' . $e->getMessage();
        }
    }

    public static function createSchema(): int
    {
        $sql = (string)file_get_contents(APP_ROOT . '/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $n = 0;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            Db::pdo()->exec($stmt);
            $n++;
        }
        return $n;
    }

    public static function createAdmin(string $email, string $name, string $password): int
    {
        $email = mb_strtolower(trim($email));
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [''];
        $row = Db::one('SELECT id FROM users WHERE email = ?', [$email]);
        $hash = Auth::hash($password);
        if ($row) {
            Db::exec('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$row['id']]);
            Db::update('users', ['role' => 'admin', 'password_hash' => $hash], 'id = ?', [$row['id']]);
            return (int)$row['id'];
        }
        return Db::insert('users', [
            'email' => $email, 'first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? '', 'full_name' => trim($name),
            'role' => 'admin', 'password_hash' => $hash, 'created_at' => now(),
        ]);
    }

    public static function writeConfig(array $cfg): string
    {
        $file = APP_ROOT . '/config/config.php';
        $php = "<?php\n// Wygenerowane przez instalator " . date('Y-m-d H:i') . ". Nie commituj tego pliku.\nreturn " . var_export($cfg, true) . ";\n";
        if (file_put_contents($file, $php, LOCK_EX) === false) {
            throw new \RuntimeException('Nie można zapisać config/config.php');
        }
        @chmod($file, 0640);
        return $file;
    }

    public static function buildConfig(array $in): array
    {
        $cfg = [
            'db' => ['host' => $in['db_host'], 'port' => (int)($in['db_port'] ?? 3306), 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass']],
            'app_url' => rtrim($in['app_url'], '/'),
            'base_path' => '',
            'timezone' => 'Europe/Warsaw',
            'debug' => false,
            'trust_proxy' => false,
            'hsts' => str_starts_with($in['app_url'], 'https://'),
            'mail' => [
                'transport' => $in['mail_transport'] ?? 'smtp',
                'host' => $in['smtp_host'] ?? '', 'port' => (int)($in['smtp_port'] ?? 587), 'encryption' => $in['smtp_enc'] ?? 'tls',
                'user' => $in['smtp_user'] ?? '', 'pass' => $in['smtp_pass'] ?? '', 'from' => $in['mail_from'] ?? '', 'timeout' => 15,
            ],
        ];
        return $cfg;
    }

    /** Wykonuje pełną instalację. Zwraca listę komunikatów. */
    public static function run(array $in): array
    {
        $in += ['db_port' => 3306, 'db_pass' => '', 'site_name' => 'Twoja Akademia'];
        $log = [];
        $cfg = self::buildConfig($in);
        if ($e = self::testDb($cfg['db'])) {
            throw new \RuntimeException($e);
        }
        $log[] = 'Połączono z bazą danych.';
        $log[] = 'Utworzono tabele: ' . self::createSchema() . ' instrukcji SQL.';
        Config::load($cfg);
        Settings::set('site_name', (string)$in['site_name']);
        if (!empty($in['mail_from'])) {
            Settings::set('mail_from', (string)$in['mail_from']);
        }
        Settings::set('mail_from_name', (string)$in['site_name']);
        $id = self::createAdmin($in['admin_email'], $in['admin_name'] ?? '', $in['admin_password']);
        $log[] = 'Utworzono konto administratora (id ' . $id . ').';
        $log[] = 'Zapisano ' . self::writeConfig($cfg);
        @file_put_contents(Config::storage('installed.lock'), date('c'));
        return $log;
    }
}
