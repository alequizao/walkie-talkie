<?php
/**
 * POST /api/push-subscribe.php
 * Body: { endpoint: '...', keys: { p256dh: '...', auth: '...' } }
 *
 * Registra o aparelho para receber Web Push (recados, chamadas e alertas
 * quando o app estiver fechado ou em segundo plano).
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Push;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$input = ApiResponse::input();

$endpoint = trim((string) ($input['endpoint'] ?? ''));
$p256dh   = trim((string) ($input['keys']['p256dh'] ?? ''));
$auth     = trim((string) ($input['keys']['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    ApiResponse::error('Inscrição incompleta.');
}
if (!filter_var($endpoint, FILTER_VALIDATE_URL) || strncmp($endpoint, 'https://', 8) !== 0) {
    ApiResponse::error('Endpoint inválido.');
}
if (strlen($endpoint) > 500) {
    ApiResponse::error('Endpoint longo demais.');
}
// p256dh = ponto EC de 65 bytes, auth = 16 bytes (ambos base64url)
if (strlen(Push::b64urlDecode($p256dh)) !== 65 || strlen(Push::b64urlDecode($auth)) < 16) {
    ApiResponse::error('Chaves da inscrição inválidas.');
}

Push::subscribe(
    (int) $session['user_id'],
    $endpoint,
    $p256dh,
    $auth,
    $_SERVER['HTTP_USER_AGENT'] ?? ''
);

Logger::event('push_subscribe', (int) $session['user_id'], null, 'Aparelho inscrito no push');

ApiResponse::ok(['subscribed' => true], 'Notificações ativadas');
