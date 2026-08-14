# 📦 Guia de Instalação — Walkie Talkie Web

Você tem três caminhos. Escolha o que melhor se encaixa.

1. **Docker** (mais rápido, ideal para testar e para produção containerizada)
2. **Servidor tradicional Linux** (Ubuntu/Debian + Nginx + PHP-FPM + MySQL + systemd) — recomendado para produção
3. **Apache** (alternativa ao Nginx)

---

## ⚙️ Pré-requisitos

| Componente | Versão mínima |
| ---------- | ------------- |
| PHP        | 7.4           |
| MySQL/MariaDB | 5.7 / 10.3 |
| Composer   | 2.x           |
| Extensões PHP | `pdo_mysql`, `sockets`, `mbstring`, `json` |
| HTTPS      | **Obrigatório em produção** (microfone exige) |

---

## 🐳 OPÇÃO 1 — Docker (mais rápido)

```bash
git clone <seu-repo> walkie-talkie
cd walkie-talkie
cp .env.example .env
```

Edite `.env` se quiser (mas os defaults já funcionam para teste local):

```ini
DB_HOST=mysql
DB_NAME=walkie_talkie
DB_USER=walkie
DB_PASS=walkie_pass
WS_PUBLIC_URL=ws://localhost/ws
```

Suba:

```bash
docker-compose up -d --build
docker-compose logs -f app   # acompanhar
```

O MySQL roda o `schema.sql` automaticamente na primeira inicialização (via `docker-entrypoint-initdb.d`). Se quiser reexecutar manualmente:

```bash
docker-compose exec app php database/install.php
```

Acesse: **http://localhost**

Login admin padrão: `admin / admin123`. **Altere imediatamente em produção.**

Para parar:

```bash
docker-compose down            # para os containers
docker-compose down -v         # remove também o volume do MySQL
```

---

## 🐧 OPÇÃO 2 — Ubuntu/Debian + Nginx (produção recomendada)

### 1. Instalar dependências do sistema

```bash
sudo apt update
sudo apt install -y nginx mysql-server php7.4 php7.4-fpm php7.4-mysql php7.4-mbstring \
                    php7.4-curl php7.4-xml php7.4-zip php7.4-cli unzip git curl

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. Clonar e configurar

```bash
cd /var/www
sudo git clone <seu-repo> walkie-talkie
cd walkie-talkie
sudo chown -R $USER:www-data .
sudo composer install --no-dev --optimize-autoloader
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/
```

### 3. Configurar `.env`

```bash
cp .env.example .env
nano .env
```

Ajuste:

```ini
APP_ENV=production
APP_URL=https://seu-dominio.com

DB_HOST=127.0.0.1
DB_NAME=walkie_talkie
DB_USER=walkie
DB_PASS=COLOQUE_SENHA_FORTE

WS_HOST=127.0.0.1
WS_PORT=8080
WS_PUBLIC_URL=wss://seu-dominio.com/ws
```

### 4. Criar banco de dados

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE walkie_talkie CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'walkie'@'localhost' IDENTIFIED BY 'COLOQUE_SENHA_FORTE';
GRANT ALL PRIVILEGES ON walkie_talkie.* TO 'walkie'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Importar schema:

```bash
mysql -u walkie -p walkie_talkie < database/schema.sql
# OU
php database/install.php
```

### 5. Configurar Nginx

```bash
sudo cp nginx-production.conf /etc/nginx/sites-available/walkie-talkie
sudo nano /etc/nginx/sites-available/walkie-talkie
# substitua todas as ocorrências de "seu-dominio.com" pelo seu domínio
sudo ln -s /etc/nginx/sites-available/walkie-talkie /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 6. SSL com Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d seu-dominio.com -d www.seu-dominio.com
```

### 7. Subir o servidor WebSocket (Ratchet) com systemd

```bash
sudo cp systemd/walkie-ws.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now walkie-ws
sudo systemctl status walkie-ws

# Acompanhar logs:
sudo journalctl -u walkie-ws -f
```

Pronto! Acesse `https://seu-dominio.com`.

---

## 🪶 OPÇÃO 3 — Apache (alternativa)

