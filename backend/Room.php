<?php
namespace Game;

class Room {
    public string $id, $name, $hostId, $mode, $state = 'lobby';
    public string $mapId = 'warzone_arena';
    public array  $players = [], $bullets = [], $flags = [];
    public int    $redScore = 0, $blueScore = 0, $currentRound = 0;
    public float  $roundEndTimer = 0.0, $lastTickTime;
    public bool   $roundEndPending = false, $isPrivate;
    public string $inviteCode = '';

    private array $platforms            = [];
    private array $wallJumpZones        = [];
    private array $climbSurfaces        = [];
    private array $specialZones         = [];
    private array $mapDimensions        = ['width' => 4800, 'height' => 800];
    private array $interactiveObjects   = [];

    private string $wolfId    = '';
    private float  $wolfTimer = 0.0;

    // ──────────────────────────────────────────────────
    public function __construct(string $id, string $name, string $host, string $mode = 'tdm', bool $priv = false, string $mapId = '')
    {
        $this->id         = $id;
        $this->name       = $name;
        $this->hostId     = $host;
        $this->mode       = $mode;
        $this->isPrivate  = $priv;
        $this->inviteCode = $priv ? strtoupper(substr(md5($id), 0, 6)) : '';
        $this->lastTickTime = microtime(true);
        $this->loadMap($mapId ?: $this->pickDefaultMap($mode));
    }

    // ── Map ───────────────────────────────────────────
    private function pickDefaultMap(string $mode): string
    {
        $map = MapManager::getInstance()->getBalancedRandom($mode);
        return $map ? $map['id'] : 'warzone_arena';
    }

    public function loadMap(string $mapId): void
    {
        $mm = MapManager::getInstance();
        if (!$mm->exists($mapId)) {
            $ids = $mm->getIds();
            $mapId = $ids[0] ?? 'default';
        }
        $this->mapId              = $mapId;
        $this->platforms          = $mm->getPlatforms($mapId);
        $this->wallJumpZones      = $mm->getWallJumpZones($mapId);
        $this->climbSurfaces      = $mm->getClimbSurfaces($mapId);
        $this->specialZones       = $mm->getSpecialZones($mapId);
        $this->mapDimensions      = $mm->getDimensions($mapId);
        $this->interactiveObjects = [];
        foreach ($mm->getInteractiveObjects($mapId) as $obj) {
            $this->interactiveObjects[$obj['id']] = array_merge($obj, [
                'current_hp'    => $obj['hp'] ?? 0,
                'alive'         => true,
                'respawn_timer' => 0.0,
            ]);
        }
        if ($this->mode === 'ctf') $this->initFlags($mapId);
        echo "[Room] Map: {$mapId} | Platforms: " . count($this->platforms)
            . " | Objects: " . count($this->interactiveObjects) . "\n";
    }

    private function initFlags(string $mapId = ''): void
    {
        $mm    = MapManager::getInstance();
        $cfDef = $mapId ? $mm->getCtfFlags($mapId) : (defined('CTF_FLAGS') ? CTF_FLAGS : []);
        $this->flags = [];
        foreach ($cfDef as $team => $pos) {
            $this->flags[$team] = new Flag($team, (float)($pos['baseX'] ?? $pos['x']), (float)($pos['baseY'] ?? $pos['y']));
        }
    }

    // ── Temel ─────────────────────────────────────────
    public function addPlayer(Player $p): void       { $this->players[$p->id] = $p; }
    public function getPlayer(string $id): ?Player   { return $this->players[$id] ?? null; }
    public function red(): array  { return array_filter($this->players, fn($p) => $p->team === 'red'); }
    public function blue(): array { return array_filter($this->players, fn($p) => $p->team === 'blue'); }
    public function isEmpty(): bool { return empty($this->players); }
    public function isFull(): bool  { return count($this->players) >= MAX_PLAYERS_PER_ROOM; }
    public function getPlatforms(): array     { return $this->platforms; }
    public function getWallJumpZones(): array { return $this->wallJumpZones; }
    public function getClimbSurfaces(): array { return $this->climbSurfaces; }
    public function getSpecialZones(): array  { return $this->specialZones; }

