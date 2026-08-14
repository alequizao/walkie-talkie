/**
 * PTT (Push To Talk)
 * - Pressiona e segura -> envia request_talk
 * - Solta -> envia stop_talk
 * - Quando o servidor confirma talk_start (com seu user.id), habilita áudio
 * - Anti-double-click via debounce
 */

WT.ptt = {
    isPressed: false,
    isMyTalking: false,
    isPrivateTalking: false,
    isQueued: false,
    isLocked: false, // outra pessoa falando
    talkStart: 0,
    talkTimer: null,
    button: null,
    lastAction: 0,

    init() {
        this.button = document.getElementById('ptt-btn');
        if (!this.button) return;

        // Mouse
        this.button.addEventListener('mousedown', e => { e.preventDefault(); this.press(); });
        this.button.addEventListener('mouseup',   e => { e.preventDefault(); this.release(); });
        this.button.addEventListener('mouseleave',e => this.release());

        // Touch
        this.button.addEventListener('touchstart', e => { e.preventDefault(); this.press(); }, { passive: false });
        this.button.addEventListener('touchend',   e => { e.preventDefault(); this.release(); }, { passive: false });
        this.button.addEventListener('touchcancel',e => this.release());

        // Atalho de teclado: Espaço (push to talk)
        document.addEventListener('keydown', (e) => {
            if (e.code === 'Space' && !e.repeat && document.activeElement?.tagName !== 'INPUT') {
                e.preventDefault();
                this.press();
            }
        });
        document.addEventListener('keyup', (e) => {
            if (e.code === 'Space') { e.preventDefault(); this.release(); }
        });

        this.updateUI();
    },

    press() {
        const now = Date.now();
        if (now - this.lastAction < 300) return; // debounce
        this.lastAction = now;
        if (this.isPressed) return;

        // Durante uma ligação ao vivo, o PTT não interfere no áudio contínuo.
        if (WT.call && WT.call.state === 'incall') {
            WT.toast('Você está em uma ligação', 'info', 1500);
            return;
        }

        // Modo privado: canal lateral 1-a-1, ignora a fila pública.
        if (WT.private && WT.private.target) {
            this.isPressed = true;
            this.isPrivateTalking = true;
            this.talkStart = Date.now();
            WT.rtc.setPrivate(WT.private.target, true);
            WT.ws.send({ type: 'private_start', target: WT.private.target });
            WT.playClick(true);
            this.startTimer();
            WT.vibrate(20);
            this.updateUI();
            return;
        }

        if (this.isLocked) {
            WT.toast('Aguarde sua vez na fila', 'warning', 1500);
            return;
        }
        this.isPressed = true;
        WT.ws.send({ type: 'request_talk' });
        this.updateUI();
        WT.vibrate(20);
    },

    release() {
        const now = Date.now();
        if (!this.isPressed) return;
        this.lastAction = now;
        this.isPressed = false;

        if (this.isPrivateTalking) {
            WT.rtc.setPrivate(WT.private.target, false);
            WT.ws.send({ type: 'private_stop', target: WT.private.target });
            WT.playClick(false);
            this.isPrivateTalking = false;
            this.stopTimer();
            this.updateUI();
            return;
        }

        if (this.isMyTalking) {
            WT.ws.send({ type: 'stop_talk' });
            WT.rtc.setLocalAudioEnabled(false);
            WT.playClick(false);
            this.isMyTalking = false;
            this.stopTimer();
        } else if (this.isQueued) {
            // Cancela pedido na fila
            WT.ws.send({ type: 'stop_talk' });
            this.isQueued = false;
        }
        this.updateUI();
    },

    onTalkStart(data) {
        // Servidor confirmou que ALGUÉM começou — pode ser eu ou outro
        const me = WT.state.user?.id;
        if (data.user?.id === me) {
            this.isMyTalking = true;
            this.isQueued = false;
            this.isLocked = false;
            this.talkStart = Date.now();
            WT.rtc.setLocalAudioEnabled(true);
            WT.playClick(true);
            this.startTimer();
            WT.notifications.show('Você está no ar', { body: 'Solte para encerrar', tag: 'mytalk' });
        } else {
            this.isMyTalking = false;
            this.isLocked = true;
            this.isQueued = false;
        }
        this.updateUI();
    },

    onTalkStop(data) {
        const me = WT.state.user?.id;
        if (data.user?.id === me) {
            this.isMyTalking = false;
            WT.rtc.setLocalAudioEnabled(false);
            WT.playClick(false);
            this.stopTimer();
        }
        this.isLocked = false;
        this.updateUI();
    },

    onTalkQueued(data) {
        this.isQueued = true;
        WT.toast(`Você está na fila (posição ${data.position + 1})`, 'info', 2500);
        this.updateUI();
    },

    onTalkTimeout() {
        if (this.isMyTalking) {
            this.isMyTalking = false;
            this.isPressed = false;
            WT.rtc.setLocalAudioEnabled(false);
            WT.toast('Tempo máximo atingido', 'warning');
            this.stopTimer();
        }
        this.isLocked = false;
        this.updateUI();
    },

    setExternalSpeaker(active) {
        // alguém falando que não sou eu
        if (active && !this.isMyTalking) {
            this.isLocked = true;
        } else if (!active) {
            this.isLocked = false;
        }
        this.updateUI();
    },

    updateUI() {
        if (!this.button) return;
        const priv = !!(WT.private && WT.private.target);
        this.button.classList.toggle('pressed', this.isMyTalking || this.isPrivateTalking);
        this.button.classList.toggle('private', priv);
        this.button.classList.toggle('queued', this.isQueued && !this.isMyTalking && !priv);
        this.button.classList.toggle('locked', this.isLocked && !this.isMyTalking && !this.isQueued && !priv);

        const hint = document.getElementById('ptt-hint');
        if (this.isPrivateTalking)      hint.textContent = `🔒 No ar (privado com ${WT.private.targetName})`;
        else if (priv)                  hint.textContent = `🔒 Privado com ${WT.private.targetName} — segure para falar`;
        else if (this.isMyTalking)      hint.textContent = 'Solte para encerrar';
        else if (this.isQueued)         hint.textContent = 'Você está na fila — solte para cancelar';
        else if (this.isLocked)         hint.textContent = 'Aguardando o canal liberar...';
        else                            hint.textContent = 'Pressione e segure para falar';
    },

    startTimer() {
        this.stopTimer();
        const tEl = document.getElementById('talk-timer');
        const max = WT.state.maxTalkSeconds || 30;
        this.talkTimer = setInterval(() => {
            const elapsed = Math.floor((Date.now() - this.talkStart) / 1000);
            const remaining = Math.max(0, max - elapsed);
            tEl.textContent = `⏱ ${WT.formatSeconds(elapsed)} / ${WT.formatSeconds(max)}`;
            if (remaining <= 5 && remaining > 0) {
                tEl.style.color = 'var(--warning)';
            }
        }, 250);
    },

    stopTimer() {
        if (this.talkTimer) clearInterval(this.talkTimer);
        this.talkTimer = null;
        const tEl = document.getElementById('talk-timer');
        if (tEl) { tEl.textContent = ''; tEl.style.color = ''; }
    },
};


