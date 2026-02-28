<?php
namespace Game;
use Ratchet\ConnectionInterface;

class Player {
    // ── Temel ──
    public string  $id, $name, $team = '', $weaponKey = 'rifle';
    public ConnectionInterface $conn;
    public ?int    $accountId;
    public bool    $ready = false, $alive = false, $hasFlag = false;
    public float   $x = 100.0, $y = 712.0, $vx = 0.0, $vy = 0.0;
    public int     $hp = 100, $maxHp = 100;
    public bool    $onGround = true, $inputLeft = false, $inputRight = false,
                   $inputJump = false, $facingRight = true;
    public float   $respawnTimer = 0.0, $lastShotTime = 0.0;
    public int     $kills = 0, $deaths = 0, $wolfKills = 0, $combo = 0, $roundKills = 0;
    public float   $comboResetTimer = 0.0, $reloadTimer = 0.0, $emoteTimer = 0.0;
    public bool    $reloading = false;
    public int     $ammo = 30, $maxAmmo = 30;
    public string  $currentEmote = '', $skinChar = 'char_default',
                   $skinTrail = '', $skinKillFx = '', $skinEntry = '';

    // ── Wolf Sistemi (YENİ) ──
    public bool    $isWolf = false;
    public string  $wolfType = 'berserker';    // seçili kurt türü
    public string  $wolfState = 'human';       // human | transforming | wolf
    public float   $transformTimer = 0.0;      // dönüşüm animasyon geri sayımı
    public float   $wolfCooldown = 0.0;        // form değişim cooldown
    public bool    $transformRequested = false;// girdi bayrağı
    public string  $transformTarget = 'wolf';  // 'wolf' | 'human'

    // Stamina
    public float   $stamina = 100.0;
    public bool    $staminaExhausted = false;  // 0'a düşünce kısa lock

    // Rage (Berserker)
    public float   $rage = 0.0;
    public bool    $rageActive = false;

    // Yetenekler — CD'ler
    public float   $ability1Cd = 0.0;
    public float   $ability2Cd = 0.0;
    public float   $pouncecd   = 0.0;         // tüm kurlara pounce

    // Görünmezlik (Phantom)
    public bool    $stealthActive = false;
    public float   $stealthTimer  = 0.0;

    // Iron Hide (Tank)
    public bool    $ironHideActive = false;
    public float   $ironHideTimer  = 0.0;

    // Lightning Field (Storm)
    public bool    $lightningFieldActive = false;
    public float   $lightningFieldTimer  = 0.0;

    // Duvara tırmanma (Phantom)
    public bool    $onWall = false;
    public int     $wallSide = 0;             // -1=sol, 1=sağ
    public bool    $inputWallClimb = false;

    // Wolf melee
    public float   $lastMeleeTime = 0.0;

    /** Map'ten dinamik spawn noktası — Room::loadMap() tarafından set edilir */
    private ?array $dynamicSpawns = null;
    public function setSpawns(?array $spawns): void { $this->dynamicSpawns = $spawns; }
    // Spawn noktaları (config sabitinden, override edilebilir)
    private function getSpawns(): array {
        if ($this->dynamicSpawns !== null && !empty($this->dynamicSpawns)) return $this->dynamicSpawns;
        return $this->team === 'red' ? RED_SPAWNS : BLUE_SPAWNS;
    }

    public function __construct(string $id, string $name, ConnectionInterface $conn, ?int $accId = null) {
        $this->id = $id; $this->name = $name; $this->conn = $conn; $this->accountId = $accId;
        $this->loadWeapon('rifle');
    }

    public function applyEquips(array $eq): void {
        $this->skinChar   = $eq['equip_char']   ?? 'char_default';
        $this->weaponKey  = $eq['equip_weapon']  ?? 'rifle';
        $this->skinTrail  = $eq['equip_trail']   ?? '';
        $this->skinKillFx = $eq['equip_kill_fx'] ?? '';
        $this->skinEntry  = $eq['equip_entry']   ?? '';
        $this->loadWeapon($this->weaponKey);
    }

    public function loadWeapon(string $key): void {
        $w = WEAPONS[$key] ?? WEAPONS['rifle'];
        $this->weaponKey = $key; $this->ammo = $w['ammo']; $this->maxAmmo = $w['ammo'];
        $this->reloading = false; $this->reloadTimer = 0.0;
    }

