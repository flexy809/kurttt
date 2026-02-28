<?php
// WolfFront - Competitive PvP Server Config
define('SERVER_HOST', '0.0.0.0'); define('SERVER_PORT', 8080);
define('TICK_RATE', 60); define('TICK_INTERVAL', 1.0 / TICK_RATE);
define('DB_PATH', __DIR__ . '/../data/game.db');

// ── Temel Oyuncu ──
define('PLAYER_SPEED', 220); define('PLAYER_JUMP_FORCE', -520);
define('PLAYER_GRAVITY', 900); define('PLAYER_MAX_FALL_SPEED', 700);
define('PLAYER_HP', 100); define('PLAYER_WIDTH', 32); define('PLAYER_HEIGHT', 48);

// ── Eski tek-kurt sabitleri (geriye dönük uyumluluk) ──
define('WOLF_HP', 200); define('WOLF_SPEED', 280); define('WOLF_DAMAGE', 50);
define('WOLF_JUMP_FORCE', -620); define('WOLF_CHANGE_INTERVAL', 20.0);

// ══════════════════════════════════════════════════════
//  YENİ: 4 KURT TÜRÜ TANIMları
//  Her oyuncu wolf moduna girerken wolfType seçer.
//  transform_time  : dönüşüm animasyon süresi (sn)
//  cooldown        : insan→kurt veya kurt→insan cd (sn)
// ══════════════════════════════════════════════════════
define('WOLF_TYPES', [

    // ── 1. BERSERKer ──────────────────────────────────
    'berserker' => [
        'name'           => 'Berserker',
        'color'          => '#FF2A2A',
        'aura'           => 'aura_berserker',   // client-side efekt anahtarı
        'hp'             => 180,
        'speed'          => 260,
        'jump_force'     => -580,
        'melee_damage'   => 65,                 // pounce / claw
        'melee_range'    => 55,
        'melee_rate'     => 0.45,               // sn arasındaki min bekleme
        'transform_time' => 1.0,
        'cooldown'       => 15.0,
        // Rage sistemi: her öldürme +20 rage, max 100
        'rage_per_kill'  => 20,
        'rage_decay'     => 4.0,                // boşta/sn azalma
        // Rage aktifken hasar bonusu
        'rage_dmg_bonus' => 0.40,               // +%40
        'rage_spd_bonus' => 0.20,               // +%20
        'rage_threshold' => 60,                 // bu değerin üstünde aktif
        // Yetenekler
        'ability_1'      => 'blood_rush',       // ileri saldırı dalışı
        'ability_1_cd'   => 6.0,
        'ability_2'      => 'war_cry',          // yakındaki düşmanlara korku
        'ability_2_cd'   => 18.0,
        // Denge
        'armor'          => 0,                  // savunma bonusu yok
        'bullet_resist'  => 0.0,                // kurşun hasarı indirimi yok
        'stealth'        => false,
        'wall_climb'     => false,
        // Takım notu (lore/UI için)
        'role'           => 'Assassin-Diver',
        'desc'           => 'Kısa sürede patlayan hasar, ancak düşük savunma.',
    ],

    // ── 2. PHANTOM (Gölge Kurt) ────────────────────────
    'phantom' => [
        'name'           => 'Phantom',
        'color'          => '#8B00FF',
        'aura'           => 'aura_phantom',
        'hp'             => 130,
        'speed'          => 310,
        'jump_force'     => -660,
        'melee_damage'   => 40,
        'melee_range'    => 45,
        'melee_rate'     => 0.30,
        'transform_time' => 1.0,
        'cooldown'       => 15.0,
        // Görünmezlik sistemi
        'stealth_duration' => 3.0,              // sn
        'stealth_cd'       => 10.0,
        'stealth_alpha'    => 0.15,             // client-side: %15 görünürlük
        // Yetenekler
        'ability_1'      => 'shadow_step',      // kısa ışınlanma (200px)
        'ability_1_cd'   => 8.0,
        'ability_2'      => 'vanish',           // 3 sn görünmezlik
        'ability_2_cd'   => 10.0,
        // Denge
        'armor'          => 0,
        'bullet_resist'  => 0.10,              // görünmezken +%10 kaçınma (mantıksal)
        'stealth'        => true,
        'wall_climb'     => true,               // duvara tırmanabilir
        'wall_climb_speed'=> 120,
        'rage_per_kill'  => 0,
        'rage_decay'     => 0,
        'rage_threshold' => 999,
        'role'           => 'Flanker-Infiltrator',
        'desc'           => 'Düşük can, yüksek mobilite, görünmezlik.',
    ],

    // ── 3. TANK (Demir Kurt) ──────────────────────────
    'tank' => [
        'name'           => 'Iron',
        'color'          => '#3A6FD8',
        'aura'           => 'aura_tank',
        'hp'             => 320,
        'speed'          => 175,
        'jump_force'     => -460,
        'melee_damage'   => 50,
        'melee_range'    => 70,                 // geniş alan hasarı
        'melee_rate'     => 0.70,               // yavaş
        'transform_time' => 1.0,
        'cooldown'       => 15.0,
        // Alan hasarı: shockwave (pounce yerine)
        'shockwave_radius'=> 110,
        'shockwave_damage'=> 35,
        'shockwave_cd'    => 9.0,
        // Yetenekler
        'ability_1'      => 'shockwave',        // yere vurarak alan hasarı
        'ability_1_cd'   => 9.0,
        'ability_2'      => 'iron_hide',        // 4 sn %50 hasar azaltma
        'ability_2_cd'   => 20.0,
        // Denge
        'armor'          => 20,                 // gelen hasardan -20 sabit (min 1)
        'bullet_resist'  => 0.20,               // kurşuna %20 dirençli
        'stealth'        => false,
        'wall_climb'     => false,
        'rage_per_kill'  => 0,
        'rage_decay'     => 0,
        'rage_threshold' => 999,
        'role'           => 'Frontline-Anchor',
        'desc'           => 'Yüksek can ve zırh, yavaş ve güçlü.',
    ],

    // ── 4. STORM (Elektrik Kurt) ──────────────────────
    'storm' => [
        'name'           => 'Storm',
        'color'          => '#FFD700',
        'aura'           => 'aura_storm',
        'hp'             => 155,
        'speed'          => 295,
        'jump_force'     => -610,
        'melee_damage'   => 30,
        'melee_range'    => 50,
        'melee_rate'     => 0.20,               // çok hızlı saldırı
        'transform_time' => 1.0,
        'cooldown'       => 15.0,
        // Zincir yıldırım: bir hedef vurulunca yakındakilere sıçrar
        'chain_range'    => 130,
        'chain_targets'  => 2,                  // max kaç hedefe sıçrar
        'chain_damage'   => 18,                 // sıçrayan hasar
        // Yetenekler
        'ability_1'      => 'thunder_dash',     // hızlı elektrik koşusu (stun bırakır)
        'ability_1_cd'   => 5.0,
        'ability_2'      => 'lightning_field',  // 2 sn çevresinde elektrik alan
        'ability_2_cd'   => 14.0,
        // Denge
        'armor'          => 0,
        'bullet_resist'  => 0.0,
        'stealth'        => false,
        'wall_climb'     => false,
        'rage_per_kill'  => 0,
        'rage_decay'     => 0,
        'rage_threshold' => 999,
        'role'           => 'Skirmisher-Disruptor',
        'desc'           => 'Hızlı saldırı, zincir elektrik hasarı.',
    ],
]);

