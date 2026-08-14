<?php
namespace WalkieTalkie;

/**
 * RateLimit - controle anti flood / anti spam por usuário ou IP
 *
 * Implementação por "janela fixa" ATÔMICA:
 *   INSERT ... ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)
 * A chave única (action, user_id, ip_address, window_start) garante que dois
 * requests simultâneos não leiam o mesmo contador (o SELECT+UPDATE anterior
 * tinha race condition e deixava o limite ser furado).
 *
 * Como índices UNIQUE do MySQL tratam NULL como valores distintos, usamos
 * user_id = 0 e ip_address = '' para o lado não utilizado.
 */
class RateLimit
{
    /**
     * Verifica e incrementa o contador de uma ação.
     * Retorna true se PERMITIDO, false se bloqueado.
     */
    public static function check(string $action, ?int $userId = null, ?string $ip = null, int $max = 60, int $window = 60): bool
    {
        $window = max(1, $window);
        $byUser = ($userId !== null && $userId > 0);

        $uid   = $byUser ? $userId : 0;
        $addr  = $byUser ? '' : (string) ($ip ?? '');
        $start = date('Y-m-d H:i:s', intdiv(time(), $window) * $window);

        // Sem a chave única (migration ainda não aplicada) o upsert viraria um
        // INSERT simples e o limite nunca bloquearia — então cai no caminho antigo.
        if (!self::hasBucketKey()) {
            $ok = self::checkLegacy($action, $uid, $addr, $max, $window, $start);
            if (!$ok) {
                Logger::event('rate_limit', $byUser ? $uid : null, null, "Bloqueado: $action",
                    ['max' => $max], $byUser ? null : $addr);
            }
            return $ok;
        }

        try {
            Database::query(
                "INSERT INTO rate_limits (user_id, ip_address, action, count, window_start, last_attempt)
                 VALUES (:u, :i, :a, 1, :w, NOW())
                 ON DUPLICATE KEY UPDATE
                    count = LAST_INSERT_ID(count + 1),
                    last_attempt = NOW()",
                ['u' => $uid, 'i' => $addr, 'a' => $action, 'w' => $start]
            );
            $count = (int) Database::getInstance()->lastInsertId();
            // Primeiro acesso da janela: LAST_INSERT_ID traz o id do INSERT, não o contador
            if ($count === 0) $count = 1;
        } catch (\Throwable $e) {
            // Sem a chave única (migration não aplicada) cai no caminho antigo.
            Logger::warn('RateLimit: upsert falhou, usando fallback. ' . $e->getMessage());
            return self::checkLegacy($action, $uid, $addr, $max, $window, $start);
        }

        // Só o INSERT novo não passa pelo LAST_INSERT_ID(count+1); nesse caso o
        // valor retornado é o AUTO_INCREMENT. Confirmamos lendo o contador real
        // apenas quando ele parece alto demais para ser um contador.
        if ($count > $max) {
            $real = (int) Database::value(
                "SELECT count FROM rate_limits
                 WHERE action = :a AND user_id = :u AND ip_address = :i AND window_start = :w",
                ['a' => $action, 'u' => $uid, 'i' => $addr, 'w' => $start]
            );
            $count = $real ?: $count;
        }

        if ($count > $max) {
            Logger::event('rate_limit', $byUser ? $uid : null, null, "Bloqueado: $action",
                ['count' => $count, 'max' => $max], $byUser ? null : $addr);
            return false;
        }

        return true;
    }

    /** A tabela já tem a chave única uk_bucket? (consultado uma vez por processo) */
    private static ?bool $hasBucketKey = null;

    private static function hasBucketKey(): bool
    {
        if (self::$hasBucketKey !== null) return self::$hasBucketKey;

        try {
            $row = Database::fetch("SHOW INDEX FROM rate_limits WHERE Key_name = 'uk_bucket'");
            return self::$hasBucketKey = (bool) $row;
        } catch (\Throwable $e) {
            return self::$hasBucketKey = false;
        }
    }

    /**
     * Caminho antigo (SELECT + UPDATE), usado só se a chave única não existir.
     * Compara o bucket por igualdade — a comparação antiga
     * (`window_start > NOW() - janela`) nunca casava com o início da janela e
     * fazia o limite não bloquear nunca.
     */
    private static function checkLegacy(string $action, int $uid, string $addr, int $max, int $window, string $start): bool
    {
        $existing = Database::fetch(
            "SELECT id, count FROM rate_limits
             WHERE action = :a AND user_id = :u AND ip_address = :i AND window_start = :w
             ORDER BY id DESC LIMIT 1",
            ['a' => $action, 'u' => $uid, 'i' => $addr, 'w' => $start]
        );

        if ($existing) {
            if ((int) $existing['count'] >= $max) return false;
            Database::query(
                'UPDATE rate_limits SET count = count + 1, last_attempt = NOW() WHERE id = :id',
                ['id' => $existing['id']]
            );
            return true;
        }

        Database::insert('rate_limits', [
            'user_id'      => $uid,
            'ip_address'   => $addr,
            'action'       => $action,
            'count'        => 1,
            'window_start' => $start,
        ]);
        return true;
    }

    /**
     * Limpa registros antigos. Chamado pelo tick() do servidor WS e/ou por cron.
     */
    public static function cleanup(): int
    {
        return Database::query(
            "DELETE FROM rate_limits WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 1 HOUR) LIMIT 5000"
        )->rowCount();
    }

    /**
     * Verifica cooldown por tempo desde a última ação.
     * Retorna true se JÁ PODE agir.
     */
    public static function checkCooldown(string $action, int $userId, int $cooldownSeconds): bool
    {
        $last = Database::value(
            "SELECT MAX(last_attempt) FROM rate_limits WHERE user_id = :u AND action = :a",
            ['u' => $userId, 'a' => $action]
        );
        if (!$last) return true;
        return (time() - strtotime($last)) >= $cooldownSeconds;
    }

    /** Segundos restantes de cooldown (0 se liberado). */
    public static function cooldownRemaining(string $action, int $userId, int $cooldownSeconds): int
    {
        $last = Database::value(
            "SELECT MAX(last_attempt) FROM rate_limits WHERE user_id = :u AND action = :a",
            ['u' => $userId, 'a' => $action]
        );
        if (!$last) return 0;
        return max(0, $cooldownSeconds - (time() - strtotime($last)));
    }
}
