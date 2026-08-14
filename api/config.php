<?php
/**
 * GET /api/config.php
 * Devolve config pública para o cliente (sem secrets)
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;

ApiResponse::requireMethod('GET');

$config = ApiResponse::config(); // já em cache — não relê o .env

// O app roda em vários domínios (publishdev.com.br/walkietalkie, voip.*,
// fofocar.alequizao.com). Cada vhost publica o proxy do WebSocket em <base>/ws,
// então devolvemos a URL do host da requisição — nunca a fixa do .env.
$host = $_SERVER['HTTP_HOST'] ?? '';
$wsUrl = $config['websocket']['public_url'];

if ($host !== '') {
    // /api/config.php -> base é o diretório acima de /api
    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if ($base === '/') $base = '';

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (stripos($_SERVER['HTTP_CF_VISITOR'] ?? '', '"https"') !== false)
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    $wsUrl = ($isHttps ? 'wss://' : 'ws://') . $host . $base . '/ws';
}

ApiResponse::ok([
    'app_name'     => $config['app']['name'],
    'app_version'  => $config['app']['version'],
    'ws_url'       => $wsUrl,
    'ice_servers'  => $config['webrtc']['ice_servers'],
    'audio'        => $config['audio'],
    'ptt'          => [
        'max_talk_seconds'   => $config['ptt']['max_talk_seconds'],
        'cooldown_seconds'   => $config['ptt']['cooldown_seconds'],
        'attention_cooldown' => $config['ptt']['attention_cooldown'],
        'debounce_ms'        => $config['ptt']['debounce_ms'],
    ],
    'media'        => [
        'max_bytes'   => $config['media']['max_bytes'],
        'ttl_seconds' => $config['media']['ttl_seconds'],
    ],
]);
