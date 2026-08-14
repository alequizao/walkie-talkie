# Changelog

## 1.9.1 — 2026-07-29 — domínio próprio: fofocar.alequizao.com

O mesmo app agora atende em três endereços, **sem duplicar arquivo**:

| Endereço | Base | WebSocket |
|---|---|---|
| `publishdev.com.br/walkietalkie` | `/walkietalkie` | `wss://publishdev.com.br/walkietalkie/ws` |
| `fofocar.alequizao.com` | `/` (raiz) | `wss://fofocar.alequizao.com/ws` |
| `voip.usegrupodona.com.br` | `/` (raiz) | `wss://voip.usegrupodona.com.br/ws` |

- Vhost `fofocar.alequizao.com.conf` com `DocumentRoot` em `walkietalkie/public`,
  `Alias /api`, proxy WebSocket em `/ws` e re-injeção do header `Authorization`
  (o `mod_proxy_fcgi` descarta). TLS do visitante é o da Cloudflare.
- **Isolamento melhor que no publishdev**: `classes/`, `config/`, `websocket/`,
  `storage/` e `vendor/` ficam fora do DocumentRoot — inalcançáveis pela web.
- A URL do WebSocket passou a ser derivada **sempre do host da requisição**
  (antes só o host `voip.*` tinha esse tratamento, então qualquer domínio novo
  herdava o `WS_PUBLIC_URL` fixo do `.env` e abria ws cross-origin).
  Detecção de HTTPS considera `X-Forwarded-Proto` e `CF-Visitor` (Cloudflare).
- `api/config.php` também devolve o `ws_url` do host da requisição.
- Marca d'água anti-vazamento removida da interface.

## 1.9.0 — 2026-07-29 — Web Push (VAPID)

Notificações que chegam **com o app fechado**, pelo sistema operacional.
Requer `php database/migrate.php` e `sudo systemctl restart walkie-ws`.

- `classes/Push.php` — Web Push nativo (VAPID + aes128gcm, RFC 8291/8188/8292),
  sem dependência externa. Portado da `lib_push.php` do módulo de ônibus.
- Chaves VAPID geradas uma vez e guardadas na tabela `settings`.
- Tabela `push_subscriptions` (1 linha por aparelho); inscrições mortas (404/410)
  se removem sozinhas no envio.
- Endpoints: `api/push-subscribe.php`, `api/push-unsubscribe.php` e
  `api/push-test.php` (dispara um push de teste para você mesmo).
- **Envio fora do loop de eventos**: `Push::queueForUser()` dispara
  `bin/push-send.php` em processo separado. Um curl de até 12s dentro do
  servidor WebSocket (single-threaded) travaria o áudio de todos os conectados.
- Gatilhos no servidor WS, sempre que o destinatário **não** está conectado:
  mensagem de texto, recado de voz, chamada recebida ("Fulano está ligando") e
  "chamou atenção" no canal.
- Service Worker: se já existe janela visível, repassa o evento ao app em vez de
  mostrar notificação duplicada; o toque abre direto a conversa (`?chat=<uuid>`).
- Cliente: `WT.notifications.enablePush()` inscreve o aparelho após a permissão,
  e reinscreve sozinho se a chave VAPID do servidor mudar.

**Verificado**: round-trip criptográfico completo (payload cifrado pelo servidor
e decifrado com a chave do "navegador" volta idêntico) e fluxo real pelo
WebSocket — mensagem para usuário offline gerou o push com o conteúdo correto.

**Limite conhecido**: no iOS o push só funciona com o app **instalado na tela de
início** (16.4+). Nenhum PWA contorna isso.

## 1.8.0 — 2026-07-29

Rodada de hardening e performance no backend. Backup do estado anterior em
`/www/backup/walkietalkie/backup-alequiza0-29-07-2026/`.

### ⚠️ Ação necessária ao atualizar

```bash
php database/migrate.php     # índices + chave única do rate limit + DATETIME(3)
sudo systemctl restart walkie-ws
```

Contas com nome reservado (ver `RESERVED_NAMES` no `.env`) passam a exigir senha.
A conta **ALEQUIZAO** recebeu senha nesta atualização.

### Segurança

- Login guest não assume mais conta de admin nem conta com senha. Nomes em
  `RESERVED_NAMES` exigem senha (`Auth::quickRegister`).
- Login com verificação em tempo constante (`password_verify` sempre executa),
  evitando enumeração de usuários por tempo de resposta.
- `Auth::login` busca por `username` (único) antes de `display_name`, e no
  fallback só considera contas com senha — fim da ambiguidade entre homônimos.
