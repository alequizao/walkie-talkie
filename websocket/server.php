<?php
/**
 * WebSocket server entry point
 * Rodar: php websocket/server.php
 *
 * Em produção use systemd ou supervisord.
 */

require __DIR__ . '/../config/bootstrap.php';

if (!class_exists('Ratchet\Server\IoServer')) {
    fwrite(STDERR, "Ratchet não instalado. Execute: composer install\n");
    exit(1);
}

require __DIR__ . '/WalkieTalkieServer.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use WalkieTalkie\WalkieTalkieServer;

$config = require __DIR__ . '/../config/config.php';

$loop = Loop::get();

$server = new WalkieTalkieServer($config);

// Tick periódico para heartbeat / timeouts
$loop->addPeriodicTimer(5.0, function () use ($server) {
    $server->tick();
});

$socket = new SocketServer(
    $config['websocket']['host'] . ':' . $config['websocket']['port'],
    [],
    $loop
);

$ioServer = new IoServer(
    new HttpServer(new WsServer($server)),
    $socket,
    $loop
);

echo "================================================\n";
echo " WALKIE TALKIE - WebSocket Server\n";
echo " Listening on {$config['websocket']['host']}:{$config['websocket']['port']}\n";
echo " Ctrl+C para parar\n";
echo "================================================\n";

$loop->run();
