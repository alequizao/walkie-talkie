# 📻 Walkie Talkie Web — Rádio Comunicador em Tempo Real

Sistema completo de comunicação por voz estilo **Push-To-Talk (PTT)** para web e mobile, pronto para produção.

> **Stack:** PHP 7.4 · MySQL 8 · WebRTC · WebSocket (Ratchet) · JavaScript puro · PWA

---

## ✨ Recursos

- 🎙️ **PTT (Push-To-Talk)** — botão gigante mobile-first; aperta e fala, solta e ouve.
- 🌐 **WebRTC mesh** com codec **Opus** mono 24 kHz, com cancelamento de eco, redução de ruído e AGC.
- 🔄 **Fila global FIFO** — apenas 1 falando por vez; o próximo assume automaticamente.
- 🚨 **"Chamar Atenção"** — alerta urgente com som, vibração, overlay piscando e prioridade na fila.
- ⚡ **Prioridade temporária** sem interromper quem está falando.
- 🛡️ **Anti-flood, anti-spam, rate limit, cooldown** e timeout automático de transmissão.
- 📡 **WebSocket Ratchet** com heartbeat, reconexão exponencial e detecção de zumbis.
- 📱 **PWA instalável** com Service Worker, ícone, splash, modo standalone e Wake Lock.
- 🌑 **Tema escuro** estilo rádio comunicador, animações suaves, totalmente responsivo.
- 🐳 **Docker opcional** + configs completas para **Nginx / Apache / systemd / SSL Let's Encrypt**.
- 🔐 **JWT-style tokens, prepared statements, CSRF, sanitização**, headers de segurança.

---

## 🏗️ Arquitetura

```
walkie-talkie/
├── public/                 # DocumentRoot do servidor web
│   ├── index.php           # Tela de login + tela do rádio
│   ├── manifest.json       # PWA manifest
│   ├── service-worker.js   # SW para cache + push
│   ├── icons/              # Ícones 192/512
│   └── assets/
│       ├── css/style.css
│       └── js/{utils,notifications,websocket,webrtc,queue,ptt,app}.js
├── api/                    # Endpoints REST (login, heartbeat, fila, etc.)
├── classes/                # Auth, Database, Queue, Room, RateLimit, Logger, ApiResponse
├── websocket/              # WalkieTalkieServer.php + server.php (Ratchet)
├── config/                 # config.php + bootstrap.php
├── database/               # schema.sql + install.php
├── storage/logs/           # Logs em arquivo
├── docker/                 # Dockerfile, nginx.conf
├── systemd/walkie-ws.service
├── nginx-production.conf
├── apache-production.conf
├── docker-compose.yml
├── composer.json
└── .env.example
```

---

## 🚀 Instalação rápida

Veja o passo-a-passo completo em **[INSTALL.md](INSTALL.md)**.

### Em 5 comandos (Docker):

```bash
git clone <seu-repo> walkie-talkie && cd walkie-talkie
cp .env.example .env
docker-compose up -d --build
# Aguarde ~30s para o MySQL inicializar
docker-compose exec app php database/install.php
# Acesse http://localhost
```

### Em 6 comandos (servidor tradicional):

```bash
cd /var/www && sudo git clone <seu-repo> walkie-talkie && cd walkie-talkie
sudo composer install --no-dev --optimize-autoloader
sudo cp .env.example .env  &&  sudo nano .env   # ajuste DB e WS_PUBLIC_URL
mysql -u root -p < database/schema.sql
sudo cp systemd/walkie-ws.service /etc/systemd/system/ && sudo systemctl enable --now walkie-ws
sudo cp nginx-production.conf /etc/nginx/sites-available/walkie-talkie && sudo nginx -t && sudo systemctl reload nginx
```

Login admin padrão: **`admin` / `admin123`** — **altere em produção!**

---

## 📋 Endpoints REST

Todos retornam JSON `{ ok: true|false, ... }`. Auth via header `Authorization: Bearer <token>`.

| Método | Endpoint                    | Descrição                                        |
| ------ | --------------------------- | ------------------------------------------------ |
| POST   | `/api/login.php`            | Login com `mode=guest&name=` ou `mode=user&...`. |
| POST   | `/api/heartbeat.php`        | Mantém sessão viva (fallback do WS).             |
| POST   | `/api/join-room.php`        | Entra em uma sala.                               |
| POST   | `/api/leave-room.php`       | Sai da sala.                                     |
| POST   | `/api/request-talk.php`     | Pede vez de falar (entra na fila).               |
| POST   | `/api/stop-talk.php`        | Encerra transmissão.                             |
| GET    | `/api/queue-status.php`     | Estado atual da fila.                            |
| GET    | `/api/online-users.php`     | Usuários online da sala.                         |
| POST   | `/api/request-attention.php`| Aciona "Chamar Atenção".                         |
| POST   | `/api/clear-attention.php`  | Limpa estado de atenção do usuário.              |
| GET    | `/api/config.php`           | Devolve config pública (ICE, WS_URL, etc.).      |

---

## 🔌 Eventos WebSocket

Endereço: `wss://seu-dominio/ws`

Cliente → Servidor: `auth`, `heartbeat`, `request_talk`, `stop_talk`, `request_attention`, `webrtc_offer`, `webrtc_answer`, `webrtc_ice`.

Servidor → Cliente: `auth_ok`, `auth_fail`, `user_online`, `user_offline`, `queue_update`, `talk_start`, `talk_stop`, `talk_queued`, `talk_timeout`, `attention_request`, `attention_received`, `attention_cooldown`, `priority_queue_update`, `error`, `pong`.

---

## 🛠️ Configuração rápida

Edite `.env`:

```ini
APP_ENV=production
APP_URL=https://seu-dominio.com

DB_HOST=127.0.0.1
DB_NAME=walkie_talkie
DB_USER=walkie
DB_PASS=segredo

WS_HOST=0.0.0.0
WS_PORT=8080
WS_PUBLIC_URL=wss://seu-dominio.com/ws

PTT_MAX_TALK_SECONDS=30
PTT_ATTENTION_COOLDOWN=60
PTT_RATE_LIMIT_PER_MINUTE=20
```

---

## 🧪 Compatibilidade

✅ Android Chrome · ✅ Samsung Internet · ✅ Safari iOS 14.5+ · ✅ Chrome / Firefox / Edge desktop.

> **Observação iOS:** WebRTC + WebAudio só funcionam em **HTTPS** real. Em desenvolvimento, use `localhost` ou um certificado válido.

---

## 📜 Licença

MIT — use, modifique e distribua livremente.

---

Feito por **Junior Lima** / Publish Digital.

---

## 📸 Tela

[![Walkie Talkie — comunicação por voz em tempo real no navegador (push-to-talk), desenvolvido por Alex Junior (alequizao)](https://image.thum.io/get/width/700/https://publishdev.com.br/walkietalkie/)](https://publishdev.com.br/walkietalkie/)

---

## 👨‍💻 Desenvolvedor

Sistema **desenvolvido sob encomenda** por **Alex Junior (alequizao)** — Analista e
Desenvolvedor de Sistemas em Maceió, Alagoas, Brasil. Programador na **Publish Digital**.

- **E-mail:** alequizao.dev@gmail.com
- **WhatsApp:** [(82) 98871-7072](https://wa.me/5582988717072)
- **Instagram:** [@alequizao](https://instagram.com/alequizao)
- **GitHub:** [@alequizao](https://github.com/alequizao) · [perfil completo](https://github.com/alequizao/alequizao)
- **Site:** [alequizao.com](https://alequizao.com)

---

© Código proprietário, desenvolvido sob encomenda.
