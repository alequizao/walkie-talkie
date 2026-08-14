<?php
namespace WalkieTalkie;

class Room
{
    /** Cache em memória (útil no WS long-running e nos endpoints REST) */
    private static ?array $defaultRoom = null;

    public static function getDefault(): array
    {
        if (self::$defaultRoom !== null) return self::$defaultRoom;

        $room = Database::fetch("SELECT * FROM rooms WHERE slug = 'principal' AND is_active = 1 LIMIT 1");

        if (!$room) {
            // Cria caso não exista (INSERT IGNORE evita corrida entre processos)
            Database::query(
                "INSERT IGNORE INTO rooms (uuid, name, slug, description, max_users, max_talk_seconds)
                 VALUES (:uuid, :name, :slug, :descr, :max_users, :max_talk)",
                [
                    'uuid'      => Auth::uuid4(),
                    'name'      => 'Canal Principal',
                    'slug'      => 'principal',
                    'descr'     => 'Canal de comunicação geral',
                    'max_users' => 100,
                    'max_talk'  => 30,
                ]
            );
            $room = Database::fetch("SELECT * FROM rooms WHERE slug = 'principal' LIMIT 1");
        }

        if (!$room) {
            throw new \RuntimeException('Não foi possível obter a sala principal.');
        }

        return self::$defaultRoom = $room;
    }

    /** Invalida o cache (usado em testes / após alterar a sala) */
    public static function flushCache(): void
    {
        self::$defaultRoom = null;
    }

    public static function getBySlug(string $slug): ?array
    {
        return Database::fetch('SELECT * FROM rooms WHERE slug = :s AND is_active = 1 LIMIT 1', ['s' => $slug]);
    }

    public static function getById(int $id): ?array
    {
        return Database::fetch('SELECT * FROM rooms WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /**
     * Versão segura para enviar ao cliente — sem password_hash nem colunas internas.
     */
    public static function publicView(array $room): array
    {
        return [
            'id'               => (int) $room['id'],
            'uuid'             => $room['uuid'] ?? null,
            'name'             => $room['name'] ?? null,
            'slug'             => $room['slug'] ?? null,
            'description'      => $room['description'] ?? null,
            'is_private'       => (bool) ($room['is_private'] ?? false),
            'max_users'        => (int) ($room['max_users'] ?? 0),
            'max_talk_seconds' => (int) ($room['max_talk_seconds'] ?? 0),
        ];
    }
}