// Dönüşüm sabitleri
define('WOLF_TRANSFORM_TIME', 1.0);  // animasyon süresi
define('WOLF_COOLDOWN', 15.0);       // hem insan→kurt hem kurt→insan

// Stamina (tüm kurt türleri için)
define('WOLF_STAMINA_MAX', 100.0);
define('WOLF_STAMINA_REGEN', 15.0);  // /sn boşta
define('WOLF_STAMINA_RUN_COST', 8.0);// /sn koşarken
define('WOLF_STAMINA_JUMP_COST', 20.0);
define('WOLF_STAMINA_MELEE_COST', 25.0);
define('WOLF_STAMINA_ABILITY_COST', 35.0);

// Pounce (tüm kurt türleri)
define('WOLF_POUNCE_FORCE_X', 480);
define('WOLF_POUNCE_FORCE_Y', -380);
define('WOLF_POUNCE_CD', 4.0);

// Rock-Paper-Scissors dengeleri
// berserker > phantom  (burst öldürür)
// phantom   > tank     (görünmez geçer, zırh işe yaramaz)
// tank      > berserker(hp farkı kazandırır)
// storm     > hepsine   orta seviye counter, bağımsız
define('WOLF_COUNTER', [
    'berserker' => ['strong'=>'phantom', 'weak'=>'tank'],
    'phantom'   => ['strong'=>'tank',    'weak'=>'berserker'],
    'tank'      => ['strong'=>'berserker','weak'=>'phantom'],
    'storm'     => ['strong'=>'',        'weak'=>''],
]);

