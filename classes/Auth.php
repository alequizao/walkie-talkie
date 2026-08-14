<?php
namespace WalkieTalkie;

/**
 * Auth - Autenticação simples por token (compatível PHP 7.4)
 */
class Auth
{
    private static array $config = [];

    /** Nomes que não podem ser usados no login guest (sem senha) */
    private static array $reserved = [];

    /** Código de exceção: o cliente deve pedir a senha e tentar de novo */
    public const NEEDS_PASSWORD = 1001;

    /** Hash "isca" para manter o tempo de resposta constante quando o usuário não existe */
    private const DUMMY_HASH = '$2y$10$NePBSZQGWio0NhCCGzdE6OPCrC2V3NBv74WTHMePOC6KNhwxECga2';

    /** Momento (por token) da última gravação de last_activity, para não escrever a cada request */
    private static array $activityTouched = [];

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function setReservedNames(array $names): void
    {
        self::$reserved = array_map(fn($n) => self::normalizeName($n), $names);
    }

    /** Normaliza para comparação case/acento-insensível */
    private static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    public static function isReserved(string $displayName): bool
    {
        return in_array(self::normalizeName($displayName), self::$reserved, true);
    }

    /**
     * Cria um usuário (rápido, sem senha — para entrar como guest)
     *
     * Regras de segurança:
     * - Nomes reservados (ex.: ALEQUIZAO) exigem senha.
     * - Se já existe uma conta COM senha usando esse nome, o guest é recusado
     *   (antes, qualquer um digitando o nome do admin herdava o selo verificado).
     */
    public static function quickRegister(string $displayName): array
    {
        $displayName = self::sanitizeName($displayName);
        if (mb_strlen($displayName) < 2) {
            throw new \InvalidArgumentException('Nome muito curto.');
        }

        if (self::isReserved($displayName)) {
            throw new \RuntimeException('Este nome é reservado. Digite a senha da conta.', self::NEEDS_PASSWORD);
        }

        // Existe alguma conta protegida (com senha) ou admin com esse nome?
        $protected = Database::fetch(
            "SELECT id FROM users
             WHERE display_name = :n AND (password_hash IS NOT NULL OR role = 'admin')
             LIMIT 1",
            ['n' => $displayName]
        );
        if ($protected) {
            throw new \RuntimeException('Este nome tem senha. Digite a senha para entrar.', self::NEEDS_PASSWORD);
        }

        // Reaproveita um guest já existente com o mesmo nome (sem senha) em vez
        // de criar um usuário novo a cada login — evita duplicatas no banco.
        $existing = Database::fetch(
            "SELECT id, is_banned FROM users
             WHERE display_name = :n AND password_hash IS NULL AND role <> 'admin'
             ORDER BY id ASC LIMIT 1",
            ['n' => $displayName]
        );
        if ($existing) {
            if ($existing['is_banned']) {
                throw new \RuntimeException('Usuário bloqueado.');
            }
            return self::createSession((int) $existing['id']);
        }

        // username único derivado do display_name
        $base = strtolower(preg_replace('/[^a-z0-9]/i', '', $displayName));
        $base = $base ?: 'user';
        $username = $base . substr(bin2hex(random_bytes(2)), 0, 4);

        $uuid = self::uuid4();
        $color = self::pickColor($displayName);

        $userId = Database::insert('users', [
            'uuid'         => $uuid,
            'username'     => $username,
            'display_name' => $displayName,
            'avatar_color' => $color,
            'role'         => 'user',
        ]);

        return self::createSession($userId);
    }

