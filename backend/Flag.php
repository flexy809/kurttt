<?php

namespace Game;

class Flag
{
    public string $team;         // 'red' | 'blue'
    public float  $x;
    public float  $y;
    public float  $baseX;
    public float  $baseY;
    public string $status;       // 'base' | 'carried' | 'dropped'
    public string $carrierId;    // playerId or ''
    public float  $dropTime;     // timestamp when dropped

    public function __construct(string $team, float $baseX, float $baseY)
    {
        $this->team     = $team;
        $this->baseX    = $baseX;
        $this->baseY    = $baseY;
        $this->x        = $baseX;
        $this->y        = $baseY;
        $this->status   = 'base';
        $this->carrierId= '';
        $this->dropTime = 0.0;
    }

    public function reset(): void
    {
        $this->x        = $this->baseX;
        $this->y        = $this->baseY;
        $this->status   = 'base';
        $this->carrierId= '';
    }

    public function pickup(string $playerId): void
    {
        $this->status    = 'carried';
        $this->carrierId = $playerId;
    }

    public function drop(float $x, float $y): void
    {
        $this->x        = $x;
        $this->y        = $y;
        $this->status   = 'dropped';
        $this->carrierId= '';
        $this->dropTime = microtime(true);
    }

    public function updateCarrierPos(float $x, float $y): void
    {
        if ($this->status === 'carried') {
            $this->x = $x + PLAYER_WIDTH / 2;
            $this->y = $y - 10;
        }
    }

    public function isNear(float $px, float $py): bool
    {
        $dx = ($this->x - $px);
        $dy = ($this->y - $py);
        return sqrt($dx * $dx + $dy * $dy) <= FLAG_CAPTURE_DIST;
    }

    public function serialize(): array
    {
        return [
            'team'     => $this->team,
            'x'        => round($this->x, 1),
            'y'        => round($this->y, 1),
            'baseX'    => $this->baseX,
            'baseY'    => $this->baseY,
            'status'   => $this->status,
            'carrierId'=> $this->carrierId,
        ];
    }
}
