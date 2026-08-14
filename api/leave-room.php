<?php
/**
 * POST /api/leave-room.php
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Queue;
use WalkieTalkie\Room;
use WalkieTalkie\Logger;
use WalkieTalkie\Database;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$room = Room::getDefault();
Queue::removeUser((int) $room['id'], $session['user_id']);

Database::query(
    "UPDATE users SET online_status = 'offline', last_seen = NOW() WHERE id = :id",
    ['id' => $session['user_id']]
);

Logger::event('leave', $session['user_id'], (int) $room['id'], 'Saiu da sala');

ApiResponse::ok(null, 'Saiu da sala');
