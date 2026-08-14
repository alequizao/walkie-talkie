<?php
/**
 * POST /api/login.php
 * Body: { display_name: 'Nome', password?: '...' }
 *
 * Modo guest: só envia display_name -> cria sessão.
 * Modo login: envia username + password.
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Auth;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$ip = ApiResponse::clientIp();

// Limite por IP vindo da config (antes estava hardcoded em 10/300)
ApiResponse::rateLimit('login', null, $ip, 'Muitas tentativas. Aguarde 5 minutos.');

$input = ApiResponse::input();

$displayName = trim((string) ($input['display_name'] ?? ''));
$username    = trim((string) ($input['username'] ?? ''));
$password    = (string) ($input['password'] ?? '');

try {
    if ($username !== '' && $password !== '') {
        $session = Auth::login($username, $password, $ip);
    } else {
        // Login guest também aceita `display_name` + senha (conta protegida)
        if ($displayName !== '' && $password !== '') {
            $session = Auth::login($displayName, $password, $ip);
        } else {
            if (mb_strlen($displayName) < 2) {
                ApiResponse::error('Informe seu nome (mínimo 2 caracteres).');
            }
            $session = Auth::quickRegister($displayName);
        }
    }

    Logger::event('login', $session['user']['id'], null, 'API login', [], $ip);
    ApiResponse::ok($session, 'Autenticado');

} catch (\InvalidArgumentException $e) {
    ApiResponse::error($e->getMessage(), 400);
} catch (\RuntimeException $e) {
    // Nome protegido: o cliente mostra o campo de senha e tenta de novo
    if ($e->getCode() === Auth::NEEDS_PASSWORD) {
        ApiResponse::error($e->getMessage(), 401, ['needs_password' => true]);
    }
    // Demais mensagens de negócio (credencial inválida, banido)
    ApiResponse::error($e->getMessage(), 401);
} catch (\Throwable $e) {
    // Erros inesperados nunca vazam detalhes internos
    Logger::error('Falha no login: ' . $e->getMessage());
    ApiResponse::error('Não foi possível autenticar agora. Tente novamente.', 500);
}
