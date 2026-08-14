<?php
/**
 * POST /api/request-talk.php
 * Fallback REST quando WebSocket não estiver disponível.
 * O fluxo principal usa WebSocket.
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Queue;
use WalkieTalkie\Room;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();

// Limite vindo da config (antes estava hardcoded em 30/60)
ApiResponse::rateLimit('talk', (int) $session['user_id'], null, 'Muitas transmissões. Aguarde.');

$room = Room::getDefault();
$result = Queue::requestTalk((int) $room['id'], $session['user_id'], false);

Logger::event(
    $result['status'] === 'talking' ? 'talk_start' : 'queue_join',
    $session['user_id'],
    (int) $room['id']
);

ApiResponse::ok($result);
