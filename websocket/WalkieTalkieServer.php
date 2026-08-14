<?php
namespace WalkieTalkie;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

/**
 * WalkieTalkieServer - Servidor WebSocket
 *
 * Mensagens recebidas (cliente -> servidor):
 *   { type: 'auth',        token: '...' }
 *   { type: 'heartbeat' }
 *   { type: 'request_talk' }
 *   { type: 'stop_talk' }
 *   { type: 'request_attention' }
 *   { type: 'webrtc_offer',   target: 'uuid', sdp: ... }
 *   { type: 'webrtc_answer',  target: 'uuid', sdp: ... }
 *   { type: 'webrtc_ice',     target: 'uuid', candidate: ... }
 *   { type: 'private_start',  target: 'uuid' }   // canal privado 1-a-1 (áudio)
 *   { type: 'private_stop',   target: 'uuid' }
 *   { type: 'private_msg',    target: 'uuid', body: '...', ref: 'cliente-id' }  // texto/recado
 *   { type: 'private_audio',  target: 'uuid', id: 'msg-uuid', ref: '...' }      // recado de voz (após upload)
 *   { type: 'private_history', target: 'uuid' }  // últimas mensagens da conversa
 *   { type: 'private_read',   target: 'uuid', id?: 'msg-uuid' }  // marca lido/ouvido -> 'private_seen' ao autor
 *   { type: 'call_request|call_accept|call_decline|call_end', target: 'uuid' }  // ligação ao vivo (full-duplex)
 *
 * Mensagens enviadas (servidor -> cliente):
 *   { type: 'connected',       you: {...} }
 *   { type: 'auth_ok',         user: {...}, room: {...}, queue: {...}, online: [...] }
 *   { type: 'auth_error',      message: '...' }
 *   { type: 'user_online',     user: {...} }
 *   { type: 'user_offline',    user: {...} }
 *   { type: 'queue_update',    state: {...} }
 *   { type: 'talk_start',      user: {...}, queue_id, priority }
 *   { type: 'talk_stop',       user: {...} }
 *   { type: 'attention_request', user: {...} }
 *   { type: 'attention_cooldown', remaining: N }
 *   { type: 'heartbeat_ack',   ts: N }
 *   { type: 'webrtc_offer',  from: 'uuid', sdp }
 *   { type: 'webrtc_answer', from: 'uuid', sdp }
 *   { type: 'webrtc_ice',    from: 'uuid', candidate }
 *   { type: 'error', message }
 */
class WalkieTalkieServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface> */
    private \SplObjectStorage $clients;

    /** @var array<string, ConnectionInterface>  uuid => conn */
    private array $byUuid = [];

    private array $config;

    /** Timestamp da última limpeza de áudios expirados */
    private int $lastAudioCleanup = 0;

    /** Timestamp da última limpeza de rate_limits/sessions expiradas */
    private int $lastHousekeeping = 0;

    /** type => método handler (substitui o switch gigante do onMessage) */
    private array $handlers = [];

    public function __construct(array $config)
    {
        $this->clients = new \SplObjectStorage();
        $this->config = $config;

        $this->handlers = [
            'auth'              => 'handleAuth',
            'heartbeat'         => 'handleHeartbeat',
            'request_talk'      => 'handleRequestTalk',
            'stop_talk'         => 'handleStopTalk',
            'request_attention' => 'handleAttention',

            'webrtc_offer'       => 'relayWebRTC',
            'webrtc_answer'      => 'relayWebRTC',
            'webrtc_ice'         => 'relayWebRTC',
            'webrtc_renegotiate' => 'relayWebRTC',

            'private_start' => 'relayPrivate',
            'private_stop'  => 'relayPrivate',
            'call_request'  => 'relayPrivate',
            'call_accept'   => 'relayPrivate',
            'call_decline'  => 'relayPrivate',
            'call_end'      => 'relayPrivate',

            'private_msg'     => 'handlePrivateMsg',
            'private_audio'   => 'handlePrivateAudio',
            'private_history' => 'handlePrivateHistory',
            'private_read'    => 'handlePrivateRead',
        ];

        echo "[WS] Servidor iniciado em {$config['websocket']['host']}:{$config['websocket']['port']}\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $conn->wt = (object) [
            'authenticated' => false,
            'user'          => null,
            'room_id'       => null,
            'last_heartbeat'=> time(),
            'connected_at'  => time(),
        ];
        $this->clients->attach($conn);

        $conn->send(json_encode([
            'type' => 'connected',
            'message' => 'Aguardando autenticação...',
            'ice_servers' => $this->config['webrtc']['ice_servers'],
        ]));
        echo "[WS] Conexão aberta ({$conn->resourceId}). Total: " . count($this->clients) . "\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        if (strlen($msg) > $this->config['websocket']['max_message_size']) {
            $from->close();
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data) || empty($data['type'])) {
            return;
        }

        $type = (string) $data['type'];

        if (!isset($this->handlers[$type])) {
            $this->sendError($from, 'Tipo desconhecido.');
            return;
        }

        // Auth obrigatório antes de qualquer ação
        if (!$from->wt->authenticated && $type !== 'auth') {
            $this->sendError($from, 'Autentique-se primeiro.');
            return;
        }

        try {
            $handler = $this->handlers[$type];
            $this->{$handler}($from, $data);
        } catch (\Throwable $e) {
            Logger::error('WS handler error: ' . $e->getMessage(), [
                'type' => $type,
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->sendError($from, 'Erro interno do servidor.');
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);

        if (!empty($conn->wt) && $conn->wt->authenticated && $conn->wt->user) {
            $user = $conn->wt->user;

            // Só remove do índice se ainda for ESTA conexão (uma reconexão da
            // mesma conta já pode ter registrado o socket novo).
            if (($this->byUuid[$user['uuid']] ?? null) === $conn) {
                unset($this->byUuid[$user['uuid']]);
            }

            // Conexão substituída por outra da mesma conta: não marca offline
            // nem tira da fila — quem manda é a conexão nova.
            if (!empty($conn->wt->replaced)) {
                echo "[WS] Conexão substituída ({$conn->resourceId}).\n";
                return;
            }

            // Atualiza status
            try {
                Database::query(
                    "UPDATE users SET online_status = 'offline', last_seen = NOW() WHERE id = :id",
                    ['id' => $user['id']]
                );

                // Remove da fila
                $next = Queue::removeUser($conn->wt->room_id, $user['id']);
                $this->broadcastQueueUpdate($conn->wt->room_id);

                if ($next) {
                    $this->broadcastToRoom($conn->wt->room_id, [
                        'type'     => 'talk_start',
                        'user'     => $this->publicUser($next),
                        'queue_id' => $next['queue_id'],
                        'priority' => $next['priority'] ?? 0,
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::error('Erro ao desconectar: ' . $e->getMessage());
            }

            $this->broadcastToRoom($conn->wt->room_id, [
                'type' => 'user_offline',
                'user' => ['uuid' => $user['uuid'], 'display_name' => $user['display_name']],
            ], $conn);

            Logger::event('logout', $user['id'], $conn->wt->room_id, 'WS desconectado');
        }

        echo "[WS] Conexão fechada ({$conn->resourceId}). Restantes: " . count($this->clients) . "\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        Logger::error('WS error: ' . $e->getMessage());
        $conn->close();
    }

    // -----------------------------------------------------------
    // Handlers
    // -----------------------------------------------------------

    private function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $token = $data['token'] ?? '';
        $session = Auth::validate($token);

        if (!$session) {
            $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Token inválido.']));
            $conn->close();
            return;
        }

        $room = Room::getDefault();
        $uuid = $session['user']['uuid'];

        // Mesma conta reconectando (troca de rede, reload do PWA): derruba a
        // conexão anterior para não deixar socket zumbi no índice byUuid.
        if (isset($this->byUuid[$uuid]) && $this->byUuid[$uuid] !== $conn) {
            $old = $this->byUuid[$uuid];
            $old->wt->replaced = true;
            $old->send(json_encode([
                'type' => 'session_replaced',
                'message' => 'Sua sessão foi aberta em outro dispositivo/aba.',
            ]));
            $old->close();
        }

        $conn->wt->authenticated = true;
        $conn->wt->token   = $token;
        $conn->wt->user    = $session['user'];
        $conn->wt->room_id = (int) $room['id'];
        $this->byUuid[$uuid] = $conn;

        Database::query(
            "UPDATE users SET online_status = 'online', last_heartbeat = NOW() WHERE id = :id",
            ['id' => $session['user']['id']]
        );

        Logger::event('login', $session['user']['id'], $room['id'], 'WS autenticado');

        // Estado atual
        $online = $this->getOnlineUsers($room['id']);
        $queueState = Queue::getState($room['id']);

        $conn->send(json_encode([
            'type'  => 'auth_ok',
            'user'  => $session['user'],
            'room'  => [
                'id'   => (int) $room['id'],
                'uuid' => $room['uuid'],
                'name' => $room['name'],
                'slug' => $room['slug'],
                'max_talk_seconds' => (int) $room['max_talk_seconds'],
            ],
            'queue'  => $queueState,
            'online' => $online,
            'config' => [
                'attention_cooldown' => $this->config['ptt']['attention_cooldown'],
                'max_talk_seconds'   => $this->config['ptt']['max_talk_seconds'],
            ],
        ]));

        // Avisa os outros
        $this->broadcastToRoom($room['id'], [
            'type' => 'user_online',
            'user' => $session['user'],
        ], $conn);

        // Entrega recados que chegaram enquanto estava offline
        $this->deliverPending($conn);
    }

    private function handleHeartbeat(ConnectionInterface $conn, array $data = []): void
    {
        $now = time();
        $conn->wt->last_heartbeat = $now;

        Database::query(
            "UPDATE users SET last_heartbeat = NOW() WHERE id = :id",
            ['id' => $conn->wt->user['id']]
        );

        // Sliding session: prolonga o token, mas só uma vez por hora por conexão
        // (antes era um UPDATE em `sessions` a cada 25s por usuário conectado).
        if (!empty($conn->wt->token) && ($now - ($conn->wt->session_touched ?? 0)) > 3600) {
            $conn->wt->session_touched = $now;
            Database::query(
                "UPDATE sessions
                 SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY), last_activity = NOW()
                 WHERE token = :t",
                ['t' => $conn->wt->token]
            );
        }

        $conn->send(json_encode(['type' => 'heartbeat_ack', 'ts' => $now]));
    }

    private function handleRequestTalk(ConnectionInterface $conn, array $data = []): void
    {
        $user = $conn->wt->user;
        $roomId = $conn->wt->room_id;

        // Anti-flood
        if (!RateLimit::check('talk', $user['id'], null,
            $this->config['rate_limit']['talk']['max'],
            $this->config['rate_limit']['talk']['window']
        )) {
            $this->sendError($conn, 'Você está falando demais. Aguarde.');
            return;
        }

        $result = Queue::requestTalk($roomId, $user['id'], false);

        Logger::event($result['status'] === 'talking' ? 'talk_start' : 'queue_join',
            $user['id'], $roomId);

        $this->broadcastQueueUpdate($roomId);

        if ($result['status'] === 'talking') {
            $this->broadcastToRoom($roomId, [
                'type'     => 'talk_start',
                'user'     => $user,
                'queue_id' => $result['queue_id'],
                'priority' => 0,
            ]);
        } else {
            // Apenas para o solicitante
            $conn->send(json_encode([
                'type'     => 'talk_queued',
                'position' => $result['position'],
            ]));
        }
    }

    private function handleStopTalk(ConnectionInterface $conn, array $data = []): void
    {
        $user = $conn->wt->user;
        $roomId = $conn->wt->room_id;

        $next = Queue::stopTalk($roomId, $user['id']);

        Logger::event('talk_stop', $user['id'], $roomId);

        $this->broadcastToRoom($roomId, [
            'type' => 'talk_stop',
            'user' => $user,
        ]);

        $this->broadcastQueueUpdate($roomId);

        if ($next) {
            $this->broadcastToRoom($roomId, [
                'type'     => 'talk_start',
                'user'     => $this->publicUser($next),
                'queue_id' => $next['queue_id'],
                'priority' => $next['priority'] ?? 0,
            ]);
        }
    }

    private function handleAttention(ConnectionInterface $conn, array $data = []): void
    {
        $user = $conn->wt->user;
        $roomId = $conn->wt->room_id;
        $cooldown = (int) $this->config['ptt']['attention_cooldown'];

        // Cooldown (uma única query, sem o SELECT extra de antes)
        $remaining = RateLimit::cooldownRemaining('attention', (int) $user['id'], $cooldown);
        if ($remaining > 0) {
            $conn->send(json_encode([
                'type'      => 'attention_cooldown',
                'remaining' => $remaining,
            ]));
            Logger::event('attention_blocked', $user['id'], $roomId, 'Cooldown ativo');
            return;
        }

        // Rate limit (max por minuto)
        if (!RateLimit::check('attention', $user['id'], null,
            $this->config['rate_limit']['attention']['max'],
            $this->config['rate_limit']['attention']['window']
        )) {
            $this->sendError($conn, 'Limite de alertas atingido. Aguarde 1 minuto.');
            return;
        }

        Database::query(
            "UPDATE users SET attention_count = attention_count + 1, last_attention_at = NOW() WHERE id = :id",
            ['id' => $user['id']]
        );

        Logger::event('attention', $user['id'], $roomId, 'Solicitou prioridade');

        // Coloca na fila com prioridade (sem interromper transmissão atual)
        Queue::requestTalk($roomId, $user['id'], true);
        $this->broadcastQueueUpdate($roomId);

        // Broadcast alerta para todos
        $this->broadcastToRoom($roomId, [
            'type' => 'attention_request',
            'user' => $user,
            'ts'   => time(),
        ]);

        // Quem não está conectado recebe por push
        $this->pushToOffline([
            'title' => '⚠️ ' . $user['display_name'],
            'body'  => 'chamou atenção no canal',
            'tag'   => 'attention',
            'kind'  => 'attention',
            'from'  => $user['uuid'],
            'url'   => '',
        ], (int) $user['id']);
    }

    private function relayWebRTC(ConnectionInterface $from, array $data): void
    {
        $targetUuid = $data['target'] ?? null;
        if (!$targetUuid || !isset($this->byUuid[$targetUuid])) return;

        $target = $this->byUuid[$targetUuid];
        if (!$target->wt->authenticated) return;
        if ($target->wt->room_id !== $from->wt->room_id) return;

        $payload = [
            'type' => $data['type'],
            'from' => $from->wt->user['uuid'],
        ];
        if (isset($data['sdp']))       $payload['sdp']       = $data['sdp'];
        if (isset($data['candidate'])) $payload['candidate'] = $data['candidate'];

        $target->send(json_encode($payload));
    }

    /**
     * Relay de sinalização de conversa privada (1-a-1).
     * Apenas notifica o alvo (para UI). O áudio em si trafega P2P via WebRTC.
     */
    private function relayPrivate(ConnectionInterface $from, array $data): void
    {
        $targetUuid = $data['target'] ?? null;
        if (!Auth::isUuid($targetUuid)) return;

        if (!isset($this->byUuid[$targetUuid])) {
            // Alvo offline: uma chamada ainda vale um push ("Fulano está ligando")
            if (($data['type'] ?? '') === 'call_request') {
                $uid = $this->userIdByUuid($targetUuid);
                if ($uid) {
                    $this->pushTo($uid, [
                        'title' => '📞 ' . $from->wt->user['display_name'],
                        'body'  => 'está ligando para você',
                        'tag'   => 'call-' . $from->wt->user['uuid'],
                        'kind'  => 'call',
                        'from'  => $from->wt->user['uuid'],
                        'from_name' => $from->wt->user['display_name'],
                        'url'   => '?chat=' . $from->wt->user['uuid'],
                    ]);
                }
                $from->send(json_encode([
                    'type'   => 'call_offline',
                    'target' => $targetUuid,
                    'message' => 'Usuário offline — avisamos por notificação.',
                ]));
            }
            return;
        }

        $target = $this->byUuid[$targetUuid];
        if (empty($target->wt->authenticated)) return;
        if ($target->wt->room_id !== $from->wt->room_id) return;

        $payload = [
            'type' => $data['type'],
            'from' => $from->wt->user['uuid'],
        ];
        if (isset($data['media'])) $payload['media'] = $data['media']; // 'audio' | 'video'
        $target->send(json_encode($payload));
    }

    /**
     * Mensagem de texto privada (1-a-1). Persiste sempre (vira "recado" se o
     * destinatário estiver offline) e entrega em tempo real se estiver online.
     */
    private function handlePrivateMsg(ConnectionInterface $from, array $data): void
    {
        $user = $from->wt->user;
        $targetUuid = $data['target'] ?? null;
        $body = trim((string) ($data['body'] ?? ''));
        $ref  = (string) ($data['ref'] ?? '');

        if (!Auth::isUuid($targetUuid) || $body === '') return;
        if (mb_strlen($body) > 2000) $body = mb_substr($body, 0, 2000);

        // Anti-flood
        if (!RateLimit::check('msg', $user['id'], null,
            $this->config['rate_limit']['msg']['max'],
            $this->config['rate_limit']['msg']['window']
        )) {
            $this->sendError($from, 'Você está enviando mensagens rápido demais. Aguarde.');
            return;
        }

        $target = Database::fetch(
            "SELECT id, uuid, display_name, avatar_color FROM users WHERE uuid = :u LIMIT 1",
            ['u' => $targetUuid]
        );
        if (!$target) { $this->sendError($from, 'Usuário não encontrado.'); return; }

        $online = isset($this->byUuid[$targetUuid]) && !empty($this->byUuid[$targetUuid]->wt->authenticated);
        $msgUuid = $this->uuidv4();

        Database::insert('messages', [
            'uuid'         => $msgUuid,
            'room_id'      => $from->wt->room_id,
            'from_user_id' => $user['id'],
            'to_user_id'   => (int) $target['id'],
            'body'         => $body,
            'delivered_at' => $online ? date('Y-m-d H:i:s') : null,
        ]);

        Logger::event('private_msg', $user['id'], $from->wt->room_id,
            'Para ' . $target['display_name'] . ($online ? '' : ' (recado)'));

        $createdAt = date('c');

        // Entrega ao destinatário se online
        if ($online) {
            $this->byUuid[$targetUuid]->send(json_encode([
                'type' => 'private_msg',
                'id'   => $msgUuid,
                'from' => $user['uuid'],
                'from_name' => $user['display_name'],
                'avatar_color' => $user['avatar_color'] ?? '#22c55e',
                'kind' => 'text',
                'body' => $body,
                'created_at' => $createdAt,
            ]));
        }

        // Offline: avisa por Web Push (chega com o app fechado)
        if (!$online) {
            $this->pushTo((int) $target['id'], [
                'title' => $user['display_name'],
                'body'  => mb_substr($body, 0, 120),
                'tag'   => 'msg-' . $user['uuid'],
                'kind'  => 'msg',
                'from'  => $user['uuid'],
                'from_name' => $user['display_name'],
                'url'   => '?chat=' . $user['uuid'],
            ]);
        }

        // ACK ao remetente (status de entrega)
        $from->send(json_encode([
            'type' => 'private_msg_sent',
            'id'   => $msgUuid,
            'ref'  => $ref,
            'target' => $targetUuid,
            'delivered' => $online,
            'created_at' => $createdAt,
        ]));
    }

    /**
     * Notificação de recado de voz. O arquivo e a linha em `messages` já foram
     * criados via POST /api/send-voice.php; aqui apenas entregamos em tempo real
     * se o destinatário estiver online (offline = recado, entregue ao reconectar).
     */
    private function handlePrivateAudio(ConnectionInterface $from, array $data): void
    {
        $user = $from->wt->user;
        $msgUuid = $data['id'] ?? null;
        $targetUuid = $data['target'] ?? null;
        if (!Auth::isUuid($msgUuid) || !Auth::isUuid($targetUuid)) return;

        // Confere que a mensagem existe, é deste remetente e é áudio.
        $msg = Database::fetch(
            "SELECT m.id, m.duration_ms, u.uuid AS to_uuid
             FROM messages m JOIN users u ON u.id = m.to_user_id
             WHERE m.uuid = :u AND m.from_user_id = :me AND m.kind = 'audio' LIMIT 1",
            ['u' => $msgUuid, 'me' => $user['id']]
        );
        if (!$msg || $msg['to_uuid'] !== $targetUuid) return;

        $online = isset($this->byUuid[$targetUuid]) && !empty($this->byUuid[$targetUuid]->wt->authenticated);

        if ($online) {
            Database::query("UPDATE messages SET delivered_at = NOW() WHERE uuid = :u", ['u' => $msgUuid]);
            $this->byUuid[$targetUuid]->send(json_encode([
                'type' => 'private_msg',
                'id'   => $msgUuid,
                'from' => $user['uuid'],
                'from_name' => $user['display_name'],
                'avatar_color' => $user['avatar_color'] ?? '#22c55e',
                'kind' => 'audio',
                'media_id' => $msgUuid,
                'duration_ms' => (int) $msg['duration_ms'],
                'created_at' => date('c'),
            ]));
        }

        if (!$online) {
            $segundos = (int) round(((int) $msg['duration_ms']) / 1000);
            $this->pushTo((int) $this->userIdByUuid($targetUuid), [
                'title' => $user['display_name'],
                'body'  => '🎤 Recado de voz' . ($segundos > 0 ? " ({$segundos}s)" : ''),
                'tag'   => 'msg-' . $user['uuid'],
                'kind'  => 'audio',
                'from'  => $user['uuid'],
                'from_name' => $user['display_name'],
                'url'   => '?chat=' . $user['uuid'],
            ]);
        }

        $from->send(json_encode([
            'type' => 'private_msg_sent',
            'id'   => $msgUuid,
            'ref'  => $data['ref'] ?? '',
            'target' => $targetUuid,
            'delivered' => $online,
            'created_at' => date('c'),
        ]));
    }

    /** Envia as últimas mensagens trocadas entre o usuário e o alvo. */
    private function handlePrivateHistory(ConnectionInterface $from, array $data): void
    {
        $me = (int) $from->wt->user['id'];
        $targetUuid = $data['target'] ?? null;
        if (!Auth::isUuid($targetUuid)) return;

        $target = Database::fetch("SELECT id FROM users WHERE uuid = :u LIMIT 1", ['u' => $targetUuid]);
        if (!$target) return;
        $tid = (int) $target['id'];

        // Só traz áudios não expirados (24h); textos sempre.
        $rows = Database::fetchAll(
            "SELECT m.uuid, m.kind, m.body, m.duration_ms, m.created_at, m.read_at, u.uuid AS from_uuid
             FROM messages m JOIN users u ON u.id = m.from_user_id
             WHERE ((m.from_user_id = :me AND m.to_user_id = :t)
                 OR (m.from_user_id = :t2 AND m.to_user_id = :me2))
               AND (m.kind = 'text' OR m.created_at > (NOW() - INTERVAL 24 HOUR))
             ORDER BY m.id DESC LIMIT 50",
            ['me' => $me, 't' => $tid, 't2' => $tid, 'me2' => $me]
        );

        $myUuid = $from->wt->user['uuid'];
        $messages = array_reverse(array_map(function ($r) use ($myUuid) {
            return [
                'id'   => $r['uuid'],
                'mine' => $r['from_uuid'] === $myUuid,
                'kind' => $r['kind'],
                'body' => $r['body'],
                'media_id' => $r['kind'] === 'audio' ? $r['uuid'] : null,
                'duration_ms' => (int) $r['duration_ms'],
                'created_at' => $r['created_at'],
                'read' => $r['read_at'] !== null,
            ];
        }, $rows));

        $from->send(json_encode([
            'type' => 'private_history',
            'target' => $targetUuid,
            'messages' => $messages,
        ]));
    }

    /**
     * Marca como lidas/ouvidas as mensagens recebidas do alvo e avisa o autor
     * (recibo estilo WhatsApp: ✓✓). Com 'id' marca um áudio específico (ouvido);
     * sem 'id' marca todos os textos da conversa (lidos ao abrir).
     */
    private function handlePrivateRead(ConnectionInterface $from, array $data): void
    {
        $me = (int) $from->wt->user['id'];
        $myUuid = $from->wt->user['uuid'];
        $targetUuid = $data['target'] ?? null;
        if (!Auth::isUuid($targetUuid)) return;
        $target = Database::fetch("SELECT id FROM users WHERE uuid = :u LIMIT 1", ['u' => $targetUuid]);
        if (!$target) return;
        $tid = (int) $target['id'];
        $msgId = $data['id'] ?? null;
        if ($msgId !== null && !Auth::isUuid($msgId)) return;

        if ($msgId) {
            $changed = Database::query(
                "UPDATE messages SET read_at = NOW()
                 WHERE uuid = :u AND to_user_id = :me AND from_user_id = :t AND read_at IS NULL",
                ['u' => $msgId, 'me' => $me, 't' => $tid]
            )->rowCount();
            $payload = ['type' => 'private_seen', 'by' => $myUuid, 'id' => $msgId];
        } else {
            $changed = Database::query(
                "UPDATE messages SET read_at = NOW()
                 WHERE to_user_id = :me AND from_user_id = :t AND read_at IS NULL AND kind = 'text'",
                ['me' => $me, 't' => $tid]
            )->rowCount();
            $payload = ['type' => 'private_seen', 'by' => $myUuid, 'scope' => 'text'];
        }

        if ($changed < 1) return;

        // Avisa o autor (o alvo) se estiver online
        if (isset($this->byUuid[$targetUuid]) && !empty($this->byUuid[$targetUuid]->wt->authenticated)) {
            $this->byUuid[$targetUuid]->send(json_encode($payload));
        }
    }

    /** Entrega recados pendentes (recebidos enquanto offline) ao autenticar. */
    private function deliverPending(ConnectionInterface $conn): void
    {
        $me = (int) $conn->wt->user['id'];
        $rows = Database::fetchAll(
            "SELECT m.uuid, m.kind, m.body, m.duration_ms, m.created_at,
                    u.uuid AS from_uuid, u.display_name AS from_name, u.avatar_color
             FROM messages m JOIN users u ON u.id = m.from_user_id
             WHERE m.to_user_id = :me AND m.delivered_at IS NULL
               AND (m.kind = 'text' OR m.created_at > (NOW() - INTERVAL 24 HOUR))
             ORDER BY m.id ASC LIMIT 200",
            ['me' => $me]
        );
        if (!$rows) return;

        $sent = [];
        foreach ($rows as $r) {
            $sent[] = $r['uuid'];
            $conn->send(json_encode([
                'type' => 'private_msg',
                'id'   => $r['uuid'],
                'from' => $r['from_uuid'],
                'from_name' => $r['from_name'],
                'avatar_color' => $r['avatar_color'] ?? '#22c55e',
                'kind' => $r['kind'],
                'body' => $r['body'],
                'media_id' => $r['kind'] === 'audio' ? $r['uuid'] : null,
                'duration_ms' => (int) $r['duration_ms'],
                'created_at' => date('c', strtotime($r['created_at'])),
                'pending' => true,
            ]));
        }
        // Marca como entregues APENAS as que realmente foram enviadas — o LIMIT
        // 200 acima fazia com que o excedente fosse marcado sem nunca chegar.
        $ph = [];
        $params = ['me' => $me];
        foreach ($sent as $i => $uuid) {
            $ph[] = ":u$i";
            $params["u$i"] = $uuid;
        }
        Database::query(
            "UPDATE messages SET delivered_at = NOW()
             WHERE to_user_id = :me AND delivered_at IS NULL AND uuid IN (" . implode(',', $ph) . ')',
            $params
        );
    }

    /** Apaga arquivos e linhas de recados de voz com mais de 24h. */
    private function cleanupExpiredAudio(): void
    {
        try {
            $rows = Database::fetchAll(
                "SELECT uuid, media_path FROM messages
                 WHERE kind = 'audio' AND created_at < (NOW() - INTERVAL 24 HOUR) LIMIT 500"
            );
            if (!$rows) return;

            $dir = __DIR__ . '/../storage/media/';
            $ids = [];
            foreach ($rows as $r) {
                if (!empty($r['media_path'])) {
                    $file = $dir . basename($r['media_path']);
                    if (is_file($file)) @unlink($file);
                }
                $ids[] = $r['uuid'];
            }
            // Remove as linhas em lote
            $in = implode(',', array_fill(0, count($ids), '?'));
            Database::query("DELETE FROM messages WHERE uuid IN ($in)", $ids);
            echo '[WS] Limpeza: ' . count($ids) . " recado(s) de voz expirado(s) removido(s)\n";
        } catch (\Throwable $e) {
            Logger::error('Cleanup áudio falhou: ' . $e->getMessage());
        }
    }

    /**
     * Dispara um Web Push sem bloquear o loop de eventos.
     * O envio real acontece em bin/push-send.php (processo separado) — um curl
     * de vários segundos aqui dentro travaria o áudio de todos os conectados.
     */
    private function pushTo(int $userId, array $payload): void
    {
        if ($userId <= 0) return;
        try {
            Push::queueForUser($userId, $payload);
        } catch (\Throwable $e) {
            Logger::error('Falha ao enfileirar push: ' . $e->getMessage(), ['user' => $userId]);
        }
    }

    /**
     * Push para todos os usuários inscritos que NÃO estão conectados agora
     * (quem está online já recebeu pelo WebSocket).
     */
    private function pushToOffline(array $payload, int $exceptUserId = 0): void
    {
        $onlineUuids = array_keys($this->byUuid);

        try {
            $sql = 'SELECT DISTINCT p.user_id FROM push_subscriptions p
                    INNER JOIN users u ON u.id = p.user_id
                    WHERE u.is_banned = 0 AND p.user_id <> :me';
            $params = ['me' => $exceptUserId];

            if ($onlineUuids) {
                $ph = [];
                foreach ($onlineUuids as $i => $uuid) {
                    $ph[] = ":o$i";
                    $params["o$i"] = $uuid;
                }
                $sql .= ' AND u.uuid NOT IN (' . implode(',', $ph) . ')';
            }

            foreach (Database::fetchAll($sql . ' LIMIT 200', $params) as $row) {
                $this->pushTo((int) $row['user_id'], $payload);
            }
        } catch (\Throwable $e) {
            Logger::error('Push para offline falhou: ' . $e->getMessage());
        }
    }

    /** id do usuário a partir do uuid (0 se não existir). */
    private function userIdByUuid(?string $uuid): int
    {
        if (!Auth::isUuid($uuid)) return 0;
        return (int) Database::value('SELECT id FROM users WHERE uuid = :u LIMIT 1', ['u' => $uuid]);
    }

    private function uuidv4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    // -----------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------

    private function broadcastToRoom(int $roomId, array $message, ?ConnectionInterface $except = null): void
    {
        $payload = json_encode($message);
        foreach ($this->clients as $client) {
            if (!empty($client->wt->authenticated) && $client->wt->room_id === $roomId) {
                if ($except && $client === $except) continue;
                $client->send($payload);
            }
        }
    }

    private function broadcastQueueUpdate(int $roomId): void
    {
        $state = Queue::getState($roomId);
        $this->broadcastToRoom($roomId, [
            'type'  => 'queue_update',
            'state' => $state,
        ]);
    }

    private function getOnlineUsers(int $roomId): array
    {
        $list = [];
        foreach ($this->clients as $client) {
            if (!empty($client->wt->authenticated) && $client->wt->room_id === $roomId) {
                $list[] = $client->wt->user;
            }
        }
        return $list;
    }

    private function publicUser(array $row): array
    {
        return [
            'id'           => $row['user_id']      ?? $row['id']           ?? null,
            'uuid'         => $row['uuid']         ?? null,
            'display_name' => $row['display_name'] ?? null,
            'avatar_color' => $row['avatar_color'] ?? '#22c55e',
        ];
    }

    private function sendError(ConnectionInterface $conn, string $message): void
    {
        $conn->send(json_encode(['type' => 'error', 'message' => $message]));
    }

    /**
     * Tarefas periódicas (chamado pelo loop)
     */
    public function tick(): void
    {
        $now = time();
        $idleTimeout = $this->config['websocket']['idle_timeout'];
        $maxTalk = $this->config['ptt']['max_talk_seconds'];

        // Limpeza de recados de voz expirados (24h), no máx. 1x por hora
        if ($now - $this->lastAudioCleanup > 3600) {
            $this->lastAudioCleanup = $now;
            $this->cleanupExpiredAudio();
        }

        // Housekeeping: rate_limits antigos, sessões expiradas e usuários
        // marcados como online que sumiram sem fechar o socket. (Antes isso
        // dependia de um cron que não existia.)
        if ($now - $this->lastHousekeeping > 600) {
            $this->lastHousekeeping = $now;
            try {
                RateLimit::cleanup();
                Database::query('DELETE FROM sessions WHERE expires_at < NOW() LIMIT 5000');
                Database::query(
                    "UPDATE users SET online_status = 'offline'
                     WHERE online_status = 'online'
                       AND (last_heartbeat IS NULL OR last_heartbeat < DATE_SUB(NOW(), INTERVAL :t SECOND))",
                    ['t' => max(120, $idleTimeout * 2)]
                );
            } catch (\Throwable $e) {
                Logger::error('Housekeeping falhou: ' . $e->getMessage());
            }
        }

        // Drop conexões zumbi
        foreach ($this->clients as $client) {
            if (!empty($client->wt->authenticated)) {
                if (($now - $client->wt->last_heartbeat) > $idleTimeout) {
                    echo "[WS] Heartbeat perdido — fechando {$client->resourceId}\n";
                    $client->close();
                }
            } else {
                // Conexão sem auth por mais de 30s
                if (($now - $client->wt->connected_at) > 30) {
                    $client->close();
                }
            }
        }

        // Timeout de transmissão por sala
        try {
            $rooms = Database::fetchAll('SELECT id, max_talk_seconds FROM rooms WHERE is_active = 1');
            foreach ($rooms as $room) {
                $roomId = (int) $room['id'];
                $limit  = (int) ($room['max_talk_seconds'] ?: $maxTalk);

                // Antes o broadcast dependia de existir um PRÓXIMO na fila — ou
                // seja, quem falava sozinho estourando o tempo nunca era cortado
                // na interface. Agora o gatilho é o timeout em si.
                if (!Queue::hasTimedOutSpeaker($roomId, $limit)) continue;

                $next = Queue::checkTimeouts($roomId, $limit);

                $this->broadcastToRoom($roomId, [
                    'type'    => 'talk_timeout',
                    'message' => 'Tempo máximo de transmissão atingido',
                ]);
                $this->broadcastQueueUpdate($roomId);

                if ($next) {
                    $this->broadcastToRoom($roomId, [
                        'type'     => 'talk_start',
                        'user'     => $this->publicUser($next),
                        'queue_id' => $next['queue_id'],
                        'priority' => $next['priority'] ?? 0,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Tick error: ' . $e->getMessage());
        }
    }
}