    // ── Wolf türü istatistiklerini döndür ──
    public function wolfData(): array {
        return WOLF_TYPES[$this->wolfType] ?? WOLF_TYPES['berserker'];
    }

    // ── Form değiştirme isteği (client'tan gelir) ──
    public function requestTransform(string $type = ''): void {
        if ($this->wolfCooldown > 0) return;
        if ($this->wolfState !== 'human' && $this->wolfState !== 'wolf') return;
        if ($type && isset(WOLF_TYPES[$type])) $this->wolfType = $type;
        $this->transformTarget  = ($this->wolfState === 'human') ? 'wolf' : 'human';
        $this->transformTimer   = WOLF_TRANSFORM_TIME;
        $this->wolfState        = 'transforming';
    }

    // ── Dönüşümü tamamla ──
    private function finishTransform(): void {
        $this->wolfCooldown = WOLF_COOLDOWN;
        if ($this->transformTarget === 'wolf') {
            $wd = $this->wolfData();
            $this->isWolf  = true;
            $this->wolfState = 'wolf';
            $hpPct = $this->maxHp > 0 ? $this->hp / $this->maxHp : 1.0;
            $this->maxHp = $wd['hp'];
            $this->hp    = (int)round($this->maxHp * $hpPct);
            if ($this->hp < 1) $this->hp = 1;
            $this->stamina   = WOLF_STAMINA_MAX;
            $this->rage      = 0.0;
            $this->rageActive= false;
            $this->ability1Cd= 0.0;
            $this->ability2Cd= 0.0;
            $this->pouncecd  = 0.0;
            $this->stealthActive = false;
            $this->ironHideActive = false;
            $this->lightningFieldActive = false;
        } else {
            $this->isWolf    = false;
            $this->wolfState = 'human';
            $hpPct = $this->maxHp > 0 ? $this->hp / $this->maxHp : 1.0;
            $this->maxHp = PLAYER_HP;
            $this->hp    = (int)round($this->maxHp * $hpPct);
            if ($this->hp < 1) $this->hp = 1;
            $this->stealthActive = false;
            $this->ironHideActive = false;
            $this->lightningFieldActive = false;
            $this->rage = 0.0; $this->rageActive = false;
        }
    }

    public function spawn(): void {
        $sp = $this->getSpawns();
        $s  = $sp[array_rand($sp)];
        $this->x = (float)$s['x']; $this->y = (float)$s['y'];
        $this->vx = $this->vy = 0.0; $this->onGround = true;
        $this->hp = $this->maxHp = PLAYER_HP;
        $this->isWolf = false; $this->wolfState = 'human';
        $this->wolfCooldown = 0.0; $this->transformTimer = 0.0;
        $this->stamina = WOLF_STAMINA_MAX; $this->rage = 0.0; $this->rageActive = false;
        $this->stealthActive = false; $this->ironHideActive = false; $this->lightningFieldActive = false;
        $this->alive = true; $this->respawnTimer = 0.0; $this->hasFlag = false;
        $this->inputLeft = $this->inputRight = $this->inputJump = false;
        $this->lastShotTime = 0.0; $this->reloading = false; $this->reloadTimer = 0.0;
        $this->facingRight = ($this->team === 'red');
        $this->ammo = WEAPONS[$this->weaponKey]['ammo'] ?? 30;
        $this->ability1Cd = $this->ability2Cd = $this->pouncecd = 0.0;
        $this->onWall = false;
    }

