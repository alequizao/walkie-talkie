<?php
/**
 * Runner de migrations (CLI).
 *   php database/migrate.php                    -> aplica todas as pendentes
 *   php database/migrate.php --file=nome.sql    -> aplica uma específica
 *
 * Idempotente: erros de "já existe / não existe" (índice duplicado, coluna já
 * alterada) são ignorados, então rodar duas vezes não quebra.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Apenas via CLI.\n");
}

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\Database;

/** Erros que significam "esse passo já estava aplicado" */
const IGNORABLE = [
    1060, // Duplicate column name
    1061, // Duplicate key name
    1062, // Duplicate entry (ex.: seed já existente)
    1091, // Can't DROP; check that column/key exists
    1826, // Duplicate foreign key constraint name
];

$only = null;
foreach ($argv as $arg) {
    if (strncmp($arg, '--file=', 7) === 0) $only = substr($arg, 7);
}

$files = glob(__DIR__ . '/migration-*.sql') ?: [];
sort($files);

if ($only) {
    $files = array_values(array_filter($files, fn($f) => basename($f) === $only));
    if (!$files) exit("Migration não encontrada: $only\n");
}

if (!$files) exit("Nenhuma migration encontrada.\n");

$pdo = Database::getInstance();

foreach ($files as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    $sql = file_get_contents($file);

    foreach (splitStatements($sql) as $stmt) {
        $preview = preg_replace('/\s+/', ' ', mb_substr($stmt, 0, 90));
        try {
            $pdo->exec($stmt);
            echo "  [ok]   $preview\n";
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($code, IGNORABLE, true)) {
                echo "  [skip] $preview  (já aplicado)\n";
                continue;
            }
            echo "  [ERRO] $preview\n         " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

echo "\nMigrations aplicadas com sucesso.\n";

/**
 * Divide o arquivo em statements, ignorando comentários e linhas vazias.
 * Remove comentários `--` em qualquer posição da linha — um comentário no fim
 * de uma linha pode conter `;` e quebraria a divisão.
 */
function splitStatements(string $sql): array
{
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $parts = array_map('trim', explode(';', $sql));
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}
