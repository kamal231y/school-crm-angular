<?php
/**
 * Single PDO connection (singleton).
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = Config::get('db');
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}";

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            Response::error('Database connection failed: ' . $e->getMessage(), 500);
        }

        return self::$pdo;
    }

    /** Run a SELECT and return all rows. */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Run a SELECT and return the first row (or null). */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Run INSERT/UPDATE/DELETE, return affected row count. */
    public static function run(string $sql, array $params = []): int
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** INSERT and return the new id. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
        $st   = self::conn()->prepare($sql);
        $st->execute($data);
        return (int) self::conn()->lastInsertId();
    }

    /** UPDATE by primary key id. */
    public static function update(string $table, int $id, array $data): int
    {
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
        $data['__id'] = $id;
        $st = self::conn()->prepare("UPDATE $table SET $sets WHERE id = :__id");
        $st->execute($data);
        return $st->rowCount();
    }

    public static function scalar(string $sql, array $params = [])
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