    public function removePlayer(string $id): void
    {
        if (isset($this->players[$id]) && $this->players[$id]->hasFlag) $this->dropFlag($id);
        unset($this->players[$id]);
        if ($this->hostId === $id && !empty($this->players)) $this->hostId = array_key_first($this->players);
    }

    // ── Başlat ────────────────────────────────────────
    public function canStart(): bool
    {
        if (count($this->red()) < 1 || count($this->blue()) < 1) return false;
        foreach ($this->players as $p) if ($p->team !== '' && !$p->ready) return false;
        return true;
    }

    public function startGame(?string $mapId = null): void
    {
        $this->loadMap($mapId ?: $this->pickDefaultMap($this->mode));
        $this->state = 'playing';
        $this->redScore = $this->blueScore = 0;
        $this->currentRound = 1;
        $this->bullets = [];

        if ($this->mode === 'ctf') $this->initFlags($this->mapId);
        if ($this->mode === 'wolf') $this->pickWolf();

        $spawns = MapManager::getInstance()->getSpawns($this->mapId);
        foreach ($this->players as $p) {
            if ($p->team !== '') {
                $p->setSpawns($spawns[$p->team] ?? null);
                $p->kills = $p->deaths = $p->wolfKills = $p->combo = 0;
                $p->spawn();
            }
        }
        MapManager::getInstance()->recordPlay($this->mapId);
        $dim = $this->mapDimensions;
        $mm  = MapManager::getInstance();
        $this->broadcast([
            'type'   => 'game_start', 'round' => $this->currentRound,
            'map'    => $this->platforms,
            'map_id' => $this->mapId,
            'map_name' => $mm->get($this->mapId)['name'] ?? $this->mapId,
            'map_w'  => $dim['width'], 'map_h' => $dim['height'],
            'wall_jump_zones'     => $this->wallJumpZones,
            'climb_surfaces'      => $this->climbSurfaces,
            'special_zones'       => $this->specialZones,
            'interactive_objects' => array_values(array_map(fn($o) => $this->serializeObject($o), $this->interactiveObjects)),
            'mode'   => $this->mode,
        ]);
        $this->broadcastState();
    }

    // ── Wolf ──────────────────────────────────────────
    private function pickWolf(): void
    {
        $cands = array_filter($this->players, fn($p) => $p->team !== '');
        if (empty($cands)) return;
        if ($this->wolfId && isset($this->players[$this->wolfId])) $this->players[$this->wolfId]->transformToHuman();
        $ids = array_keys($cands); $nid = $ids[array_rand($ids)];
        $this->wolfId = $nid; $this->wolfTimer = (float)WOLF_CHANGE_INTERVAL;
        $this->players[$nid]->transformToWolf();
        $this->broadcast(['type' => 'wolf_assigned', 'wolfId' => $nid, 'name' => $this->players[$nid]->name]);
    }

    // ── Flag ──────────────────────────────────────────
    private function dropFlag(string $pid): void
    {
        foreach ($this->flags as $flag) {
            if ($flag->carrierId === $pid) {
                $p = $this->players[$pid] ?? null;
                $flag->drop($p ? $p->x : $flag->x, $p ? $p->y : $flag->y);
                if ($p) $p->hasFlag = false;
                $this->broadcast(['type' => 'flag_dropped', 'flagTeam' => $flag->team, 'x' => $flag->x, 'y' => $flag->y]);
            }
        }
    }

