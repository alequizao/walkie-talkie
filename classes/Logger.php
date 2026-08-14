<?php
namespace WalkieTalkie;

/**
 * Logger - Grava logs em arquivo + banco
 */
class Logger
{
    private static array $config = [];

    public static function setConfig(array $config): void
    {
        self::$config = $config;
        if (!is_dir($config['path'])) {
            @mkdir($config['path'], 0775, true);
        }
    }

    public static function log(string $type, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($type),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
        );

        $file = (self::$config['path'] ?? __DIR__ . '/../storage/logs')
              . '/' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function event(
        string $type,
        ?int $userId = null,
        ?int $roomId = null,
        ?string $message = null,
        array $metadata = [],
        ?string $ip = null
    ): void {
        try {
            Database::insert('logs', [
                'user_id'    => $userId,
                'room_id'    => $roomId,
                'type'       => $type,
                'message'    => $message,
                'metadata'   => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => $ip,
            ]);
        } catch (\Throwable $e) {
            self::log('error', 'Falha ao gravar log: ' . $e->getMessage());
        }
    }

    public static function info(string $msg, array $ctx = []): void  { self::log('info', $msg, $ctx); }
    public static function warn(string $msg, array $ctx = []): void  { self::log('warning', $msg, $ctx); }
    public static function error(string $msg, array $ctx = []): void { self::log('error', $msg, $ctx); }
    public static function debug(string $msg, array $ctx = []): void { self::log('debug', $msg, $ctx); }
}
