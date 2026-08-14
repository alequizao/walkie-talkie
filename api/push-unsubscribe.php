<?php
/**
 * POST /api/push-unsubscribe.php
 * Body: { endpoint: '...' }
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Push;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$endpoint = trim((string) (ApiResponse::input()['endpoint'] ?? ''));
if ($endpoint === '') ApiResponse::error('Endpoint não informado.');

$removed = Push::unsubscribe($endpoint);

Logger::event('push_unsubscribe', (int) $session['user_id'], null, 'Aparelho removido do push');

ApiResponse::ok(['removed' => $removed], 'Notificações desativadas');