    private function updateCTF(): void
    {
        foreach ($this->players as $p) {
            if (!$p->alive) continue;
            foreach ($this->flags as $flag) {
                if ($flag->status !== 'carried' && $flag->team !== $p->team && !$p->hasFlag && $flag->isNear($p->x, $p->y)) {
                    $flag->pickup($p->id); $p->hasFlag = true;
                    $this->broadcast(['type' => 'flag_picked', 'flagTeam' => $flag->team, 'by' => $p->id, 'name' => $p->name]);
                }
                if ($flag->status === 'dropped' && $flag->team === $p->team && $flag->isNear($p->x, $p->y)) {
                    $flag->reset();
                    $this->broadcast(['type' => 'flag_returned', 'flagTeam' => $flag->team, 'by' => $p->name]);
                }
                if ($flag->status === 'carried' && $flag->carrierId === $p->id) {
                    $flag->updateCarrierPos($p->x, $p->y);
                    $own = $this->flags[$p->team] ?? null;
                    if ($own && $own->status === 'base' && $own->isNear($p->x, $p->y)) {
                        if ($p->team === 'red') $this->redScore++; else $this->blueScore++;
                        $p->hasFlag = false; $flag->reset();
                        if ($p->accountId) Database::addTaskProgress($p->accountId, 'flag_cap');
                        $this->broadcast(['type' => 'flag_captured', 'flagTeam' => $flag->team, 'by' => $p->name, 'team' => $p->team, 'red_score' => $this->redScore, 'blue_score' => $this->blueScore]);
                        if ($this->redScore >= CTF_SCORE_TO_WIN || $this->blueScore >= CTF_SCORE_TO_WIN) $this->endRound($p->team);
                    }
                }
            }
        }
    }

    // ── İnteraktif Objeler ────────────────────────────
    private function updateInteractiveObjects(float $dt): void
    {
        foreach ($this->interactiveObjects as $oid => &$obj) {
            if (!$obj['alive']) {
                $obj['respawn_timer'] -= $dt;
                if ($obj['respawn_timer'] <= 0) {
                    $obj['alive'] = true; $obj['current_hp'] = $obj['hp'] ?? 0; $obj['respawn_timer'] = 0.0;
                    $this->broadcast(['type' => 'object_respawned', 'object_id' => $oid, 'object_type' => $obj['type']]);
                }
                continue;
            }
            foreach ($this->players as $p) {
                if (!$p->alive) continue;
                $hit = $this->aabb($p->x, $p->y, PLAYER_WIDTH, PLAYER_HEIGHT, $obj['x'], $obj['y'], $obj['w'] ?? 32, $obj['h'] ?? 32);
                if (!$hit) continue;
                if ($obj['type'] === 'health_pack' && $p->hp < $p->maxHp) {
                    $p->hp = min($p->maxHp, $p->hp + ($obj['heal'] ?? 30));
                    $obj['alive'] = false; $obj['respawn_timer'] = (float)($obj['respawn_time'] ?? 20.0);
                    $this->broadcast(['type' => 'object_used', 'object_id' => $oid, 'by' => $p->id, 'object_type' => 'health_pack']);
                } elseif ($obj['type'] === 'ammo_box') {
                    $maxAmmo = WEAPONS[$p->weaponKey]['ammo'] ?? 30;
                    if ($p->ammo < $maxAmmo) {
                        $p->ammo = (int)min($maxAmmo, $p->ammo + $maxAmmo * (float)($obj['refill'] ?? 1.0));
                        $p->reloading = false; $obj['alive'] = false; $obj['respawn_timer'] = (float)($obj['respawn_time'] ?? 15.0);
                        $this->broadcast(['type' => 'object_used', 'object_id' => $oid, 'by' => $p->id, 'object_type' => 'ammo_box']);
                    }
                }
            }
        }
        unset($obj);
    }

