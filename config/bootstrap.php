<?php
/**
 * Bootstrap - inicializa autoload, config, DB, logger
 *
 * Idempotente: pode ser incluído mais de uma vez sem re-executar nada.
 */

if (isset($GLOBALS['WT_BOOTSTRAPPED'])) {
    return $GLOBALS['WT_CONFIG_CACHE'];
}

// Composer autoload (preferencial — traz Ratchet/ReactPHP e as classes do projeto)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

// Fallback PSR-4 manual (funciona sem `composer install`)
spl_autoload_register(function ($class) {
    $prefix = 'WalkieTalkie\\';
    $base = __DIR__ . '/../classes/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

$config = require __DIR__ . '/config.php';

date_default_timezone_set($config['app']['timezone']);

// Tratamento de erros
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Inicializa serviços
WalkieTalkie\Database::setConfig($config['db']);
WalkieTalkie\Auth::setConfig($config['session']);
WalkieTalkie\Auth::setReservedNames($config['reserved_names'] ?? []);
WalkieTalkie\Logger::setConfig($config['logs']);

// Erros não tratados viram log + resposta genérica (nunca stack trace para o cliente)
set_exception_handler(function (\Throwable $e) use ($config) {
    WalkieTalkie\Logger::error('Exceção não tratada: ' . $e->getMessage(), [
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => $config['app']['debug'] ? $e->getMessage() : 'Erro interno do servidor.',
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE);
});

$GLOBALS['WT_BOOTSTRAPPED'] = true;

return $config;
