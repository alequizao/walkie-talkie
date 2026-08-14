<?php
namespace WalkieTalkie;

/**
 * Push - Web Push nativo (VAPID + aes128gcm, RFC 8291/8188/8292).
 *
 * Sem dependências externas — usa openssl/hash_hkdf do PHP 7.3+.
 * Portado da lib_push.php do módulo de ônibus (Agenda Social), adaptado para
 * as classes deste projeto (Database/Settings/Logger).
 *
 * As chaves VAPID são geradas uma vez e guardadas na tabela `settings`.
 *
 * Uso típico:
 *   Push::publicKey();                       // chave pública p/ o navegador
 *   Push::sendToUser($userId, [...]);        // envia para todos os aparelhos do usuário
 *   Push::queueForUser($userId, [...]);      // idem, porém em processo separado (não bloqueia o WS)
 */
class Push
{
    /** Assunto do VAPID (obrigatório: mailto: ou https:) */
    private const SUBJECT = 'mailto:contato@publishdev.com.br';

    /** Tempo que o push service guarda a mensagem se o aparelho estiver offline */
    private const TTL = 1800;

    // ------------------------------------------------------------------
    // base64url
    // ------------------------------------------------------------------

    public static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return (string) base64_decode($s);
    }

    // ------------------------------------------------------------------
    // Chaves VAPID
    // ------------------------------------------------------------------

    /** @return array{pub:string,priv:string,sub:string} */
    public static function vapidKeys(): array
    {
        $pub  = (string) Settings::get('vapid_public', '');
        $priv = (string) Settings::get('vapid_private', '');

        if ($pub === '' || $priv === '') {
            $res = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name'       => 'prime256v1',
            ]);
            if ($res === false) {
                throw new \RuntimeException('Não foi possível gerar as chaves VAPID.');
            }

            openssl_pkey_export($res, $privPem);
            $d = openssl_pkey_get_details($res);
            $x = str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT);
            $y = str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT);

            $pub  = self::b64urlEncode("\x04" . $x . $y);
            $priv = (string) $privPem;

            Settings::set('vapid_public', $pub);
            Settings::set('vapid_private', $priv);
            Logger::info('Chaves VAPID geradas.');
        }

        return ['pub' => $pub, 'priv' => $priv, 'sub' => self::SUBJECT];
    }

    public static function publicKey(): string
    {
        return self::vapidKeys()['pub'];
    }

    // ------------------------------------------------------------------
    // Criptografia / assinatura
    // ------------------------------------------------------------------

    /** Monta uma chave pública EC (PEM) a partir do ponto bruto de 65 bytes. */
    private static function pubFromRaw(string $raw65): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004')
             . substr($raw65, 1);
        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    /** Converte assinatura ECDSA DER -> raw R||S (64 bytes) para JWT ES256. */
    private static function derToRaw(string $der): string
    {
        $off = 0;
        if (ord($der[$off++]) !== 0x30) return '';
        $len = ord($der[$off++]);
        if ($len & 0x80) $off += ($len & 0x7f);

        $readInt = function () use ($der, &$off) {
            $off++; // 0x02
            $l = ord($der[$off++]);
            $v = substr($der, $off, $l);
            $off += $l;
            return ltrim($v, "\0");
        };

        $r = $readInt();
        $s = $readInt();
        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    /** JWT VAPID (ES256) para o endpoint (audience = origem do push service). */
    private static function vapidJwt(string $audience): string
    {
        $k = self::vapidKeys();
        $header  = self::b64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64urlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $k['sub'],
        ]));

        $data = $header . '.' . $payload;
        $sig = '';
        openssl_sign($data, $sig, $k['priv'], OPENSSL_ALGO_SHA256);

        return $data . '.' . self::b64urlEncode(self::derToRaw($sig));
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    }

    // ------------------------------------------------------------------
    // Envio
    // ------------------------------------------------------------------

    /**
     * Envia 1 push. Retorna ['ok'=>bool,'status'=>int,'erro'=>string].
     * status 404/410 = inscrição morta (deve ser removida).
     */
    public static function send(string $endpoint, string $p256dhB64, string $authB64, array $payload): array
    {
        if (!function_exists('openssl_pkey_derive')) {
            return ['ok' => false, 'status' => 0, 'erro' => 'sem openssl_pkey_derive'];
        }

        $uaPub = self::b64urlDecode($p256dhB64);  // 65 bytes
        $authS = self::b64urlDecode($authB64);    // 16 bytes
        if (strlen($uaPub) !== 65 || strlen($authS) < 16) {
            return ['ok' => false, 'status' => 0, 'erro' => 'chaves da inscrição inválidas'];
        }

        // Par efêmero do servidor (as = application server)
        $as = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $asd = openssl_pkey_get_details($as);
        $asPub = "\x04" . str_pad((string) $asd['ec']['x'], 32, "\0", STR_PAD_LEFT)
                        . str_pad((string) $asd['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // ECDH(as_priv, ua_pub)
        $uaKey = openssl_pkey_get_public(self::pubFromRaw($uaPub));
        if ($uaKey === false) {
            return ['ok' => false, 'status' => 0, 'erro' => 'falha ao ler chave da inscrição'];
        }
        $ecdh = openssl_pkey_derive($uaKey, $as);
        if ($ecdh === false) {
            return ['ok' => false, 'status' => 0, 'erro' => 'ECDH falhou'];
        }

        // IKM (RFC 8291)
        $keyInfo = "WebPush: info\x00" . $uaPub . $asPub;
        $ikm = hash_hkdf('sha256', $ecdh, 32, $keyInfo, $authS);

        // Conteúdo (RFC 8188 aes128gcm)
        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $plain = json_encode($payload, JSON_UNESCAPED_UNICODE) . "\x02";
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            return ['ok' => false, 'status' => 0, 'erro' => 'falha ao cifrar'];
        }

        $header = $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub;
        $body = $header . $cipher . $tag;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . self::vapidJwt(self::origin($endpoint)) . ', k=' . self::publicKey(),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: ' . self::TTL,
                'Urgency: high',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            'erro'   => $err ?: (string) $resp,
        ];
    }

    /**
     * Envia para todos os aparelhos inscritos de um usuário.
     * Inscrições mortas (404/410) são removidas automaticamente.
     * ATENÇÃO: bloqueia (curl). No servidor WS use queueForUser().
     *
     * @return int quantidade de envios bem-sucedidos
     */
    public static function sendToUser(int $userId, array $payload): int
    {
        $subs = Database::fetchAll(
            'SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions WHERE user_id = :u',
            ['u' => $userId]
        );
        if (!$subs) return 0;

        $sent = 0;
        foreach ($subs as $s) {
            $r = self::send($s['endpoint'], $s['p256dh'], $s['auth_key'], $payload);

            if ($r['ok']) {
                $sent++;
                Database::query(
                    'UPDATE push_subscriptions SET last_sent_at = NOW(), fail_count = 0 WHERE id = :id',
                    ['id' => $s['id']]
                );
                continue;
            }

            // Inscrição morta: o navegador desinstalou/expirou
            if (in_array($r['status'], [404, 410], true)) {
                Database::query('DELETE FROM push_subscriptions WHERE id = :id', ['id' => $s['id']]);
                Logger::info('Inscrição de push removida (expirada)', ['user' => $userId]);
                continue;
            }

            Database::query(
                'UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = :id',
                ['id' => $s['id']]
            );
            Logger::warn('Push falhou', [
                'user' => $userId, 'status' => $r['status'], 'erro' => mb_substr($r['erro'], 0, 200),
            ]);
        }

        return $sent;
    }

    /**
     * Dispara o envio em um processo separado e retorna na hora.
     *
     * O servidor WebSocket é single-threaded (ReactPHP): um curl de até 12s
     * dentro do loop travaria o áudio de todo mundo. Por isso o envio real
     * acontece em `bin/push-send.php`, desacoplado.
     */
    public static function queueForUser(int $userId, array $payload): void
    {
        // Sem inscrições, nem gasta processo
        $has = Database::value('SELECT 1 FROM push_subscriptions WHERE user_id = :u LIMIT 1', ['u' => $userId]);
        if (!$has) return;

        $script = realpath(__DIR__ . '/../bin/push-send.php');
        if (!$script || !function_exists('proc_open')) {
            // Fallback: envia inline (aceitável fora do WS)
            self::sendToUser($userId, $payload);
            return;
        }

        $cmd = sprintf(
            '%s %s %d %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            $userId,
            escapeshellarg(self::b64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE)))
        );

        // Processo destacado: não herda os pipes nem prende o loop de eventos
        $proc = @proc_open(
            $cmd . ' > /dev/null 2>&1 &',
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (is_resource($proc)) proc_close($proc);
    }

    /** Registra/atualiza a inscrição de um aparelho. */
    public static function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $ua = ''): void
    {
        Database::query(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_key, user_agent)
             VALUES (:u, :e, :h, :p, :a, :ua)
             ON DUPLICATE KEY UPDATE
                user_id = :u2, p256dh = :p2, auth_key = :a2, user_agent = :ua2, fail_count = 0',
            [
                'u'  => $userId, 'u2'  => $userId,
                'e'  => $endpoint,
                'h'  => hash('sha256', $endpoint),
                'p'  => $p256dh, 'p2'  => $p256dh,
                'a'  => $auth,   'a2'  => $auth,
                'ua' => mb_substr($ua, 0, 255), 'ua2' => mb_substr($ua, 0, 255),
            ]
        );
    }

    public static function unsubscribe(string $endpoint): int
    {
        return Database::query(
            'DELETE FROM push_subscriptions WHERE endpoint_hash = :h',
            ['h' => hash('sha256', $endpoint)]
        )->rowCount();
    }
}
