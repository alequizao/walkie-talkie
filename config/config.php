<?php
/**
 * Configuração principal do sistema
 * Walkie Talkie Web - PHP 7.4+
 *
 * Este arquivo é idempotente: pode ser incluído com `require` várias vezes que o
 * array é montado uma única vez (cache em $GLOBALS) — evita reparse do .env a
 * cada endpoint.
 */

if (isset($GLOBALS['WT_CONFIG_CACHE'])) {
    return $GLOBALS['WT_CONFIG_CACHE'];
}

// Carregar variáveis de ambiente do .env (se existir)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strncmp($line, 'export ', 7) === 0) $line = substr($line, 7);
        if (strpos($line, '=') === false) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Remove aspas envolventes (preservando o conteúdo interno)
        $len = strlen($value);
        if ($len >= 2 && (
            ($value[0] === '"' && $value[$len - 1] === '"') ||
            ($value[0] === "'" && $value[$len - 1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        // putenv() costuma estar em disable_functions em hospedagens (é o caso
        // deste servidor) — $_ENV já basta para a função env() abaixo.
        if (function_exists('putenv') && !in_array('putenv', explode(',', str_replace(' ', '', (string) ini_get('disable_functions'))), true)) {
            putenv("$key=$value");
        }
    }
}

if (!function_exists('env')) {
    /**
     * Lê variável de ambiente preservando "0" e "" (o antigo `?:` transformava
     * ambos em default — bug clássico com DB_PASSWORD=0, por exemplo).
     */
    function env(string $key, $default = null) {
        $value = $_ENV[$key] ?? null;
        if ($value === null) {
            $value = getenv($key);
            if ($value === false) return $default;
        }

        switch (strtolower((string) $value)) {
            case 'true':  case '(true)':  return true;
            case 'false': case '(false)': return false;
            case 'null':  case '(null)':  return null;
            case 'empty': case '(empty)': return '';
        }

        return $value;
    }
}

$appEnv    = env('APP_ENV', 'production');
$appSecret = env('APP_SECRET', '');

// Em produção o segredo NÃO pode ser derivável (o default antigo era md5(__FILE__)).
if (!is_string($appSecret) || strlen($appSecret) < 32) {
    if ($appEnv === 'production') {
        throw new RuntimeException(
            'APP_SECRET ausente ou fraco no .env. Gere com: php -r "echo bin2hex(random_bytes(32));"'
        );
    }
    $appSecret = str_repeat('0', 64); // apenas dev/teste
}

$config = [
    'app' => [
        'name'        => env('APP_NAME', 'Walkie Talkie'),
        'version'     => env('APP_VERSION', '1.1.0'),
        'env'         => $appEnv,
        'debug'       => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN),
        'url'         => env('APP_URL', 'https://localhost'),
        'timezone'    => env('APP_TIMEZONE', 'America/Sao_Paulo'),
        'secret'      => $appSecret,
    ],

    'db' => [
        'host'     => env('DB_HOST', 'localhost'),
        'port'     => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'walkie_talkie'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => (string) env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        // O fuso do MySQL passa a ser derivado de app.timezone (ver Database::getInstance)
        'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ],
    ],

    'websocket' => [
        'host'           => env('WS_HOST', '0.0.0.0'),
        'port'           => (int) env('WS_PORT', 8080),
        'public_url'     => env('WS_PUBLIC_URL', 'wss://localhost:8080'),
        'heartbeat_interval' => 25, // segundos
        'idle_timeout'       => 90, // desconecta cliente sem heartbeat
        'max_message_size'   => 64 * 1024, // 64 KB
    ],

    'ptt' => [
        // Tempo máximo de transmissão contínua (segundos)
        'max_talk_seconds'    => (int) env('PTT_MAX_TALK', 30),
        // Cooldown entre transmissões (segundos)
        'cooldown_seconds'    => (int) env('PTT_COOLDOWN', 1),
        // Cooldown do "Chamar Atenção"
        'attention_cooldown'  => (int) env('PTT_ATTENTION_COOLDOWN', 60),
        // Máximo de alertas por minuto
        'attention_per_minute' => 2,
        // Debounce de clique (ms)
        'debounce_ms'         => 300,
    ],

    'rate_limit' => [
        'login'     => ['max' => 10, 'window' => 300],   // 10 tentativas em 5 min
        'talk'      => ['max' => 30, 'window' => 60],    // 30 transmissões/min
        'attention' => ['max' => 2,  'window' => 60],    // 2 alertas/min
        'api'       => ['max' => 120,'window' => 60],    // 120 reqs/min
        'msg'       => ['max' => 30, 'window' => 60],    // 30 mensagens/min
    ],

    'session' => [
        'lifetime'     => 86400 * 7, // 7 dias
        'cookie_name'  => 'wt_session',
        'cookie_secure'=> true,
        'cookie_http_only' => true,
        'cookie_samesite' => 'Lax',
        // Só regrava sessions.last_activity se a última escrita foi há mais que isso
        'activity_throttle' => 60,
    ],

    'media' => [
        'path'          => __DIR__ . '/../storage/media',
        'max_bytes'     => 6 * 1024 * 1024, // 6 MB (~3 min de WAV 16kHz mono)
        'ttl_seconds'   => 86400,           // recados de voz expiram em 24h
    ],

    'audio' => [
        'sample_rate'   => 24000,
        'channels'      => 1,
        'codec'         => 'opus',
        'bitrate'       => 24000,
        'echo_cancellation' => true,
        'noise_suppression' => true,
        'auto_gain_control' => true,
    ],

    'webrtc' => [
        'ice_servers' => array_values(array_filter([
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
            // TURN (necessário para NAT simétrico). Configure no .env:
            //   TURN_URL=turn:seu-turn.exemplo.com:3478
            //   TURN_USER=usuario
            //   TURN_PASS=senha
            env('TURN_URL') ? [
                'urls'       => env('TURN_URL'),
                'username'   => env('TURN_USER', ''),
                'credential' => env('TURN_PASS', ''),
            ] : null,
        ])),
    ],

    'logs' => [
        'path'  => __DIR__ . '/../storage/logs',
        'level' => env('LOG_LEVEL', 'info'), // debug|info|warning|error
    ],

    // Nomes reservados: não podem ser usados no login guest (sem senha).
    'reserved_names' => array_filter(array_map(
        'trim',
        explode(',', (string) env('RESERVED_NAMES', 'ALEQUIZAO,admin,administrador,suporte'))
    )),
];

$GLOBALS['WT_CONFIG_CACHE'] = $config;

return $config;
