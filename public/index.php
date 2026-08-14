<?php
$config = require __DIR__ . '/../config/bootstrap.php';
$wsUrl = htmlspecialchars($config['websocket']['public_url'], ENT_QUOTES);

// Chave pública VAPID para o Web Push. Se a migration ainda não rodou, o app
// segue funcionando normalmente — só sem push.
$vapidPublic = '';
try {
    $vapidPublic = htmlspecialchars(WalkieTalkie\Push::publicKey(), ENT_QUOTES);
} catch (\Throwable $e) {
    WalkieTalkie\Logger::warn('VAPID indisponível: ' . $e->getMessage());
}
// Base da URL: '' quando o app é a raiz do domínio (voip.usegrupodona.com.br)
// e '/walkietalkie' quando servido como subdiretório de publishdev.com.br.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($base === '/') { $base = ''; }

$host = $_SERVER['HTTP_HOST'] ?? '';
$isVoipHost = stripos($host, 'voip.') === 0;

// Tema. `dona.php` define $WT_THEME; no domínio voip.* o tema Dona é o padrão.
$theme = ((isset($WT_THEME) && $WT_THEME === 'dona') || $isVoipHost) ? 'dona' : 'default';
$isDona = $theme === 'dona';

// WebSocket: TODO vhost publica o proxy em <base>/ws, então derivamos sempre do
// host atual — evita ws cross-origin ao entrar por qualquer domínio novo
// (publishdev.com.br/walkietalkie, voip.*, fofocar.alequizao.com...).
// Atrás da Cloudflare o $_SERVER['HTTPS'] pode não vir; conferimos os headers
// de proxy também.
$isHttps = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (stripos($_SERVER['HTTP_CF_VISITOR'] ?? '', '"https"') !== false)
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');

if ($host !== '') {
    $wsUrl = ($isHttps ? 'wss://' : 'ws://')
        . htmlspecialchars($host, ENT_QUOTES) . $base . '/ws';
}

$appName = $isDona ? 'Voip Dona' : htmlspecialchars($config['app']['name'], ENT_QUOTES);
$appVersion = htmlspecialchars($config['app']['version'] ?? '1.0.0', ENT_QUOTES);

// Cache-buster baseado no mtime do arquivo
$assetVer = function (string $rel) {
    $f = __DIR__ . '/' . ltrim($rel, '/');
    return file_exists($f) ? (int) filemtime($f) : time();
};

// Evita o HTML ser cacheado por intermediários
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="<?= $isDona ? '#FAF7F4' : '#0a0a0a' ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="<?= $isDona ? 'default' : 'black-translucent' ?>">
    <meta name="apple-mobile-web-app-title" content="<?= $appName ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">

    <link rel="manifest" href="<?= $base ?>/manifest.php?v=<?= $assetVer('index.php') ?>">
    <link rel="icon" href="<?= $base ?>/icons/icon-192.png">
    <link rel="apple-touch-icon" href="<?= $base ?>/icons/icon-192.png">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/style.css?v=<?= $assetVer('assets/css/style.css') ?>">
<?php if ($isDona): ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,500;0,9..144,600;0,9..144,700&family=Inter+Tight:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/theme-dona.css?v=<?= $assetVer('assets/css/theme-dona.css') ?>">
<?php endif; ?>

    <title><?= $appName ?></title>
</head>
<body<?= $isDona ? ' class="theme-dona"' : '' ?>>

<!-- ====== TELA DE LOGIN ====== -->
<div id="login-screen" class="screen active">
    <div class="login-card">
        <div class="logo">
<?php if ($isDona): ?>
            <img src="https://publishdev.com.br/dona/dona-logo.png" alt="Donattela">
<?php else: ?>
            <svg viewBox="0 0 24 24" width="64" height="64" fill="currentColor" aria-hidden="true">
                <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/>
            </svg>