    public function hitInteractiveObject(string $oid, int $damage, string $shooterId): void
    {
        if (!isset($this->interactiveObjects[$oid])) return;
        $obj = &$this->interactiveObjects[$oid];
        if (!$obj['alive']) return;
        $obj['current_hp'] -= $damage;
        $this->broadcast(['type' => 'object_hit', 'object_id' => $oid, 'hp' => $obj['current_hp'], 'max_hp' => $obj['hp']]);
        if ($obj['current_hp'] <= 0) {
            $obj['alive'] = false; $obj['respawn_timer'] = (float)($obj['respawn_time'] ?? 20.0);
            if ($obj['type'] === 'explosive_barrel') {
                $ex = $obj['x'] + ($obj['w'] ?? 32) / 2; $ey = $obj['y'] + ($obj['h'] ?? 32) / 2;
                $radius = (float)($obj['explosion_radius'] ?? 120); $dmg = (int)($obj['explosion_damage'] ?? 60);
                foreach ($this->players as $p) {
                    if (!$p->alive) continue;
                    $dist = sqrt(($p->x + PLAYER_WIDTH / 2 - $ex) ** 2 + ($p->y + PLAYER_HEIGHT / 2 - $ey) ** 2);
                    if ($dist <= $radius) {
                        $fd = (int)($dmg * (1.0 - $dist / $radius));
                        if ($p->takeDamage($fd)) {
                            if ($p->hasFlag) $this->dropFlag($p->id);
                            $p->die(); $att = $this->getPlayer($shooterId);
                            if ($att) { $ci = $att->addKill(); $this->broadcast(['type' => 'player_killed', 'victim' => $p->id, 'victim_name' => $p->name, 'killer' => $att->id, 'killer_name' => $att->name, 'was_wolf' => false, 'skin_kill_fx' => $att->skinKillFx, 'combo' => $ci['combo'], 'combo_event' => $ci['event'], 'cause' => 'explosion']); }
                        }
                    }
                }
                $this->broadcast(['type' => 'object_destroyed', 'object_id' => $oid, 'object_type' => 'explosive_barrel', 'x' => $ex, 'y' => $ey, 'radius' => $radius]);
            } elseif ($obj['type'] === 'breakable_glass') {
                $this->broadcast(['type' => 'object_destroyed', 'object_id' => $oid, 'object_type' => 'breakable_glass', 'x' => $obj['x'], 'y' => $obj['y']]);
            }
        }
        unset($obj);
    }

    // ── Tick ──────────────────────────────────────────
    public function tick(float $now): void
    {
        if ($this->state !== 'playing' && $this->state !== 'round_end') return;
        $dt = min($now - $this->lastTickTime, 0.05); $this->lastTickTime = $now;

        if ($this->state === 'round_end') {
            $this->roundEndTimer -= $dt;
            if ($this->roundEndTimer <= 0) {
                $sw = $this->mode === 'ctf' ? CTF_SCORE_TO_WIN : ROUNDS_TO_WIN;
                if ($this->redScore >= $sw || $this->blueScore >= $sw) $this->endGame();
                else $this->startNewRound();
            }
            return;
        }

        if ($this->mode === 'wolf') { $this->wolfTimer -= $dt; if ($this->wolfTimer <= 0) $this->pickWolf(); }

        $spawns = MapManager::getInstance()->getSpawns($this->mapId);
        foreach ($this->players as $p) {
            $p->update($dt, $this->platforms);
            if (!$p->alive && $p->respawnTimer <= 0 && $p->team !== '') {
                $p->setSpawns($spawns[$p->team] ?? null);
                $p->spawn();
                if ($this->mode === 'wolf' && $p->id === $this->wolfId) $p->transformToWolf();
            }
        }

        foreach ($this->bullets as $bid => $b) {
            $b->update($dt);
            if (!$b->active) { unset($this->bullets[$bid]); continue; }
            if ($b->checkPlatforms($this->platforms)) { unset($this->bullets[$bid]); continue; }

            foreach ($this->interactiveObjects as $oid => $obj) {
                if (!$obj['alive'] || !in_array($obj['type'], ['explosive_barrel', 'breakable_glass'])) continue;
                if ($this->aabb($b->x, $b->y, BULLET_WIDTH, BULLET_HEIGHT, $obj['x'], $obj['y'], $obj['w'] ?? 32, $obj['h'] ?? 32)) {
                    $this->hitInteractiveObject($oid, $b->damage, $b->ownerId);
                    $b->active = false; unset($this->bullets[$bid]); break;
                }
            }
            if (!isset($this->bullets[$bid])) continue;

            foreach ($this->players as $p) {
                if (!$p->alive || $p->id === $b->ownerId || $p->team === $b->ownerTeam) continue;
                if ($this->aabb($b->x, $b->y, BULLET_WIDTH, BULLET_HEIGHT, $p->x, $p->y, PLAYER_WIDTH, PLAYER_HEIGHT)) {
                    $died = $p->takeDamage($b->damage); $b->active = false; unset($this->bullets[$bid]);
                    if ($died) {
                        if ($p->hasFlag) $this->dropFlag($p->id);
                        $wasWolf = $p->isWolf; $p->die();
                        $att = $this->getPlayer($b->ownerId);
                        if ($att) {
                            $ci = $att->addKill();
                            if ($wasWolf) { $att->wolfKills++; $this->pickWolf(); }
                            if ($att->accountId) {
                                $r = Database::addTaskProgress($att->accountId, 'kill');
                                if ($wasWolf) Database::addTaskProgress($att->accountId, 'wolf_kill');
                                if (!empty($r)) $this->sendTo($att->id, ['type' => 'task_complete', 'rewards' => $r]);
                            }
                            $this->broadcast(['type' => 'player_killed', 'victim' => $p->id, 'victim_name' => $p->name, 'killer' => $att->id, 'killer_name' => $att->name, 'was_wolf' => $wasWolf, 'skin_kill_fx' => $att->skinKillFx, 'combo' => $ci['combo'], 'combo_event' => $ci['event']]);
                            if ($ci['event'] === 'legendary') $this->broadcast(['type' => 'legendary_combo', 'player_name' => $att->name, 'combo' => $ci['combo']]);
                        } else {
                            $this->broadcast(['type' => 'player_killed', 'victim' => $p->id, 'victim_name' => $p->name, 'killer' => '', 'killer_name' => 'World', 'was_wolf' => false, 'skin_kill_fx' => '', 'combo' => 0, 'combo_event' => '']);
                        }
                    } else {
                        $this->broadcast(['type' => 'player_hit', 'id' => $p->id, 'hp' => $p->hp, 'damage' => $b->damage]);
                    }
                    break;
                }
            }
        }

        $this->updateInteractiveObjects($dt);
        if ($this->mode === 'ctf')      $this->updateCTF();
        elseif ($this->mode !== 'wolf') $this->checkRoundEnd();
        else                            $this->checkWolfRoundEnd();
        $this->broadcastState();
    }

