<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';

// Manuel require — composer autoload'un Game\ namespace'ini bulamadığı duruma karşı güvenlik
foreach (['Database','MapManager','Flag','Bullet','Player','Room','GameLoop','GameServer'] as $_cls) {
    $f = __DIR__ . '/' . $_cls . '.php';
    if (file_exists($f)) require_once $f;
}

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use Game\GameServer;
use Game\GameLoop;
use Game\MapManager;

// Create the React event loop
$loop = Loop::get();


// Initialize MapManager (auto-scans maps/ directory)
$mapManager = MapManager::getInstance();
$mapManager->loadPersistedStats();
// Create game server
$gameServer = new GameServer();

// Create and attach game loop
$gameLoop = new GameLoop($loop, $gameServer);
$gameServer->setGameLoop($gameLoop);

// Build Ratchet server using standard IoServer factory
$wsServer  = new WsServer($gameServer);
$httpServer = new HttpServer($wsServer);

// ReactPHP v1 compatible socket creation
$socket = new SocketServer(SERVER_HOST . ':' . SERVER_PORT, [], $loop);
$ioServer = new IoServer($httpServer, $socket, $loop);

echo "============================================\n";
echo "  WolfTeam2D WebSocket Server\n";
echo "  Listening on ws://" . SERVER_HOST . ":" . SERVER_PORT . "\n";
echo "  Tick Rate: " . TICK_RATE . " Hz\n";
echo "  Press Ctrl+C to stop.\n";
echo "============================================\n";

$loop->run();