    /**
     * Login por usuário/senha.
     * Busca por `username` primeiro (único). Só cai em `display_name` se não achar,
     * e nesse caso restringe a contas COM senha — evitando ambiguidade entre guests
     * homônimos.
     */
    public static function login(string $username, string $password, string $ip = ''): array
    {
        $user = Database::fetch(
            'SELECT * FROM users WHERE username = :u LIMIT 1',
            ['u' => $username]
        );

        if (!$user) {
            $user = Database::fetch(
                'SELECT * FROM users WHERE display_name = :u AND password_hash IS NOT NULL
                 ORDER BY id ASC LIMIT 1',
                ['u' => $username]
            );
        }

        // Verificação em tempo constante: sempre roda password_verify, mesmo sem usuário.
        $hash  = (is_array($user) && !empty($user['password_hash'])) ? $user['password_hash'] : self::DUMMY_HASH;
        $valid = password_verify($password, $hash);

        if (!is_array($user) || empty($user['password_hash']) || !$valid) {
            Logger::event('error', is_array($user) ? (int) $user['id'] : null, null,
                'Login inválido', ['username' => $username], $ip);
            throw new \RuntimeException('Credenciais inválidas.');
        }

        if ($user['is_banned']) {
            throw new \RuntimeException('Usuário bloqueado.');
        }

        // Reforço do hash se o custo do bcrypt mudou
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                'id = :id', ['id' => (int) $user['id']]);
        }

        return self::createSession((int) $user['id'], $ip);
    }

    /**
     * Cria sessão e retorna token + dados do usuário
     */
    public static function createSession(int $userId, string $ip = '', string $ua = ''): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (self::$config['lifetime'] ?? 86400 * 7));

        Database::insert('sessions', [
            'user_id'    => $userId,
            'token'      => $token,
            'ip_address' => $ip ?: ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent' => substr($ua ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'expires_at' => $expires,
        ]);

        $user = Database::fetch('SELECT id, uuid, username, display_name, avatar_color, role FROM users WHERE id = :id', ['id' => $userId]);

        return [
            'token' => $token,
            'expires_at' => $expires,
            'user' => $user,
        ];
    }

    /**
     * Valida token e retorna usuário.
     * `sessions.last_activity` só é regravado a cada `session.activity_throttle`
     * segundos (antes era um UPDATE por request, inclusive nos heartbeats).
     */
    public static function validate(string $token): ?array
    {
        if (!$token || strlen($token) < 32 || !ctype_xdigit($token)) return null;

        $session = Database::fetch(
            'SELECT s.last_activity, u.id AS uid, u.uuid, u.username, u.display_name,
                    u.avatar_color, u.role, u.is_banned
             FROM sessions s
             INNER JOIN users u ON u.id = s.user_id
             WHERE s.token = :t AND s.expires_at > NOW()
             LIMIT 1',
            ['t' => $token]
        );

        if (!$session || $session['is_banned']) return null;

        self::touchActivity($token, $session['last_activity'] ?? null);

        return [
            'token'   => $token,
            'user_id' => (int) $session['uid'],
            'user' => [
                'id'           => (int) $session['uid'],
                'uuid'         => $session['uuid'],
                'username'     => $session['username'],
                'display_name' => $session['display_name'],
                'avatar_color' => $session['avatar_color'],
                'role'         => $session['role'],
            ],
        ];
    }

    private static function touchActivity(string $token, ?string $lastActivity): void
    {
        $throttle = (int) (self::$config['activity_throttle'] ?? 60);
        $now = time();

        // Cache em memória (útil no servidor WS, long-running)
        $key = substr($token, 0, 16);
        if (isset(self::$activityTouched[$key]) && ($now - self::$activityTouched[$key]) < $throttle) {
            return;
        }
        if ($lastActivity !== null && ($now - strtotime($lastActivity)) < $throttle) {
            self::$activityTouched[$key] = $now;
            return;
        }

        self::$activityTouched[$key] = $now;
        if (count(self::$activityTouched) > 5000) self::$activityTouched = [];

        Database::query('UPDATE sessions SET last_activity = NOW() WHERE token = :t', ['t' => $token]);
    }

    public static function logout(string $token): void
    {
        Database::query('DELETE FROM sessions WHERE token = :t', ['t' => $token]);
    }

    /**
     * Token do header Authorization: Bearer.
     * O fallback por querystring (?token=) só é aceito quando o endpoint pede
     * explicitamente ($allowQuery) — usado apenas em api/voice.php, porque a tag
     * <audio> não envia headers. Isso evita CSRF nos demais endpoints.
     */
    public static function getBearerToken(bool $allowQuery = false): ?string
    {
        $auth = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders() ?: [];
            $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if ($auth === '') {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }

        return $allowQuery ? ($_GET['token'] ?? null) : null;
    }

    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Valida o formato de um UUID v4 (evita queries com lixo). */
    public static function isUuid(?string $value): bool
    {
        return is_string($value)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    public static function sanitizeName(string $name): string
    {
        $name = trim(strip_tags($name));
        $name = preg_replace('/[^\p{L}\p{N}\s\-_.]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return trim(mb_substr($name, 0, 60));
    }

    public static function pickColor(string $seed): string
    {
        $palette = [
            '#22c55e','#3b82f6','#f59e0b','#ef4444','#a855f7',
            '#06b6d4','#ec4899','#84cc16','#f97316','#14b8a6'
        ];
        return $palette[crc32($seed) % count($palette)];
    }
}
