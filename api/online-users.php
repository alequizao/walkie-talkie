<?php
/**
 * GET /api/online-users.php
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Database;

ApiResponse::requireMethod('GET');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$timeout = (int) (ApiResponse::config()['websocket']['idle_timeout'] ?? 90);

$users = Database::fetchAll(
    "SELECT uuid, username, display_name, avatar_color, role, last_heartbeat
     FROM users
     WHERE online_status = 'online'
       AND is_banned = 0
       AND last_heartbeat > DATE_SUB(NOW(), INTERVAL :t SECOND)
     ORDER BY display_name ASC",
    ['t' => $timeout]
);

ApiResponse::ok($users);