    // ── Tur/Oyun Sonu ─────────────────────────────────
    private function aabb(float $ax, float $ay, float $aw, float $ah, float $bx, float $by, float $bw, float $bh): bool
    {
        return $ax < $bx + $bw && $ax + $aw > $bx && $ay < $by + $bh && $ay + $ah > $by;
    }

    private function checkRoundEnd(): void
    {
        if ($this->roundEndPending) return;
        $ra = count(array_filter($this->red(),  fn($p) => $p->alive));
        $ba = count(array_filter($this->blue(), fn($p) => $p->alive));
        if (count($this->red()) < 1 || count($this->blue()) < 1) return;
        if ($ra === 0 && $ba === 0) $this->endRound('draw');
        elseif ($ra === 0) $this->endRound('blue');
        elseif ($ba === 0) $this->endRound('red');
    }

    private function checkWolfRoundEnd(): void {}

    private function endRound(string $winner): void
    {
        if ($this->roundEndPending) return;
        $this->roundEndPending = true; $this->state = 'round_end'; $this->roundEndTimer = (float)ROUND_END_DELAY;
        if ($winner === 'red') $this->redScore++; elseif ($winner === 'blue') $this->blueScore++;
        foreach ($this->players as $p) if ($p->accountId) Database::addTaskProgress($p->accountId, 'round');
        $this->broadcast(['type' => 'round_end', 'winner' => $winner, 'red_score' => $this->redScore, 'blue_score' => $this->blueScore, 'next_in' => ROUND_END_DELAY]);
    }

    private function startNewRound(): void
    {
        $this->currentRound++; $this->bullets = []; $this->roundEndPending = false; $this->state = 'playing';
        if ($this->mode === 'ctf') $this->initFlags($this->mapId);
        if ($this->mode === 'wolf') $this->pickWolf();
        $spawns = MapManager::getInstance()->getSpawns($this->mapId);
        foreach ($this->players as $p) {
            if ($p->team !== '') { $p->setSpawns($spawns[$p->team] ?? null); $p->spawn(); }
        }
        $this->broadcast(['type' => 'round_start', 'round' => $this->currentRound, 'red_score' => $this->redScore, 'blue_score' => $this->blueScore]);
        $this->broadcastState();
    }