/* =================== CONVERSA PRIVADA (1-a-1): áudio + texto =================== */
WT.private = {
    target: null,        // uuid do interlocutor
    targetName: '',
    minimized: false,
    unread: {},          // uuid -> contagem não lida
    _domReady: false,

    /** Liga os elementos do painel de chat (uma vez). */
    initDom() {
        if (this._domReady) return;
        const form = document.getElementById('pc-form');
        const input = document.getElementById('pc-input');
        const min  = document.getElementById('pc-min');
        const close = document.getElementById('pc-close');
        if (form) form.addEventListener('submit', (e) => {
            e.preventDefault();
            const v = (input?.value || '').trim();
            if (v) { this.sendText(v); input.value = ''; }
            input && input.focus();
        });
        if (min) min.addEventListener('click', () => this.minimize());
        if (close) close.addEventListener('click', () => this.close());
        const max = document.getElementById('pc-max');
        if (max) max.addEventListener('click', () => this.toggleMaximize());

        // Botão de voz: segurar para gravar, soltar para enviar
        const rec = document.getElementById('pc-rec');
        if (rec) {
            rec.addEventListener('pointerdown', (e) => { e.preventDefault(); this.startRecording(); });
            rec.addEventListener('pointerup',   (e) => { e.preventDefault(); this.finishRecording(); });
            rec.addEventListener('pointerleave', () => this.cancelRecording());
            rec.addEventListener('pointercancel', () => this.cancelRecording());
        }
        this._domReady = true;
    },

    /** Abre/fecha o canal privado com um usuário (toggle). */
    toggle(user) {
        if (!user || !user.uuid) return;
        if (this.target === user.uuid && !this.minimized) { this.close(); return; }
        this.open(user);
    },

    open(user) {
        if (WT.ptt.isMyTalking) WT.ptt.release(); // não pode estar no ar público
        this.initDom();
        const full = (WT.state.onlineUsers || []).find(x => x.uuid === user.uuid) || user;
        this.target = user.uuid;
        this.targetName = user.display_name || 'usuário';
        this.targetVerified = WT.isVerified(full);
        this.minimized = false;
        this.clearUnread(user.uuid);
        WT.vibrate(20);
        this._renderBanner();
        this._openPanel();
        this._clearMessages();
        WT.ws.send({ type: 'private_history', target: this.target });
        WT.ws.send({ type: 'private_read', target: this.target });
        WT.ptt.updateUI();
        WT.app && WT.app.renderUsersList && WT.app.renderUsersList();
    },

    /** Encerra completamente (áudio + chat). */
    close() {
        if (WT.ptt.isPrivateTalking) WT.ptt.release();
        this.target = null;
        this.targetName = '';
        this.minimized = false;
        this._renderBanner();
        this._closePanel();
        WT.ptt.updateUI();
        WT.app && WT.app.renderUsersList && WT.app.renderUsersList();
    },

    /** Esconde o painel mas mantém o canal (áudio segue mirando o alvo). */
    minimize() {
        this.minimized = true;
        this._closePanel();
        this._renderBanner();
    },

    /** Alterna entre tela cheia e o tamanho padrão (bottom sheet). */
    toggleMaximize() {
        const p = document.getElementById('private-chat');
        if (!p) return;
        this.maximized = !p.classList.contains('maximized');
        p.classList.toggle('maximized', this.maximized);
        const btn = document.getElementById('pc-max');
        if (btn) {
            btn.title = this.maximized ? 'Restaurar' : 'Maximizar';
            btn.setAttribute('aria-label', btn.title);
        }
        const box = document.getElementById('pc-messages');
        if (box) box.scrollTop = box.scrollHeight;
    },

    sendText(body) {
        if (!this.target) return;
        const ref = 'r' + Date.now() + Math.random().toString(36).slice(2, 6);
        WT.ws.send({ type: 'private_msg', target: this.target, body, ref });
        this._appendMessage({ mine: true, body, created_at: new Date().toISOString(), status: 'sending', ref });
    },

    /* ---- Recebimento via WS ---- */

    onMessage(msg) {
        const isAudio = msg.kind === 'audio';
        const open = this.target === msg.from && !this.minimized;
        if (open) {
            this._appendMessage({
                mine: false, kind: msg.kind, body: msg.body, id: msg.id,
                mediaId: msg.media_id, durationMs: msg.duration_ms, created_at: msg.created_at,
            });
            WT.ws.send({ type: 'private_read', target: this.target });
        } else {
            this.bumpUnread(msg.from);
            WT.playClick(true);
            WT.vibrate(20);
            const preview = isAudio ? '🎤 Recado de voz' : (msg.body || '').slice(0, 60);
            const label = msg.pending ? '📨 Recado de' : '💬';
            WT.toast(`${label} ${msg.from_name}: ${preview}`, 'info', 5000, () => {
                this.open({ uuid: msg.from, display_name: msg.from_name });
                const panel = document.getElementById('users-panel');
                if (panel) panel.classList.remove('open');
            });
            if (WT.notifications && WT.notifications.show && document.visibilityState !== 'visible') {
                WT.notifications.show(`${msg.from_name}`, { body: preview, tag: 'pm-' + msg.from });
            }
        }
    },

    onSent(msg) {
        // Confirma a mensagem otimista correspondente e fixa o id real na bolha
        const bubble = msg.ref && document.querySelector(`[data-ref="${msg.ref}"]`);
        if (bubble) {
            if (msg.id) bubble.dataset.id = msg.id;
            const st = bubble.querySelector('.pc-status');
            if (st) st.textContent = msg.delivered ? '✓' : '🕓';
        }
    },

    /** Recibo de leitura/escuta do destinatário (✓ -> ✓✓). */
    onSeen(msg) {
        if (msg.by !== this.target) return;
        if (msg.id) {
            const st = document.querySelector(`#pc-messages [data-id="${msg.id}"] .pc-status`);
            if (st) st.textContent = '✓✓';
        } else { // scope text: todas as minhas mensagens de texto
            document.querySelectorAll('#pc-messages .pc-msg.mine').forEach(b => {
                if (!b.querySelector('.pc-audio')) {
                    const st = b.querySelector('.pc-status');
                    if (st) st.textContent = '✓✓';
                }
            });
        }
    },

    onHistory(msg) {
        if (msg.target !== this.target) return;
        this._clearMessages();
        (msg.messages || []).forEach(m =>
            this._appendMessage({
                mine: m.mine, kind: m.kind, body: m.body, id: m.id,
                mediaId: m.media_id, durationMs: m.duration_ms, created_at: m.created_at,
                status: m.mine ? (m.read ? '✓✓' : '✓') : '',
            }));
    },

    /** Envia um recado de voz: faz upload e notifica via WS. */
    async sendVoice(blob, durationMs) {
        if (!this.target) return;
        const base = (window.WT_CONFIG && WT_CONFIG.apiBase) || '';
        const fd = new FormData();
        fd.append('target', this.target);
        fd.append('duration_ms', String(durationMs || 0));
        fd.append('audio', blob, 'voice.wav');

        const ref = 'v' + Date.now();
        this._appendMessage({ mine: true, kind: 'audio', mediaId: null, durationMs, created_at: new Date().toISOString(), status: '⏳', ref });

        try {
            const token = WT.state.token || '';
            const res = await fetch(`${base}/api/send-voice.php?token=${encodeURIComponent(token)}`, {
                method: 'POST',
                headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
                body: fd,
            });
            let json;
            try { json = await res.json(); } catch (_) { json = { success: false, message: 'Resposta inválida (' + res.status + ')' }; }
            if (!res.ok || !json.success) throw new Error(json.message || ('Erro ' + res.status));
            const data = json.data || {};
            // Atualiza a bolha otimista com o player real
            const bubble = document.querySelector(`[data-ref="${ref}"]`);
            if (bubble) {
                if (data.id) bubble.dataset.id = data.id;
                const audioSpan = `🎤<audio controls preload="none" src="${this._mediaUrl(data.id)}"></audio>`
                    + `<small class="pc-dur"> ${WT.formatSeconds(Math.round((durationMs||0)/1000))}</small>`;
                bubble.querySelector('.pc-audio').innerHTML = audioSpan;
                const st = bubble.querySelector('.pc-status');
                if (st) st.textContent = '✓';
            }
            // Notifica entrega em tempo real (offline = recado, entregue depois)
            WT.ws.send({ type: 'private_audio', target: this.target, id: data.id, ref });
        } catch (e) {
            WT.toast('Não foi possível enviar o áudio: ' + e.message, 'error');
            const st = document.querySelector(`[data-ref="${ref}"] .pc-status`);
            if (st) st.textContent = '⚠';
        }
    },

    /* ---- Gravação de voz (segurar o botão 🎤) ---- */
    async startRecording() {
        if (!this.target || WT.voice.recording) return;
        const ok = await WT.voice.start();
        if (!ok) { WT.toast('Não foi possível acessar o microfone', 'error'); return; }
        WT.vibrate(20);
        this._recUI(true);
    },
    finishRecording() {
        if (!WT.voice.recording) return;
        const r = WT.voice.stop();
        this._recUI(false);
        if (r && r.durationMs >= 500) this.sendVoice(r.blob, r.durationMs);
        else WT.toast('Áudio muito curto', 'warning', 1500);
    },
    cancelRecording() {
        if (!WT.voice.recording) return;
        WT.voice.cancel();
        this._recUI(false);
    },
    _recUI(active) {
        const rec = document.getElementById('pc-rec');
        const ind = document.getElementById('pc-recording');
        if (rec) rec.classList.toggle('recording', active);
        if (!ind) return;
        if (active) {
            ind.classList.add('show');
            this._recStart = Date.now();
            const upd = () => {
                const s = Math.floor((Date.now() - this._recStart) / 1000);
                ind.textContent = `● Gravando ${WT.formatSeconds(s)} — solte para enviar`;
                if (s >= 60) this.finishRecording(); // limite de 60s
            };
            upd();
            this._recTimer = setInterval(upd, 250);
        } else {
            ind.classList.remove('show');
            clearInterval(this._recTimer);
        }
    },

    /* ---- Não lidas ---- */
    bumpUnread(uuid) {
        this.unread[uuid] = (this.unread[uuid] || 0) + 1;
        WT.app && WT.app.renderUsersList && WT.app.renderUsersList();
        this._updateGlobalBadge();
    },
    clearUnread(uuid) {
        if (this.unread[uuid]) { delete this.unread[uuid]; this._updateGlobalBadge(); }
    },
    totalUnread() {
        return Object.values(this.unread).reduce((a, b) => a + b, 0);
    },
    _updateGlobalBadge() {
        const b = document.getElementById('msg-badge');
        if (!b) return;
        const n = this.totalUnread();
        b.textContent = n;
        b.style.display = n > 0 ? '' : 'none';
    },

    /* ---- DOM ---- */
    _openPanel() {
        const p = document.getElementById('private-chat');
        const name = document.getElementById('pc-name');
        if (name) name.innerHTML = WT.escape(this.targetName) + (this.targetVerified ? ' ' + WT.VERIFIED_BADGE : '');
        if (p) {
            p.classList.add('open');
            p.classList.add('maximized'); // abre em tela cheia (estilo WhatsApp)
            this.maximized = true;
        }
    },
    _closePanel() {
        const p = document.getElementById('private-chat');
        if (p) p.classList.remove('open');
    },
    _clearMessages() {
        const box = document.getElementById('pc-messages');
        if (box) box.innerHTML = '';
    },
    _mediaUrl(mediaId) {
        const base = (window.WT_CONFIG && WT_CONFIG.apiBase) || '';
        return `${base}/api/voice.php?id=${encodeURIComponent(mediaId)}&token=${encodeURIComponent(WT.state.token || '')}`;
    },

    _appendMessage({ mine, kind, body, mediaId, durationMs, created_at, status, ref, id }) {
        const box = document.getElementById('pc-messages');
        if (!box) return;
        const div = document.createElement('div');
        div.className = 'pc-msg ' + (mine ? 'mine' : 'theirs');
        if (ref) div.dataset.ref = ref;
        if (id) div.dataset.id = id;
        const time = WT.formatClock ? WT.formatClock(created_at) : '';

        let content;
        if (kind === 'audio') {
            const secs = durationMs ? Math.round(durationMs / 1000) : 0;
            const dur = secs ? ` ${WT.formatSeconds(secs)}` : '';
            const player = mediaId
                ? `🎤<audio controls preload="none" src="${this._mediaUrl(mediaId)}"></audio><small class="pc-dur">${dur}</small>`
                : `🎤<small class="pc-dur">enviando…</small>`;
            content = `<span class="pc-audio">${player}</span>`;
        } else {
            content = `<span class="pc-body">${WT.escape(body)}</span>`;
        }
        div.innerHTML = content
            + `<span class="pc-meta">${time}${mine ? ` <span class="pc-status">${WT.escape(status || '')}</span>` : ''}</span>`;
        box.appendChild(div);
        box.scrollTop = box.scrollHeight;

        // Áudio recebido: ao tocar pela 1ª vez, avisa o autor (recibo "ouvido" ✓✓)
        if (!mine && kind === 'audio' && mediaId) {
            const audioEl = div.querySelector('audio');
            if (audioEl) audioEl.addEventListener('play', () => {
                if (audioEl.dataset.seen) return;
                audioEl.dataset.seen = '1';
                if (this.target) WT.ws.send({ type: 'private_read', target: this.target, id: mediaId });
            }, { once: true });
        }
        return div;
    },

    _renderBanner() {
        const banner = document.getElementById('private-banner');
        if (!banner) return;
        if (this.target) {
            const minBtn = this.minimized
                ? `<button id="private-open" class="private-close">abrir chat</button>`
                : '';
            banner.innerHTML = `🔒 Privado com <strong>${WT.escape(this.targetName)}</strong> `
                + minBtn
                + ` <button id="private-close" class="private-close">sair</button>`;
            banner.classList.add('show');
            const close = document.getElementById('private-close');
            if (close) close.addEventListener('click', () => this.close());
            const openBtn = document.getElementById('private-open');
            if (openBtn) openBtn.addEventListener('click', () => {
                this.minimized = false; this._openPanel(); this._renderBanner();
                WT.ws.send({ type: 'private_read', target: this.target });
                this.clearUnread(this.target);
                WT.app && WT.app.renderUsersList && WT.app.renderUsersList();
            });
        } else {
            banner.classList.remove('show');
            banner.innerHTML = '';
        }
    },

    /** Alguém está falando comigo no privado (áudio, recebido via WS). */
    onIncoming(user, active) {
        const banner = document.getElementById('private-incoming');
        if (!banner) return;
        if (active) {
            banner.textContent = `🔒 ${user.display_name} está falando com você (privado)`;
            banner.classList.add('show');
            WT.vibrate(15);
        } else {
            banner.classList.remove('show');
        }
    },
};


