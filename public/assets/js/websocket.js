/**
 * Walkie Talkie - WebSocket Client
 * Reconexão automática + heartbeat + handlers
 */

WT.ws = {
    socket: null,
    connected: false,
    reconnectAttempts: 0,
    maxReconnectDelay: 30000,
    heartbeatTimer: null,
    handlers: {},
    queueOutgoing: [],

    connect(token) {
        if (this.socket && (this.socket.readyState === WebSocket.CONNECTING || this.socket.readyState === WebSocket.OPEN)) {
            return;
        }
        WT.state.token = token;
        this.setStatus('connecting');

        let url = WT_CONFIG.wsUrl;
        // se for relativo (ex: '/ws'), preencher com host atual
        if (url.startsWith('/')) {
            const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
            url = proto + '//' + location.host + url;
        }

        try {
            this.socket = new WebSocket(url);
        } catch (e) {
            console.error('[WS] erro ao criar:', e);
            this.scheduleReconnect();
            return;
        }

        this.socket.onopen = () => {
            console.log('[WS] aberto');
            this.reconnectAttempts = 0;
            this.send({ type: 'auth', token });
        };

        this.socket.onmessage = (ev) => {
            let data;
            try { data = JSON.parse(ev.data); } catch (_) { return; }
            this.handle(data);
        };

        this.socket.onclose = (ev) => {
            console.warn('[WS] fechado', ev.code, ev.reason);
            this.connected = false;
            this.setStatus('offline');
            this.stopHeartbeat();
            this.scheduleReconnect();
        };

        this.socket.onerror = (e) => {
            console.error('[WS] erro:', e);
        };
    },

    scheduleReconnect() {
        this.reconnectAttempts++;
        const delay = Math.min(1000 * Math.pow(2, this.reconnectAttempts), this.maxReconnectDelay);
        const jitter = Math.random() * 1000;
        console.log('[WS] reconectando em', Math.round((delay + jitter)/1000), 's');
        clearTimeout(this._reconnectTimer);
        this._reconnectTimer = setTimeout(() => {
            if (WT.state.token) this.connect(WT.state.token);
        }, delay + jitter);
    },

    setStatus(status) {
        const label = ({
            online: 'Conectado',
            connecting: 'Conectando...',
            offline: 'Reconectando...',
        })[status] || status;
        // Atualiza o status nos dois cabeçalhos (rádio e conversas)
        [['connection-dot', 'connection-text'], ['c-connection-dot', 'c-connection-text']]
            .forEach(([d, t]) => {
                const dot = document.getElementById(d);
                const txt = document.getElementById(t);
                if (dot) dot.className = 'status-dot ' + status;
                if (txt) txt.textContent = label;
            });
    },

    send(data) {
        const json = JSON.stringify(data);
        if (this.connected || this.socket?.readyState === WebSocket.OPEN) {
            this.socket.send(json);
        } else {
            this.queueOutgoing.push(json);
        }
    },

    flushQueue() {
        while (this.queueOutgoing.length && this.socket?.readyState === WebSocket.OPEN) {
            this.socket.send(this.queueOutgoing.shift());
        }
    },

    startHeartbeat() {
        this.stopHeartbeat();
        this.heartbeatTimer = setInterval(() => {
            if (this.connected) this.send({ type: 'heartbeat' });
        }, 25000);
    },

    stopHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    },

    on(eventType, handler) {
        if (!this.handlers[eventType]) this.handlers[eventType] = [];
        this.handlers[eventType].push(handler);
    },

    handle(data) {
        const type = data.type;

        // Internos
        if (type === 'connected') {
            // recebe ICE servers do server
            if (data.ice_servers) WT.state.iceServers = data.ice_servers;
            return;
        }

        if (type === 'auth_ok') {
            this.connected = true;
            this.setStatus('online');
            this.flushQueue();
            this.startHeartbeat();
            console.log('[WS] autenticado', data);
        }

        if (type === 'auth_error') {
            WT.toast('Sessão inválida. Faça login novamente.', 'error');
            localStorage.removeItem('wt_token');
            setTimeout(() => location.reload(), 2000);
            return;
        }

        // Dispatch para handlers registrados
        const list = this.handlers[type] || [];
        list.forEach(h => {
            try { h(data); } catch (e) { console.error('[WS] handler', type, e); }
        });

        // Wildcard
        (this.handlers['*'] || []).forEach(h => h(data));
    },

    close() {
        clearTimeout(this._reconnectTimer);
        this.stopHeartbeat();
        if (this.socket) {
            this.socket.onclose = null;
            this.socket.close();
            this.socket = null;
        }
        this.connected = false;
    },
};
