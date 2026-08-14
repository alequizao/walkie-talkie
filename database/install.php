<?php
/**
 * install.php — Instalação do banco MySQL
 * Uso (CLI):
 *   php database/install.php
 * Uso (web):
 *   acessar /database/install.php (REMOVER após instalar!)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "schema.sql não encontrado em $schemaFile\n");
    exit(1);
}

$cfg = require __DIR__ . '/../config/config.php';
$db  = $cfg['db'];

$dsnNoDb = "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4";
try {
    $pdo = new PDO($dsnNoDb, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "Erro conectando ao MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

// Cria DB se não existir
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$db['name']}`");

$sql = file_get_contents($schemaFile);
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "schema.sql vazio\n");
    exit(1);
}

// Remove USE/CREATE DATABASE caso o arquivo tenha
$sql = preg_replace('/^\s*CREATE\s+DATABASE.*?;/im', '', $sql) ?? $sql;
$sql = preg_replace('/^\s*USE\s+.*?;/im', '', $sql) ?? $sql;

// Divide por ';' respeitando linhas; suficiente para nosso schema
$statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');

$cli = (PHP_SAPI === 'cli');
$ok = 0;
$fail = 0;

foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        $msg = "[FAIL] " . substr($stmt, 0, 80) . "...\n  -> " . $e->getMessage() . "\n";
        if ($cli) fwrite(STDERR, $msg); else echo "<pre>" . htmlspecialchars($msg) . "</pre>";
    }
}

$summary = "Instalação concluída: $ok statements OK, $fail falhas.\n";
if ($cli) {
    echo $summary;
    echo "Banco: {$db['name']} em {$db['host']}:{$db['port']}\n";
    echo "Login admin padrão: admin / admin123 (ALTERE EM PRODUÇÃO!)\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h1>Instalação Walkie Talkie</h1>";
    echo "<pre>" . htmlspecialchars($summary) . "</pre>";
    echo "<p>Banco: <b>" . htmlspecialchars($db['name']) . "</b></p>";
    echo "<p>Login admin padrão: <b>admin / admin123</b> (altere em produção)</p>";
    echo "<p style='color:#c00'><b>IMPORTANTE:</b> remova este arquivo após instalar.</p>";
}

exit($fail === 0 ? 0 : 2);