    private function endGame(): void
    {
        $sw = $this->mode === 'ctf' ? CTF_SCORE_TO_WIN : ROUNDS_TO_WIN;
        $gw = $this->redScore >= $sw ? 'red' : 'blue';
        $totalKills = array_sum(array_map(fn($p) => $p->kills, $this->players));
        MapManager::getInstance()->recordResult($this->mapId, $gw, $totalKills);
        $this->state = 'lobby'; $sb = [];
        foreach ($this->players as $p) {
            $won = $p->team === $gw;
            if ($p->accountId) {
                Database::updateStats($p->accountId, $p->kills, $p->deaths, $won, $p->wolfKills, $this->currentRound);
                if ($won) { $r = Database::addTaskProgress($p->accountId, 'win'); if (!empty($r)) $this->sendTo($p->id, ['type' => 'task_complete', 'rewards' => $r]); }
            }
            $sb[] = ['id' => $p->id, 'name' => $p->name, 'team' => $p->team, 'kills' => $p->kills, 'deaths' => $p->deaths, 'wolf_kills' => $p->wolfKills, 'combo' => $p->combo];
        }
        $this->broadcast(['type' => 'game_over', 'winner' => $gw, 'red_score' => $this->redScore, 'blue_score' => $this->blueScore, 'scoreboard' => $sb, 'mode' => $this->mode, 'map_id' => $this->mapId]);
        foreach ($this->players as $p) { $p->ready = false; $p->alive = false; $p->kills = $p->deaths = $p->wolfKills = $p->combo = $p->roundKills = 0; $p->isWolf = false; }
        $this->bullets = [];
        if ($this->mode === 'ctf') $this->initFlags($this->mapId);
    }

    // ── Input Handler ─────────────────────────────────
    public function handleInput(string $pid, array $d): void
    {
        $p = $this->getPlayer($pid);
        if (!$p || $this->state !== 'playing') return;
        switch ($d['action'] ?? '') {
            case 'move':
                if (!$p->alive) return;
                $p->inputLeft = !empty($d['left']); $p->inputRight = !empty($d['right']); $p->inputJump = !empty($d['jump']);
                $wk = $d['weapon'] ?? ''; if ($wk && isset(WEAPONS[$wk]) && $wk !== $p->weaponKey && !$p->reloading) $p->loadWeapon($wk);
                break;
            case 'shoot':
                if (!$p->alive) return;
                $wdata = $p->tryShoot(microtime(true)); if (!$wdata) return;
                $o = $p->getBulletOrigin();
                $dx = isset($d['dx']) ? (float)$d['dx'] : ($p->facingRight ? 1.0 : -1.0);
                $dy = isset($d['dy']) ? (float)$d['dy'] : 0.0;
                $pellets = $wdata['pellets'] ?? 1; $spread = $wdata['spread'] ?? 0;
                for ($i = 0; $i < $pellets; $i++) {
                    $pdx = $dx + ($spread > 0 ? ((mt_rand(-100,100)/100)*$spread) : 0);
                    $pdy = $dy + ($spread > 0 ? ((mt_rand(-100,100)/100)*$spread*0.5) : 0);
                    $b = new Bullet($pid, $p->team, $o['x'], $o['y'], $pdx, $pdy, $wdata['damage'], $wdata['speed'], $p->skinTrail, $pellets > 1);
                    $this->bullets[$b->id] = $b;
                }
                break;
            case 'reload': if ($p->alive) $p->startReload(); break;
            case 'ability': if ($p->alive && $p->isWolf && method_exists($p, 'activateGhost')) $p->activateGhost(); break;
            case 'emote':
                $ok = $p->setEmote($d['key'] ?? '');
                if ($ok) $this->broadcast(['type' => 'emote', 'player_id' => $pid, 'player_name' => $p->name, 'emote_key' => $d['key'], 'emote_icon' => EMOTES[$d['key']] ?? '']);
                break;
            case 'select_map':
                if ($pid === $this->hostId && $this->state === 'lobby') {
                    $mid = $d['map_id'] ?? '';
                    if (MapManager::getInstance()->exists($mid)) {
                        $this->mapId = $mid;
                        $mm = MapManager::getInstance();
                        $this->broadcast(['type' => 'map_selected', 'map_id' => $mid, 'map_name' => $mm->get($mid)['name'] ?? $mid]);
                    }
                }
                break;
        }
    }

