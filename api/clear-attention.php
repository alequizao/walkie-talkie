<?php
/**
 * POST /api/clear-attention.php
 * Limpa o flag de atenção do usuário (cancelar pedido)
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Database;
use WalkieTalkie\Room;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$room = Room::getDefault();

Database::query(
    "UPDATE queue SET attention_requested = 0, priority = 0
     WHERE user_id = :u AND room_id = :r AND status = 'waiting'",
    ['u' => $session['user_id'], 'r' => (int) $room['id']]
);

ApiResponse::ok(null, 'Atenção removida');