<?php endif; ?>
        </div>
        <h1><?= $appName ?></h1>
        <p class="tagline"><?= $isDona ? 'Rádio da equipe Donattela — aperte e fale' : 'Comunicação por rádio em tempo real' ?></p>

        <form id="login-form" autocomplete="off">
            <input type="text" id="display-name" name="display_name"
                   placeholder="Seu nome ou apelido"
                   required minlength="2" maxlength="60"
                   autocomplete="username" autocapitalize="words">
            <!-- Só aparece quando o nome pertence a uma conta protegida -->
            <input type="password" id="login-password" name="password"
                   placeholder="Senha da conta"
                   maxlength="120" hidden
                   autocomplete="current-password">
            <button type="submit" class="btn-primary">
                Entrar no canal
            </button>
        </form>

        <p class="hint">Você precisará permitir acesso ao microfone</p>
        <p class="app-version">v<?= $appVersion ?></p>
<?php if ($isDona): ?>
        <div class="dev-credit">
            <a href="https://publishdigital.com.br/" target="_blank" rel="noopener">
                Desenvolvido por <strong>Publish Digital</strong>
            </a>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ====== TELA DE CONVERSAS (estilo WhatsApp) ====== -->
<div id="contacts-screen" class="screen">
    <header class="radio-header">
        <div class="header-left">
            <div class="status-dot" id="c-connection-dot"></div>
            <span id="c-connection-text">Conectando...</span>
        </div>
        <div class="header-center"><strong>Conversas</strong></div>
        <div class="header-right">
            <button id="c-logout" class="icon-btn" title="Sair">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
            </button>
        </div>
    </header>

    <div class="contacts-body">
        <!-- Canal Principal (rádio do grupo) -->
        <button id="open-channel" class="channel-card">
            <span class="channel-avatar">📻</span>
            <span class="channel-info">
                <strong><?= $isDona ? 'Canal Donattela' : 'Canal Principal' ?></strong>
                <small>Rádio do grupo — toque para falar (PTT)</small>
            </span>
            <span class="channel-count"><span id="channel-online">0</span> online</span>
        </button>

        <div class="contacts-section">Contatos online</div>
        <ul id="contacts-list" class="contacts-list">
            <li class="contacts-empty">Ninguém online além de você</li>
        </ul>
    </div>
</div>

<!-- ====== TELA PRINCIPAL DO RÁDIO ====== -->
<div id="radio-screen" class="screen">

    <!-- Header -->
    <header class="radio-header">
        <div class="header-left">
            <button id="radio-back" class="icon-btn" title="Voltar" aria-label="Voltar">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20z"/></svg>
            </button>
            <div class="status-dot" id="connection-dot"></div>
            <span id="connection-text">Conectando...</span>
        </div>
        <div class="header-center">
            <span id="room-name">Canal Principal</span>
        </div>
        <div class="header-right">
            <button id="users-toggle" class="icon-btn" title="Usuários">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <span id="user-count" class="badge">0</span>
                <span id="msg-badge" class="badge badge-msg" style="display:none">0</span>
            </button>
        </div>
    </header>

    <!-- Banner: estou num canal privado -->
    <div id="private-banner" class="private-banner"></div>
    <!-- Banner: alguém está falando comigo no privado -->
    <div id="private-incoming" class="private-incoming"></div>

    <!-- Status do transmissor atual -->
    <div class="speaker-info" id="speaker-info">
        <div class="speaker-status" id="speaker-status">Aguardando...</div>
        <div class="speaker-name" id="speaker-name">Ninguém transmitindo</div>
        <div class="audio-meter">
            <div class="audio-bars" id="audio-bars">
                <span></span><span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span><span></span>
            </div>
        </div>
        <div class="talk-timer" id="talk-timer"></div>
    </div>

    <!-- Fila -->
    <div class="queue-display" id="queue-display">
        <div class="queue-label">Na fila:</div>
        <div class="queue-list" id="queue-list">
            <span class="queue-empty">Vazia</span>
        </div>
    </div>

    <!-- Botão PTT principal -->
    <div class="ptt-container">
        <button id="ptt-btn" class="ptt-button" aria-label="Apertar para falar">
            <div class="ptt-ring"></div>
            <div class="ptt-inner">
                <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17.3 11c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/></svg>
                <div class="ptt-label">FALAR</div>
            </div>
        </button>
        <div class="ptt-hint" id="ptt-hint">Pressione e segure para falar</div>
    </div>

    <!-- Botão Chamar Atenção -->
    <div class="attention-container">
        <button id="attention-btn" class="attention-button" aria-label="Chamar atenção">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
            <span>ALERTA</span>
            <span id="attention-cooldown" class="cooldown-text"></span>
        </button>
    </div>
