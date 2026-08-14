<?php
/**
 * GET /api/queue-status.php
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Queue;
use WalkieTalkie\Room;

ApiResponse::requireMethod('GET');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$room = Room::getDefault();
$state = Queue::getState((int) $room['id']);

ApiResponse::ok([
    'room'  => Room::publicView($room),
    'queue' => $state,
]);