/* =================== LIGAÇÃO AO VIVO (full-duplex) =================== */
// Usa a conexão WebRTC já existente: ambos habilitam o microfone um para o
// outro continuamente (sem PTT). Sinalização request/accept/decline/end via WS.
WT.call = {
    state: 'idle',     // idle | calling | ringing | incall
    media: 'audio',    // 'audio' | 'video'
    peer: null,        // uuid do outro
    peerName: '',
    timer: null,
    startedAt: 0,
    _facing: 'user',
    _micMuted: false,

    async _ensureMic() {
        if (WT.rtc && WT.rtc.localStream) return true;
        try { await WT.rtc.initMic(); return true; } catch (e) { return false; }
    },

    _name(uuid) {
        const u = (WT.state.onlineUsers || []).find(x => x.uuid === uuid);
        return u ? u.display_name : 'usuário';
    },

    /** Inicia uma ligação (áudio ou vídeo) para o alvo do chat privado. */
    async start(media = 'audio') {
        const target = WT.private && WT.private.target;
        if (!target) { WT.toast('Abra uma conversa privada primeiro', 'warning'); return; }
        if (this.state !== 'idle') return;
        if (!WT.rtc.peers[target]) { WT.toast('Usuário offline', 'warning'); return; }
        if (!await this._ensureMic()) { WT.toast('Microfone indisponível', 'error'); return; }

        this.media = media;
        this.peer = target;
        this.peerName = WT.private.targetName || this._name(target);
        this.state = 'calling';
        if (media === 'video') {
            this._facing = 'user';
            const cam = await WT.rtc.startCamera(this._facing);
            if (cam) { this._showVideoUI(true); this._attachLocalPreview(); }
            else { this.media = 'audio'; WT.toast('Sem câmera — seguindo só com áudio', 'warning'); }
        }
        WT.ws.send({ type: 'call_request', target, media: this.media });
        this._renderBar((media === 'video' ? '📹' : '📞') + ' Chamando ' + this.peerName + '…');
        WT.vibrate(30);
    },

    /** Recebeu pedido de ligação. */
    onRequest(fromUuid, media) {
        if (this.state === 'incall' || this.state === 'ringing' || this.state === 'calling') {
            WT.ws.send({ type: 'call_decline', target: fromUuid }); // ocupado
            return;
        }
        this.media = media || 'audio';
        this.peer = fromUuid;
        this.peerName = this._name(fromUuid);
        this.state = 'ringing';
        WT.playClick(true);
        WT.vibrate([300, 150, 300]);
        const ov = document.getElementById('call-incoming');
        const nm = document.getElementById('call-from');
        const ttl = document.getElementById('call-title');
        if (nm) nm.textContent = this.peerName;
        if (ttl) ttl.textContent = this.media === 'video' ? '📹 Chamada de vídeo' : '📞 Ligação recebida';
        if (ov) ov.classList.add('show');
    },

    async accept() {
        if (this.state !== 'ringing') return;
        if (!await this._ensureMic()) { this.decline(); return; }
        if (this.media === 'video') {
            this._facing = 'user';
            const cam = await WT.rtc.startCamera(this._facing);
            if (cam) { this._showVideoUI(true); this._attachLocalPreview(); }
            else { this.media = 'audio'; WT.toast('Sem câmera — atendendo só com áudio', 'warning'); }
        }
        this._hideIncoming();
        WT.ws.send({ type: 'call_accept', target: this.peer });
        this._begin();
    },

    decline() {
        if (this.peer) WT.ws.send({ type: 'call_decline', target: this.peer });
        this._hideIncoming();
        this._reset();
    },

    /** Caller: o outro aceitou. */
    onAccepted(fromUuid) {
        if (this.state === 'calling' && fromUuid === this.peer) this._begin();
    },

    onDeclined(fromUuid) {
        if (fromUuid === this.peer) {
            WT.toast(this.peerName + ' não atendeu', 'info');
            this._hideIncoming();
            this._reset();
        }
    },

    onEnded(fromUuid) {
        if (fromUuid === this.peer) {
            WT.toast('Ligação encerrada', 'info');
            this._hideIncoming();
            this._reset();
        }
    },

    hangup() {
        if (this.peer) WT.ws.send({ type: 'call_end', target: this.peer });
        this._reset();
    },

    /** Começa o áudio (e vídeo, se for o caso) full-duplex. */
    _begin() {
        this.state = 'incall';
        this._micMuted = false;
        WT.rtc.setPrivate(this.peer, true); // microfone contínuo ao par
        if (this.media === 'video') {
            WT.rtc.addVideo(this.peer);      // adiciona vídeo + renegocia
            this._showVideoUI(true);
            this._attachLocalPreview();
        }
        WT.playClick(true);
        this.startedAt = Date.now();
        this._renderBar('');
        this._tick();
        this.timer = setInterval(() => this._tick(), 1000);
    },

    _tick() {
        const s = Math.floor((Date.now() - this.startedAt) / 1000);
        this._renderBar('🔊 Em ligação com ' + this.peerName + ' · ' + WT.formatSeconds(s));
    },

    _reset() {
        if (this.timer) clearInterval(this.timer);
        this.timer = null;
        if (this.peer) {
            if (this.state === 'incall') WT.rtc.setPrivate(this.peer, false);
            if (this.media === 'video') WT.rtc.removeVideo(this.peer);
        }
        WT.rtc.stopCamera();
        this._showVideoUI(false);
        this.media = 'audio';
        this._micMuted = false;
        this.state = 'idle';
        this.peer = null;
        this.peerName = '';
        const bar = document.getElementById('call-bar');
        if (bar) bar.classList.remove('show');
    },

    /* ---- Vídeo ---- */
    onRemoteVideo(uuid, stream) {
        if (uuid !== this.peer) return;
        const v = document.getElementById('video-remote');
        if (v) { v.srcObject = stream; v.playsInline = true; v.play().catch(() => {}); }
        this._showVideoUI(true);
    },

    _attachLocalPreview() {
        const v = document.getElementById('video-local');
        if (!v || !WT.rtc.localVideoTrack) return;
        const ms = new MediaStream();
        ms.addTrack(WT.rtc.localVideoTrack);
        v.srcObject = ms;
        v.muted = true;
        v.playsInline = true;
        v.play().catch(() => {});
    },

    _showVideoUI(on) {
        const ov = document.getElementById('video-call');
        if (ov) ov.classList.toggle('show', on);
        if (on) {
            const nm = document.getElementById('video-name');
            if (nm) nm.textContent = this.peerName;
        }
    },

    async flipCamera() {
        if (this.media !== 'video') return;
        this._facing = this._facing === 'environment' ? 'user' : 'environment';
        const t = await WT.rtc.startCamera(this._facing);
        if (t && this.peer) {
            const peer = WT.rtc.peers[this.peer];
            if (peer && peer.videoSender) { try { await peer.videoSender.replaceTrack(t); } catch (_) {} }
            this._attachLocalPreview();
        }
    },

    toggleMicMute() {
        if (this.state !== 'incall' || !this.peer) return;
        this._micMuted = !this._micMuted;
        WT.rtc.setPrivate(this.peer, !this._micMuted);
        const btn = document.getElementById('video-mic');
        if (btn) btn.classList.toggle('off', this._micMuted);
        WT.toast(this._micMuted ? 'Microfone mudo' : 'Microfone ligado', 'info', 1200);
    },

    _hideIncoming() {
        const ov = document.getElementById('call-incoming');
        if (ov) ov.classList.remove('show');
    },

    _renderBar(text) {
        const bar = document.getElementById('call-bar');
        const label = document.getElementById('call-bar-text');
        if (label && text) label.textContent = text;
        if (bar) bar.classList.add('show');
    },

    initDom() {
        if (this._domReady) return;
        const acc = document.getElementById('call-accept');
        const dec = document.getElementById('call-decline');
        const hang = document.getElementById('call-hangup');
        const btn = document.getElementById('pc-call');
        const vbtn = document.getElementById('pc-vcall');
        const spk = document.getElementById('call-speaker');
        if (acc) acc.addEventListener('click', () => this.accept());
        if (dec) dec.addEventListener('click', () => this.decline());
        if (hang) hang.addEventListener('click', () => this.hangup());
        if (btn) btn.addEventListener('click', () => this.start('audio'));
        if (vbtn) vbtn.addEventListener('click', () => this.start('video'));
        if (spk) spk.addEventListener('click', () => this.toggleSpeaker());

        // Controles da chamada de vídeo
        const vhang = document.getElementById('video-hangup');
        const vmic  = document.getElementById('video-mic');
        const vflip = document.getElementById('video-flip');
        if (vhang) vhang.addEventListener('click', () => this.hangup());
        if (vmic)  vmic.addEventListener('click', () => this.toggleMicMute());
        if (vflip) vflip.addEventListener('click', () => this.flipCamera());
        this._domReady = true;
    },

    async toggleSpeaker() {
        const r = await WT.rtc.toggleSpeaker();
        if (!r.supported) {
            WT.toast('Neste aparelho a saída segue o sistema — conecte o fone para ouvir nele', 'info', 4000);
            return;
        }
        const spk = document.getElementById('call-speaker');
        if (spk) spk.classList.toggle('active', r.on);
        WT.toast(r.on ? '🔊 Alto-falante ligado' : '🎧 Saída padrão (fone)', 'info', 1500);
    },
};