- Rehash automático da senha quando o custo do bcrypt muda.
- Token por querystring (`?token=`) só é aceito em `api/voice.php` (a tag
  `<audio>` não manda header). Nos demais endpoints, apenas `Authorization`.
- `APP_SECRET` fraco/ausente derruba a aplicação em produção (o default antigo
  era `md5(__FILE__)`, derivável).
- Erros inesperados não vazam mais mensagem interna; handler global padroniza a
  resposta 500.
- Endpoints validam método HTTP (405) e UUIDs antes de consultar o banco.
- `.htaccess`: bloqueio de `tests/`, `config/`, `classes/`, `websocket/`,
  `storage/`, `vendor/`, dotfiles e listagem de diretório.
- `join-room`/`queue-status` devolvem `Room::publicView()` — sem `password_hash`.

### Correção de concorrência

- Fila serializada por sala com `SELECT ... FOR UPDATE` — dois `request_talk`
  simultâneos não viram mais dois `talking`.
- `Database::query` não reconecta no meio de uma transação (antes, um reconnect
  descartava o rollback silenciosamente). Reconexão só fora de transação, com
  retry único em "server has gone away".
- Rate limit atômico (`INSERT ... ON DUPLICATE KEY UPDATE`) com chave única por
  janela. O caminho antigo comparava `window_start > NOW() - janela`, que nunca
  casava com o início da janela — na prática o limite não bloqueava.
- `Database::insert` lê `lastInsertId()` da mesma conexão do INSERT.
- `Database::update` prefixa os placeholders do SET (`:set_col`), evitando que
  uma coluna sobrescreva silenciosamente um parâmetro do WHERE.

### Correção de comportamento

- Timeout de transmissão agora avisa a sala mesmo quando não há ninguém na fila
  (antes o broadcast dependia de existir um próximo — quem falava sozinho nunca
  era cortado na interface).
- Recados pendentes: só são marcados como entregues os que realmente foram
  enviados (o `LIMIT 200` marcava o excedente sem nunca entregar).
- Reconexão da mesma conta derruba o socket anterior sem marcar o usuário como
  offline nem tirá-lo da fila.
- `duration_ms` tem milissegundos de verdade (`DATETIME(3)`); antes era
  `segundos * 1000`.
- Fuso do MySQL derivado de `APP_TIMEZONE` (antes fixo em `-03:00`).
- `.env`: suporte a `export`, aspas e preservação de `"0"`/`""` (o `?:` antigo
  transformava ambos em default).
- `putenv()` não é mais chamado quando está em `disable_functions`.

### Performance

- Fim do `SELECT 1` antes de toda query (dobrava os round-trips ao MySQL).
- `sessions.last_activity` regravado no máximo 1x/min por token; sliding session
  no WS 1x/hora por conexão e no `heartbeat.php` só perto de expirar.
- `config.php` e `bootstrap.php` idempotentes — o `.env` deixa de ser reparseado
  a cada endpoint.
- Reindexação da fila em uma query (`CASE`) em vez de um `UPDATE` por linha.
- `Room::getDefault()` com cache em memória.
- `api/voice.php` usa `fpassthru`/`stream_copy_to_stream` e cacheia só até a
  expiração real do recado (antes fixava 24h e podia servir áudio já apagado).
- Índices novos: `users(display_name)`, `sessions(user_id,expires_at)`,
  `queue(room_id,status,priority,queue_position,id)`, `messages(kind,created_at)`,
  `messages(to_user_id,from_user_id,id)`.
- Housekeeping no `tick()` do WS a cada 10 min: limpa `rate_limits`, sessões
  expiradas e usuários "online" fantasma (antes dependia de um cron inexistente).

### Manutenção

- `onMessage` do WS usa mapa `type => handler` no lugar do `switch` gigante.
- Boilerplate dos endpoints extraído para `ApiResponse::requireMethod()`,
  `rateLimit()`, `config()` e `clientIp()` (respeita Cloudflare).
- Limites de rate vêm todos da config (antes havia valores hardcoded que
  contradiziam o `config.php`).
- Autoload do Composer em primeiro lugar, PSR-4 manual como fallback.
- `tests/run.php`: 29 testes de fila, rate limit e auth, sem tocar em dados reais.
- `database/migrate.php`: runner de migrations idempotente.
- Versão com fonte única: `APP_VERSION` no `.env` alimenta a tela, o manifest e o
  `CACHE_VERSION` do Service Worker (via `?v=` no `register()`).
- Suporte a TURN por `.env` (`TURN_URL`/`TURN_USER`/`TURN_PASS`).
