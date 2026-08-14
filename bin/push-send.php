<?php
/**
 * Worker de envio de push (CLI, descartável).
 *   php bin/push-send.php <user_id> <payload_base64url>
 *
 * Chamado por Push::queueForUser() em processo separado, porque o servidor
 * WebSocket é single-threaded e um curl lento travaria o áudio de todo mundo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Apenas via CLI.\n");
}

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\Push;
use WalkieTalkie\Logger;

$userId  = (int) ($argv[1] ?? 0);
$encoded = (string) ($argv[2] ?? '');

if ($userId <= 0 || $encoded === '') {
    fwrite(STDERR, "uso: php bin/push-send.php <user_id> <payload_base64url>\n");
    exit(1);
}

$payload = json_decode(Push::b64urlDecode($encoded), true);
if (!is_array($payload)) {
    fwrite(STDERR, "payload inválido\n");
    exit(1);
}

try {
    $sent = Push::sendToUser($userId, $payload);
    if ($sent > 0) {
        Logger::info("Push entregue em $sent aparelho(s)", ['user' => $userId, 'tag' => $payload['tag'] ?? null]);
    }
    exit(0);
} catch (\Throwable $e) {
    Logger::error('Worker de push falhou: ' . $e->getMessage(), ['user' => $userId]);
    exit(1);
}