    public function update(float $dt, array $ps): void {
        if (!$this->alive) {
            if ($this->respawnTimer > 0) { $this->respawnTimer -= $dt; if ($this->respawnTimer < 0) $this->respawnTimer = 0.0; }
            return;
        }

        // ── Cooldown'ları düşür ──
        if ($this->wolfCooldown > 0)   { $this->wolfCooldown   -= $dt; if ($this->wolfCooldown   < 0) $this->wolfCooldown   = 0.0; }
        if ($this->ability1Cd > 0)     { $this->ability1Cd     -= $dt; if ($this->ability1Cd     < 0) $this->ability1Cd     = 0.0; }
        if ($this->ability2Cd > 0)     { $this->ability2Cd     -= $dt; if ($this->ability2Cd     < 0) $this->ability2Cd     = 0.0; }
        if ($this->pouncecd   > 0)     { $this->pouncecd       -= $dt; if ($this->pouncecd       < 0) $this->pouncecd       = 0.0; }
        if ($this->comboResetTimer > 0){ $this->comboResetTimer -= $dt; if ($this->comboResetTimer <= 0) $this->combo = 0; }
        if ($this->emoteTimer > 0)     { $this->emoteTimer     -= $dt; if ($this->emoteTimer     <= 0) $this->currentEmote = ''; }

        // ── Dönüşüm animasyonu ──
        if ($this->wolfState === 'transforming') {
            $this->transformTimer -= $dt;
            $this->vx = 0.0; // dönüşüm sırasında hareket yok
            if ($this->transformTimer <= 0) $this->finishTransform();
            // Fizik güncellemesini atla (hareketsiz)
            $this->applyGravityOnly($dt, $ps);
            return;
        }

        // ── İnsan vs Kurt fizik ──
        if (!$this->isWolf) {
            $this->updateHuman($dt, $ps);
        } else {
            $this->updateWolf($dt, $ps);
        }

        // Reload
        if ($this->reloading) {
            $this->reloadTimer -= $dt;
            if ($this->reloadTimer <= 0) {
                $this->ammo = WEAPONS[$this->weaponKey]['ammo'] ?? 30;
                $this->reloading = false;
            }
        }

        // Haritadan düş → öl
        if ($this->y > MAP_HEIGHT + 20) $this->die();
    }

    private function updateHuman(float $dt, array $ps): void {
        $this->vx = 0.0;
        if ($this->inputLeft)  { $this->vx -= PLAYER_SPEED; $this->facingRight = false; }
        if ($this->inputRight) { $this->vx += PLAYER_SPEED; $this->facingRight = true; }
        if ($this->inputJump && $this->onGround) { $this->vy = (float)PLAYER_JUMP_FORCE; $this->onGround = false; }
        $this->applyPhysics($dt, $ps);
    }

    private function updateWolf(float $dt, array $ps): void {
        $wd  = $this->wolfData();
        $spd = (float)$wd['speed'];
        $jmp = (float)$wd['jump_force'];

        // ── Stamina ──
        $moving = $this->inputLeft || $this->inputRight;
        if ($this->staminaExhausted) {
            $this->stamina += WOLF_STAMINA_REGEN * $dt * 1.5;
            if ($this->stamina >= 30.0) $this->staminaExhausted = false;
        } else {
            if ($moving) {
                $this->stamina -= WOLF_STAMINA_RUN_COST * $dt;
            } else {
                $this->stamina += WOLF_STAMINA_REGEN * $dt;
            }
            if ($this->stamina <= 0) { $this->stamina = 0.0; $this->staminaExhausted = true; }
        }
        $this->stamina = min($this->stamina, WOLF_STAMINA_MAX);

        // Stamina bitti → yarı hız
        if ($this->staminaExhausted) $spd *= 0.5;

        // ── Rage (Berserker) ──
        if ($this->wolfType === 'berserker') {
            if ($this->rage > 0) { $this->rage -= $wd['rage_decay'] * $dt; if ($this->rage < 0) $this->rage = 0.0; }
            $this->rageActive = $this->rage >= $wd['rage_threshold'];
            if ($this->rageActive) {
                $spd *= (1.0 + $wd['rage_spd_bonus']);
            }
        }

        // ── Görünmezlik Zamanlayıcısı (Phantom) ──
        if ($this->wolfType === 'phantom' && $this->stealthActive) {
            $this->stealthTimer -= $dt;
            if ($this->stealthTimer <= 0) { $this->stealthActive = false; $this->stealthTimer = 0.0; }
        }

        // ── Iron Hide Zamanlayıcısı (Tank) ──
        if ($this->wolfType === 'tank' && $this->ironHideActive) {
            $this->ironHideTimer -= $dt;
            if ($this->ironHideTimer <= 0) { $this->ironHideActive = false; $this->ironHideTimer = 0.0; }
        }

        // ── Lightning Field Zamanlayıcısı (Storm) ──
        if ($this->wolfType === 'storm' && $this->lightningFieldActive) {
            $this->lightningFieldTimer -= $dt;
            if ($this->lightningFieldTimer <= 0) { $this->lightningFieldActive = false; $this->lightningFieldTimer = 0.0; }
        }

        // ── Hareket ──
        $this->vx = 0.0;
        if (!$this->staminaExhausted || true) { // her zaman yön ata, sadece hız azalır
            if ($this->inputLeft)  { $this->vx -= $spd; $this->facingRight = false; }
            if ($this->inputRight) { $this->vx += $spd; $this->facingRight = true; }
        }

        // ── Duvara Tırmanma (Phantom) ──
        if ($wd['wall_climb'] && $this->onWall && $this->inputWallClimb && !$this->onGround) {
            $this->vy = -(float)($wd['wall_climb_speed']);
        } elseif ($this->inputJump && ($this->onGround || ($wd['wall_climb'] && $this->onWall))) {
            $this->vy = (float)$jmp;
            $this->onGround = false;
            $this->onWall   = false;
            if (!empty($this->stamina)) $this->stamina -= WOLF_STAMINA_JUMP_COST;
        }

        $this->applyPhysics($dt, $ps);

        // Duvar temas kontrolü (Phantom)
        if ($wd['wall_climb']) $this->checkWallContact($ps);
    }

