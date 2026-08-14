/**
 * Notifications + permissions + Web Push (VAPID)
 *
 * Notificação local  -> só funciona com o app aberto (aba em background conta).
 * Web Push (VAPID)   -> chega pelo sistema operacional mesmo com o app FECHADO.
 *                       No iOS exige o app instalado na tela de início (16.4+).
 */

WT.notifications = {
    permission: 'default',
    pushReady: false,

    async init() {
        if (!('Notification' in window)) {
            console.warn('[WT] Notification API indisponível');
            return false;
        }
        if (Notification.permission === 'default') {
            try {
                this.permission = await Notification.requestPermission();
            } catch (_) {
                this.permission = Notification.permission;
            }
        } else {
            this.permission = Notification.permission;
        }

        if (this.permission === 'granted') {
            // Não bloqueia o login se o push falhar
            this.enablePush().catch(err => console.warn('[WT] push:', err.message));
        }
        return this.permission === 'granted';
    },

    show(title, options = {}) {
        if (this.permission !== 'granted') return null;
        if (document.visibilityState === 'visible') return null; // só notifica se estiver em background

        try {
            const n = new Notification(title, {
                icon: ((window.WT_CONFIG && WT_CONFIG.basePath) || '/') + 'icons/icon-192.png',
                badge: ((window.WT_CONFIG && WT_CONFIG.basePath) || '/') + 'icons/icon-192.png',
                vibrate: [200, 100, 200],
                ...options,
            });
            setTimeout(() => n.close(), 5000);
            return n;
        } catch (e) {
            console.warn('[WT] Notif falhou:', e);
        }
    },

    showAlert(fromUser) {
        this.show('⚠️ ' + fromUser + ' chamou sua atenção!', {
            body: 'Solicitando prioridade no canal',
            requireInteraction: false,
            tag: 'attention',
        });
    },

    /* ===================== Web Push ===================== */

    _urlBase64ToUint8Array(base64) {
        const padding = '='.repeat((4 - (base64.length % 4)) % 4);
        const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    },

    /** Inscreve este aparelho no push e registra no servidor. Idempotente. */
    async enablePush() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            console.info('[WT] Push não suportado neste navegador');
            return false;
        }
        if (this.permission !== 'granted') return false;

        const vapid = (window.WT_CONFIG && WT_CONFIG.vapidPublicKey) || '';
        if (!vapid) {
            console.warn('[WT] chave VAPID ausente na config');
            return false;
        }

        const reg = await navigator.serviceWorker.ready;

        let sub = await reg.pushManager.getSubscription();

        // Se a chave do servidor mudou, a inscrição antiga não serve mais
        if (sub) {
            const atual = btoa(String.fromCharCode(...new Uint8Array(sub.options.applicationServerKey || new ArrayBuffer(0))))
                .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
            if (atual && atual !== vapid) {
                await sub.unsubscribe().catch(() => {});
                sub = null;
            }
        }

        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this._urlBase64ToUint8Array(vapid),
            });
        }

        const json = sub.toJSON();
        await WT.api('/api/push-subscribe.php', {
            method: 'POST',
            body: { endpoint: json.endpoint, keys: json.keys },
        });

        this.pushReady = true;
        console.info('[WT] Push ativo');
        return true;
    },

    async disablePush() {
        if (!('serviceWorker' in navigator)) return;
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (!sub) return;

        await WT.api('/api/push-unsubscribe.php', {
            method: 'POST',
            body: { endpoint: sub.endpoint },
        }).catch(() => {});
        await sub.unsubscribe().catch(() => {});
        this.pushReady = false;
    },

    /** Dispara um push de teste para o próprio usuário. */
    async testPush() {
        const r = await WT.api('/api/push-test.php', { method: 'POST' });
        return r;
    },
};
