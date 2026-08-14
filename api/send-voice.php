<?php
/**
 * POST /api/send-voice.php  (multipart/form-data)
 *   campos: target=<uuid destinatário>, duration_ms=<int>, audio=<arquivo WAV>
 *   header: Authorization: Bearer <token>
 *
 * Salva o recado de voz em storage/media e cria a mensagem (kind=audio).
 * A entrega em tempo real é disparada pelo cliente via WS (private_audio);
 * se o destinatário estiver offline, vira recado e é entregue ao reconectar.
 */

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\ApiResponse;
use WalkieTalkie\Auth;
use WalkieTalkie\Database;
use WalkieTalkie\Logger;

ApiResponse::requireMethod('POST');

$config  = ApiResponse::config();
$session = ApiResponse::requireAuth();
$me      = $session['user'];

// Anti-flood (mesmo balde das mensagens)
ApiResponse::rateLimit('msg', (int) $me['id'], null, 'Você está enviando rápido demais. Aguarde.');

$targetUuid = trim((string) ($_POST['target'] ?? ''));
$durationMs = (int) ($_POST['duration_ms'] ?? 0);

if (!Auth::isUuid($targetUuid))       ApiResponse::error('Destinatário inválido.');
if ($targetUuid === $me['uuid'])      ApiResponse::error('Não dá para enviar para si mesmo.');
if ($durationMs < 0 || $durationMs > 3600000) $durationMs = 0;

if (empty($_FILES['audio']) || !is_array($_FILES['audio'])) {
    ApiResponse::error('Nenhum áudio enviado.');
}

switch ((int) $_FILES['audio']['error']) {
    case UPLOAD_ERR_OK:
        break;
    case UPLOAD_ERR_INI_SIZE:
    case UPLOAD_ERR_FORM_SIZE:
        ApiResponse::error('Áudio maior que o limite do servidor.', 413);
    case UPLOAD_ERR_NO_FILE:
        ApiResponse::error('Nenhum áudio enviado.');
    default:
        Logger::error('Upload de áudio falhou', ['code' => $_FILES['audio']['error']]);
        ApiResponse::error('Falha no upload do áudio.');
}

$maxBytes = (int) ($config['media']['max_bytes'] ?? 6 * 1024 * 1024);
if ($_FILES['audio']['size'] > $maxBytes) {
    ApiResponse::error('Áudio muito grande (máx. ' . round($maxBytes / 1048576) . ' MB).', 413);
}

$tmp = $_FILES['audio']['tmp_name'];
if (!is_uploaded_file($tmp)) {
    ApiResponse::error('Upload inválido.', 400);
}

// Confere assinatura WAV (RIFF....WAVE)
$head = file_get_contents($tmp, false, null, 0, 12);
if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
    ApiResponse::error('Formato de áudio inválido (esperado WAV).');
}

$target = Database::fetch(
    "SELECT id, uuid, display_name FROM users WHERE uuid = :u LIMIT 1",
    ['u' => $targetUuid]
);
if (!$target) ApiResponse::error('Usuário não encontrado.', 404);

$msgUuid  = Auth::uuid4();
$filename = $msgUuid . '.wav';
$dir      = $config['media']['path'];

if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    Logger::error('Não foi possível criar ' . $dir);
    ApiResponse::error('Não foi possível salvar o áudio.', 500);
}

$destination = $dir . '/' . $filename;
if (!move_uploaded_file($tmp, $destination)) {
    Logger::error('Falha ao salvar áudio em ' . $dir);
    ApiResponse::error('Não foi possível salvar o áudio.', 500);
}
@chmod($destination, 0644);

try {
    Database::insert('messages', [
        'uuid'         => $msgUuid,
        'room_id'      => null,
        'from_user_id' => (int) $me['id'],
        'to_user_id'   => (int) $target['id'],
        'kind'         => 'audio',
        'body'         => '',
        'media_path'   => $filename,
        'duration_ms'  => $durationMs > 0 ? $durationMs : null,
        'delivered_at' => null, // marcado na entrega (online ou ao reconectar)
    ]);
} catch (\Throwable $e) {
    // Não deixa arquivo órfão em disco se o INSERT falhar
    @unlink($destination);
    Logger::error('Falha ao registrar recado de voz: ' . $e->getMessage());
    ApiResponse::error('Não foi possível salvar o áudio.', 500);
}

Logger::event('private_msg', (int) $me['id'], null, 'Recado de voz para ' . $target['display_name']);

ApiResponse::ok([
    'id'          => $msgUuid,
    'target'      => $targetUuid,
    'duration_ms' => $durationMs,
    'media_url'   => 'api/voice.php?id=' . $msgUuid,
    'created_at'  => date('c'),
], 'Áudio enviado');