// Wolf mode: Puan tablosu
// Her öldürme 1 puan. Wolf modu 60 sn, en çok puan kazanan takım.
define('WOLF_MODE_DURATION', 60.0);  // sn
define('WOLF_MODE_SCORE_WIN', 30);   // puana ulaşan takım kazanır (alternatif)

// ── Silahlar ──
define('WEAPONS', [
    'rifle'  =>['name'=>'Assault Rifle','damage'=>25,'rate'=>0.12,'speed'=>620,'ammo'=>30,'reload'=>2.0,'spread'=>0.04,'auto'=>true, 'pellets'=>1],
    'sniper' =>['name'=>'Sniper Rifle', 'damage'=>80,'rate'=>1.2, 'speed'=>900,'ammo'=>5, 'reload'=>3.0,'spread'=>0.0, 'auto'=>false,'pellets'=>1],
    'shotgun'=>['name'=>'Shotgun',      'damage'=>15,'rate'=>0.6, 'speed'=>500,'ammo'=>8, 'reload'=>2.5,'spread'=>0.15,'auto'=>false,'pellets'=>6],
    'smg'   =>['name'=>'SMG',          'damage'=>14,'rate'=>0.07,'speed'=>550,'ammo'=>40,'reload'=>1.5,'spread'=>0.08,'auto'=>true, 'pellets'=>1],
    'pistol' =>['name'=>'Pistol',       'damage'=>30,'rate'=>0.35,'speed'=>580,'ammo'=>12,'reload'=>1.2,'spread'=>0.02,'auto'=>false,'pellets'=>1],
]);
define('BULLET_WIDTH', 8); define('BULLET_HEIGHT', 4); define('BULLET_LIFETIME', 3.0);