    private function applyGravityOnly(float $dt, array $ps): void {
        if (!$this->onGround) {
            $this->vy += PLAYER_GRAVITY * $dt;
            if ($this->vy > PLAYER_MAX_FALL_SPEED) $this->vy = (float)PLAYER_MAX_FALL_SPEED;
        }
        $this->y += $this->vy * $dt;
        $this->onGround = false;
        foreach ($ps as $p) {
            if (!$this->ov($p)) continue;
            $b = ($this->y + PLAYER_HEIGHT) - $p['y'];
            $t = ($p['y'] + $p['h']) - $this->y;
            if ($b < $t) { $this->y = (float)($p['y'] - PLAYER_HEIGHT); $this->vy = 0.0; $this->onGround = true; }
            else         { $this->y = (float)($p['y'] + $p['h']); if ($this->vy < 0) $this->vy = 0.0; }
        }
    }

    private function applyPhysics(float $dt, array $ps): void {
        // Yatay
        $this->x += $this->vx * $dt;
        if ($this->x < 0)                          { $this->x = 0.0;                              $this->vx = 0.0; }
        if ($this->x + PLAYER_WIDTH > MAP_WIDTH)   { $this->x = (float)(MAP_WIDTH - PLAYER_WIDTH); $this->vx = 0.0; }
        foreach ($ps as $p) {
            if (!$this->ov($p)) continue;
            $l = ($p['x'] + $p['w']) - $this->x;
            $r = ($this->x + PLAYER_WIDTH) - $p['x'];
            if ($l < $r) $this->x = (float)($p['x'] + $p['w']); else $this->x = (float)($p['x'] - PLAYER_WIDTH);
            $this->vx = 0.0;
        }
        // Düşey + yerçekimi
        if (!$this->onGround) {
            $this->vy += PLAYER_GRAVITY * $dt;
            if ($this->vy > PLAYER_MAX_FALL_SPEED) $this->vy = (float)PLAYER_MAX_FALL_SPEED;
        }
        $this->onGround = false;
        $this->y += $this->vy * $dt;
        foreach ($ps as $p) {
            if (!$this->ov($p)) continue;
            $b = ($this->y + PLAYER_HEIGHT) - $p['y'];
            $t = ($p['y'] + $p['h']) - $this->y;
            if ($b < $t) { $this->y = (float)($p['y'] - PLAYER_HEIGHT); $this->vy = 0.0; $this->onGround = true; }
            else         { $this->y = (float)($p['y'] + $p['h']); if ($this->vy < 0) $this->vy = 0.0; }
        }
    }

    private function checkWallContact(array $ps): void {
        $this->onWall = false;
        if ($this->onGround) return;
        foreach ($ps as $p) {
            // Sol duvar
            $leftTouching  = abs(($this->x) - ($p['x'] + $p['w'])) < 4
                && $this->y < $p['y'] + $p['h'] && $this->y + PLAYER_HEIGHT > $p['y'];
            // Sağ duvar
            $rightTouching = abs(($this->x + PLAYER_WIDTH) - $p['x']) < 4
                && $this->y < $p['y'] + $p['h'] && $this->y + PLAYER_HEIGHT > $p['y'];
            if ($leftTouching)  { $this->onWall = true; $this->wallSide = -1; return; }
            if ($rightTouching) { $this->onWall = true; $this->wallSide =  1; return; }
        }
    }

