<?php
/**
 * GET /api/voice.php?id=<uuid>&token=<token>
 * Faz streaming do recado de voz (WAV) apenas para o remetente ou destinatário.
 * Suporta Range requests (necessário para o <audio> do Safari/iOS).
 * Áudios expirados (ver media.ttl_seconds) respondem 410.
 *
 * Este é o ÚNICO endpoint que aceita token por querystring — a tag <audio> não
 * envia o header Authorization.
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\Auth;
use WalkieTalkie\Database;

$config = $GLOBALS['WT_CONFIG_CACHE'];

function fail(int $code, string $msg): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo $msg;
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD'], true)) fail(405, 'Método não permitido');

$token = Auth::getBearerToken(true); // querystring liberada só aqui
$session = $token ? Auth::validate($token) : null;
if (!$session) fail(401, 'Não autorizado');

$id = $_GET['id'] ?? '';
if (!Auth::isUuid($id)) fail(400, 'id inválido');

$msg = Database::fetch(
    "SELECT from_user_id, to_user_id, media_path, created_at
     FROM messages WHERE uuid = :u AND kind = 'audio' LIMIT 1",
    ['u' => $id]
);
if (!$msg || !$msg['media_path']) fail(404, 'Áudio não encontrado');

$me = (int) $session['user_id'];
if ($me !== (int) $msg['from_user_id'] && $me !== (int) $msg['to_user_id']) {
    fail(403, 'Acesso negado');
}

// Expiração (24h por padrão)
$ttl = (int) ($config['media']['ttl_seconds'] ?? 86400);
$age = time() - strtotime($msg['created_at']);
if ($age >= $ttl) fail(410, 'Áudio expirado');

$base = realpath($config['media']['path']);
$path = $base ? realpath($base . '/' . basename($msg['media_path'])) : false;
if (!$base || !$path || strncmp($path, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0 || !is_file($path)) {
    fail(404, 'Arquivo indisponível');
}

$size = filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;

// Range (parcial) — Safari/iOS pede para tocar mídia
if (isset($_SERVER['HTTP_RANGE'])) {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
        header("Content-Range: bytes */$size");
        fail(416, 'Range inválido');
    }
    if ($m[1] === '' && $m[2] === '') {
        header("Content-Range: bytes */$size");
        fail(416, 'Range inválido');
    }
    if ($m[1] === '') {
        // suffix range: últimos N bytes
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') $end = (int) $m[2];
    }
    if ($start > $end || $start >= $size) {
        header("Content-Range: bytes */$size");
        fail(416, 'Range inválido');
    }
    $end = min($end, $size - 1);
    $status = 206;
}
$length = $end - $start + 1;

// Cache privado só até a expiração do recado (antes eram 24h fixas, o que
// permitia servir do cache um áudio já expirado/apagado).
$maxAge = max(0, $ttl - $age);

http_response_code($status);
header('Content-Type: audio/wav');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Content-Disposition: inline; filename="recado.wav"');
header('Cache-Control: private, max-age=' . $maxAge);
header('X-Content-Type-Options: nosniff');
header('X-Accel-Buffering: no');
if ($status === 206) {
    header("Content-Range: bytes $start-$end/$size");
}

if ($method === 'HEAD') exit;

// Desliga buffers para não carregar o arquivo inteiro em memória
while (ob_get_level() > 0) ob_end_flush();

$fp = fopen($path, 'rb');
if ($fp === false) fail(500, 'Falha ao abrir o áudio');

if ($start > 0) fseek($fp, $start);

if ($start === 0 && $length === $size) {
    fpassthru($fp);
} else {
    stream_copy_to_stream($fp, fopen('php://output', 'wb'), $length);
}
fclose($fp);
