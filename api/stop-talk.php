<?php
/**
 * POST /api/stop-talk.php
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Queue;
use WalkieTalkie\Room;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$room = Room::getDefault();

$next = Queue::stopTalk((int) $room['id'], $session['user_id']);

Logger::event('talk_stop', $session['user_id'], (int) $room['id']);

ApiResponse::ok([
    'next' => $next,
    'queue' => Queue::getState((int) $room['id']),
]);
