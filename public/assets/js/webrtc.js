/**
 * WebRTC mesh — cada usuário mantém uma RTCPeerConnection com cada outro.
 *
 * Modelo de envio (por peer, via RTCRtpSender.replaceTrack):
 *   - O microfone fica sempre "live" (track.enabled = true).
 *   - Cada sender começa SEM track (replaceTrack(null)) => mudo.
 *   - Falar no canal (broadcast): coloca a track em TODOS os senders.
 *   - Falar no privado: coloca a track APENAS no sender do alvo.
 *   Isso permite áudio 1-a-1 sem que os demais ouçam.
 *
 * Recepção (volume):
 *   - O áudio remoto passa por um GainNode (Web Audio API), o que permite
 *     amplificar acima de 100% (o <audio>.volume satura em 1.0).
 *
 * Sinalização (offer/answer/ice) trafega pelo WebSocket.
 */

WT.rtc = {
    localStream: null,
    micTrack: null,
    peers: {},          // uuid -> { pc, audioEl, sender, gain, src }
    audioCtx: null,
    isMicReady: false,

    // 'idle' | 'all' | '<uuid>'  — para onde o microfone está sendo enviado
    sendMode: 'idle',

    // Volume de saída (0..1). O áudio ao vivo toca direto no <audio> (confiável e
    // sem engasgos), por isso satura em 100% — sem depender de AudioContext, que
    // ficaria suspenso ao ouvir passivamente e causaria silêncio/interrupções.
    outputGain: Math.min(1, parseFloat(localStorage.getItem('wt_gain') || '1') || 1),

    // Bitrate alvo do Opus (kbps). Mais alto = áudio mais cheio/alto.
    opusKbps: 48,

    // Saída de áudio: '' = padrão do sistema (fone quando conectado).
    outputSinkId: '',
    speakerOn: false,

    _ctx() {
        if (!this.audioCtx) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            this.audioCtx = WT._audioCtx || (WT._audioCtx = new Ctx());
        }
        if (this.audioCtx.state === 'suspended') this.audioCtx.resume().catch(() => {});
        return this.audioCtx;
    },

    /**
     * Web Audio (GainNode) só é confiável para tocar streams REMOTOS de
     * WebRTC fora do WebKit. No Safari (iOS e macOS) o AudioContext não
     * reproduz a track remota — então caímos no <audio> direto (volume
     * limitado a 100%, mas o áudio funciona). No Chrome/Firefox/Edge
     * (desktop e Android) usamos o ganho e amplificamos acima de 100%.
     */
    _useWebAudio() {
        if (this._waSupport != null) return this._waSupport;
        const ua = navigator.userAgent || '';
        const isIOS = /iP(hone|ad|od)/.test(ua) ||
            (/Macintosh/.test(ua) && (navigator.maxTouchPoints || 0) > 1); // iPadOS finge ser Mac
        const isSafari = /^((?!chrome|crios|chromium|android|edg|fxios|opr).)*safari/i.test(ua);
        const hasWA = !!(window.AudioContext || window.webkitAudioContext);
        this._waSupport = hasWA && !isIOS && !isSafari;
        console.log('[RTC] Web Audio para áudio remoto:', this._waSupport);
        return this._waSupport;
    },

    camPreauthorized: false,

    get _audioConstraints() {
        return {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
            channelCount: 1,
            sampleRate: 48000, // captura em alta taxa; Opus reamostra
        };
    },

    /** Pede o microfone (crítico). A câmera é pedida à parte, num gesto. */
    async initMic() {
        if (this.isMicReady) return this.localStream;
        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: this._audioConstraints,
                video: false,
            });
            this.micTrack = this.localStream.getAudioTracks()[0] || null;
            if (this.micTrack) this.micTrack.enabled = true;
            this.sendMode = 'idle';
            this.isMicReady = true;
            console.log('[RTC] microfone OK');
            return this.localStream;
        } catch (e) {
            console.error('[RTC] microfone falhou:', e);
            WT.toast('Acesso ao microfone negado. Habilite nas configurações.', 'error', 6000);
            throw e;
        }
    },

    /**
     * Pede a permissão da câmera de forma confiável. DEVE ser chamada a partir
     * de um gesto do usuário (toque/clique) — senão o navegador suprime o prompt
     * de uma permissão nova (o que fazia "a câmera não pedir" no auto-login).
     * Concede e desliga na hora (luz apaga); a videochamada reusa a permissão.
     */
    async requestCameraPermission() {
        if (this.camPreauthorized) return true;
        if (this._askingCam) return false;
        this._askingCam = true;
        try {
            // 'video: true' puro = mais compatível (sem OverconstrainedError).
            const v = await navigator.mediaDevices.getUserMedia({ video: true });
            v.getTracks().forEach(t => t.stop()); // só queríamos a permissão
            this.camPreauthorized = true;
            console.log('[RTC] câmera autorizada');
            return true;
        } catch (e) {
            const n = e && e.name;
            console.warn('[RTC] permissão de câmera:', n);
            if (n === 'NotAllowedError' || n === 'SecurityError') {
                const ua = navigator.userAgent || '';
                const ios = /iP(hone|ad|od)/.test(ua) || (/Macintosh/.test(ua) && (navigator.maxTouchPoints || 0) > 1);
                const msg = ios
                    ? 'Câmera bloqueada. No Safari: toque em "aA" → Definições do site → Câmera → Permitir, e recarregue.'
                    : 'Câmera bloqueada. Toque no 🔒 ao lado do endereço → Permissões → Câmera → Permitir, e recarregue.';
                WT.toast(msg, 'warning', 8000);
            } else if (n === 'NotFoundError') {
                WT.toast('Nenhuma câmera encontrada neste aparelho.', 'warning', 5000);
            }
            return false;
        } finally {
            this._askingCam = false;
        }
    },

    /* ----------------------- Controle de envio ----------------------- */

    /** Liga/desliga transmissão para TODOS (canal público). */
    setBroadcast(on) {
        this.sendMode = on ? 'all' : 'idle';
        const track = on ? this.micTrack : null;
        Object.values(this.peers).forEach(p => this._setSenderTrack(p, track));
    },

    /** Liga/desliga transmissão APENAS para um usuário (privado). */
    setPrivate(uuid, on) {
        this.sendMode = on ? uuid : 'idle';
        Object.entries(this.peers).forEach(([u, p]) => {
            this._setSenderTrack(p, (on && u === uuid) ? this.micTrack : null);
        });
    },

    /** Compatibilidade: corta qualquer transmissão. */
    setLocalAudioEnabled(enabled) {
        if (enabled) this.setBroadcast(true);
        else { this.sendMode = 'idle'; Object.values(this.peers).forEach(p => this._setSenderTrack(p, null)); }
    },

    _setSenderTrack(peer, track) {
        if (!peer || !peer.sender) return;
        // replaceTrack não exige renegociação.
        peer.sender.replaceTrack(track).catch(e => console.warn('[RTC] replaceTrack:', e));
    },

    /* ----------------------- Volume (recepção) ----------------------- */

    setOutputGain(value) {
        this.outputGain = Math.max(0, Math.min(1, value));
        localStorage.setItem('wt_gain', String(this.outputGain));
        Object.values(this.peers).forEach(p => {
            if (p.audioEl) p.audioEl.volume = this.outputGain;
        });
        return this.outputGain;
    },

    /* ----------------------- Saída de áudio (fone / alto-falante) ----------------------- */

    sinkSupported() {
        return (typeof HTMLMediaElement !== 'undefined' && 'setSinkId' in HTMLMediaElement.prototype)
            || (this.audioCtx && typeof this.audioCtx.setSinkId === 'function');
    },

    /** Aplica um sinkId (saída) em todos os áudios remotos. '' = padrão do sistema. */
    async setSink(sinkId) {
        this.outputSinkId = sinkId || '';
        // Caminho Web Audio: o destino é o AudioContext (Chrome 110+).
        if (this.audioCtx && typeof this.audioCtx.setSinkId === 'function') {
            try { await this.audioCtx.setSinkId(this.outputSinkId); } catch (e) { console.warn('[RTC] ctx.setSinkId:', e); }
        }
        // Caminho <audio> (Safari não suporta).
        await Promise.all(Object.values(this.peers).map(async p => {
            if (p.audioEl && typeof p.audioEl.setSinkId === 'function') {
                try { await p.audioEl.setSinkId(this.outputSinkId); } catch (_) {}
            }
        }));
    },

    /** Alterna alto-falante. Retorna {ok, on, supported}. */
    async toggleSpeaker(force) {
        const want = (typeof force === 'boolean') ? force : !this.speakerOn;
        if (!this.sinkSupported()) {
            return { ok: false, on: false, supported: false };
        }
        try {
            if (want) {
                const devs = await navigator.mediaDevices.enumerateDevices();
                const outs = devs.filter(d => d.kind === 'audiooutput');
                const spk = outs.find(d => /speaker|alto.?falante|speakerphone/i.test(d.label))
                    || outs.find(d => d.deviceId === 'default')
                    || outs[0];
                await this.setSink(spk ? spk.deviceId : '');
                this.speakerOn = true;
            } else {
                await this.setSink(''); // volta ao padrão (fone se conectado)
                this.speakerOn = false;
            }
            return { ok: true, on: this.speakerOn, supported: true };
        } catch (e) {
            console.warn('[RTC] toggleSpeaker:', e);
            return { ok: false, on: this.speakerOn, supported: true };
        }
    },

    /**
     * Liga o stream remoto ao alto-falante via <audio> direto.
     * É o caminho mais confiável e sem engasgos: o áudio toca em hardware,
     * funciona com autoplay (a página já teve gesto no login) e não depende
     * de AudioContext (que ficaria suspenso ao ouvir passivamente = silêncio).
     */
    _attachAudio(peer, stream) {
        const el = peer.audioEl;
        el.srcObject = stream;
        el.autoplay = true;
        el.playsInline = true;
        el.muted = false;
        el.volume = Math.min(1, this.outputGain || 1);
        if (this.outputSinkId && typeof el.setSinkId === 'function') {
            el.setSinkId(this.outputSinkId).catch(() => {});
        }
        const pr = el.play();
        if (pr && pr.catch) pr.catch(() => { /* destrava no 1º gesto via bindAudioUnlock */ });
        peer.gain = null;
        peer.src = null;
    },

    /* ----------------------- Peers ----------------------- */

    createPeer(targetUuid, isInitiator) {
        if (this.peers[targetUuid]) return this.peers[targetUuid];

        const pc = new RTCPeerConnection({
            iceServers: WT.state.iceServers || [{ urls: 'stun:stun.l.google.com:19302' }],
        });

        // Adiciona a track local e guarda o sender (começa mudo).
        let sender = null;
        if (this.micTrack) {
            sender = pc.addTrack(this.micTrack, this.localStream);
            sender.replaceTrack(null).catch(() => {});
        }

        const audioEl = new Audio();
        audioEl.autoplay = true;
        audioEl.playsInline = true;
        document.getElementById('audio-pool').appendChild(audioEl);

        const peer = { pc, audioEl, sender, videoSender: null, gain: null, src: null, isInitiator };
        this.peers[targetUuid] = peer;

        pc.ontrack = (ev) => {
            if (ev.track && ev.track.kind === 'video') {
                console.log('[RTC] vídeo recebido de', targetUuid);
                this._attachVideo(targetUuid, ev.streams[0]);
            } else {
                console.log('[RTC] áudio recebido de', targetUuid);
                this._attachAudio(peer, ev.streams[0]);
            }
        };

        pc.onicecandidate = (ev) => {
            if (ev.candidate) {
                WT.ws.send({ type: 'webrtc_ice', target: targetUuid, candidate: ev.candidate.toJSON() });
            }
        };

        pc.onconnectionstatechange = () => {
            console.log('[RTC]', targetUuid, '->', pc.connectionState);
            // 'closed' é terminal; 'disconnected'/'failed' tentamos recuperar
            // (não derruba a ligação por uma oscilação de rede).
            if (pc.connectionState === 'closed') {
                this.removePeer(targetUuid);
            }
            if (pc.connectionState === 'connected') this._reapplySendMode(targetUuid);
        };

        // Recuperação de ICE: reconecta sem derrubar a chamada.
        pc.oniceconnectionstatechange = () => {
            const st = pc.iceConnectionState;
            if ((st === 'failed' || st === 'disconnected') && peer.isInitiator) {
                clearTimeout(peer._iceRetry);
                peer._iceRetry = setTimeout(() => {
                    if (pc.iceConnectionState === 'failed' || pc.iceConnectionState === 'disconnected') {
                        console.warn('[RTC] reiniciando ICE com', targetUuid);
                        try { pc.restartIce && pc.restartIce(); } catch (_) {}
                        this.makeOffer(targetUuid, { iceRestart: true });
                    }
                }, st === 'failed' ? 0 : 1500);
            }
        };

        if (isInitiator) this.makeOffer(targetUuid);
        return peer;
    },

    _reapplySendMode(uuid) {
        const peer = this.peers[uuid];
        if (!peer) return;
        if (this.sendMode === 'all') this._setSenderTrack(peer, this.micTrack);
        else if (this.sendMode === uuid) this._setSenderTrack(peer, this.micTrack);
        else this._setSenderTrack(peer, null);
    },

    /* ----------------------- Vídeo (chamada de vídeo 1-a-1) ----------------------- */

    localVideoTrack: null,

    /** Liga a câmera e devolve a track. facing: 'user' (frontal) | 'environment'. */
    async startCamera(facing = 'user') {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            WT.toast('Câmera não suportada neste navegador', 'error');
            return null;
        }
        // Tenta com a câmera pedida; se as restrições falharem, tenta qualquer câmera.
        const attempts = [
            { video: { facingMode: facing, width: { ideal: 640 }, height: { ideal: 480 } }, audio: false },
            { video: true, audio: false },
        ];
        let lastErr = null;
        for (const constraints of attempts) {
            try {
                const v = await navigator.mediaDevices.getUserMedia(constraints);
                const track = v.getVideoTracks()[0];
                if (!track) { lastErr = new Error('sem track de vídeo'); continue; }
                this.stopCamera(); // troca se já havia
                this.localVideoTrack = track;
                if (this.localStream) this.localStream.addTrack(track);
                return track;
            } catch (e) {
                lastErr = e;
                console.warn('[RTC] getUserMedia vídeo falhou:', e && e.name, e);
                // Permissão negada não adianta tentar de novo.
                if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) break;
            }
        }
        this._cameraError(lastErr);
        return null;
    },

    _cameraError(e) {
        const n = e && e.name;
        let msg = 'Não foi possível acessar a câmera.';
        if (n === 'NotAllowedError' || n === 'SecurityError') {
            msg = 'Permissão da câmera negada. Toque no cadeado/ícone do site e permita a câmera, depois tente de novo.';
        } else if (n === 'NotFoundError' || n === 'OverconstrainedError' || n === 'DevicesNotFoundError') {
            msg = 'Nenhuma câmera encontrada neste aparelho.';
        } else if (n === 'NotReadableError' || n === 'TrackStartError') {
            msg = 'A câmera está sendo usada por outro app. Feche-o e tente novamente.';
        }
        WT.toast(msg, 'error', 7000);
    },

    stopCamera() {
        if (this.localVideoTrack) {
            try { if (this.localStream) this.localStream.removeTrack(this.localVideoTrack); } catch (_) {}
            try { this.localVideoTrack.stop(); } catch (_) {}
            this.localVideoTrack = null;
        }
    },

    /** Adiciona o vídeo local ao peer e renegocia. */
    async addVideo(uuid) {
        const peer = this.peers[uuid];
        if (!peer || !this.localVideoTrack) return;
        if (peer.videoSender) {
            try { await peer.videoSender.replaceTrack(this.localVideoTrack); } catch (_) {}
        } else {
            peer.videoSender = peer.pc.addTrack(this.localVideoTrack, this.localStream);
        }
        await this._renegotiate(uuid);
    },

    /** Remove o vídeo local do peer e renegocia. */
    async removeVideo(uuid) {
        const peer = this.peers[uuid];
        if (!peer || !peer.videoSender) return;
        try { peer.pc.removeTrack(peer.videoSender); } catch (_) {}
        peer.videoSender = null;
        await this._renegotiate(uuid);
    },

    /** Renegocia: só o iniciador da conexão envia a oferta (evita glare). */
    _renegotiate(uuid) {
        const peer = this.peers[uuid];
        if (!peer) return;
        if (peer.isInitiator) return this.makeOffer(uuid);
        WT.ws.send({ type: 'webrtc_renegotiate', target: uuid });
    },

    /** O outro lado pediu renegociação; sou o iniciador -> ofereço. */
    onRenegotiate(uuid) {
        const peer = this.peers[uuid];
        if (peer && peer.isInitiator) this.makeOffer(uuid);
    },

    _attachVideo(uuid, stream) {
        if (WT.call && WT.call.onRemoteVideo) WT.call.onRemoteVideo(uuid, stream);
    },

    /* ----------------------- SDP (bitrate Opus) ----------------------- */

    _boostOpus(sdp) {
        const kbps = this.opusKbps;
        // Descobre o payload type do opus
        const m = sdp.match(/a=rtpmap:(\d+) opus\/48000/i);
        if (!m) return sdp;
        const pt = m[1];
        const lines = sdp.split(/\r?\n/);
        let out = [];
        let touchedFmtp = false;
        for (let line of lines) {
            if (line.startsWith('a=fmtp:' + pt)) {
                if (!/maxaveragebitrate/.test(line)) {
                    line += `;maxaveragebitrate=${kbps * 1000};stereo=0;useinbandfec=1;usedtx=0`;
                }
                touchedFmtp = true;
            }
            out.push(line);
        }
        if (!touchedFmtp) {
            // Sem fmtp existente: cria um
            out.push(`a=fmtp:${pt} maxaveragebitrate=${kbps * 1000};stereo=0;useinbandfec=1;usedtx=0`);
        }
        return out.join('\r\n');
    },

    async makeOffer(targetUuid, opts = {}) {
        const peer = this.peers[targetUuid];
        if (!peer) return;
        try {
            const offer = await peer.pc.createOffer(opts);
            offer.sdp = this._boostOpus(offer.sdp);
            await peer.pc.setLocalDescription(offer);
            WT.ws.send({ type: 'webrtc_offer', target: targetUuid, sdp: peer.pc.localDescription });
        } catch (e) {
            console.error('[RTC] makeOffer:', e);
        }
    },

    async handleOffer(fromUuid, sdp) {
        let peer = this.peers[fromUuid];
        if (!peer) peer = this.createPeer(fromUuid, false);
        try {
            await peer.pc.setRemoteDescription(new RTCSessionDescription(sdp));
            const answer = await peer.pc.createAnswer();
            answer.sdp = this._boostOpus(answer.sdp);
            await peer.pc.setLocalDescription(answer);
            WT.ws.send({ type: 'webrtc_answer', target: fromUuid, sdp: peer.pc.localDescription });
        } catch (e) {
            console.error('[RTC] handleOffer:', e);
        }
    },

    async handleAnswer(fromUuid, sdp) {
        const peer = this.peers[fromUuid];
        if (!peer) return;
        try {
            await peer.pc.setRemoteDescription(new RTCSessionDescription(sdp));
        } catch (e) {
            console.error('[RTC] handleAnswer:', e);
        }
    },

    async handleIce(fromUuid, candidate) {
        const peer = this.peers[fromUuid];
        if (!peer) return;
        try {
            await peer.pc.addIceCandidate(new RTCIceCandidate(candidate));
        } catch (e) {
            console.error('[RTC] handleIce:', e);
        }
    },

    removePeer(uuid) {
        const peer = this.peers[uuid];
        if (!peer) return;
        try { peer.pc.close(); } catch (_) {}
        try { if (peer.src) peer.src.disconnect(); } catch (_) {}
        try { if (peer.gain) peer.gain.disconnect(); } catch (_) {}
        if (peer.audioEl) {
            peer.audioEl.srcObject = null;
            peer.audioEl.remove();
        }
        delete this.peers[uuid];
    },

    closeAll() {
        this.stopCamera();
        Object.keys(this.peers).forEach(u => this.removePeer(u));
        if (this.localStream) {
            this.localStream.getTracks().forEach(t => t.stop());
            this.localStream = null;
            this.micTrack = null;
        }
        this.sendMode = 'idle';
        this.isMicReady = false;
    },

    /**
     * Sincroniza peers com a lista de online users.
     * Regra anti-glare: o uuid lexicograficamente menor inicia a offer.
     */
    syncPeers(onlineUuids, myUuid) {
        Object.keys(this.peers).forEach(u => {
            if (!onlineUuids.includes(u)) this.removePeer(u);
        });
        onlineUuids.forEach(u => {
            if (u === myUuid) return;
            if (this.peers[u]) return;
            const isInitiator = myUuid < u;
            this.createPeer(u, isInitiator);
        });
    },
};
