/**
 * Renderização da fila no cliente
 */

WT.queue = {
    state: { talking: null, waiting: [] },

    update(state) {
        this.state = state;
        this.render();
        this.updateSpeaker();
    },

    render() {
        const list = document.getElementById('queue-list');
        if (!list) return;
        const myId = WT.state.user?.id;

        if (!this.state.waiting.length) {
            list.innerHTML = '<span class="queue-empty">Vazia</span>';
            return;
        }
        list.innerHTML = this.state.waiting.map(u => {
            const isMe = u.user_id === myId;
            const cls = ['queue-item'];
            if (u.priority) cls.push('priority');
            if (isMe) cls.push('is-me');
            return `<div class="${cls.join(' ')}">
                <div class="queue-avatar" style="background:${WT.escape(u.avatar_color)}">${WT.escape(WT.initials(u.display_name))}</div>
                <span>${WT.escape(u.display_name)}${u.priority ? ' ⚡' : ''}</span>
            </div>`;
        }).join('');
    },

    updateSpeaker() {
        const info = document.getElementById('speaker-info');
        const status = document.getElementById('speaker-status');
        const name = document.getElementById('speaker-name');

        if (this.state.talking) {
            const t = this.state.talking;
            const isMe = t.user_id === WT.state.user?.id;
            info.classList.add('active');
            info.classList.toggle('priority', !!t.priority);
            status.textContent = isMe ? '◉ VOCÊ ESTÁ FALANDO' : '◉ TRANSMITINDO';
            name.textContent = isMe ? 'Você' : t.display_name;
            WT.ptt?.setExternalSpeaker(!isMe);
        } else {
            info.classList.remove('active');
            info.classList.remove('priority');
            status.textContent = 'Aguardando...';
            name.textContent = 'Ninguém transmitindo';
            WT.ptt?.setExternalSpeaker(false);
        }
    },
};
