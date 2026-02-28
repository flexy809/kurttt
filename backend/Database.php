<?php
namespace Game;
class Database {
    private static ?\PDO $db = null;
    public static function get(): \PDO {
        if (self::$db) return self::$db;
        $dir = dirname(DB_PATH); if (!is_dir($dir)) mkdir($dir, 0755, true);
        self::$db = new \PDO('sqlite:'.DB_PATH);
        self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::$db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::$db->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        self::migrate(); return self::$db;
    }
    private static function migrate(): void {
        $db = self::$db;
        $db->exec("
            CREATE TABLE IF NOT EXISTS accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL COLLATE NOCASE,
                password TEXT NOT NULL,
                created_at INTEGER DEFAULT (strftime('%s','now')),
                last_login INTEGER, is_banned INTEGER DEFAULT 0,
                kills INTEGER DEFAULT 0, deaths INTEGER DEFAULT 0,
                wins INTEGER DEFAULT 0,  losses INTEGER DEFAULT 0,
                wolf_kills INTEGER DEFAULT 0, coins INTEGER DEFAULT 0,
                xp INTEGER DEFAULT 0, bp_level INTEGER DEFAULT 1, bp_premium INTEGER DEFAULT 0,
                elo INTEGER DEFAULT 1000, rank_tier TEXT DEFAULT 'Bronze',
                equip_char TEXT DEFAULT 'char_default', equip_weapon TEXT DEFAULT 'rifle',
                equip_trail TEXT DEFAULT '', equip_kill_fx TEXT DEFAULT '', equip_entry TEXT DEFAULT '',
                is_admin INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS friends (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES accounts(id),
                friend_id INTEGER NOT NULL REFERENCES accounts(id),
                status TEXT DEFAULT 'pending',
                created_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(user_id, friend_id)
            );
            CREATE TABLE IF NOT EXISTS inventory (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER REFERENCES accounts(id),
                skin_key TEXT NOT NULL,
                obtained TEXT DEFAULT 'purchase',
                created_at INTEGER DEFAULT (strftime('%s','now')),
                UNIQUE(account_id, skin_key)
            );
            CREATE TABLE IF NOT EXISTS crates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL, coin_price INTEGER DEFAULT 200, active INTEGER DEFAULT 1
            );
            CREATE TABLE IF NOT EXISTS crate_contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                crate_id INTEGER REFERENCES crates(id),
                skin_key TEXT NOT NULL, weight REAL DEFAULT 1.0
            );
            CREATE TABLE IF NOT EXISTS task_progress (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER REFERENCES accounts(id),
                task_id TEXT NOT NULL,
                progress INTEGER DEFAULT 0, completed INTEGER DEFAULT 0, reset_at INTEGER,
                UNIQUE(account_id, task_id)
            );
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER, username TEXT, coins INTEGER DEFAULT 0,
                amount REAL DEFAULT 0, provider TEXT DEFAULT 'admin',
                status TEXT DEFAULT 'success', ref_id TEXT,
                created_at INTEGER DEFAULT (strftime('%s','now'))
            );
            CREATE INDEX IF NOT EXISTS idx_f_u ON friends(user_id);
            CREATE INDEX IF NOT EXISTS idx_f_f ON friends(friend_id);
            CREATE INDEX IF NOT EXISTS idx_tp  ON task_progress(account_id);
            CREATE INDEX IF NOT EXISTS idx_inv ON inventory(account_id);
        ");
        // Migration: add is_admin column if missing
        try { $db->exec("ALTER TABLE accounts ADD COLUMN is_admin INTEGER DEFAULT 0"); } catch(\Exception $e) {}
        // Seed crate if empty
        if (self::scalar("SELECT COUNT(*) FROM crates") == 0) {
            $db->exec("INSERT INTO crates (name,coin_price) VALUES ('Standard Crate',200)");
            $cId = $db->lastInsertId();
            $s = $db->prepare("INSERT INTO crate_contents (crate_id,skin_key,weight) VALUES (?,?,?)");
            foreach (SKINS_CATALOG as $sk) $s->execute([$cId, $sk['key'], $sk['drop_w']]);
        }
    }
    private static function scalar(string $q): mixed { return self::$db->query($q)->fetchColumn(); }

    // ── Auth ──────────────────────────────────────────────────
    public static function register(string $u, string $pw): array {
        if (strlen($u)<3||strlen($u)>20) return ['ok'=>false,'msg'=>'Username 3-20 chars'];
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $u)) return ['ok'=>false,'msg'=>'Letters/numbers/_ only'];
        if (strlen($pw)<6) return ['ok'=>false,'msg'=>'Password min 6 chars'];
        try {
            self::get()->prepare("INSERT INTO accounts (username,password) VALUES (?,?)")->execute([$u,password_hash($pw,PASSWORD_BCRYPT)]);
            return ['ok'=>true,'id'=>(int)self::get()->lastInsertId(),'username'=>$u];
        } catch (\PDOException $e) { return ['ok'=>false,'msg'=>'Username already taken']; }
    }
    public static function login(string $u, string $pw): array {
        $st=self::get()->prepare("SELECT * FROM accounts WHERE username=? COLLATE NOCASE");
        $st->execute([$u]); $row=$st->fetch();
        if (!$row||!password_verify($pw,$row['password'])) return ['ok'=>false,'msg'=>'Invalid credentials'];
        if ($row['is_banned']) return ['ok'=>false,'msg'=>'Account is banned'];
        self::get()->prepare("UPDATE accounts SET last_login=strftime('%s','now') WHERE id=?")->execute([$row['id']]);
        unset($row['password']); return ['ok'=>true,'account'=>$row];
    }

    // ── Stats / XP / BP ───────────────────────────────────────
    public static function updateStats(int $id, int $kills, int $deaths, bool $won, int $wolfKills=0, int $rounds=0): void {
        $xp = $kills*XP_KILL + ($won?XP_WIN:XP_LOSS) + $rounds*XP_ROUND;
        self::get()->prepare("UPDATE accounts SET kills=kills+?,deaths=deaths+?,wins=wins+?,losses=losses+?,wolf_kills=wolf_kills+?,xp=xp+? WHERE id=?")
            ->execute([$kills,$deaths,$won?1:0,$won?0:1,$wolfKills,$xp,$id]);
        // BP level-up check
        $row = self::get()->prepare("SELECT xp,bp_level FROM accounts WHERE id=?");
        $row->execute([$id]); $a=$row->fetch();
        if ($a) { $nl = min(BP_MAX_LEVEL, (int)floor($a['xp']/BP_XP_PER_LEVEL)+1); if ($nl>$a['bp_level']) self::get()->prepare("UPDATE accounts SET bp_level=? WHERE id=?")->execute([$nl,$id]); }
    }

    // ── Inventory ─────────────────────────────────────────────
    public static function hasSkin(int $accId, string $key): bool {
        return (bool)self::get()->prepare("SELECT COUNT(*) FROM inventory WHERE account_id=? AND skin_key=?")->execute([$accId,$key]) &&
            (int)self::get()->prepare("SELECT COUNT(*) FROM inventory WHERE account_id=? AND skin_key=?")->execute([$accId,$key]) > 0;
    }
    public static function give(int $accId, string $key, string $how='purchase'): bool {
        // Proper hasSkin
        $st=self::get()->prepare("SELECT COUNT(*) as c FROM inventory WHERE account_id=? AND skin_key=?"); $st->execute([$accId,$key]); if($st->fetch()['c']>0) return false;
        self::get()->prepare("INSERT OR IGNORE INTO inventory (account_id,skin_key,obtained) VALUES (?,?,?)")->execute([$accId,$key,$how]);
        return true;
    }
    public static function getInventory(int $accId): array {
        $st=self::get()->prepare("SELECT skin_key,obtained FROM inventory WHERE account_id=?");
        $st->execute([$accId]); return $st->fetchAll();
    }

    // ── Crates ────────────────────────────────────────────────
    public static function openCrate(int $accId, int $crateId=1): array {
        $cr=self::get()->prepare("SELECT * FROM crates WHERE id=? AND active=1"); $cr->execute([$crateId]); $c=$cr->fetch();
        if (!$c) return ['ok'=>false,'msg'=>'Crate not found'];
        $bal=self::get()->prepare("SELECT coins FROM accounts WHERE id=?"); $bal->execute([$accId]); $a=$bal->fetch();
        if ($a['coins'] < $c['coin_price']) return ['ok'=>false,'msg'=>'Not enough coins'];
        // Build pool with duplicate protection
        $pool=self::get()->prepare("SELECT skin_key,weight FROM crate_contents WHERE crate_id=?"); $pool->execute([$crateId]); $all=$pool->fetchAll();
        $owned=array_column(self::getInventory($accId),'skin_key');
        $avail=array_values(array_filter($all,fn($p)=>!in_array($p['skin_key'],$owned)));
        if (empty($avail)) { // all owned → refund 50%
            $ref=(int)($c['coin_price']*0.5);
            self::get()->prepare("UPDATE accounts SET coins=coins+? WHERE id=?")->execute([$ref,$accId]);
            return ['ok'=>true,'refund'=>true,'refund_coins'=>$ref];
        }
        // Weighted random
        $totalW=array_sum(array_column($avail,'weight'));
        $r=mt_rand(0,(int)($totalW*1000))/1000; $cum=0; $picked=null;
        foreach($avail as $item){$cum+=$item['weight'];if($r<=$cum){$picked=$item;break;}}
        $picked=$picked??$avail[array_rand($avail)];
        // Deduct & grant
        self::get()->prepare("UPDATE accounts SET coins=coins-? WHERE id=?")->execute([$c['coin_price'],$accId]);
        self::give($accId,$picked['skin_key'],'crate');
        // Find catalog info
        $meta=null; foreach(SKINS_CATALOG as $s){ if($s['key']===$picked['skin_key']){$meta=$s;break;} }
        return ['ok'=>true,'refund'=>false,'item'=>array_merge($picked,['name'=>$meta['name']??'','rarity'=>$meta['rarity']??'common'])];
    }

    // ── Tasks ─────────────────────────────────────────────────
    public static function getTaskProgress(int $accId): array {
        $now=time(); $dayReset=strtotime('tomorrow'); $weekReset=strtotime('next monday');
        // auto-reset expired
        self::get()->prepare("UPDATE task_progress SET progress=0,completed=0 WHERE account_id=? AND reset_at<=? AND task_id LIKE 'd_%'")->execute([$accId,$now]);
        self::get()->prepare("UPDATE task_progress SET progress=0,completed=0 WHERE account_id=? AND reset_at<=? AND task_id LIKE 'w_%'")->execute([$accId,$now]);
        $rows=[]; foreach(TASKS_DEF as $t){
            $st=self::get()->prepare("SELECT progress,completed FROM task_progress WHERE account_id=? AND task_id=?"); $st->execute([$accId,$t['id']]); $prog=$st->fetch();
            $rows[]=array_merge($t,['progress'=>$prog['progress']??0,'completed'=>$prog['completed']??0]);
        }
        return $rows;
    }
    public static function addTaskProgress(int $accId, string $event, int $amt=1): array {
        $rewards=[]; $dayReset=strtotime('tomorrow'); $weekReset=strtotime('next monday');
        $map=['kill'=>['d_kills','w_kills'],'win'=>['d_wins','w_wins'],'round'=>['d_rounds'],'wolf_kill'=>['w_wolf'],'flag_cap'=>['w_ctf']];
        $relevant=$map[$event]??[];
        foreach(TASKS_DEF as $t){
            if(!in_array($t['id'],$relevant)) continue;
            $st=self::get()->prepare("SELECT progress,completed FROM task_progress WHERE account_id=? AND task_id=?"); $st->execute([$accId,$t['id']]); $prog=$st->fetch();
            if(!$prog){ $rs=$t['type']==='daily'?$dayReset:$weekReset; self::get()->prepare("INSERT INTO task_progress (account_id,task_id,progress,completed,reset_at) VALUES (?,?,0,0,?)")->execute([$accId,$t['id'],$rs]); $prog=['progress'=>0,'completed'=>0]; }
            if($prog['completed']) continue;
            $np=min($t['goal'],$prog['progress']+$amt); $done=$np>=$t['goal']?1:0;
            self::get()->prepare("UPDATE task_progress SET progress=?,completed=? WHERE account_id=? AND task_id=?")->execute([$np,$done,$accId,$t['id']]);
            if($done&&!$prog['completed']){
                self::get()->prepare("UPDATE accounts SET coins=coins+?,xp=xp+? WHERE id=?")->execute([$t['coins'],$t['xp'],$accId]);
                $rewards[]=['id'=>$t['id'],'name'=>$t['name'],'xp'=>$t['xp'],'coins'=>$t['coins']];
            }
        }
        return $rewards;
    }

    // ── Battle Pass ───────────────────────────────────────────
    public static function getBPData(int $accId): array {
        $st=self::get()->prepare("SELECT xp,bp_level,bp_premium,coins FROM accounts WHERE id=?"); $st->execute([$accId]); $a=$st->fetch();
        $inv=array_column(self::getInventory($accId),'skin_key'); $items=[];
        foreach(SKINS_CATALOG as $s){
            if(!$s['bp_lvl']) continue;
            $unlocked=$a['bp_level']>=$s['bp_lvl']&&(!$s['bp_prem']||$a['bp_premium']);
            if($unlocked&&!in_array($s['key'],$inv)){ self::give($accId,$s['key'],'battlepass'); $inv[]=$s['key']; }
            $items[]=['key'=>$s['key'],'name'=>$s['name'],'rarity'=>$s['rarity'],'bp_level'=>$s['bp_lvl'],'premium'=>$s['bp_prem'],'owned'=>in_array($s['key'],$inv),'unlocked'=>$unlocked];
        }
        return ['level'=>(int)$a['bp_level'],'xp'=>(int)$a['xp'],'premium'=>(bool)$a['bp_premium'],'coins'=>(int)$a['coins'],'items'=>$items,'xp_per_level'=>BP_XP_PER_LEVEL,'max_level'=>BP_MAX_LEVEL];
    }
    public static function buyBPPremium(int $accId): array {
        $st=self::get()->prepare("SELECT coins,bp_premium FROM accounts WHERE id=?"); $st->execute([$accId]); $a=$st->fetch();
        if ($a['bp_premium']) return ['ok'=>false,'msg'=>'Already premium'];
        if ($a['coins']<BP_PREMIUM_COST) return ['ok'=>false,'msg'=>'Not enough coins'];
        self::get()->prepare("UPDATE accounts SET bp_premium=1,coins=coins-? WHERE id=?")->execute([BP_PREMIUM_COST,$accId]);
        self::getBPData($accId); // grant retroactive rewards
        return ['ok'=>true];
    }

    // ── Equip ─────────────────────────────────────────────────
    public static function equipSkin(int $accId, string $key, string $slot): array {
        if($key!==''){ $st=self::get()->prepare("SELECT COUNT(*) as c FROM inventory WHERE account_id=? AND skin_key=?"); $st->execute([$accId,$key]); if(!$st->fetch()['c']) return ['ok'=>false,'msg'=>'Not owned']; }
        $col=match($slot){'character'=>'equip_char','weapon'=>'equip_weapon','trail'=>'equip_trail','kill_fx'=>'equip_kill_fx','entry'=>'equip_entry',default=>''};
        if(!$col) return ['ok'=>false,'msg'=>'Invalid slot'];
        self::get()->prepare("UPDATE accounts SET $col=? WHERE id=?")->execute([$key,$accId]);
        return ['ok'=>true];
    }
    public static function getEquips(int $accId): array {
        $st=self::get()->prepare("SELECT equip_char,equip_weapon,equip_trail,equip_kill_fx,equip_entry FROM accounts WHERE id=?"); $st->execute([$accId]); return $st->fetch()?:[];
    }

    // ── Friends ───────────────────────────────────────────────
    public static function sendFriendReq(int $from, string $toUser): array {
        $st=self::get()->prepare("SELECT id FROM accounts WHERE username=? COLLATE NOCASE"); $st->execute([$toUser]); $t=$st->fetch();
        if(!$t) return ['ok'=>false,'msg'=>'User not found'];
        if($t['id']===$from) return ['ok'=>false,'msg'=>'Cannot add yourself'];
        $ex=self::get()->prepare("SELECT status FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)"); $ex->execute([$from,$t['id'],$t['id'],$from]); $e=$ex->fetch();
        if($e){if($e['status']==='accepted')return['ok'=>false,'msg'=>'Already friends'];return['ok'=>false,'msg'=>'Request already sent'];}
        self::get()->prepare("INSERT OR REPLACE INTO friends (user_id,friend_id,status) VALUES (?,?,'pending')")->execute([$from,$t['id']]);
        return ['ok'=>true,'target_id'=>$t['id'],'target_name'=>$toUser];
    }
    public static function acceptFriend(int $userId, int $fromId): array {
        $st=self::get()->prepare("UPDATE friends SET status='accepted' WHERE user_id=? AND friend_id=? AND status='pending'"); $st->execute([$fromId,$userId]);
        if($st->rowCount()===0) return ['ok'=>false,'msg'=>'No pending request'];
        self::get()->prepare("INSERT OR IGNORE INTO friends (user_id,friend_id,status) VALUES (?,?,'accepted')")->execute([$userId,$fromId]);
        return ['ok'=>true];
    }
    public static function removeFriend(int $a, int $b): void { self::get()->prepare("DELETE FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)")->execute([$a,$b,$b,$a]); }
    public static function getFriends(int $id): array { $st=self::get()->prepare("SELECT a.id,a.username FROM friends f JOIN accounts a ON a.id=CASE WHEN f.user_id=? THEN f.friend_id ELSE f.user_id END WHERE (f.user_id=? OR f.friend_id=?) AND f.status='accepted'"); $st->execute([$id,$id,$id]); return $st->fetchAll(); }
    // ── Admin ─────────────────────────────────────────────────
    public static function isAdmin(int $id): bool {
        $st=self::get()->prepare("SELECT is_admin FROM accounts WHERE id=?"); $st->execute([$id]); $r=$st->fetch();
        return $r && (bool)$r['is_admin'];
    }
    public static function setAdmin(int $id, bool $val): void {
        self::get()->prepare("UPDATE accounts SET is_admin=? WHERE id=?")->execute([$val?1:0,$id]);
    }
    public static function banPlayer(int $id, bool $ban): void {
        self::get()->prepare("UPDATE accounts SET is_banned=? WHERE id=?")->execute([$ban?1:0,$id]);
    }
    public static function giveCoins(int $id, int $coins): void {
        self::get()->prepare("UPDATE accounts SET coins=coins+? WHERE id=?")->execute([$coins,$id]);
    }
    public static function setCoins(int $id, int $coins): void {
        self::get()->prepare("UPDATE accounts SET coins=? WHERE id=?")->execute([$coins,$id]);
    }
    public static function resetStats(int $id): void {
        self::get()->prepare("UPDATE accounts SET kills=0,deaths=0,wins=0,losses=0,wolf_kills=0,xp=0,bp_level=1,elo=1000,rank_tier='Bronze' WHERE id=?")->execute([$id]);
    }
    public static function getPlayers(int $limit=100, int $offset=0, string $search=''): array {
        if ($search) {
            $st=self::get()->prepare("SELECT id,username,kills,deaths,wins,losses,coins,xp,bp_level,elo,rank_tier,is_banned,is_admin,created_at,last_login FROM accounts WHERE username LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?");
            $st->execute(['%'.$search.'%',$limit,$offset]);
        } else {
            $st=self::get()->prepare("SELECT id,username,kills,deaths,wins,losses,coins,xp,bp_level,elo,rank_tier,is_banned,is_admin,created_at,last_login FROM accounts ORDER BY id DESC LIMIT ? OFFSET ?");
            $st->execute([$limit,$offset]);
        }
        return $st->fetchAll();
    }
    public static function getPlayerCount(): int {
        return (int)self::scalar("SELECT COUNT(*) FROM accounts");
    }
    public static function getServerStats(): array {
        return [
            'total_players' => (int)self::scalar("SELECT COUNT(*) FROM accounts"),
            'banned'        => (int)self::scalar("SELECT COUNT(*) FROM accounts WHERE is_banned=1"),
            'total_kills'   => (int)self::scalar("SELECT SUM(kills) FROM accounts"),
            'total_wins'    => (int)self::scalar("SELECT SUM(wins) FROM accounts"),
            'total_coins'   => (int)self::scalar("SELECT SUM(coins) FROM accounts"),
            'total_xp'      => (int)self::scalar("SELECT SUM(xp) FROM accounts"),
        ];
    }
    public static function deleteAccount(int $id): void {
        self::get()->prepare("DELETE FROM inventory WHERE account_id=?")->execute([$id]);
        self::get()->prepare("DELETE FROM task_progress WHERE account_id=?")->execute([$id]);
        self::get()->prepare("DELETE FROM friends WHERE user_id=? OR friend_id=?")->execute([$id,$id]);
        self::get()->prepare("DELETE FROM accounts WHERE id=?")->execute([$id]);
    }
}