</div>

<!-- ====== PAINEL DE USUÁRIOS ONLINE ====== -->
<div id="users-panel" class="side-panel">
    <div class="panel-header">
        <h3>Online no canal</h3>
        <button class="icon-btn" onclick="document.getElementById('users-panel').classList.remove('open')"
                aria-label="Fechar">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
    </div>
    <p class="panel-hint">Toque em um nome para falar no privado 🔒</p>
    <ul id="users-list" class="users-list"></ul>

    <div class="volume-control">
        <label for="volume-slider">🔊 Volume de saída <span id="volume-value">200%</span></label>
        <input type="range" id="volume-slider" min="50" max="400" step="10" value="200">
        <small id="volume-note" class="volume-note"></small>
    </div>
</div>

<!-- ====== CHAT PRIVADO (texto / recados) ====== -->
<div id="private-chat" class="private-chat">
    <div class="pc-header">
        <span class="pc-title">🔒 <span id="pc-name">Privado</span></span>
        <div class="pc-actions">
            <button id="pc-call" class="icon-btn" title="Ligar (voz)" aria-label="Ligar voz">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
            </button>
            <button id="pc-vcall" class="icon-btn" title="Chamada de vídeo" aria-label="Chamada de vídeo">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>
            </button>
            <button id="pc-max" class="icon-btn" title="Maximizar" aria-label="Maximizar">
                <svg id="pc-max-icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
            </button>
            <button id="pc-min" class="icon-btn" title="Minimizar" aria-label="Minimizar">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M6 19h12v2H6z"/></svg>
            </button>
            <button id="pc-close" class="icon-btn" title="Encerrar" aria-label="Encerrar">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>
    </div>
    <div id="pc-messages" class="pc-messages"></div>
    <div id="pc-recording" class="pc-recording"></div>
    <form id="pc-form" class="pc-form" autocomplete="off">
        <button type="button" id="pc-rec" class="pc-rec" aria-label="Segurar para gravar áudio" title="Segurar para gravar">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17.3 11c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.49 6-3.31 6-6.72h-1.7z"/></svg>
        </button>
        <input id="pc-input" type="text" placeholder="Mensagem ou recado…" maxlength="2000" autocomplete="off">
        <button type="submit" class="pc-send" aria-label="Enviar">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
    </form>
</div>

<!-- ====== BARRA DE LIGAÇÃO EM ANDAMENTO ====== -->
<div id="call-bar" class="call-bar">
    <span id="call-bar-text">Em ligação…</span>
    <button id="call-speaker" class="call-speaker" title="Alto-falante" aria-label="Alternar alto-falante">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
    </button>
    <button id="call-hangup" class="call-hangup" aria-label="Desligar">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08c-.18-.17-.29-.42-.29-.7 0-.28.11-.53.29-.71C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.7l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.1-.7-.28-.79-.74-1.69-1.36-2.67-1.85-.33-.16-.56-.51-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/></svg>
    </button>
</div>

