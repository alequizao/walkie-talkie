/**
 * Walkie Talkie - utils
 */

const WT = window.WT = window.WT || {};

WT.api = async function(path, options = {}) {
    const token = WT.state?.token;
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };
    if (token) headers['Authorization'] = 'Bearer ' + token;

    const res = await fetch(WT_CONFIG.apiBase + path, {
        method: options.method || 'GET',
        headers: { ...headers, ...(options.headers || {}) },
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    let data;
    try { data = await res.json(); } catch (_) { data = { success: false, message: 'Resposta inválida' }; }
    if (!res.ok || !data.success) {
        const err = new Error(data.message || ('Erro ' + res.status));
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data.data;
};

WT.toast = function(msg, type = 'info', timeout = 3000, onClick = null) {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.textContent = msg;
    const dismiss = () => {
        el.style.transition = 'opacity .3s, transform .3s';
        el.style.opacity = '0';
        el.style.transform = 'translateY(10px)';
        setTimeout(() => el.remove(), 300);
    };
    if (typeof onClick === 'function') {
        el.style.cursor = 'pointer';
        el.addEventListener('click', () => { onClick(); dismiss(); });
    }
    document.getElementById('toast-container').appendChild(el);
    setTimeout(dismiss, timeout);
};

WT.escape = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
}[c]));

WT.initials = (name) => String(name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(s => s[0])
    .join('')
    .toUpperCase();

WT.vibrate = (pattern) => {
    if (navigator.vibrate) navigator.vibrate(pattern);
};

WT.debounce = (fn, ms) => {
    let t;
    return (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
};

WT.throttle = (fn, ms) => {
    let last = 0;
    return (...args) => {
        const now = Date.now();
        if (now - last >= ms) {
            last = now;
            fn(...args);
        }
    };
};

WT.requestWakeLock = async () => {
    try {
        if ('wakeLock' in navigator) {
            const lock = await navigator.wakeLock.request('screen');
            console.log('[WT] WakeLock ativado');
            return lock;
        }
    } catch (e) {
        console.warn('[WT] WakeLock falhou:', e);
    }
    return null;
};

// Som de alerta gerado via Web Audio API (sem precisar de arquivo)
WT.playAlertBeep = function() {
    try {
        const ctx = WT._audioCtx || (WT._audioCtx = new (window.AudioContext || window.webkitAudioContext)());
        if (ctx.state === 'suspended') ctx.resume();
        const now = ctx.currentTime;

        // Dois beeps curtos
        for (let i = 0; i < 2; i++) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'square';
            osc.frequency.value = 880;
            gain.gain.value = 0;
            osc.connect(gain);
            gain.connect(ctx.destination);
            const start = now + i * 0.18;
            const end = start + 0.12;
            gain.gain.setValueAtTime(0, start);
            gain.gain.linearRampToValueAtTime(0.25, start + 0.01);
            gain.gain.linearRampToValueAtTime(0, end);
            osc.start(start);
            osc.stop(end + 0.01);
        }
    } catch (e) {
        console.warn('[WT] Beep falhou:', e);
    }
};

// Beep curto de "click" do walkie talkie ao começar/terminar
WT.playClick = function(highPitch = true) {
    try {
        const ctx = WT._audioCtx || (WT._audioCtx = new (window.AudioContext || window.webkitAudioContext)());
        if (ctx.state === 'suspended') ctx.resume();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = highPitch ? 1200 : 600;
        gain.gain.value = 0;
        osc.connect(gain);
        gain.connect(ctx.destination);
        const now = ctx.currentTime;
        gain.gain.setValueAtTime(0, now);
        gain.gain.linearRampToValueAtTime(0.15, now + 0.005);
        gain.gain.linearRampToValueAtTime(0, now + 0.06);
        osc.start(now);
        osc.stop(now + 0.07);
    } catch (e) { /* ignore */ }
};

WT.formatSeconds = (s) => {
    const mm = String(Math.floor(s / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return mm + ':' + ss;
};

// Selo de verificado (estilo Instagram) — azul com check branco.
WT.VERIFIED_BADGE = '<svg class="verified-badge" viewBox="0 0 40 40" width="15" height="15" aria-label="Verificado" role="img">'
    + '<path fill="#3897f0" d="M19.998 3.094L14.638 0l-2.972 5.15H5.432v6.354L0 14.64 3.094 20 0 25.359l5.432 3.137v5.905h5.975L14.638 40l5.36-3.094L25.358 40l3.232-5.6h6.162v-6.01L40 25.359 36.905 20 40 14.641l-5.248-3.03v-6.46h-6.419L25.358 0l-5.36 3.094z"/>'
    + '<path fill="#fff" d="M27.413 14.319l2.254 2.287-11.43 11.5-6.835-6.93 2.244-2.30 4.587 4.654z"/></svg>';

// Mostra o selo? (usuário master/desenvolvedor)
WT.isVerified = (user) => !!user && user.role === 'admin';

// Hora curta (HH:MM) a partir de uma data/ISO; vazio se inválida
WT.formatClock = (input) => {
    const d = input ? new Date(input) : new Date();
    if (isNaN(d.getTime())) return '';
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
};
