<?php

namespace Game;

use React\EventLoop\LoopInterface;

class GameLoop
{
    private LoopInterface $loop;
    private GameServer $server;
    private array $timers = []; // roomId => timer

    public function __construct(LoopInterface $loop, GameServer $server)
    {
        $this->loop = $loop;
        $this->server = $server;
    }

    public function startRoomLoop(string $roomId): void
    {
        if (isset($this->timers[$roomId])) return;

        $room = $this->server->getRoom($roomId);
        if (!$room) return;

        $room->lastTickTime = microtime(true);

        $timer = $this->loop->addPeriodicTimer(TICK_INTERVAL, function () use ($roomId) {
            $room = $this->server->getRoom($roomId);
            if (!$room) {
                $this->stopRoomLoop($roomId);
                return;
            }

            if ($room->isEmpty()) {
                $this->stopRoomLoop($roomId);
                $this->server->removeRoom($roomId);
                return;
            }

            if ($room->state === 'lobby' || $room->state === 'game_over') {
                return;
            }

            $now = microtime(true);
            $room->tick($now);
        });

        $this->timers[$roomId] = $timer;
        echo "[GameLoop] Started loop for room: {$roomId}\n";
    }

    public function stopRoomLoop(string $roomId): void
    {
        if (!isset($this->timers[$roomId])) return;
        $this->loop->cancelTimer($this->timers[$roomId]);
        unset($this->timers[$roomId]);
        echo "[GameLoop] Stopped loop for room: {$roomId}\n";
    }

    public function isRunning(string $roomId): bool
    {
        return isset($this->timers[$roomId]);
    }
}