<!-- ====== CHAMADA DE VÍDEO (tela cheia) ====== -->
<div id="video-call" class="video-call">
    <video id="video-remote" class="video-remote" autoplay playsinline></video>
    <video id="video-local" class="video-local" autoplay playsinline muted></video>
    <div class="video-top"><span id="video-name">…</span></div>
    <div class="video-controls">
        <button id="video-mic" class="vc-btn" title="Microfone" aria-label="Microfone">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
        </button>
        <button id="video-flip" class="vc-btn" title="Virar câmera" aria-label="Virar câmera">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M20 4h-3.17L15 2H9L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm-5 11.5V13H9v2l-3-3 3-3v2h6v-2l3 3-3 3z"/></svg>
        </button>
        <button id="video-hangup" class="vc-btn hangup" title="Desligar" aria-label="Desligar">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08c-.18-.17-.29-.42-.29-.7 0-.28.11-.53.29-.71C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.7l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.1-.7-.28-.79-.74-1.69-1.36-2.67-1.85-.33-.16-.56-.51-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"/></svg>
        </button>
    </div>
</div>

<!-- ====== OVERLAY DE CHAMADA RECEBIDA ====== -->
<div id="call-incoming" class="call-incoming">
    <div class="call-card">
        <div class="call-pulse">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
        </div>
        <h2 id="call-title">📞 Ligação recebida</h2>
        <p id="call-from">Alguém</p>
        <div class="call-actions">
            <button id="call-decline" class="call-btn decline">Recusar</button>
            <button id="call-accept" class="call-btn accept">Atender</button>
        </div>
    </div>
</div>

<!-- ====== OVERLAY DE ALERTA ====== -->
<div id="attention-overlay" class="attention-overlay">
    <div class="attention-card">
        <div class="alert-icon">
            <svg viewBox="0 0 24 24" width="64" height="64" fill="currentColor"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
        </div>
        <h2 id="attention-title">⚠️ ALERTA</h2>
        <p id="attention-text">Solicitando prioridade</p>
    </div>
</div>

<!-- ====== BLUR DE PRIVACIDADE (ao perder o foco da aba) ====== -->
<div id="blur-guard" class="blur-guard" aria-hidden="true">
    <div class="blur-guard-card">
        <svg viewBox="0 0 24 24" width="56" height="56" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
        <p>Conteúdo protegido</p>
        <small>Toque para voltar</small>
    </div>
</div>

<!-- ====== TOAST ====== -->
<div id="toast-container" class="toast-container"></div>

<!-- ====== ÁUDIO ELEMENTS (criados dinamicamente) ====== -->
<div id="audio-pool" hidden></div>

<!-- Áudio do alerta (gerado via Web Audio API também) -->
<audio id="alert-sound" preload="auto" hidden>
    <source src="<?= $base ?>/assets/alert.mp3" type="audio/mpeg">
</audio>

<script>
window.WT_CONFIG = {
    wsUrl: '<?= $wsUrl ?>',
    apiBase: '<?= $base ?>',
    basePath: '<?= $base ?>/',
    appVersion: '<?= $appVersion ?>',
    vapidPublicKey: '<?= $vapidPublic ?>',
};
</script>
<script src="<?= $base ?>/assets/js/utils.js?v=<?= $assetVer('assets/js/utils.js') ?>"></script>
<script src="<?= $base ?>/assets/js/notifications.js?v=<?= $assetVer('assets/js/notifications.js') ?>"></script>
<script src="<?= $base ?>/assets/js/websocket.js?v=<?= $assetVer('assets/js/websocket.js') ?>"></script>
<script src="<?= $base ?>/assets/js/webrtc.js?v=<?= $assetVer('assets/js/webrtc.js') ?>"></script>
<script src="<?= $base ?>/assets/js/queue.js?v=<?= $assetVer('assets/js/queue.js') ?>"></script>
<script src="<?= $base ?>/assets/js/ptt.js?v=<?= $assetVer('assets/js/ptt.js') ?>"></script>
<script src="<?= $base ?>/assets/js/background.js?v=<?= $assetVer('assets/js/background.js') ?>"></script>
<script src="<?= $base ?>/assets/js/app.js?v=<?= $assetVer('assets/js/app.js') ?>"></script>

</body>
</html>