/* =================== GRAVADOR DE VOZ (WAV, cross-platform) =================== */
// Grava do microfone via Web Audio e gera um WAV 16 kHz mono — formato que toca
// em qualquer navegador (inclusive Safari/iOS), sem precisar de transcodificação.
WT.voice = {
    recording: false,
    chunks: [],
    ctx: null, src: null, proc: null, sink: null,
    inRate: 48000,
    startedAt: 0,
    OUT_RATE: 16000,

    async start() {
        if (this.recording) return false;
        let stream = WT.rtc && WT.rtc.localStream;
        if (!stream && WT.rtc && WT.rtc.initMic) {
            try { stream = await WT.rtc.initMic(); } catch (e) { return false; }
        }
        if (!stream) return false;
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            this.ctx = WT._audioCtx || (WT._audioCtx = new Ctx());
            if (this.ctx.state === 'suspended') await this.ctx.resume().catch(() => {});
            this.inRate = this.ctx.sampleRate || 48000;
            this.chunks = [];
            this.src = this.ctx.createMediaStreamSource(stream);
            this.proc = this.ctx.createScriptProcessor(4096, 1, 1);
            this.sink = this.ctx.createGain();
            this.sink.gain.value = 0; // não devolve o som ao alto-falante
            this.proc.onaudioprocess = (e) => {
                if (!this.recording) return;
                this.chunks.push(new Float32Array(e.inputBuffer.getChannelData(0)));
            };
            this.src.connect(this.proc);
            this.proc.connect(this.sink);
            this.sink.connect(this.ctx.destination);
            this.recording = true;
            this.startedAt = Date.now();
            return true;
        } catch (e) {
            console.error('[VOICE] start falhou:', e);
            return false;
        }
    },

    stop() {
        if (!this.recording) return null;
        this.recording = false;
        this._teardown();
        const durationMs = Date.now() - this.startedAt;
        const flat = this._flatten(this.chunks);
        this.chunks = [];
        const down = this._downsample(flat, this.inRate, this.OUT_RATE);
        return { blob: this._encodeWav(down, this.OUT_RATE), durationMs };
    },

    cancel() {
        if (!this.recording) return;
        this.recording = false;
        this._teardown();
        this.chunks = [];
    },

    _teardown() {
        try { this.src && this.src.disconnect(); } catch (_) {}
        try { this.proc && (this.proc.onaudioprocess = null, this.proc.disconnect()); } catch (_) {}
        try { this.sink && this.sink.disconnect(); } catch (_) {}
    },

    _flatten(chunks) {
        let len = 0; chunks.forEach(c => len += c.length);
        const out = new Float32Array(len);
        let o = 0; chunks.forEach(c => { out.set(c, o); o += c.length; });
        return out;
    },

    _downsample(buf, inRate, outRate) {
        if (outRate >= inRate) return buf;
        const ratio = inRate / outRate;
        const newLen = Math.round(buf.length / ratio);
        const out = new Float32Array(newLen);
        let oi = 0, bi = 0;
        while (oi < newLen) {
            const next = Math.round((oi + 1) * ratio);
            let sum = 0, cnt = 0;
            for (let i = bi; i < next && i < buf.length; i++) { sum += buf[i]; cnt++; }
            out[oi] = cnt ? sum / cnt : 0;
            oi++; bi = next;
        }
        return out;
    },

    _encodeWav(samples, rate) {
        const buf = new ArrayBuffer(44 + samples.length * 2);
        const view = new DataView(buf);
        const ws = (o, s) => { for (let i = 0; i < s.length; i++) view.setUint8(o + i, s.charCodeAt(i)); };
        ws(0, 'RIFF'); view.setUint32(4, 36 + samples.length * 2, true); ws(8, 'WAVE');
        ws(12, 'fmt '); view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
        view.setUint32(24, rate, true); view.setUint32(28, rate * 2, true);
        view.setUint16(32, 2, true); view.setUint16(34, 16, true);
        ws(36, 'data'); view.setUint32(40, samples.length * 2, true);
        let o = 44;
        for (let i = 0; i < samples.length; i++) {
            const s = Math.max(-1, Math.min(1, samples[i]));
            view.setInt16(o, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
            o += 2;
        }
        return new Blob([view], { type: 'audio/wav' });
    },
};


