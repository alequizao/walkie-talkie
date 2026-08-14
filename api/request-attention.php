<?php
/**
 * POST /api/request-attention.php
 * Fallback REST. O fluxo real é via WebSocket.
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Queue;
use WalkieTalkie\Room;
use WalkieTalkie\RateLimit;
use WalkieTalkie\Database;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
$config  = ApiResponse::config();

$cooldown  = (int) $config['ptt']['attention_cooldown'];
$remaining = RateLimit::cooldownRemaining('attention', (int) $session['user_id'], $cooldown);
if ($remaining > 0) {
    ApiResponse::error("Aguarde $remaining segundo(s) para chamar atenção de novo.", 429, [
        'remaining' => $remaining,
    ]);
}

ApiResponse::rateLimit('attention', (int) $session['user_id'], null, 'Limite de alertas atingido.');

$room = Room::getDefault();

Database::query(
    "UPDATE users SET attention_count = attention_count + 1, last_attention_at = NOW() WHERE id = :id",
    ['id' => $session['user_id']]
);

Queue::requestTalk((int) $room['id'], $session['user_id'], true);

Logger::event('attention', $session['user_id'], (int) $room['id'], 'Solicitou prioridade');

ApiResponse::ok(null, 'Alerta enviado');
