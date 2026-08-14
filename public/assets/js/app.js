/**
 * app.js — Orquestrador principal Walkie Talkie
 * Liga login, websocket, webrtc, fila, ptt, notificações e PWA.
 */
(function () {
    'use strict';

    const cfg = window.WT_CONFIG || {};

    WT.state = {
        token: localStorage.getItem('wt_token') || null,
        user: JSON.parse(localStorage.getItem('wt_user') || 'null'),
        room: null,
        onlineUsers: [],
        currentSpeaker: null,
        queue: [],
        iceServers: [{ urls: 'stun:stun.l.google.com:19302' }],
        maxTalkSeconds: 30,
        attentionCooldown: 60,
        connected: false
    };

    /* ========================== DOM helpers ========================== */
    const $ = (sel) => document.querySelector(sel);

    function showScreen(name) {
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
        const el = document.getElementById(name + '-screen');
        if (el) el.classList.add('active');
    }

    function updateOnlineCount(n) {
        const el = document.getElementById('user-count');
        if (el) el.textContent = n;
    }

    function renderUsersList(users) {
        const list = document.getElementById('users-list');
        if (!list) return;
        users = users || WT.state.onlineUsers || [];
        list.innerHTML = '';
        const myUuid = WT.state.user?.uuid;
        const privTarget = WT.private?.target;
        users.forEach(u => {
            const li = document.createElement('li');
            const isMe = u.uuid === myUuid;
            const isPriv = u.uuid === privTarget;
            const unread = (WT.private?.unread && WT.private.unread[u.uuid]) || 0;
            li.className = 'user-item' + (isMe ? '' : ' clickable') + (isPriv ? ' private-active' : '');
            li.innerHTML = `
                <span class="user-avatar" style="background:${WT.escape(u.avatar_color || '#3aa676')}">
                    ${WT.escape(WT.initials(u.display_name))}
                </span>
                <span class="user-name">${WT.escape(u.display_name)}${WT.isVerified(u) ? ' ' + WT.VERIFIED_BADGE : ''}${isMe ? ' <small>(você)</small>' : ''}</span>
                ${unread ? `<span class="user-unread">${unread > 9 ? '9+' : unread}</span>` : ''}
                ${isMe ? '' : `<span class="user-priv" title="Conversar no privado">${isPriv ? '🔒' : '💬'}</span>`}
                <span class="user-status on"></span>
            `;
            if (!isMe) {
                li.addEventListener('click', () => WT.private.toggle(u));
            }
            list.appendChild(li);
        });

        renderContacts(users);
    }

    // Lista de conversas (estilo WhatsApp) na tela inicial
    function renderContacts(users) {
        users = users || WT.state.onlineUsers || [];
        const myUuid = WT.state.user?.uuid;
        const others = users.filter(u => u.uuid !== myUuid);

        const chOnline = document.getElementById('channel-online');
        if (chOnline) chOnline.textContent = users.length;

        const list = document.getElementById('contacts-list');
        if (!list) return;
        if (!others.length) {
            list.innerHTML = '<li class="contacts-empty">Ninguém online além de você</li>';
            return;
        }
        list.innerHTML = '';
        others.forEach(u => {
            const unread = (WT.private?.unread && WT.private.unread[u.uuid]) || 0;
            const li = document.createElement('li');
            li.className = 'contact-row';
            li.innerHTML = `
                <span class="contact-avatar" style="background:${WT.escape(u.avatar_color || '#3aa676')}">
                    ${WT.escape(WT.initials(u.display_name))}
                </span>
                <span class="contact-main">
                    <span class="contact-name">${WT.escape(u.display_name)}${WT.isVerified(u) ? ' ' + WT.VERIFIED_BADGE : ''}</span>
                    <span class="contact-sub">${unread ? unread + ' nova(s) mensagem(ns)' : 'online'}</span>
                </span>
                ${unread ? `<span class="user-unread">${unread > 9 ? '9+' : unread}</span>` : ''}
            `;
            li.addEventListener('click', () => WT.private.open(u));
            list.appendChild(li);
        });
    }

    // Exposto para WT.private re-renderizar as listas ao abrir/fechar canal
    WT.app = WT.app || {};
    WT.app.renderUsersList = renderUsersList;
    WT.app.renderContacts = renderContacts;

    /* ========================== Login ========================== */
    async function doLogin(payload) {
        try {
            const data = await WT.api('/api/login.php', { method: 'POST', body: payload });
            if (!data || !data.token) throw new Error('Resposta inválida');
            WT.state.token = data.token;
            WT.state.user = data.user;
            localStorage.setItem('wt_token', data.token);
            localStorage.setItem('wt_user', JSON.stringify(data.user));
            await enterRadio();
        } catch (err) {
            // Nome pertence a uma conta protegida: revela o campo de senha
            if (err.data?.data?.needs_password) {
                revealPasswordField();
            }
            WT.toast(err.message || 'Falha no login', 'error');
        }
    }

    function revealPasswordField() {
        const pwd = document.getElementById('login-password');
        if (!pwd) return;
        pwd.hidden = false;
        pwd.required = true;
        pwd.focus();
    }

    function bindLoginForm() {
        const form = document.getElementById('login-form');
        if (!form) return;
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const input = document.getElementById('display-name');
            const pwd = document.getElementById('login-password');
            const name = (input?.value || '').trim();
            if (name.length < 2) {
                WT.toast('Informe um nome com pelo menos 2 caracteres', 'warning');
                return;
            }
            const payload = { display_name: name };
            const senha = (pwd && !pwd.hidden) ? pwd.value : '';
            if (senha) payload.password = senha;
            doLogin(payload);
        });
    }

    /* ========================== Radio screen ========================== */
    async function enterRadio() {
        showScreen('contacts'); // landing estilo WhatsApp
        askCameraOnGesture();   // pede a câmera no 1º toque (prompt confiável)

        // Inicializa subsistemas (idempotente)
        if (WT.ptt && !WT.ptt._inited)        { WT.ptt.init(); WT.ptt._inited = true; }
        if (WT.attention && !WT.attention._inited) { WT.attention.init(); WT.attention._inited = true; }
        if (WT.private && WT.private.initDom) WT.private.initDom();
        if (WT.call && WT.call.initDom) WT.call.initDom();

        // Microfone
        if (WT.rtc && WT.rtc.initMic) {
            try { await WT.rtc.initMic(); }
            catch (err) { WT.toast('Microfone bloqueado: ' + err.message, 'error'); }
        }

        // Notificações locais + inscrição no Web Push (chega com o app fechado)
        if (WT.notifications && WT.notifications.init) WT.notifications.init();

        // Push abriu o app apontando para uma conversa?
        handleDeepLink();

        // Modo plantão (silent audio + media session + wake lock + heartbeat)
        if (WT.background && WT.background.enable && !WT.background._enabled) {
            WT.background.enable();
            WT.background._enabled = true;
        }

        // Conectar WebSocket
        connectWS();
    }

    /* ========================== WebSocket handlers ========================== */
    function connectWS() {
        WT.ws.on('auth_ok', (msg) => {
            WT.state.connected = true;
            WT.state.room   = msg.room || null;
            WT.state.user   = msg.user || WT.state.user;
            WT.state.onlineUsers = msg.online || [];
            if (msg.config) {
                WT.state.maxTalkSeconds   = msg.config.max_talk_seconds   || 30;
                WT.state.attentionCooldown = msg.config.attention_cooldown || 60;
            }
            if (msg.user) localStorage.setItem('wt_user', JSON.stringify(msg.user));

            updateOnlineCount(WT.state.onlineUsers.length);
            renderUsersList(WT.state.onlineUsers);
            if (msg.queue) WT.queue.update(msg.queue);

            if (WT.rtc && WT.rtc.syncPeers && WT.state.user) {
                WT.rtc.syncPeers(WT.state.onlineUsers.map(u => u.uuid), WT.state.user.uuid);
            }

            const roomName = document.getElementById('room-name');
            if (roomName && msg.room) roomName.textContent = msg.room.name;
        });

        WT.ws.on('auth_error', () => doLogout());

        WT.ws.on('user_online', (msg) => {
            const u = msg.user;
            if (!u) return;
            if (!WT.state.onlineUsers.find(x => x.uuid === u.uuid)) WT.state.onlineUsers.push(u);
            updateOnlineCount(WT.state.onlineUsers.length);
            renderUsersList(WT.state.onlineUsers);
            if (WT.state.user && u.uuid !== WT.state.user.uuid) {
                WT.toast(`${u.display_name} entrou`, 'info');
                if (WT.rtc && WT.rtc.syncPeers) {
                    WT.rtc.syncPeers(WT.state.onlineUsers.map(x => x.uuid), WT.state.user.uuid);
                }
            }
        });

        WT.ws.on('user_offline', (msg) => {
            const u = msg.user || {};
            const uuid = u.uuid;
            const removed = WT.state.onlineUsers.find(x => x.uuid === uuid);
            WT.state.onlineUsers = WT.state.onlineUsers.filter(x => x.uuid !== uuid);
            updateOnlineCount(WT.state.onlineUsers.length);
            renderUsersList(WT.state.onlineUsers);
            if (WT.rtc && WT.rtc.removePeer) WT.rtc.removePeer(uuid);
            if (removed) WT.toast(`${removed.display_name} saiu`, 'info');
        });

        WT.ws.on('queue_update', (msg) => {
            if (msg.state) WT.queue.update(msg.state);
        });

        WT.ws.on('talk_start', (msg) => {
            WT.ptt && WT.ptt.onTalkStart && WT.ptt.onTalkStart(msg);
            if (msg.user && WT.state.user && msg.user.id !== WT.state.user.id
                && document.visibilityState !== 'visible') {
                WT.notifications.show(`${msg.user.display_name} está falando`, { silent: true });
            }
        });

        WT.ws.on('talk_stop', (msg) => {
            WT.ptt && WT.ptt.onTalkStop && WT.ptt.onTalkStop(msg);
        });

        WT.ws.on('talk_queued', (msg) => {
            WT.ptt && WT.ptt.onTalkQueued && WT.ptt.onTalkQueued(msg);
        });

        WT.ws.on('talk_timeout', (msg) => {
            WT.ptt && WT.ptt.onTalkTimeout && WT.ptt.onTalkTimeout(msg);
            WT.toast('Tempo máximo de transmissão atingido', 'warning');
        });

        WT.ws.on('attention_request', (msg) => {
            WT.attention && WT.attention.showAlert && WT.attention.showAlert(msg.user || {});
        });

        WT.ws.on('attention_cooldown', (msg) => {
            WT.attention && WT.attention.startCooldown
                && WT.attention.startCooldown(msg.remaining || WT.state.attentionCooldown);
        });

        WT.ws.on('error', (msg) => {
            WT.toast(msg.message || 'Erro do servidor', 'error');
        });

        // Chat privado (texto / recados)
        WT.ws.on('private_msg',      (m) => WT.private?.onMessage?.(m));
        WT.ws.on('private_msg_sent', (m) => WT.private?.onSent?.(m));
        WT.ws.on('private_seen',     (m) => WT.private?.onSeen?.(m));
        WT.ws.on('private_history',  (m) => WT.private?.onHistory?.(m));

        // Ligação ao vivo (full-duplex) — áudio e vídeo
        WT.ws.on('call_request', (m) => WT.call?.onRequest?.(m.from, m.media));
        WT.ws.on('call_accept',  (m) => WT.call?.onAccepted?.(m.from));
        WT.ws.on('call_decline', (m) => WT.call?.onDeclined?.(m.from));
        WT.ws.on('call_end',     (m) => WT.call?.onEnded?.(m.from));
        WT.ws.on('webrtc_renegotiate', (m) => WT.rtc?.onRenegotiate?.(m.from));

        // Conversa privada (1-a-1)
        WT.ws.on('private_start', (m) => {
            const u = WT.state.onlineUsers.find(x => x.uuid === m.from) || { display_name: 'Alguém', uuid: m.from };
            WT.private?.onIncoming?.(u, true);
        });
        WT.ws.on('private_stop', (m) => {
            const u = WT.state.onlineUsers.find(x => x.uuid === m.from) || { display_name: 'Alguém', uuid: m.from };
            WT.private?.onIncoming?.(u, false);
        });

        // WebRTC signalling
        WT.ws.on('webrtc_offer',  (m) => WT.rtc?.handleOffer?.(m.from, m.sdp));
        WT.ws.on('webrtc_answer', (m) => WT.rtc?.handleAnswer?.(m.from, m.sdp));
        WT.ws.on('webrtc_ice',    (m) => WT.rtc?.handleIce?.(m.from, m.candidate));

        WT.ws.connect(WT.state.token);
    }

    /* ========================== Proteção (anti-vazamento, deterrente) ========================== */
    function bindPrivacyGuard() {
        // 1) Bloqueia menu de contexto (botão direito / long-press)
        document.addEventListener('contextmenu', (e) => e.preventDefault());

        // 2) Bloqueia atalhos comuns de salvar/imprimir/ver-fonte/devtools
        document.addEventListener('keydown', (e) => {
            const k = (e.key || '').toLowerCase();
            const ctrl = e.ctrlKey || e.metaKey;
            const inField = /^(input|textarea)$/i.test(document.activeElement?.tagName || '');

            if (k === 'f12') { e.preventDefault(); return; }                       // devtools
            if (ctrl && e.shiftKey && ['i', 'j', 'c'].includes(k)) { e.preventDefault(); return; } // devtools
            if (ctrl && ['s', 'p', 'u'].includes(k)) { e.preventDefault(); return; } // salvar/imprimir/fonte
            if (!inField && ctrl && k === 'c') { e.preventDefault(); return; }       // copiar (fora de campos)
            if (k === 'printscreen') {
                // Não dá pra impedir o print; tenta limpar o clipboard e ofusca por 1s.
                try { navigator.clipboard && navigator.clipboard.writeText(' '); } catch (_) {}
                flashBlur();
            }
        });

        // 3) Blur ao perder o foco da aba / minimizar / trocar de app
        const show = () => toggleBlur(true);
        const hide = () => toggleBlur(false);
        window.addEventListener('blur', show);
        window.addEventListener('focus', hide);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') show(); else hide();
        });
        const guard = document.getElementById('blur-guard');
        if (guard) guard.addEventListener('click', hide);
    }

    function toggleBlur(on) {
        const g = document.getElementById('blur-guard');
        if (!g) return;
        // Só faz sentido depois de logado (não na tela de login)
        if (on && !WT.state.user) return;
        g.classList.toggle('show', on);
    }

    function flashBlur() {
        toggleBlur(true);
        setTimeout(() => { if (document.hasFocus()) toggleBlur(false); }, 1200);
    }

    /* ========================== Permissão da câmera (no 1º gesto) ========================== */
    function askCameraOnGesture() {
        if (!WT.rtc || WT.rtc.camPreauthorized) return;
        // 'click'/'touchend' contam como ativação do usuário (mobile/iOS exigem
        // isso p/ getUserMedia). 'pointerdown' NÃO basta no mobile -> prompt não aparece.
        const ask = () => {
            document.removeEventListener('touchend', ask, true);
            document.removeEventListener('click', ask, true);
            WT.rtc.requestCameraPermission && WT.rtc.requestCameraPermission();
        };
        document.addEventListener('touchend', ask, { once: true, capture: true });
        document.addEventListener('click', ask, { once: true, capture: true });
    }

    /* ========================== Navegação (conversas <-> rádio) ========================== */
    function bindContacts() {
        const openCh = document.getElementById('open-channel');
        const back = document.getElementById('radio-back');
        const logout = document.getElementById('c-logout');
        if (openCh) openCh.addEventListener('click', () => showScreen('radio'));
        if (back) back.addEventListener('click', () => showScreen('contacts'));
        if (logout) logout.addEventListener('click', () => {
            if (confirm('Deseja sair?')) doLogout();
        });
    }

    /* ========================== Side panel (lista de usuários) ========================== */
    function bindSidePanel() {
        const toggle = document.getElementById('users-toggle');
        const panel = document.getElementById('users-panel');
        if (!toggle || !panel) return;
        toggle.addEventListener('click', () => panel.classList.toggle('open'));
    }

    /* ========================== Controle de volume ========================== */
    function bindVolume() {
        const slider = document.getElementById('volume-slider');
        const label  = document.getElementById('volume-value');
        if (!slider) return;

        slider.min = 0;
        slider.max = 100;
        slider.step = 5;
        const initial = (WT.rtc && typeof WT.rtc.outputGain === 'number') ? WT.rtc.outputGain : 1;
        slider.value = Math.round(Math.min(1, initial) * 100);

        const apply = () => {
            const pct = parseInt(slider.value, 10);
            if (WT.rtc && WT.rtc.setOutputGain) WT.rtc.setOutputGain(pct / 100);
            if (label) label.textContent = pct + '%';
        };
        slider.addEventListener('input', apply);
        apply();
    }

    /* ========================== Destrava de áudio (mobile) ========================== */
    // Android Chrome inicia o AudioContext suspenso; iOS pode re-suspender.
    // Qualquer gesto reativa o contexto e os elementos <audio>.
    function bindAudioUnlock() {
        const resume = () => {
            try { if (WT.rtc && WT.rtc.audioCtx && WT.rtc.audioCtx.state === 'suspended') WT.rtc.audioCtx.resume(); } catch (_) {}
            try { if (WT._audioCtx && WT._audioCtx.state === 'suspended') WT._audioCtx.resume(); } catch (_) {}
            // Reforça o play dos elementos remotos (autoplay bloqueado até o gesto)
            if (WT.rtc && WT.rtc.peers) {
                Object.values(WT.rtc.peers).forEach(p => { if (p.audioEl) p.audioEl.play().catch(() => {}); });
            }
        };
        ['pointerdown', 'touchend', 'click', 'keydown'].forEach(ev =>
            document.addEventListener(ev, resume, { passive: true }));
    }

    /* ========================== Logout ========================== */
    function doLogout() {
        if (WT.ws.close) WT.ws.close();
        if (WT.rtc && WT.rtc.closeAll) WT.rtc.closeAll();
        localStorage.removeItem('wt_token');
        localStorage.removeItem('wt_user');
        WT.state.token = null;
        WT.state.user = null;
        showScreen('login');
    }

    /* ========================== Service Worker ========================== */
    function registerSW() {
        if (!('serviceWorker' in navigator)) return;
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') return;
        const base = (cfg.basePath || '/');

        // Recarrega automaticamente quando o novo SW assume — atualiza todos os
        // usuários sem precisar tocar em nada. Adia só se estiver em ligação/gravando.
        let reloading = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (reloading) return;
            reloading = true;
            safeReload();
        });

        // A versão vai na URL do SW: assim o CACHE_VERSION vem de APP_VERSION (.env)
        // e não precisa mais ser editado à mão dentro do service-worker.js.
        const swUrl = base + 'service-worker.js?v=' + encodeURIComponent(cfg.appVersion || '0');

        navigator.serviceWorker.register(swUrl, { scope: base })
            .then(reg => {
                // Procura atualização agora, ao voltar o foco e a cada 30 min.
                reg.update().catch(() => {});
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') reg.update().catch(() => {});
                });
                setInterval(() => reg.update().catch(() => {}), 30 * 60 * 1000);

                reg.addEventListener('updatefound', () => {
                    const sw = reg.installing;
                    if (!sw) return;
                    sw.addEventListener('statechange', () => {
                        // Update (não 1ª instalação): aplica automaticamente.
                        if (sw.state === 'installed' && navigator.serviceWorker.controller) {
                            if (WT.toast) WT.toast('🔄 Atualizando para a versão mais recente…', 'info', 2500);
                            const apply = () => (reg.waiting || sw).postMessage({ type: 'SKIP_WAITING' });
                            // Espera sair de ligação/gravação antes de trocar.
                            whenIdle(apply);
                        }
                    });
                });
            })
            .catch(err => console.warn('SW register fail:', err));

        // Toque na notificação de push -> abre a conversa certa
        navigator.serviceWorker.addEventListener('message', (ev) => {
            const { type, payload } = ev.data || {};
            if (type === 'NOTIFICATION_CLICK' && payload?.from) {
                openChatByUuid(payload.from, payload.from_name);
            }
            if (type === 'PUSH' && payload?.kind === 'attention' && WT.notifications) {
                WT.notifications.showAlert(payload.from_name || 'Alguém');
            }
        });
    }

    /** Abre a conversa privada com um uuid (usado pelo push e pelo ?chat=). */
    function openChatByUuid(uuid, fallbackName) {
        if (!uuid || !WT.private || !WT.private.open) return;
        const known = (WT.state.onlineUsers || []).find(u => u.uuid === uuid);
        WT.private.open(known || {
            uuid,
            display_name: fallbackName || 'Contato',
        });
    }

    /** ?chat=<uuid> na URL (o push abre o app já apontando para a conversa). */
    function handleDeepLink() {
        const uuid = new URLSearchParams(location.search).get('chat');
        if (!uuid) return;
        // Limpa a query para não reabrir a cada reload
        history.replaceState(null, '', location.pathname);
        setTimeout(() => openChatByUuid(uuid), 800); // espera o WS trazer os online
    }

    // Executa quando não houver ligação ativa nem gravação em andamento.
    function whenIdle(fn) {
        const busy = () => (WT.call && WT.call.state === 'incall') || (WT.voice && WT.voice.recording);
        if (!busy()) { fn(); return; }
        const t = setInterval(() => { if (!busy()) { clearInterval(t); fn(); } }, 1500);
    }

    function safeReload() {
        const busy = () => (WT.call && WT.call.state === 'incall') || (WT.voice && WT.voice.recording);
        if (!busy()) { location.reload(); return; }
        const t = setInterval(() => { if (!busy()) { clearInterval(t); location.reload(); } }, 1500);
    }

    /* ========================== Visibility / network ========================== */
    function bindVisibility() {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                if (WT.requestWakeLock) WT.requestWakeLock();
                if (!WT.state.connected && WT.state.token) WT.ws.connect(WT.state.token);
            }
        });
        window.addEventListener('online', () => {
            if (WT.state.token) WT.ws.connect(WT.state.token);
        });
    }

    /* ========================== Auto-login ========================== */
    async function tryAutoLogin() {
        if (!WT.state.token) { showScreen('login'); return; }
        try {
            await WT.api('/api/heartbeat.php', { method: 'POST', body: {} });
            await enterRadio();
        } catch (err) {
            localStorage.removeItem('wt_token');
            localStorage.removeItem('wt_user');
            WT.state.token = null;
            WT.state.user = null;
            showScreen('login');
        }
    }

    /* ========================== Boot ========================== */
    document.addEventListener('DOMContentLoaded', () => {
        bindLoginForm();
        bindContacts();
        bindSidePanel();
        bindVolume();
        bindAudioUnlock();
        bindPrivacyGuard();
        bindVisibility();
        registerSW();
        tryAutoLogin();
    });
})();