    private function ov(array $p): bool {
        return $this->x < $p['x'] + $p['w'] && $this->x + PLAYER_WIDTH  > $p['x']
            && $this->y < $p['y'] + $p['h'] && $this->y + PLAYER_HEIGHT > $p['y'];
    }

    // ── Melee (Kurt saldırısı) ──
    public function canMelee(float $now): bool {
        if (!$this->isWolf || !$this->alive) return false;
        $wd = $this->wolfData();
        return ($now - $this->lastMeleeTime) >= $wd['melee_rate']
            && $this->stamina >= WOLF_STAMINA_MELEE_COST
            && !$this->staminaExhausted;
    }

    public function doMelee(float $now): int {
        $wd = $this->wolfData();
        $this->lastMeleeTime = $now;
        $this->stamina -= WOLF_STAMINA_MELEE_COST;
        if ($this->stamina < 0) $this->stamina = 0.0;
        $dmg = $wd['melee_damage'];
        if ($this->wolfType === 'berserker' && $this->rageActive) {
            $dmg = (int)ceil($dmg * (1.0 + $wd['rage_dmg_bonus']));
        }
        return $dmg;
    }

    public function getMeleeRange(): float {
        return (float)($this->wolfData()['melee_range'] ?? 55);
    }

    // ── Pounce (ileri dalış) ──
    public function canPounce(): bool {
        return $this->isWolf && $this->alive && $this->pouncecd <= 0
            && $this->stamina >= WOLF_STAMINA_ABILITY_COST && !$this->staminaExhausted;
    }

    public function doPounce(): void {
        $this->vx = $this->facingRight ? (float)WOLF_POUNCE_FORCE_X : -(float)WOLF_POUNCE_FORCE_X;
        $this->vy = (float)WOLF_POUNCE_FORCE_Y;
        $this->onGround = false;
        $this->pouncecd = (float)WOLF_POUNCE_CD;
        $this->stamina -= WOLF_STAMINA_ABILITY_COST;
        if ($this->stamina < 0) $this->stamina = 0.0;
    }

    // ── Yetenek 1 ──
    public function canAbility1(): bool {
        return $this->isWolf && $this->alive && $this->ability1Cd <= 0
            && $this->stamina >= WOLF_STAMINA_ABILITY_COST;
    }

    // Ability 1 sonuç verisi döner (Room bunu işler)
    public function doAbility1(): array {
        $wd = $this->wolfData();
        $this->ability1Cd = (float)$wd['ability_1_cd'];
        $this->stamina -= WOLF_STAMINA_ABILITY_COST;
        if ($this->stamina < 0) $this->stamina = 0.0;

        switch ($this->wolfType) {
            case 'berserker':
                // Blood Rush: hızlı ileri dalış + hasar
                $this->vx = $this->facingRight ? 700.0 : -700.0;
                $this->vy = -200.0; $this->onGround = false;
                return ['type' => 'blood_rush', 'x' => $this->x, 'y' => $this->y, 'dir' => $this->facingRight ? 1 : -1];

            case 'phantom':
                // Shadow Step: 200px ışınlanma
                $tpX = $this->x + ($this->facingRight ? 200.0 : -200.0);
                $tpX = max(0.0, min((float)(MAP_WIDTH - PLAYER_WIDTH), $tpX));
                $this->x = $tpX;
                return ['type' => 'shadow_step', 'x' => $this->x, 'y' => $this->y];

            case 'tank':
                // Shockwave: döndürülür Room'a (alan hasarı hesabı orada)
                return ['type' => 'shockwave', 'x' => $this->x + PLAYER_WIDTH / 2, 'y' => $this->y + PLAYER_HEIGHT,
                        'radius' => $wd['shockwave_radius'], 'damage' => $wd['shockwave_damage']];

            case 'storm':
                // Thunder Dash: hızlı yatay koşu, geçtiği düşmanları elektrikler
                $this->vx = $this->facingRight ? 800.0 : -800.0;
                return ['type' => 'thunder_dash', 'x' => $this->x, 'y' => $this->y, 'dir' => $this->facingRight ? 1 : -1];

            default:
                return ['type' => 'none'];
        }
    }