/* =================== ATTENTION BUTTON =================== */
WT.attention = {
    cooldownEnd: 0,
    cooldownTimer: null,
    button: null,

    init() {
        this.button = document.getElementById('attention-btn');
        if (!this.button) return;
        this.button.addEventListener('click', () => this.send());
    },

    send() {
        if (Date.now() < this.cooldownEnd) {
            const remaining = Math.ceil((this.cooldownEnd - Date.now()) / 1000);
            WT.toast(`Aguarde ${remaining}s para enviar outro alerta`, 'warning');
            return;
        }
        WT.ws.send({ type: 'request_attention' });
        WT.vibrate([100, 50, 100]);
        this.startCooldown(WT.state.attentionCooldown || 60);
    },

    startCooldown(seconds) {
        this.cooldownEnd = Date.now() + seconds * 1000;
        this.button.disabled = true;
        const cdEl = document.getElementById('attention-cooldown');
        clearInterval(this.cooldownTimer);
        const update = () => {
            const remaining = Math.ceil((this.cooldownEnd - Date.now()) / 1000);
            if (remaining <= 0) {
                this.button.disabled = false;
                cdEl.textContent = '';
                clearInterval(this.cooldownTimer);
            } else {
                cdEl.textContent = `(${remaining}s)`;
            }
        };
        update();
        this.cooldownTimer = setInterval(update, 1000);
    },

    showAlert(user) {
        const isSelf = user && WT.state.user && user.id === WT.state.user.id;

        // Vibração e beep em TODOS os dispositivos (inclusive quem disparou)
        WT.playAlertBeep();
        WT.vibrate([300, 100, 300, 100, 300]);

        const overlay = document.getElementById('attention-overlay');
        const text = document.getElementById('attention-text');
        if (text) {
            text.textContent = isSelf
                ? 'Você solicitou prioridade'
                : `${user.display_name} está solicitando prioridade`;
        }
        if (overlay) overlay.classList.add('show');
        document.body.classList.add('shake-screen');

        // Notificação só para os outros (no remetente seria redundante)
        if (!isSelf) WT.notifications.showAlert(user.display_name);

        setTimeout(() => {
            if (overlay) overlay.classList.remove('show');
            document.body.classList.remove('shake-screen');
        }, 3000);
    },
};
