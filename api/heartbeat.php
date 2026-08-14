<?php
/**
 * POST /api/heartbeat.php
 * Atualiza o status online do usuário (para fallback além do WS)
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Database;

ApiResponse::requireMethod(['POST', 'GET']);

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

Database::query(
    "UPDATE users SET last_heartbeat = NOW(), online_status = 'online' WHERE id = :id",
    ['id' => $session['user_id']]
);

// Sliding session: só reescreve quando falta menos de 15 dias para expirar
// (antes era um UPDATE em `sessions` a cada heartbeat).
Database::query(
    "UPDATE sessions SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
     WHERE token = :t AND expires_at < DATE_ADD(NOW(), INTERVAL 15 DAY)",
    ['t' => $session['token']]
);

ApiResponse::ok([
    'ts'    => time(),
    'user'  => $session['user'],
], 'pong');