    // ── Yetenek 2 ──
    public function canAbility2(): bool {
        return $this->isWolf && $this->alive && $this->ability2Cd <= 0
            && $this->stamina >= WOLF_STAMINA_ABILITY_COST;
    }

    public function doAbility2(): array {
        $wd = $this->wolfData();
        $this->ability2Cd = (float)$wd['ability_2_cd'];
        $this->stamina -= WOLF_STAMINA_ABILITY_COST;
        if ($this->stamina < 0) $this->stamina = 0.0;

        switch ($this->wolfType) {
            case 'berserker':
                // War Cry: çevresine korku — Room yayın yapar
                return ['type' => 'war_cry', 'x' => $this->x, 'y' => $this->y, 'radius' => 200];

            case 'phantom':
                // Vanish: 3 sn görünmezlik
                $this->stealthActive = true;
                $this->stealthTimer  = (float)$wd['stealth_duration'];
                return ['type' => 'vanish', 'playerId' => $this->id, 'duration' => $wd['stealth_duration']];

            case 'tank':
                // Iron Hide: 4 sn hasar azaltma
                $this->ironHideActive = true;
                $this->ironHideTimer  = 4.0;
                return ['type' => 'iron_hide', 'playerId' => $this->id, 'duration' => 4.0];

            case 'storm':
                // Lightning Field: 2 sn çevresine elektrik
                $this->lightningFieldActive = true;
                $this->lightningFieldTimer  = 2.0;
                return ['type' => 'lightning_field', 'x' => $this->x, 'y' => $this->y, 'radius' => 100, 'duration' => 2.0];

            default:
                return ['type' => 'none'];
        }
    }

    // ── Hasar Alma ──
    public function takeDamage(int $amt): bool {
        if (!$this->alive) return false;
        $wd = $this->wolfData();

        // Tank: armor flat azaltma
        if ($this->isWolf && $this->wolfType === 'tank') {
            $armor = $wd['armor'] ?? 0;
            $amt   = max(1, $amt - $armor);
        }

        // Tank Iron Hide: %50 azaltma
        if ($this->isWolf && $this->wolfType === 'tank' && $this->ironHideActive) {
            $amt = (int)ceil($amt * 0.5);
        }

        // Phantom görünmezken bonus kaçınma (mantıksal, %10 şans)
        if ($this->isWolf && $this->wolfType === 'phantom' && $this->stealthActive) {
            if (mt_rand(0, 99) < 10) return false; // %10 dodge
        }

        // Genel dirençler
        if ($this->isWolf) {
            $res = (float)($wd['bullet_resist'] ?? 0.0);
            if ($res > 0) $amt = (int)ceil($amt * (1.0 - $res));
        }

        $this->hp -= $amt;
        if ($this->hp <= 0) { $this->hp = 0; return true; }
        return false;
    }

    public function die(): void {
        $this->alive = false; $this->deaths++;
        $this->respawnTimer = (float)RESPAWN_TIME;
        $this->vx = $this->vy = 0.0; $this->onGround = false; $this->hasFlag = false;
        $this->inputLeft = $this->inputRight = $this->inputJump = false;
        $this->combo = 0; $this->comboResetTimer = 0.0;
        // İnsan formuna geri dön
        $this->isWolf = false; $this->wolfState = 'human';
        $this->maxHp = PLAYER_HP; $this->wolfCooldown = 0.0;
        $this->stealthActive = false; $this->ironHideActive = false;
        $this->lightningFieldActive = false; $this->rage = 0.0; $this->rageActive = false;
    }

    public function addKill(): array {
        $this->kills++; $this->roundKills++; $this->combo++;
        $this->comboResetTimer = (float)COMBO_RESET_TIME;
        // Berserker rage
        if ($this->isWolf && $this->wolfType === 'berserker') {
            $wd = $this->wolfData();
            $this->rage = min(100.0, $this->rage + $wd['rage_per_kill']);
        }
        $ev = '';
        foreach (COMBO_THRESHOLDS as $n => $name) { if ($this->combo >= $n) $ev = $name; }
        return ['combo' => $this->combo, 'event' => $ev];
    }

