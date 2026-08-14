<?php
/**
 * POST /api/push-test.php
 * Envia um push de teste para os aparelhos do próprio usuário.
 * Útil para conferir a permissão no celular sem depender de outra pessoa.
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Push;

ApiResponse::requireMethod('POST');

$session = ApiResponse::requireAuth();
ApiResponse::rateLimit('api', (int) $session['user_id']);

$sent = Push::sendToUser((int) $session['user_id'], [
    'title' => '🔔 Notificações ativas',
    'body'  => 'Você vai receber avisos mesmo com o app fechado.',
    'tag'   => 'push-test',
    'url'   => '',
]);

if ($sent === 0) {
    ApiResponse::error('Nenhum aparelho inscrito recebeu o teste.', 404, ['sent' => 0]);
}

ApiResponse::ok(['sent' => $sent], "Push enviado para $sent aparelho(s)");