```bash
sudo apt install -y apache2 libapache2-mod-php7.4 php7.4 php7.4-mysql
sudo a2enmod ssl rewrite headers proxy proxy_http proxy_wstunnel

sudo cp apache-production.conf /etc/apache2/sites-available/walkie-talkie.conf
sudo nano /etc/apache2/sites-available/walkie-talkie.conf   # ajuste o domínio
sudo a2ensite walkie-talkie
sudo systemctl reload apache2

sudo certbot --apache -d seu-dominio.com
```

WebSocket via systemd igual à opção 2.

---

## 🔍 Validação pós-instalação

1. Acesse o site → deve aparecer a tela de login.
2. Entre como **convidado** ("guest") com qualquer nome.
3. Permita microfone e notificações.
4. O ponto de status no topo deve ficar **verde** (online).
5. Abra em outro celular/aba → ambos devem se ver na lista de usuários.
6. Aperte e segure o botão grande para falar — o outro deve ouvir.
7. Aperte o botão de alerta vermelho — o outro deve receber overlay + vibração.

---

## 🐛 Troubleshooting

**Problema:** Microfone não funciona / "NotAllowedError".
**Causa:** Sem HTTPS. Browsers exigem TLS para `getUserMedia`. Use Let's Encrypt ou `localhost`.

**Problema:** WebSocket fica em "Conectando..." infinito.
**Verifique:**
```bash
sudo systemctl status walkie-ws
sudo ss -tlnp | grep 8080         # deve estar escutando
sudo journalctl -u walkie-ws -n 50
```
- Confira se `WS_PUBLIC_URL` no `.env` está correto (`wss://` em produção, `ws://` em local).
- Verifique se o Nginx/Apache tem o proxy `/ws` configurado corretamente.

**Problema:** "Mixed Content" no console.
**Causa:** Site está em HTTPS mas tentando ligar `ws://`. Use `wss://`.

**Problema:** PWA não instala / SW não registra.
- SW exige HTTPS (ou localhost).
- Verifique no DevTools → Application → Service Workers.
- Tente forçar reload com Cache desativado.

**Problema:** WebRTC não conecta entre redes diferentes.
- Pode ser necessário um servidor TURN (não apenas STUN).
- Configure no `.env` (futuro: `TURN_SERVER`, `TURN_USER`, `TURN_PASS`) e adapte `config.php`.

**Problema:** Aplicativo trava após uns minutos no celular bloqueado.
- iOS Safari suspende JS após ~30s em background. Isso é limitação do sistema.
- Wake Lock e Service Worker amenizam, mas não eliminam.

**Problema:** Eco / áudio ruim.
- WebRTC já aplica EC/NS/AGC. Use fones de ouvido se possível.
- Verifique a config em `assets/js/webrtc.js` (`audio: { echoCancellation, noiseSuppression, autoGainControl }`).

---

## 🔐 Hardening de produção

1. **Altere a senha do admin** imediatamente.
2. **Remova** ou bloqueie o acesso a `database/install.php`.
3. **Aponte o DocumentRoot direto para `/public`** — nunca exponha a raiz.
4. **Mantenha o `.env` fora do servidor web** (já é, se DocumentRoot estiver correto).
5. Configure **firewall** para liberar apenas 80/443 ao público; 8080 fica interno.
6. Ative **fail2ban** para SSH e endpoints sensíveis.
7. Faça **backup** automatizado do MySQL.
8. Considere um **TURN server** (coturn) se houver muitos usuários atrás de NAT/firewall corporativo.
9. Considere **Redis** para escalar PUB/SUB entre múltiplas instâncias do servidor WS.

---

## 📈 Escalando

Para **dezenas de usuários** simultâneos: a arquitetura mesh (P2P) atual basta — cada peer fala direto com cada peer.

Para **centenas de usuários** simultâneos numa mesma sala: o mesh deixa de funcionar (N² conexões). Soluções:

- Usar uma **SFU** (Selective Forwarding Unit) — ex: [mediasoup](https://mediasoup.org), [Janus](https://janus.conf.meetecho.com), [LiveKit](https://livekit.io).
- Substituir só a parte WebRTC; o backend de fila/atenção continua igual.

Para **múltiplas instâncias** do servidor WS: adicione Redis PUB/SUB no `WalkieTalkieServer` para sincronizar broadcasts entre processos.

---

Pronto. Qualquer dúvida, abra um issue ou consulte o `README.md`.
