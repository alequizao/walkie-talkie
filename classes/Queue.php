<?php
namespace WalkieTalkie;

/**
 * Queue - Gerencia fila de transmissão (FIFO + Prioridade)
 *
 * Regras:
 * - Apenas 1 'talking' por sala
 * - 'waiting' ordenado por priority DESC, queue_position ASC
 * - Prioridade só vale UMA vez por solicitação
 * - Ao sair, remove da fila
 *
 * Concorrência: toda mutação da fila roda dentro de uma transação que começa
 * travando a linha da sala (`SELECT ... FOR UPDATE`). Sem isso, dois
 * `request_talk` simultâneos podiam ambos ver "ninguém falando" e virar dois
 * 'talking' na mesma sala.
 */
class Queue
{
    /** Timestamp com milissegundos, para duration_ms fazer jus ao nome */
    private static function nowMs(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.v');
    }

    private static function toMillis(?string $datetime): ?float
    {
        if (!$datetime) return null;
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $datetime)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);
        if (!$dt) return null;
        return (float) $dt->format('U.u') * 1000;
    }

    /** Trava a sala para serializar as operações de fila. */
    private static function lockRoom(int $roomId): void
    {
        Database::query('SELECT id FROM rooms WHERE id = :r FOR UPDATE', ['r' => $roomId]);
    }

    /**
     * Adiciona usuário na fila para falar.
     * Se a fila estiver vazia e ninguém estiver falando, ele se torna 'talking' imediatamente.
     * Retorna ['status' => 'talking'|'waiting', 'position' => N]
     */
    public static function requestTalk(int $roomId, int $userId, bool $priority = false): array
    {
        return Database::transaction(function () use ($roomId, $userId, $priority) {
            self::lockRoom($roomId);

            // Já está na fila/falando? Idempotente.
            $existing = Database::fetch(
                "SELECT id, status, queue_position FROM queue
                 WHERE room_id = :r AND user_id = :u AND status IN ('waiting','talking') LIMIT 1",
                ['r' => $roomId, 'u' => $userId]
            );

            if ($existing) {
                return [
                    'status'   => $existing['status'],
                    'position' => (int) $existing['queue_position'],
                    'queue_id' => (int) $existing['id'],
                ];
            }

            // Alguém falando?
            $current = Database::fetch(
                "SELECT id FROM queue WHERE room_id = :r AND status = 'talking' LIMIT 1",
                ['r' => $roomId]
            );

            $priorityVal = $priority ? 1 : 0;

            if (!$current) {
                // Vai falar agora
                $id = Database::insert('queue', [
                    'room_id'        => $roomId,
                    'user_id'        => $userId,
                    'queue_position' => 0,
                    'priority'       => $priorityVal,
                    'attention_requested' => $priorityVal,
                    'status'         => 'talking',
                    'started_at'     => self::nowMs(),
                ]);
                return ['status' => 'talking', 'position' => 0, 'queue_id' => $id];
            }

            // Vai pra fila — pega próxima posição
            $maxPos = (int) Database::value(
                "SELECT COALESCE(MAX(queue_position), -1) FROM queue
                 WHERE room_id = :r AND status = 'waiting'",
                ['r' => $roomId]
            );

            $newPos = $priority ? 0 : ($maxPos + 1);

            // Se prioridade: empurra os outros não-prioritários para trás
            if ($priority) {
                Database::query(
                    "UPDATE queue
                     SET queue_position = queue_position + 1
                     WHERE room_id = :r AND status = 'waiting' AND priority = 0",
                    ['r' => $roomId]
                );
            }

            $id = Database::insert('queue', [
                'room_id'        => $roomId,
                'user_id'        => $userId,
                'queue_position' => $newPos,
                'priority'       => $priorityVal,
                'attention_requested' => $priorityVal,
                'status'         => 'waiting',
            ]);

            return ['status' => 'waiting', 'position' => $newPos, 'queue_id' => $id];
        });
    }

    /**
     * Encerra a transmissão atual e promove o próximo da fila.
     * Retorna o próximo usuário (ou null).
     */
    public static function stopTalk(int $roomId, int $userId): ?array
    {
        return Database::transaction(function () use ($roomId, $userId) {
            self::lockRoom($roomId);

            $current = Database::fetch(
                "SELECT q.* FROM queue q
                 WHERE q.room_id = :r AND q.status = 'talking' AND q.user_id = :u
                 LIMIT 1",
                ['r' => $roomId, 'u' => $userId]
            );

            if (!$current) {
                return self::nextSpeaker($roomId);
            }

            $endedAt    = self::nowMs();
            $startMs    = self::toMillis($current['started_at']);
            $durationMs = $startMs === null
                ? 0
                : (int) max(0, round(self::toMillis($endedAt) - $startMs));

            Database::update('queue', [
                'status'   => 'done',
                'ended_at' => $endedAt,
            ], 'id = :id', ['id' => $current['id']]);

            // Salva no histórico
            Database::insert('transmissions', [
                'room_id'      => $roomId,
                'user_id'      => $userId,
                'duration_ms'  => $durationMs,
                'had_priority' => $current['priority'],
                'started_at'   => $current['started_at'],
                'ended_at'     => $endedAt,
            ]);

            // Atualiza estatísticas do usuário
            Database::query(
                "UPDATE users
                 SET talk_count = talk_count + 1,
                     total_talk_seconds = total_talk_seconds + :s
                 WHERE id = :u",
                ['s' => intdiv($durationMs, 1000), 'u' => $userId]
            );

            return self::nextSpeaker($roomId);
        });
    }

    /**
     * Promove o próximo da fila para 'talking'.
     * Deve ser chamado com a sala já travada (é o caso em todos os call sites).
     */
    public static function nextSpeaker(int $roomId): ?array
    {
        $next = Database::fetch(
            "SELECT q.*, u.uuid, u.display_name, u.avatar_color
             FROM queue q INNER JOIN users u ON u.id = q.user_id
             WHERE q.room_id = :r AND q.status = 'waiting'
             ORDER BY q.priority DESC, q.queue_position ASC, q.id ASC
             LIMIT 1",
            ['r' => $roomId]
        );

        if (!$next) return null;

        Database::update('queue', [
            'status'     => 'talking',
            'started_at' => self::nowMs(),
        ], 'id = :id', ['id' => $next['id']]);

        // Reordena posições
        self::reindexPositions($roomId);

        return [
            'queue_id'     => (int) $next['id'],
            'user_id'      => (int) $next['user_id'],
            'uuid'         => $next['uuid'],
            'display_name' => $next['display_name'],
            'avatar_color' => $next['avatar_color'],
            'priority'     => (int) $next['priority'],
        ];
    }

    /**
     * Remove usuário da fila (saiu, desconectou ou cancelou)
     */
    public static function removeUser(int $roomId, int $userId): ?array
    {
        return Database::transaction(function () use ($roomId, $userId) {
            self::lockRoom($roomId);

            $row = Database::fetch(
                "SELECT id, status FROM queue
                 WHERE room_id = :r AND user_id = :u AND status IN ('waiting','talking') LIMIT 1",
                ['r' => $roomId, 'u' => $userId]
            );

            if (!$row) return null;

            $wasTalking = $row['status'] === 'talking';

            Database::update('queue', [
                'status'   => 'cancelled',
                'ended_at' => self::nowMs(),
            ], 'id = :id', ['id' => $row['id']]);

            if ($wasTalking) {
                return self::nextSpeaker($roomId);
            }

            self::reindexPositions($roomId);
            return null;
        });
    }

    /**
     * Renumera as posições de 0..N-1 em UMA query (antes era um UPDATE por linha).
     */
    private static function reindexPositions(int $roomId): void
    {
        $ids = Database::fetchAll(
            "SELECT id FROM queue
             WHERE room_id = :r AND status = 'waiting'
             ORDER BY priority DESC, queue_position ASC, id ASC",
            ['r' => $roomId]
        );
        if (!$ids) return;

        // Um único UPDATE com CASE (antes era 1 UPDATE por linha da fila)
        $cases = '';
        $params = ['r' => $roomId];
        $in = [];
        foreach ($ids as $i => $row) {
            $cases .= " WHEN :id$i THEN :pos$i";
            $params["id$i"]  = (int) $row['id'];
            $params["pos$i"] = $i;
            $params["in$i"]  = (int) $row['id'];
            $in[] = ":in$i";
        }

        Database::query(
            "UPDATE queue SET queue_position = CASE id$cases END
             WHERE room_id = :r AND id IN (" . implode(',', $in) . ')',
            $params
        );
    }

    /**
     * Estado completo da fila
     */
    public static function getState(int $roomId): array
    {
        $current = Database::fetch(
            "SELECT q.*, u.uuid, u.display_name, u.avatar_color
             FROM queue q INNER JOIN users u ON u.id = q.user_id
             WHERE q.room_id = :r AND q.status = 'talking' LIMIT 1",
            ['r' => $roomId]
        );

        $waiting = Database::fetchAll(
            "SELECT q.*, u.uuid, u.display_name, u.avatar_color
             FROM queue q INNER JOIN users u ON u.id = q.user_id
             WHERE q.room_id = :r AND q.status = 'waiting'
             ORDER BY q.priority DESC, q.queue_position ASC, q.id ASC",
            ['r' => $roomId]
        );

        return [
            'talking' => $current ? [
                'queue_id'     => (int) $current['id'],
                'user_id'      => (int) $current['user_id'],
                'uuid'         => $current['uuid'],
                'display_name' => $current['display_name'],
                'avatar_color' => $current['avatar_color'],
                'started_at'   => $current['started_at'],
                'priority'     => (int) $current['priority'],
            ] : null,
            'waiting' => array_map(fn($w) => [
                'queue_id'     => (int) $w['id'],
                'user_id'      => (int) $w['user_id'],
                'uuid'         => $w['uuid'],
                'display_name' => $w['display_name'],
                'avatar_color' => $w['avatar_color'],
                'position'     => (int) $w['queue_position'],
                'priority'     => (int) $w['priority'],
            ], $waiting),
        ];
    }

    /**
     * Verifica timeout de transmissão (mais de N segundos falando)
     * Retorna o próximo se forçou stop.
     */
    public static function checkTimeouts(int $roomId, int $maxSeconds): ?array
    {
        $current = Database::fetch(
            "SELECT user_id FROM queue
             WHERE room_id = :r AND status = 'talking'
             AND started_at < DATE_SUB(NOW(), INTERVAL :s SECOND)
             LIMIT 1",
            ['r' => $roomId, 's' => $maxSeconds]
        );
        if (!$current) return null;

        Logger::event('talk_timeout', (int) $current['user_id'], $roomId, 'Timeout automático');
        return self::stopTalk($roomId, (int) $current['user_id']);
    }

    /**
     * Indica se houve alguém falando além do tempo — usado pelo tick() para saber
     * se deve avisar a sala mesmo quando não há próximo na fila.
     */
    public static function hasTimedOutSpeaker(int $roomId, int $maxSeconds): bool
    {
        return (bool) Database::value(
            "SELECT 1 FROM queue
             WHERE room_id = :r AND status = 'talking'
               AND started_at < DATE_SUB(NOW(), INTERVAL :s SECOND) LIMIT 1",
            ['r' => $roomId, 's' => $maxSeconds]
        );
    }
}