    // ── Silah ──
    public function canShoot(float $now): bool {
        if (!$this->alive || $this->isWolf || $this->reloading || $this->ammo <= 0) return false;
        return ($now - $this->lastShotTime) >= (WEAPONS[$this->weaponKey]['rate'] ?? 0.12);
    }

    public function tryShoot(float $now): ?array {
        if (!$this->canShoot($now)) return null;
        $this->lastShotTime = $now; $this->ammo--;
        if ($this->ammo <= 0) $this->startReload();
        return WEAPONS[$this->weaponKey] ?? WEAPONS['rifle'];
    }

    public function startReload(): void {
        if ($this->reloading || $this->isWolf) return;
        $this->reloading = true;
        $this->reloadTimer = WEAPONS[$this->weaponKey]['reload'] ?? 2.0;
    }

    public function setEmote(string $key): bool {
        if (!isset(EMOTES[$key])) return false;
        $this->currentEmote = $key; $this->emoteTimer = (float)EMOTE_DURATION;
        return true;
    }

    public function getBulletOrigin(): array {
        return ['x' => $this->x + ($this->facingRight ? PLAYER_WIDTH : 0), 'y' => $this->y + PLAYER_HEIGHT * 0.3];
    }

    // ── Kurt formuna / insan formuna eski uyumlu methodlar ──
    public function transformToWolf(): void  { /* artık requestTransform kullanılır */ $this->isWolf = true; $this->wolfState = 'wolf'; $this->maxHp = $this->wolfData()['hp']; $this->hp = $this->maxHp; }
    public function transformToHuman(): void { $this->isWolf = false; $this->wolfState = 'human'; $this->maxHp = PLAYER_HP; }

    public function serialize(): array {
        $wd = $this->wolfData();
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'team'        => $this->team,
            'x'           => round($this->x, 1),
            'y'           => round($this->y, 1),
            'hp'          => $this->hp,
            'maxHp'       => $this->maxHp,
            'alive'       => $this->alive,
            'facingRight' => $this->facingRight,
            'kills'       => $this->kills,
            'deaths'      => $this->deaths,
            'respawn'     => round($this->respawnTimer, 1),
            'ready'       => $this->ready,
            // Kurt sistemi
            'isWolf'      => $this->isWolf,
            'wolfType'    => $this->wolfType,
            'wolfState'   => $this->wolfState,
            'wolfCd'      => round($this->wolfCooldown, 1),
            'wolfColor'   => $wd['color'] ?? '#ffffff',
            'wolfAura'    => $wd['aura']  ?? '',
            // Stamina & Rage
            'stamina'     => round($this->stamina, 1),
            'staminaMax'  => WOLF_STAMINA_MAX,
            'rage'        => round($this->rage, 1),
            'rageActive'  => $this->rageActive,
            // Durum efektleri
            'stealth'     => $this->stealthActive,
            'ironHide'    => $this->ironHideActive,
            'lightField'  => $this->lightningFieldActive,
            'onWall'      => $this->onWall,
            // Yetenek CD'leri (client UI için)
            'ab1Cd'       => round($this->ability1Cd, 1),
            'ab1MaxCd'    => (float)($wd['ability_1_cd'] ?? 0),
            'ab2Cd'       => round($this->ability2Cd, 1),
            'ab2MaxCd'    => (float)($wd['ability_2_cd'] ?? 0),
            'pounceCd'    => round($this->pouncecd, 1),
            // Diğer
            'hasFlag'     => $this->hasFlag,
            'combo'       => $this->combo,
            'weapon'      => $this->weaponKey,
            'ammo'        => $this->ammo,
            'maxAmmo'     => $this->maxAmmo,
            'reloading'   => $this->reloading,
            'reloadPct'   => $this->reloading ? round(1 - $this->reloadTimer / (WEAPONS[$this->weaponKey]['reload'] ?? 2.0), 2) : 1.0,
            'emote'       => $this->currentEmote,
            'skinChar'    => $this->skinChar,
            'skinTrail'   => $this->skinTrail,
            'skinKillFx'  => $this->skinKillFx,
            'skinEntry'   => $this->skinEntry,
        ];
    }
}