// ══════════════════════════════════════════════════════
//  GENİŞLETİLMİŞ MAP  (2400 → 4800 genişlik, 600 → 800 yükseklik)
//  Açık alan ortada (insan avantajı), dar tüneller kenarda (kurt avantajı)
// ══════════════════════════════════════════════════════
define('MAP_WIDTH',  4800);
define('MAP_HEIGHT', 800);
define('MAP_PLATFORMS', [
    // ── Zemin ─────────────────────────────────────────
    ['x'=>0,    'y'=>760, 'w'=>4800,'h'=>40],   // ana zemin

    // ── Sol bölge (RED spawn) ─────────────────────────
    ['x'=>100,  'y'=>620, 'w'=>220, 'h'=>20],
    ['x'=>360,  'y'=>520, 'w'=>180, 'h'=>20],
    ['x'=>560,  'y'=>420, 'w'=>160, 'h'=>20],
    ['x'=>200,  'y'=>380, 'w'=>140, 'h'=>20],
    ['x'=>450,  'y'=>290, 'w'=>130, 'h'=>20],
    ['x'=>700,  'y'=>460, 'w'=>200, 'h'=>20],
    ['x'=>820,  'y'=>340, 'w'=>150, 'h'=>20],
    ['x'=>100,  'y'=>230, 'w'=>200, 'h'=>20],   // yüksek platform
    // Sol dar tünel zemini
    ['x'=>0,    'y'=>560, 'w'=>90,  'h'=>20],   // duvar kenarı

    // ── Orta sol ──────────────────────────────────────
    ['x'=>1050, 'y'=>580, 'w'=>240, 'h'=>20],
    ['x'=>1180, 'y'=>460, 'w'=>200, 'h'=>20],
    ['x'=>1050, 'y'=>340, 'w'=>160, 'h'=>20],
    ['x'=>1300, 'y'=>260, 'w'=>180, 'h'=>20],
    ['x'=>1520, 'y'=>480, 'w'=>240, 'h'=>20],
    ['x'=>1600, 'y'=>360, 'w'=>160, 'h'=>20],
    ['x'=>1380, 'y'=>640, 'w'=>220, 'h'=>20],

    // ── Merkez (açık alan — insan avantajı) ───────────
    ['x'=>1900, 'y'=>680, 'w'=>1000,'h'=>20],   // geniş orta platform
    ['x'=>2050, 'y'=>560, 'w'=>700, 'h'=>20],
    ['x'=>2200, 'y'=>440, 'w'=>400, 'h'=>20],
    ['x'=>2300, 'y'=>320, 'w'=>200, 'h'=>20],   // tepe noktası
    ['x'=>1950, 'y'=>380, 'w'=>180, 'h'=>20],
    ['x'=>2650, 'y'=>380, 'w'=>180, 'h'=>20],

    // ── Orta sağ ──────────────────────────────────────
    ['x'=>3050, 'y'=>480, 'w'=>240, 'h'=>20],
    ['x'=>3080, 'y'=>360, 'w'=>160, 'h'=>20],
    ['x'=>3280, 'y'=>640, 'w'=>220, 'h'=>20],
    ['x'=>3220, 'y'=>260, 'w'=>180, 'h'=>20],
    ['x'=>3480, 'y'=>480, 'w'=>240, 'h'=>20],
    ['x'=>3600, 'y'=>360, 'w'=>160, 'h'=>20],
    ['x'=>3700, 'y'=>460, 'w'=>200, 'h'=>20],
    ['x'=>3820, 'y'=>340, 'w'=>150, 'h'=>20],

    // ── Sağ bölge (BLUE spawn) ────────────────────────
    ['x'=>3950, 'y'=>620, 'w'=>220, 'h'=>20],
    ['x'=>3980, 'y'=>420, 'w'=>160, 'h'=>20],
    ['x'=>4100, 'y'=>520, 'w'=>180, 'h'=>20],
    ['x'=>4200, 'y'=>380, 'w'=>140, 'h'=>20],
    ['x'=>4250, 'y'=>290, 'w'=>130, 'h'=>20],
    ['x'=>4500, 'y'=>230, 'w'=>200, 'h'=>20],   // yüksek platform
    // Sağ dar tünel
    ['x'=>4710, 'y'=>560, 'w'=>90,  'h'=>20],

    // ── Alt geçit tüneli (haritanın tabanında — kurt avantajı) ──
    ['x'=>600,  'y'=>740, 'w'=>160, 'h'=>20],   // sol tünel girişi
    ['x'=>900,  'y'=>740, 'w'=>120, 'h'=>20],
    ['x'=>1200, 'y'=>740, 'w'=>120, 'h'=>20],
    ['x'=>1500, 'y'=>740, 'w'=>120, 'h'=>20],
    ['x'=>3000, 'y'=>740, 'w'=>120, 'h'=>20],
    ['x'=>3300, 'y'=>740, 'w'=>120, 'h'=>20],
    ['x'=>3600, 'y'=>740, 'w'=>120, 'h'=>20],
    ['x'=>3900, 'y'=>740, 'w'=>160, 'h'=>20],   // sağ tünel çıkışı
]);

// ── CTF bayrak konumları (yeni map boyutuna göre) ──
define('FLAG_WIDTH', 24); define('FLAG_HEIGHT', 32);
define('FLAG_CAPTURE_DIST', 45);
define('CTF_SCORE_TO_WIN', 3);
define('CTF_FLAGS', [
    'red'  =>['x'=>80,   'y'=>712,'baseX'=>80,   'baseY'=>712],
    'blue' =>['x'=>4688, 'y'=>712,'baseX'=>4688, 'baseY'=>712],
]);

