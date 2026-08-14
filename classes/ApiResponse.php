<?php
namespace WalkieTalkie;

/**
 * ApiResponse - resposta JSON padronizada + helpers de endpoint.
 *
 * Os endpoints usam este helper para eliminar o boilerplate repetido
 * (headers, checagem de método, auth e rate limit).
 */
class ApiResponse
{
    private static bool $headersSent = false;

    public static function init(): void
    {
        if (self::$headersSent || headers_sent()) return;
        self::$headersSent = true;

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store');
    }

    /** Config da aplicação (já em cache — não relê o .env). */
    public static function config(): array
    {
        return $GLOBALS['WT_CONFIG_CACHE'] ?? require __DIR__ . '/../config/config.php';
    }

    public static function ok($data = null, string $message = 'OK'): void
    {
        self::init();
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $status = 400, $data = null): void
    {
        if (!headers_sent()) http_response_code($status);
        self::init();
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Exige um método HTTP (ou lista deles). Responde 405 se não bater. */
    public static function requireMethod($methods): void
    {
        $methods = (array) $methods;
        $current = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($current === 'OPTIONS') {
            if (!headers_sent()) {
                http_response_code(204);
                header('Allow: ' . implode(', ', array_merge($methods, ['OPTIONS'])));
            }
            exit;
        }

        if (!in_array($current, $methods, true)) {
            if (!headers_sent()) header('Allow: ' . implode(', ', $methods));
            self::error('Método não permitido.', 405);
        }
    }

    public static function input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data)) return $data;
        }
        return $_POST;
    }

    /**
     * Exige token válido.
     * @param bool $allowQueryToken só para endpoints de mídia (<audio> não manda header)
     */
    public static function requireAuth(bool $allowQueryToken = false): array
    {
        $token = Auth::getBearerToken($allowQueryToken);
        if (!$token) self::error('Token não informado.', 401);
        $session = Auth::validate($token);
        if (!$session) self::error('Sessão inválida ou expirada.', 401);
        return $session;
    }

    /**
     * Aplica um limite configurado em `rate_limit.<name>`.
     * Responde 429 automaticamente quando estourado.
     */
    public static function rateLimit(string $name, ?int $userId = null, ?string $ip = null, string $message = 'Muitas requisições. Aguarde.'): void
    {
        $cfg = self::config()['rate_limit'][$name] ?? null;
        if (!$cfg) return;

        if (!RateLimit::check($name, $userId, $ip, (int) $cfg['max'], (int) $cfg['window'])) {
            if (!headers_sent()) header('Retry-After: ' . (int) $cfg['window']);
            self::error($message, 429);
        }
    }

    /** IP do cliente (respeita Cloudflare / proxy reverso). */
    public static function clientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $key) {
            if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
                return $_SERVER[$key];
            }
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return hash_equals($_SESSION['csrf'] ?? '', $token);
    }
}
