<?php
namespace WalkieTalkie;

use PDO;
use PDOException;

/**
 * Database - Conexão PDO MySQL singleton
 *
 * Notas de projeto:
 * - NÃO faz mais `SELECT 1` antes de cada query (dobrava o número de round-trips).
 *   Em vez disso, detecta "MySQL server has gone away" e refaz a conexão UMA vez,
 *   e somente quando não há transação aberta (reconectar no meio de uma transação
 *   perderia o rollback silenciosamente).
 * - `insert()` lê o lastInsertId da MESMA conexão usada no INSERT.
 */
class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    /** Códigos de erro que indicam conexão perdida */
    private const GONE_AWAY = [2006, 2013, 1047, 1053, 2055];

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $cfg = self::$config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $cfg['username'],
                    $cfg['password'],
                    $cfg['options']
                );
                // Fuso do MySQL derivado do fuso da aplicação (antes era fixo em -03:00)
                self::$instance->exec("SET time_zone = '" . self::tzOffset() . "'");
            } catch (PDOException $e) {
                // Não vaza credenciais/DSN para a camada de cima
                Logger::error('Falha ao conectar com o banco: ' . $e->getMessage());
                throw new \RuntimeException('Falha ao conectar com o banco de dados.');
            }
        }
        return self::$instance;
    }

    /** Offset do fuso configurado, no formato aceito pelo MySQL (ex.: -03:00) */
    private static function tzOffset(): string
    {
        $name = self::$config['timezone'] ?? date_default_timezone_get();
        try {
            $tz  = new \DateTimeZone($name);
            $off = $tz->getOffset(new \DateTime('now', $tz));
        } catch (\Throwable $e) {
            $off = -3 * 3600;
        }
        $sign = $off < 0 ? '-' : '+';
        $off  = abs($off);
        return sprintf('%s%02d:%02d', $sign, intdiv($off, 3600), intdiv($off % 3600, 60));
    }

    /**
     * Reconecta caso a conexão tenha caído (importante para WS server long-running).
     * Mantido por compatibilidade — dentro de uma transação nunca reconecta.
     */
    public static function ensureConnection(): PDO
    {
        $pdo = self::getInstance();
        if ($pdo->inTransaction()) return $pdo;

        try {
            $pdo->query('SELECT 1');
        } catch (PDOException $e) {
            self::$instance = null;
            return self::getInstance();
        }
        return $pdo;
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $pdo = self::getInstance();

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Só tenta reconectar se a conexão caiu E não há transação aberta.
            if (!self::isGoneAway($e) || $pdo->inTransaction()) {
                throw $e;
            }
            Logger::warn('Conexão MySQL perdida — reconectando.');
            self::$instance = null;
            $pdo = self::getInstance();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
    }

    private static function isGoneAway(PDOException $e): bool
    {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($driverCode, self::GONE_AWAY, true)) return true;
        return stripos($e->getMessage(), 'server has gone away') !== false
            || stripos($e->getMessage(), 'Lost connection') !== false;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Retorna a primeira coluna da primeira linha (ou null). */
    public static function value(string $sql, array $params = [])
    {
        $row = self::query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            self::ident($table),
            implode(',', array_map(fn($c) => '`' . self::ident($c) . '`', $cols)),
            implode(',', $placeholders)
        );
        self::query($sql, $data);
        // lastInsertId da MESMA conexão usada acima
        return (int) self::getInstance()->lastInsertId();
    }

    /**
     * UPDATE seguro: os placeholders do SET recebem prefixo `set_` para nunca
     * colidirem com os placeholders do WHERE (antes, um SET em `id` sobrescrevia
     * silenciosamente o `:id` do WHERE).
     */
    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = [];
        $bind = [];
        foreach ($data as $col => $val) {
            $ph = 'set_' . $col;
            $set[] = '`' . self::ident($col) . '` = :' . $ph;
            $bind[$ph] = $val;
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', self::ident($table), implode(',', $set), $where);
        return self::query($sql, array_merge($bind, $params))->rowCount();
    }

    /**
     * Executa $fn dentro de uma transação, com commit/rollback automáticos.
     * Suporta aninhamento (a transação externa é a que commita).
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::getInstance();

        if ($pdo->inTransaction()) {
            return $fn($pdo); // já dentro de uma transação: participa dela
        }

        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Sanitiza identificadores (tabela/coluna) usados em SQL montado. */
    private static function ident(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $name);
    }
}