    // ── Broadcast ─────────────────────────────────────
    public function broadcast(array $msg): void { $j = json_encode($msg); foreach ($this->players as $p) try { $p->conn->send($j); } catch (\Exception $e) {} }
    public function sendTo(string $pid, array $msg): void { $p = $this->getPlayer($pid); if ($p) try { $p->conn->send(json_encode($msg)); } catch (\Exception $e) {} }

    public function broadcastState(): void
    {
        $this->broadcast([
            'type'       => 'state',
            'players'    => array_values(array_map(fn($p) => $p->serialize(), $this->players)),
            'bullets'    => array_values(array_map(fn($b) => $b->serialize(), $this->bullets)),
            'flags'      => array_values(array_map(fn($f) => $f->serialize(), $this->flags)),
            'objects'    => array_values(array_map(fn($o) => $this->serializeObject($o), $this->interactiveObjects)),
            'red_score'  => $this->redScore, 'blue_score' => $this->blueScore,
            'round'      => $this->currentRound, 'room_state' => $this->state,
            'mode'       => $this->mode, 'map_id' => $this->mapId,
            'wolf_timer' => $this->mode === 'wolf' ? round($this->wolfTimer, 1) : 0,
        ]);
    }

    private function serializeObject(array $obj): array
    {
        return ['id' => $obj['id'], 'type' => $obj['type'], 'x' => $obj['x'], 'y' => $obj['y'],
            'w' => $obj['w'] ?? 32, 'h' => $obj['h'] ?? 32,
            'alive' => $obj['alive'], 'current_hp' => $obj['current_hp'] ?? 0, 'max_hp' => $obj['hp'] ?? 0];
    }

    // ── Map API (GameServer tarafından kullanılır) ─────
    public function getMapId(): string { return $this->mapId; }

    public function getMapData(): array
    {
        $mm  = MapManager::getInstance();
        $map = $mm->get($this->mapId);
        if (!$map) return [];
        // Client'a gerekli alanları gönder (fazla veri göndermekten kaçın)
        return [
            'id'                  => $map['id'],
            'name'                => $map['name'],
            'description'         => $map['description'] ?? '',
            'thumbnail'           => $map['thumbnail'] ?? '',
            'modes'               => $map['modes'] ?? [],
            'width'               => $map['width'],
            'height'              => $map['height'],
            'background'          => $map['background'] ?? '',
            'music'               => $map['music'] ?? '',
            'wall_jump_zones'     => $map['wall_jump_zones'] ?? [],
            'climb_surfaces'      => $map['climb_surfaces'] ?? [],
            'special_zones'       => $map['special_zones'] ?? [],
            'interactive_objects' => $map['interactive_objects'] ?? [],
            'spawns'              => $map['spawns'] ?? [],
            'ctf_flags'           => $map['ctf_flags'] ?? [],
        ];
    }

    public function changeMap(string $mapId): void
    {
        $this->loadMap($mapId);
        // Oyun lobideyken değiştiriliyor — flag/bullet sıfırla
        $this->bullets = [];
        $this->flags   = [];
        if ($this->mode === 'ctf') $this->initFlags($this->mapId);
    }

    public function getLobbyInfo(): array
    {
        $mm = MapManager::getInstance();
        return ['id' => $this->id, 'name' => $this->name, 'host' => $this->hostId, 'state' => $this->state,
            'mode' => $this->mode, 'map_id' => $this->mapId, 'map_name' => $mm->get($this->mapId)['name'] ?? $this->mapId,
            'private' => $this->isPrivate, 'invite' => $this->inviteCode,
            'players' => array_values(array_map(fn($p) => ['id' => $p->id, 'name' => $p->name, 'team' => $p->team, 'ready' => $p->ready], $this->players)),
            'red' => count($this->red()), 'blue' => count($this->blue()), 'total' => count($this->players)];
    }
}
