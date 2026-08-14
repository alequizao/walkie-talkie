/**
 * background.js — Mantém o app responsivo em segundo plano e celular bloqueado
 *  - Silent audio loop (impede o Android de matar o WebRTC)
 *  - Media Session API (sistema reconhece como app de mídia)
 *  - Wake Lock reaquisitivo
 *  - Heartbeat periódico (mantém token e WS vivos)
 */
// Base e nome mudam conforme o domínio (subdiretório x voip.usegrupodona.com.br)
const BG_BASE  = (window.WT_CONFIG && WT_CONFIG.basePath) || '/';
const BG_TITLE = /^voip\./i.test(location.hostname) ? 'Voip Dona' : 'Walkie Talkie';

WT.background = {
    silentAudio: null,
    wakeLock: null,
    heartbeatTimer: null,

    /**
     * Liga o "modo plantão". Chamar depois do primeiro gesto do usuário
     * (necessário para autoplay).
     */
    async enable() {
        this.startSilentAudio();
        this.installMediaSession();
        await this.acquireWakeLock();
        this.startHeartbeat();
        this.bindLifecycle();
    },

    /**
     * Loop silencioso por Web Audio mantém o pipeline ativo no Android.
     * Volume zero — não faz som mas o sistema vê uma sessão de mídia ativa.
     */
    startSilentAudio() {
        if (this.silentAudio) return;
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            gain.gain.value = 0.0001; // praticamente inaudível
            osc.frequency.value = 1;
            osc.connect(gain).connect(ctx.destination);
            osc.start();

            // Reanima quando o sistema suspende
            const resume = () => { if (ctx.state === 'suspended') ctx.resume().catch(()=>{}); };
            document.addEventListener('visibilitychange', resume);
            window.addEventListener('focus', resume);

            this.silentAudio = { ctx, osc, gain };
            console.log('[BG] silent audio loop on');
        } catch (e) {
            console.warn('[BG] silent audio falhou:', e);
        }
    },

    /**
     * Registra o app no Media Session do sistema. Em alguns Androids isto
     * impede o navegador de descartar a aba quando o celular trava.
     */
    installMediaSession() {
        if (!('mediaSession' in navigator)) return;
        try {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: BG_TITLE,
                artist: 'Canal ativo',
                album: 'Push To Talk',
                artwork: [
                    { src: BG_BASE + 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: BG_BASE + 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                ],
            });
            navigator.mediaSession.playbackState = 'playing';
            navigator.mediaSession.setActionHandler('play',  () => {});
            navigator.mediaSession.setActionHandler('pause', () => {});
        } catch (e) {
            console.warn('[BG] media session falhou:', e);
        }
    },

    async acquireWakeLock() {
        if (!('wakeLock' in navigator)) return;
        try {
            this.wakeLock = await navigator.wakeLock.request('screen');
            this.wakeLock.addEventListener('release', () => { this.wakeLock = null; });
            console.log('[BG] wake lock OK');
        } catch (e) {
            console.warn('[BG] wake lock falhou:', e);
        }
    },

    startHeartbeat() {
        clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = setInterval(() => {
            // Heartbeat HTTP (mantém token, fallback do WS)
            if (WT.state && WT.state.token) {
                WT.api('/api/heartbeat.php', { method: 'POST', body: {} })
                    .catch(()=>{ /* ignora erro */ });
            }
            // WS heartbeat se aberto (websocket.js também tem o seu)
            if (WT.ws && WT.ws.connected) WT.ws.send({ type: 'heartbeat' });
        }, 60000); // 1 min
    },

    bindLifecycle() {
        // Ao voltar à tela: reanimar wake lock e WS
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.acquireWakeLock();
                if (WT.ws && !WT.ws.connected && WT.state.token) WT.ws.connect(WT.state.token);
                if (this.silentAudio?.ctx?.state === 'suspended') this.silentAudio.ctx.resume().catch(()=>{});
            }
        });

        // Reconectar quando voltar online
        window.addEventListener('online', () => {
            if (WT.state && WT.state.token && WT.ws && !WT.ws.connected) WT.ws.connect(WT.state.token);
        });
    },
};
