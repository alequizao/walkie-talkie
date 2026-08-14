<?php
/**
 * Testes da lógica de fila (sem PHPUnit — roda com `php tests/run.php`).
 *
 * Cria uma sala e usuários temporários, exercita Queue/RateLimit/Auth e apaga
 * tudo no final. Não toca em dados reais.
 */

if (PHP_SAPI !== 'cli') exit("Apenas via CLI.\n");

require __DIR__ . '/../config/bootstrap.php';

use WalkieTalkie\Auth;
use WalkieTalkie\Database;
use WalkieTalkie\Queue;
use WalkieTalkie\RateLimit;

$passed = 0;
$failed = 0;

function check(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  ✓ $name\n";
    } else {
        $failed++;
        echo "  ✗ $name\n";
        echo "      esperado: " . var_export($expected, true) . "\n";
        echo "      obtido:   " . var_export($actual, true) . "\n";
    }
}

function section(string $title): void { echo "\n$title\n"; }

// ---------------------------------------------------------------
// Setup: sala + 3 usuários temporários
// ---------------------------------------------------------------
$suffix = bin2hex(random_bytes(4));
$roomId = Database::insert('rooms', [
    'uuid' => Auth::uuid4(),
    'name' => "Sala de teste $suffix",
    'slug' => "test-$suffix",
    'max_users' => 10,
    'max_talk_seconds' => 30,
]);

$users = [];
foreach (['ana', 'bruno', 'carla'] as $nome) {
    $users[$nome] = Database::insert('users', [
        'uuid'         => Auth::uuid4(),
        'username'     => "test_{$nome}_$suffix",
        'display_name' => "Test $nome $suffix",
        'role'         => 'user',
    ]);
}

$cleanup = function () use ($roomId, $users) {
    Database::query('DELETE FROM queue WHERE room_id = :r', ['r' => $roomId]);
    Database::query('DELETE FROM transmissions WHERE room_id = :r', ['r' => $roomId]);
    foreach ($users as $id) {
        Database::query('DELETE FROM logs WHERE user_id = :u', ['u' => $id]);
        Database::query('DELETE FROM rate_limits WHERE user_id = :u', ['u' => $id]);
        Database::query('DELETE FROM sessions WHERE user_id = :u', ['u' => $id]);
        Database::query('DELETE FROM users WHERE id = :u', ['u' => $id]);
    }
    Database::query('DELETE FROM rooms WHERE id = :r', ['r' => $roomId]);
};

register_shutdown_function($cleanup);

try {
    // -----------------------------------------------------------
    section('Fila: primeiro a pedir fala na hora');
    $r1 = Queue::requestTalk($roomId, $users['ana']);
    check('ana vira talking', $r1['status'], 'talking');

    section('Fila: os demais entram como waiting em ordem');
    $r2 = Queue::requestTalk($roomId, $users['bruno']);
    $r3 = Queue::requestTalk($roomId, $users['carla']);
    check('bruno waiting', $r2['status'], 'waiting');
    check('bruno na posição 0', $r2['position'], 0);
    check('carla waiting', $r3['status'], 'waiting');
    check('carla na posição 1', $r3['position'], 1);

    section('Fila: pedido repetido é idempotente');
    $again = Queue::requestTalk($roomId, $users['bruno']);
    check('bruno continua waiting', $again['status'], 'waiting');
    check('mesmo queue_id', $again['queue_id'], $r2['queue_id']);

    section('Fila: só existe UM talking por sala');
    $talkingCount = (int) Database::value(
        "SELECT COUNT(*) FROM queue WHERE room_id = :r AND status = 'talking'",
        ['r' => $roomId]
    );
    check('exatamente 1 talking', $talkingCount, 1);

    section('Fila: stopTalk promove o próximo (FIFO)');
    $next = Queue::stopTalk($roomId, $users['ana']);
    check('próximo é bruno', $next['user_id'] ?? null, $users['bruno']);
    $state = Queue::getState($roomId);
    check('bruno está falando', $state['talking']['user_id'], $users['bruno']);
    check('resta 1 na espera', count($state['waiting']), 1);
    check('carla reindexada para 0', $state['waiting'][0]['position'], 0);

    section('Fila: transmissão foi registrada no histórico');
    $trans = (int) Database::value(
        'SELECT COUNT(*) FROM transmissions WHERE room_id = :r AND user_id = :u',
        ['r' => $roomId, 'u' => $users['ana']]
    );
    check('1 transmissão de ana', $trans, 1);

    section('Fila: prioridade fura a fila dos não-prioritários');
    Queue::requestTalk($roomId, $users['ana'], true); // ana volta, com prioridade
    $state = Queue::getState($roomId);
    check('ana é a primeira da espera', $state['waiting'][0]['user_id'], $users['ana']);
    check('ana marcada como prioridade', $state['waiting'][0]['priority'], 1);

    section('Fila: removeUser tira da espera sem promover ninguém');
    $r = Queue::removeUser($roomId, $users['carla']);
    check('nenhum novo speaker', $r, null);
    $state = Queue::getState($roomId);
    check('só ana na espera', count($state['waiting']), 1);

    section('Fila: quem está falando sai e o próximo assume');
    $next = Queue::removeUser($roomId, $users['bruno']);
    check('ana assume', $next['user_id'] ?? null, $users['ana']);
    check('fila de espera vazia', count(Queue::getState($roomId)['waiting']), 0);

    section('Fila: sala vazia devolve estado nulo');
    Queue::removeUser($roomId, $users['ana']);
    $state = Queue::getState($roomId);
    check('ninguém falando', $state['talking'], null);

    // -----------------------------------------------------------
    section('RateLimit: bloqueia ao passar do máximo');
    $action = 'test_' . $suffix;
    $allowed = 0;
    for ($i = 0; $i < 6; $i++) {
        if (RateLimit::check($action, $users['ana'], null, 3, 60)) $allowed++;
    }
    check('exatamente 3 permitidos', $allowed, 3);

    section('RateLimit: cooldown');
    check('cooldown ativo agora', RateLimit::checkCooldown($action, $users['ana'], 3600), false);
    check('cooldown zero não bloqueia', RateLimit::checkCooldown($action, $users['ana'], 0), true);

    // -----------------------------------------------------------
    section('Auth: nome reservado não pode virar guest');
    Auth::setReservedNames(['ALEQUIZAO']);
    $blocked = false;
    try { Auth::quickRegister('ALEQUIZAO'); } catch (\RuntimeException $e) { $blocked = true; }
    check('ALEQUIZAO recusado no guest', $blocked, true);

    $blockedLower = false;
    try { Auth::quickRegister('alequizao'); } catch (\RuntimeException $e) { $blockedLower = true; }
    check('comparação é case-insensitive', $blockedLower, true);

    section('Auth: validação de uuid');
    check('uuid válido', Auth::isUuid(Auth::uuid4()), true);
    check('uuid inválido', Auth::isUuid("' OR 1=1 --"), false);

    section('Auth: sanitização de nome');
    check('remove tags', Auth::sanitizeName('<b>Zé</b>'), 'Zé');
    check('corta espaços duplicados', Auth::sanitizeName("  João   Silva  "), 'João Silva');

} catch (\Throwable $e) {
    $failed++;
    echo "\n✗ EXCEÇÃO: " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n------------------------------------------\n";
echo "Passou: $passed   Falhou: $failed\n";
exit($failed > 0 ? 1 : 0);