// Spawn noktaları config (Player.php'de kullanılır)
define('RED_SPAWNS',  [
    ['x'=>80,  'y'=>712],['x'=>160,'y'=>712],['x'=>240,'y'=>712],
    ['x'=>100, 'y'=>592],['x'=>200,'y'=>592],
]);
define('BLUE_SPAWNS', [
    ['x'=>4560,'y'=>712],['x'=>4640,'y'=>712],['x'=>4720,'y'=>712],
    ['x'=>4580,'y'=>592],['x'=>4660,'y'=>592],
]);

define('MAX_PLAYERS_PER_ROOM', 16); // 8v8
define('ROUNDS_TO_WIN', 5);
define('RESPAWN_TIME', 3.0);
define('ROUND_END_DELAY', 3.0);
define('MAX_SHOOT_RATE', 0.05);
define('EMOTES', ['dance'=>'💃','laugh'=>'😂','cry'=>'😭','rage'=>'😡','wave'=>'👋','salute'=>'🫡']);
define('EMOTE_DURATION', 3.0);
define('COMBO_THRESHOLDS', [3=>'triple',5=>'inferno',8=>'aura',10=>'legendary']);
define('COMBO_RESET_TIME', 8.0);
define('BP_MAX_LEVEL', 60); define('BP_XP_PER_LEVEL', 1000); define('BP_PREMIUM_COST', 999);
define('XP_KILL', 50); define('XP_WIN', 300); define('XP_ROUND', 20); define('XP_LOSS', 50);
define('TASKS_DEF', [
    ['id'=>'d_wins',  'name'=>'Win 3 Matches',      'type'=>'daily',  'goal'=>3,  'xp'=>200,'coins'=>50],
    ['id'=>'d_kills', 'name'=>'Get 10 Kills',        'type'=>'daily',  'goal'=>10, 'xp'=>150,'coins'=>30],
    ['id'=>'d_rounds','name'=>'Play 5 Rounds',       'type'=>'daily',  'goal'=>5,  'xp'=>100,'coins'=>20],
    ['id'=>'w_kills', 'name'=>'Get 50 Kills',         'type'=>'weekly', 'goal'=>50, 'xp'=>800,'coins'=>200],
    ['id'=>'w_wins',  'name'=>'Win 10 Matches',       'type'=>'weekly', 'goal'=>10, 'xp'=>1000,'coins'=>300],
    ['id'=>'w_wolf',  'name'=>'Kill Wolf 5 Times',    'type'=>'weekly', 'goal'=>5,  'xp'=>600,'coins'=>150],
    ['id'=>'w_ctf',   'name'=>'Capture Flag 3 Times', 'type'=>'weekly', 'goal'=>3,  'xp'=>700,'coins'=>180],
]);
define('SKINS_CATALOG', [
    ['key'=>'char_default', 'name'=>'Default Soldier','type'=>'character','rarity'=>'common',   'price'=>0,   'drop_w'=>5.0,'bp_lvl'=>0, 'bp_prem'=>0],
    ['key'=>'char_shadow',  'name'=>'Shadow Wolf',    'type'=>'character','rarity'=>'rare',     'price'=>300, 'drop_w'=>2.0,'bp_lvl'=>10,'bp_prem'=>0],
    ['key'=>'char_crimson', 'name'=>'Crimson Knight', 'type'=>'character','rarity'=>'epic',     'price'=>600, 'drop_w'=>0.8,'bp_lvl'=>20,'bp_prem'=>1],
    ['key'=>'char_neon',    'name'=>'Neon Ghost',     'type'=>'character','rarity'=>'legendary','price'=>1200,'drop_w'=>0.2,'bp_lvl'=>40,'bp_prem'=>1],
    ['key'=>'char_inferno', 'name'=>'Inferno Soldier','type'=>'character','rarity'=>'epic',     'price'=>500, 'drop_w'=>0.6,'bp_lvl'=>30,'bp_prem'=>0],
    ['key'=>'char_cyber',   'name'=>'Cyber Warrior',  'type'=>'character','rarity'=>'rare',     'price'=>250, 'drop_w'=>1.5,'bp_lvl'=>0, 'bp_prem'=>0],
    ['key'=>'wskin_golden', 'name'=>'Golden Rifle',   'type'=>'weapon',  'rarity'=>'epic',     'price'=>450, 'drop_w'=>0.9,'bp_lvl'=>25,'bp_prem'=>1],
    ['key'=>'wskin_dragon', 'name'=>'Dragon Sniper',  'type'=>'weapon',  'rarity'=>'rare',     'price'=>400, 'drop_w'=>1.5,'bp_lvl'=>15,'bp_prem'=>0],
    ['key'=>'wskin_neon',   'name'=>'Neon SMG',       'type'=>'weapon',  'rarity'=>'epic',     'price'=>600, 'drop_w'=>0.7,'bp_lvl'=>35,'bp_prem'=>1],
    ['key'=>'trail_fire',   'name'=>'Fire Trail',     'type'=>'trail',   'rarity'=>'rare',     'price'=>200, 'drop_w'=>2.0,'bp_lvl'=>5, 'bp_prem'=>0],
    ['key'=>'trail_elec',   'name'=>'Electric Trail', 'type'=>'trail',   'rarity'=>'epic',     'price'=>450, 'drop_w'=>0.7,'bp_lvl'=>35,'bp_prem'=>1],
    ['key'=>'trail_void',   'name'=>'Void Trail',     'type'=>'trail',   'rarity'=>'legendary','price'=>900, 'drop_w'=>0.15,'bp_lvl'=>50,'bp_prem'=>1],
    ['key'=>'kill_boom',    'name'=>'Explosion Kill', 'type'=>'kill_fx', 'rarity'=>'rare',     'price'=>300, 'drop_w'=>1.8,'bp_lvl'=>0, 'bp_prem'=>0],
    ['key'=>'kill_lightning','name'=>'Lightning Kill','type'=>'kill_fx', 'rarity'=>'epic',     'price'=>500, 'drop_w'=>0.6,'bp_lvl'=>45,'bp_prem'=>1],
    ['key'=>'kill_galaxy',  'name'=>'Galaxy Kill',    'type'=>'kill_fx', 'rarity'=>'legendary','price'=>1000,'drop_w'=>0.1,'bp_lvl'=>55,'bp_prem'=>1],
    ['key'=>'entry_flame',  'name'=>'Flame Entry',   'type'=>'entry',   'rarity'=>'rare',     'price'=>250, 'drop_w'=>2.0,'bp_lvl'=>0, 'bp_prem'=>0],
    ['key'=>'entry_tele',   'name'=>'Teleport Entry','type'=>'entry',   'rarity'=>'epic',     'price'=>480, 'drop_w'=>0.7,'bp_lvl'=>20,'bp_prem'=>1],
    ['key'=>'entry_meteor', 'name'=>'Meteor Drop',   'type'=>'entry',   'rarity'=>'legendary','price'=>1100,'drop_w'=>0.12,'bp_lvl'=>60,'bp_prem'=>1],
]);
// ── Map & Wall Mekanik Sabitleri ──
define('WOLF_WALL_GRIP_TIME',   0.8);   // Kurt duvar tutunma süresi (sn) — kısa süreli
define('WOLF_WALLJUMP_CHAIN_MAX', 10);  // Bir zincirde max walljump sayısı
define('WOLF_WALL_CLIMB_SPEED', 280);   // Kurt duvar tırnanma hızı (px/s)
define('HUMAN_WALL_CLIMB_SPEED', 180);  // İnsan duvar tırnanma hızı (px/s)
define('MAPS_DIR', __DIR__ . '/../maps'); // Otomatik tarama klasörü

define('COIN_PACKAGES', [
    ['id'=>1,'coins'=>500,  'price_usd'=>9.99, 'price_try'=>299, 'label'=>'Starter'],
    ['id'=>2,'coins'=>1200, 'price_usd'=>19.99,'price_try'=>599, 'label'=>'Value'],
    ['id'=>3,'coins'=>2800, 'price_usd'=>39.99,'price_try'=>1199,'label'=>'Pro'],
    ['id'=>4,'coins'=>6500, 'price_usd'=>79.99,'price_try'=>2399,'label'=>'Elite'],
    ['id'=>5,'coins'=>15000,'price_usd'=>149.99,'price_try'=>4499,'label'=>'Legend'],
]);
