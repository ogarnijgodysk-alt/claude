<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db', []);
            $host = $c['host'] ?? 'localhost';
            $dsn = 'mysql:host=' . $host . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
            if (!empty($c['port'])) {
                $dsn .= ';port=' . (int)$c['port'];
            }
            if (!empty($c['socket'])) {
                $dsn = 'mysql:unix_socket=' . $c['socket'] . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4';
            }
            self::$pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $r = self::q($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => '`' . $c . '`=?', array_keys($row)));
        return self::exec('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $where, array_merge(array_values($row), $params));
    }

    /** Wstawia wiele wierszy jednym zapytaniem (porcje po $chunk). */
    public static function insertMany(string $table, array $cols, array $rows, int $chunk = 300, string $verb = 'INSERT', string $suffix = ''): void
    {
        foreach (array_chunk($rows, $chunk) as $part) {
            $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $sql = $verb . ' INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($part), $ph)) . ($suffix !== '' ? ' ' . $suffix : '');
            $flat = [];
            foreach ($part as $r) {
                foreach ($r as $v) {
                    $flat[] = $v;
                }
            }
            self::q($sql, $flat);
        }
    }

    public static function tx(callable $fn): mixed
    {
        $p = self::pdo();
        $p->beginTransaction();
        try {
            $r = $fn();
            $p->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($p->inTransaction()) {
                $p->rollBack();
            }
            throw $e;
        }
    }
}
