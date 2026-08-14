<?php
/**
 * POST /api/join-room.php
 * Body: { slug?: 'principal' }
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Room;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$input = ApiResponse::input();

$slug = (string) ($input['slug'] ?? 'principal');
$room = Room::getBySlug($slug) ?? Room::getDefault();

Logger::event('join', $session['user_id'], (int) $room['id'], 'Entrou na sala via API');

ApiResponse::ok([
    // Nunca devolve password_hash da sala
    'room' => Room::publicView($room),
], 'Entrou na sala');
