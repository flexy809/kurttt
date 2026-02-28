<?php
namespace Game;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Game\MapManager;

class GameServer implements MessageComponentInterface {
    private array $conns=[],$players=[],$rooms=[],$onlineMap=[];
    private GameLoop $gl;
    public function __construct(){ echo "[WolfTeam2D v3] Server ready\n"; }
    public function setGameLoop(GameLoop $gl): void { $this->gl=$gl; }

    public function onOpen(ConnectionInterface $c): void { $this->conns[$c->resourceId]=['conn'=>$c,'pid'=>null,'rid'=>null,'accId'=>null]; }

    public function onMessage(ConnectionInterface $c, $msg): void {
        $d=json_decode($msg,true); if(!is_array($d)) return;
        switch($d['type']??''){
            case 'register':      $this->doRegister($c,$d); break;
            case 'login':         $this->doLogin($c,$d); break;
            case 'guest':         $this->doGuest($c,$d); break;
            case 'create_room':   $this->doCreateRoom($c,$d); break;
            case 'join_room':     $this->doJoinRoom($c,$d); break;
            case 'join_private':  $this->doJoinPrivate($c,$d); break;
            case 'leave_room':    $this->doLeaveRoom($c); break;
            case 'select_team':   $this->doTeam($c,$d); break;
            case 'ready':         $this->doReady($c,$d); break;
            case 'start_game':    $this->doStart($c); break;
            case 'input':         $this->doInput($c,$d); break;
            case 'get_rooms':     $this->sendRooms($c); break;
            case 'open_crate':    $this->doCrate($c,$d); break;
            case 'equip':         $this->doEquip($c,$d); break;
            case 'get_inventory': $this->doGetInv($c); break;
            case 'get_tasks':     $this->doGetTasks($c); break;
            case 'get_bp':        $this->doGetBP($c); break;
            case 'buy_bp':        $this->doBuyBP($c); break;
            case 'friend_add':    $this->doFriendAdd($c,$d); break;
            case 'friend_accept': $this->doFriendAccept($c,$d); break;
            case 'friend_remove': $this->doFriendRemove($c,$d); break;
            case 'friend_list':   $this->doFriendList($c); break;
            case 'friend_invite':   $this->doFriendInvite($c,$d); break;
            case 'get_maps':       $this->doGetMaps($c,$d); break;
            case 'get_map_pool':   $this->doGetMapPool($c,$d); break;
            case 'set_room_map':   $this->doSetRoomMap($c,$d); break;
            // Admin
            case 'admin_login':       $this->doAdminLogin($c,$d); break;
            case 'admin_get_stats':   $this->doAdminGetStats($c); break;
            case 'admin_get_players': $this->doAdminGetPlayers($c,$d); break;
            case 'admin_ban':         $this->doAdminBan($c,$d); break;
            case 'admin_give_coins':  $this->doAdminGiveCoins($c,$d); break;
            case 'admin_set_coins':   $this->doAdminSetCoins($c,$d); break;
            case 'admin_reset_stats': $this->doAdminResetStats($c,$d); break;
            case 'admin_delete_acc':  $this->doAdminDeleteAcc($c,$d); break;
            case 'admin_set_admin':   $this->doAdminSetAdmin($c,$d); break;
            case 'admin_get_rooms':   $this->doAdminGetRooms($c); break;
            case 'admin_kick_player': $this->doAdminKick($c,$d); break;
            case 'admin_close_room':  $this->doAdminCloseRoom($c,$d); break;
            case 'admin_get_maps':    $this->doAdminGetMaps($c,$d); break;
            case 'admin_save_map':    $this->doAdminSaveMap($c,$d); break;
            case 'admin_reload_maps': $this->doAdminReloadMaps($c); break;
            case 'admin_broadcast':   $this->doAdminBroadcast($c,$d); break;
        }
    }

    // ── Auth ──────────────────────────────────────────────────
    private function doRegister(ConnectionInterface $c,array $d): void { $r=Database::register($d['username']??'',$d['password']??''); if(!$r['ok']){$this->err($c,$r['msg']);return;} $c->send(json_encode(['type'=>'registered','username'=>$r['username']])); }
    private function doLogin(ConnectionInterface $c,array $d): void { $r=Database::login($d['username']??'',$d['password']??''); if(!$r['ok']){$this->err($c,$r['msg']);return;} $this->completeJoin($c,$r['account']['username'],(int)$r['account']['id'],$r['account']); }
    private function doGuest(ConnectionInterface $c,array $d): void { $n=trim($d['name']??''); if(strlen($n)<1||strlen($n)>20){$this->err($c,'Name 1-20 chars');return;} $this->completeJoin($c,htmlspecialchars($n,ENT_QUOTES,'UTF-8').'[G]',null,null); }

    private function completeJoin(ConnectionInterface $c,string $name,?int $accId,?array $acc): void {
        $rid=$c->resourceId; $pid=uniqid('p_',true);
        $p=new Player($pid,$name,$c,$accId);
        if($accId){ $eq=Database::getEquips($accId); if($eq) $p->applyEquips($eq); }
        $this->players[$pid]=$p; $this->conns[$rid]['pid']=$pid; $this->conns[$rid]['accId']=$accId;
        if($accId) $this->onlineMap[$accId]=$pid;
        $friends=$accId?Database::getFriends($accId):[];
       // $pending=$accId?Database::getPending($accId):[];
        foreach($friends as $f){ $fp=$this->getPlayerByAcc((int)$f['id']); if($fp) $fp->conn->send(json_encode(['type'=>'friend_online','id'=>$accId,'name'=>$name])); }
        $c->send(json_encode(['type'=>'joined_server','playerId'=>$pid,'name'=>$name,'accountId'=>$accId,'account'=>$acc,'friends'=>$friends,'pending'=>$pending]));
        $this->sendRooms($c);
    }

    // ── Rooms ──────────────────────────────────────────────────
    private function doCreateRoom(ConnectionInterface $c,array $d): void {
        [$pid,$room]=$this->pr($c); if(!$pid){$this->err($c,'Not connected');return;}
        if($this->conns[$c->resourceId]['rid']){$this->err($c,'Already in a room');return;}
        $name=trim(htmlspecialchars($d['room_name']??'',ENT_QUOTES,'UTF-8'));
        $mode=in_array($d['mode']??'tdm',['tdm','ctf','wolf'])?($d['mode']):('tdm');
        $priv=!empty($d['private']);
        if(strlen($name)<1||strlen($name)>30){$this->err($c,'Name 1-30 chars');return;}
        $mapId = trim((string)($d['map_id'] ?? ''));
        $rid=uniqid('r_'); $r=new Room($rid,$name,$pid,$mode,$priv,$mapId);
        $this->rooms[$rid]=$r; $r->addPlayer($this->players[$pid]);
        $this->conns[$c->resourceId]['rid']=$rid; $this->gl->startRoomLoop($rid);
        $c->send(json_encode(['type'=>'room_created','room'=>$r->getLobbyInfo(),
            'platforms'=>$r->getPlatforms(),'map_id'=>$r->getMapId(),'map_data'=>$r->getMapData()]));
        $this->broadcastRooms();
    }
    private function doJoinRoom(ConnectionInterface $c,array $d): void {
        $room=$this->rooms[$d['room_id']??'']??null;
        if(!$room){$this->err($c,'Room not found');return;}
        if($room->isPrivate){$this->err($c,'Private room, use invite code');return;}
        if($room->isFull()){$this->err($c,'Room full');return;}
        if($room->state!=='lobby'){$this->err($c,'Game in progress');return;}
        $this->joinRoom($c,$room);
    }
    private function doJoinPrivate(ConnectionInterface $c,array $d): void {
        $code=strtoupper(trim($d['code']??'')); $room=null;
        foreach($this->rooms as $r){if($r->inviteCode===$code){$room=$r;break;}}
        if(!$room){$this->err($c,'Invalid code');return;}
        if($room->isFull()){$this->err($c,'Room full');return;}
        if($room->state!=='lobby'){$this->err($c,'Game in progress');return;}
        $this->joinRoom($c,$room);
    }
    private function joinRoom(ConnectionInterface $c, Room $room): void {
        $rid=$c->resourceId; $pid=$this->conns[$rid]['pid'];
        $room->addPlayer($this->players[$pid]); $this->conns[$rid]['rid']=$room->id;
        $c->send(json_encode(['type'=>'room_joined','room'=>$room->getLobbyInfo(),
            'platforms'=>$room->getPlatforms(),'map_id'=>$room->getMapId(),'map_data'=>$room->getMapData()]));
        $room->broadcast(['type'=>'player_joined_room','room'=>$room->getLobbyInfo()]);
        $this->broadcastRooms();
    }
    private function doLeaveRoom(ConnectionInterface $c): void {
        $rid=$c->resourceId; $pid=$this->conns[$rid]['pid']??null; $roomId=$this->conns[$rid]['rid']??null;
        if(!$pid||!$roomId) return; $room=$this->rooms[$roomId]??null; if(!$room) return;
        $room->removePlayer($pid); $this->conns[$rid]['rid']=null;
        $c->send(json_encode(['type'=>'room_left']));
        if($room->isEmpty()){$this->gl->stopRoomLoop($roomId);unset($this->rooms[$roomId]);}
        else $room->broadcast(['type'=>'player_left_room','room'=>$room->getLobbyInfo()]);
        $this->broadcastRooms();
    }
    private function doTeam(ConnectionInterface $c,array $d): void {
        [$pid,$room]=$this->pr($c); if(!$room||$room->state!=='lobby') return;
        $team=$d['team']??''; if(!in_array($team,['red','blue',''])) return;
        $p=$room->getPlayer($pid); if($p){$p->team=$team;$p->ready=false;}
        $room->broadcast(['type'=>'team_selected','room'=>$room->getLobbyInfo()]);
    }
    private function doReady(ConnectionInterface $c,array $d): void {
        [$pid,$room]=$this->pr($c); if(!$room||$room->state!=='lobby') return;
        $p=$room->getPlayer($pid); if(!$p||$p->team===''){$this->err($c,'Select a team first');return;}
        $p->ready=(bool)($d['ready']??true);
        $room->broadcast(['type'=>'ready_update','room'=>$room->getLobbyInfo()]);
        if($pid===$room->hostId&&$room->canStart()) $room->startGame();
    }
    private function doStart(ConnectionInterface $c): void {
        [$pid,$room]=$this->pr($c); if(!$room){return;}
        if($pid!==$room->hostId){$this->err($c,'Only host can start');return;}
        if(!$room->canStart()){$this->err($c,'Need at least 1 player per team, all ready');return;}
        if($room->state!=='lobby') return;
        $room->startGame(); $this->broadcastRooms();
    }
    private function doInput(ConnectionInterface $c,array $d): void { [$pid,$room]=$this->pr($c); if($room) $room->handleInput($pid,$d); }

    // ── Cosmetics / Inventory ─────────────────────────────────
    private function doCrate(ConnectionInterface $c,array $d): void {
        $accId=$this->conns[$c->resourceId]['accId']??null;
        if(!$accId){$this->err($c,'Login required');return;}
        $r=Database::openCrate($accId,(int)($d['crate_id']??1));
        if(!$r['ok']){$this->err($c,$r['msg']);return;}
        $c->send(json_encode(['type'=>'crate_opened']+$r));
    }
    private function doEquip(ConnectionInterface $c,array $d): void {
        $accId=$this->conns[$c->resourceId]['accId']??null; $pid=$this->conns[$c->resourceId]['pid']??null;
        if(!$accId||!$pid){$this->err($c,'Login required');return;}
        $key=$d['skin_key']??''; $slot=$d['slot']??'character';
        $r=Database::equipSkin($accId,$key,$slot);
        if(!$r['ok']){$this->err($c,$r['msg']);return;}
        // Apply to live player
        $player=$this->players[$pid]??null; if($player){
            match($slot){'character'=>($player->skinChar=$key),'trail'=>($player->skinTrail=$key),'kill_fx'=>($player->skinKillFx=$key),'entry'=>($player->skinEntry=$key),'weapon'=>($player->loadWeapon(str_replace('wskin_','',$key))),default=>null};
        }
        $c->send(json_encode(['type'=>'equip_ok','slot'=>$slot,'key'=>$key]));
    }
    private function doGetInv(ConnectionInterface $c): void {
        $accId=$this->conns[$c->resourceId]['accId']??null;
        $inv=$accId?Database::getInventory($accId):[];
        $c->send(json_encode(['type'=>'inventory','items'=>$inv]));
    }
    private function doGetTasks(ConnectionInterface $c): void {
        $accId=$this->conns[$c->resourceId]['accId']??null;
        $c->send(json_encode(['type'=>'tasks','tasks'=>$accId?Database::getTaskProgress($accId):[]]));
    }
    private function doGetBP(ConnectionInterface $c): void {
        $accId=$this->conns[$c->resourceId]['accId']??null;
        if(!$accId){$this->err($c,'Login required');return;}
        $c->send(json_encode(['type'=>'bp_data']+Database::getBPData($accId)));
    }
    private function doBuyBP(ConnectionInterface $c): void {
        $accId=$this->conns[$c->resourceId]['accId']??null;
        if(!$accId){$this->err($c,'Login required');return;}
        $r=Database::buyBPPremium($accId);
        if($r['ok']) $c->send(json_encode(['type'=>'bp_data']+Database::getBPData($accId)));
        else $this->err($c,$r['msg']);
    }

    // ── Map Yönetimi ──────────────────────────────────────────
    private function doGetMaps(ConnectionInterface $c, array $d): void {
        $mm   = MapManager::getInstance();
        $mode = $d['mode'] ?? null;
        $maps = $mode ? $mm->getLobbyList($mode) : $mm->getLobbyList();
        $c->send(json_encode(['type'=>'maps_list','maps'=>array_values($maps)]));
    }

    private function doGetMapPool(ConnectionInterface $c, array $d): void {
        $mm     = MapManager::getInstance();
        $mode   = $d['mode'] ?? 'tdm';
        $random = $mm->getBalancedRandom($mode);
        $pool   = $mm->getLobbyList($mode);
        $c->send(json_encode(['type'=>'map_pool','mode'=>$mode,'random'=>$random,'pool'=>array_values($pool)]));
    }

    private function doSetRoomMap(ConnectionInterface $c, array $d): void {
        [$pid,$room] = $this->pr($c);
        if (!$room || $room->state !== 'lobby') { $this->err($c,'Room not in lobby'); return; }
        if ($pid !== $room->hostId) { $this->err($c,'Only host can change map'); return; }
        $mapId = trim((string)($d['map_id'] ?? ''));
        if (!$mapId) { $this->err($c,'map_id gerekli'); return; }
        $room->changeMap($mapId);
        $room->broadcast(['type'=>'map_changed','map_id'=>$room->getMapId(),'map_data'=>$room->getMapData(),'platforms'=>$room->getPlatforms()]);
    }

    // ── Friends ───────────────────────────────────────────────
    private function doFriendAdd(ConnectionInterface $c,array $d): void {
        $accId=$this->conns[$c->resourceId]['accId']??null; if(!$accId){$this->err($c,'Login required');return;}
        $r=Database::sendFriendReq($accId,$d['username']??''); if(!$r['ok']){$this->err($c,$r['msg']);return;}
        $c->send(json_encode(['type'=>'friend_request_sent','to'=>$d['username']]));
        $fp=$this->getPlayerByAcc((int)$r['target_id']);
        if($fp) $fp->conn->send(json_encode(['type'=>'friend_request_received','from_id'=>$accId,'from_name'=>$this->players[$this->conns[$c->resourceId]['pid']]->name]));
    }
    private function doFriendAccept(ConnectionInterface $c,array $d): void {
        $accId=$this->conns[$c->resourceId]['accId']??null; if(!$accId){$this->err($c,'Login required');return;}
        $fromId=(int)($d['from_id']??0); $r=Database::acceptFriend($accId,$fromId);
        if(!$r['ok']){$this->err($c,$r['msg']);return;}
        $c->send(json_encode(['type'=>'friend_accepted','friend_id'=>$fromId]));
        $fp=$this->getPlayerByAcc($fromId); if($fp) $fp->conn->send(json_encode(['type'=>'friend_accepted','friend_id'=>$accId]));
    }
    private function doFriendRemove(ConnectionInterface $c,array $d): void {
        $accId=$this->conns[$c->resourceId]['accId']??null; if(!$accId) return;
        Database::removeFriend($accId,(int)($d['friend_id']??0));
        $c->send(json_encode(['type'=>'friend_removed','friend_id'=>$d['friend_id']]));
    }
    private function doFriendList(ConnectionInterface $c): void {
        $accId=$this->conns[$c->resourceId]['accId']??null; if(!$accId){$this->err($c,'Login required');return;}
        $fs=Database::getFriends($accId); foreach($fs as &$f) $f['online']=isset($this->onlineMap[(int)$f['id']]);
        $c->send(json_encode(['type'=>'friend_list','friends'=>$fs,'pending'=>Database::getPending($accId)]));
    }
    private function doFriendInvite(ConnectionInterface $c,array $d): void {
        $rid=$c->resourceId; $roomId=$this->conns[$rid]['rid']??null; $accId=$this->conns[$rid]['accId']??null;
        if(!$roomId||!$accId){$this->err($c,'Not in room or not logged in');return;}
        $room=$this->rooms[$roomId]??null; if(!$room) return;
        $fp=$this->getPlayerByAcc((int)($d['friend_id']??0)); if(!$fp){$this->err($c,'Friend not online');return;}
        $myName=$this->players[$this->conns[$rid]['pid']??'']->name??'Someone';
        $fp->conn->send(json_encode(['type'=>'room_invite','from_name'=>$myName,'room_id'=>$room->id,'room_name'=>$room->name,'invite_code'=>$room->inviteCode,'mode'=>$room->mode]));
        $c->send(json_encode(['type'=>'invite_sent']));
    }

    // ── Helpers ───────────────────────────────────────────────
    private function pr(ConnectionInterface $c): array {
        $rid=$c->resourceId; $pid=$this->conns[$rid]['pid']??null; $roomId=$this->conns[$rid]['rid']??null;
        return [$pid,$roomId?($this->rooms[$roomId]??null):null];
    }
    private function getPlayerByAcc(int $id): ?Player { $pid=$this->onlineMap[$id]??null; return $pid?($this->players[$pid]??null):null; }
    private function sendRooms(ConnectionInterface $c): void {
        $c->send(json_encode(['type'=>'room_list','rooms'=>array_values(array_map(fn($r)=>$r->getLobbyInfo(),array_filter($this->rooms,fn($r)=>!$r->isPrivate)))]));
    }
    private function broadcastRooms(): void {
        $rooms=array_values(array_map(fn($r)=>$r->getLobbyInfo(),array_filter($this->rooms,fn($r)=>!$r->isPrivate)));
        $msg=json_encode(['type'=>'room_list','rooms'=>$rooms]);
        foreach($this->conns as $info) try{$info['conn']->send($msg);}catch(\Exception $e){}
    }
    private function err(ConnectionInterface $c,string $msg): void { $c->send(json_encode(['type'=>'error','message'=>$msg])); }

    // ── Admin Handlers ────────────────────────────────────────
    private function requireAdmin(ConnectionInterface $c): bool {
        $accId = $this->conns[$c->resourceId]['accId'] ?? null;
        if (!$accId || !Database::isAdmin((int)$accId)) { $this->err($c,'Unauthorized'); return false; }
        return true;
    }
    private function doAdminLogin(ConnectionInterface $c, array $d): void {
        $r = Database::login($d['username']??'', $d['password']??'');
        if (!$r['ok']) { $this->err($c, $r['msg']); return; }
        if (!$r['account']['is_admin']) { $this->err($c,'Not an admin account'); return; }
        $rid = $c->resourceId;
        $this->conns[$rid]['accId'] = (int)$r['account']['id'];
        $c->send(json_encode(['type'=>'admin_auth_ok','username'=>$r['account']['username'],'id'=>$r['account']['id']]));
        // Send initial data
        $this->doAdminGetStats($c);
        $this->doAdminGetRooms($c);
    }
    private function doAdminGetStats(ConnectionInterface $c): void {
        if (!$this->requireAdmin($c)) return;
        $stats = Database::getServerStats();
        $stats['online_players'] = count($this->players);
        $stats['active_rooms']   = count($this->rooms);
        $c->send(json_encode(['type'=>'admin_stats','stats'=>$stats]));
    }
    private function doAdminGetPlayers(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $limit  = (int)($d['limit']  ?? 50);
        $offset = (int)($d['offset'] ?? 0);
        $search = trim($d['search'] ?? '');
        $players = Database::getPlayers($limit, $offset, $search);
        $total   = Database::getPlayerCount();
        // Mark online players
        foreach ($players as &$p) {
            $p['online'] = isset($this->onlineMap[(int)$p['id']]);
        }
        $c->send(json_encode(['type'=>'admin_players','players'=>$players,'total'=>$total,'limit'=>$limit,'offset'=>$offset]));
    }
    private function doAdminBan(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $id  = (int)($d['id'] ?? 0);
        $ban = (bool)($d['ban'] ?? true);
        Database::banPlayer($id, $ban);
        // Kick if online
        if ($ban) {
            $pid = $this->onlineMap[$id] ?? null;
            if ($pid) { $p=$this->players[$pid]??null; if($p) try{$p->conn->close();}catch(\Exception $e){} }
        }
        $c->send(json_encode(['type'=>'admin_ok','action'=>'ban','id'=>$id,'ban'=>$ban]));
    }
    private function doAdminGiveCoins(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $id = (int)($d['id'] ?? 0); $coins = (int)($d['coins'] ?? 0);
        Database::giveCoins($id, $coins);
        $c->send(json_encode(['type'=>'admin_ok','action'=>'give_coins','id'=>$id,'coins'=>$coins]));
    }
    private function doAdminSetCoins(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $id = (int)($d['id'] ?? 0); $coins = (int)($d['coins'] ?? 0);
        Database::setCoins($id, $coins);
        $c->send(json_encode(['type'=>'admin_ok','action'=>'set_coins','id'=>$id,'coins'=>$coins]));
    }
    private function doAdminResetStats(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $id = (int)($d['id'] ?? 0);
        Database::resetStats($id);
        $c->send(json_encode(['type'=>'admin_ok','action'=>'reset_stats','id'=>$id]));
    }
    private function doAdminDeleteAcc(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $id = (int)($d['id'] ?? 0);
        // Kick if online
        $pid = $this->onlineMap[$id] ?? null;
        if ($pid) { $p=$this->players[$pid]??null; if($p) try{$p->conn->close();}catch(\Exception $e){} }
        Database::deleteAccount($id);
        $c->send(json_encode(['type'=>'admin_ok','action'=>'delete_acc','id'=>$id]));
    }
    private function doAdminSetAdmin(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $id  = (int)($d['id'] ?? 0);
        $val = (bool)($d['value'] ?? false);
        Database::setAdmin($id, $val);
        $c->send(json_encode(['type'=>'admin_ok','action'=>'set_admin','id'=>$id,'value'=>$val]));
    }
    private function doAdminGetRooms(ConnectionInterface $c): void {
        if (!$this->requireAdmin($c)) return;
        $rooms = array_values(array_map(fn($r) => array_merge($r->getLobbyInfo(), [
            'bullet_count' => count($r->bullets),
        ]), $this->rooms));
        $c->send(json_encode(['type'=>'admin_rooms','rooms'=>$rooms]));
    }
    private function doAdminKick(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $pid = $d['player_id'] ?? '';
        $p = $this->players[$pid] ?? null;
        if ($p) try{ $p->conn->send(json_encode(['type'=>'kicked','reason'=>$d['reason']??'Kicked by admin'])); $p->conn->close(); }catch(\Exception $e){}
        $c->send(json_encode(['type'=>'admin_ok','action'=>'kick','player_id'=>$pid]));
    }
    private function doAdminCloseRoom(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $rid = $d['room_id'] ?? '';
        $room = $this->rooms[$rid] ?? null;
        if (!$room) { $this->err($c,'Room not found'); return; }
        $room->broadcast(['type'=>'room_closed','reason'=>$d['reason']??'Closed by admin']);
        foreach ($room->players as $p) try{ $p->conn->close(); }catch(\Exception $e){}
        $this->gl->stopRoomLoop($rid);
        unset($this->rooms[$rid]);
        $c->send(json_encode(['type'=>'admin_ok','action'=>'close_room','room_id'=>$rid]));
        $this->broadcastRooms();
    }
    private function doAdminGetMaps(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $mm   = MapManager::getInstance();
        $mode = $d['mode'] ?? null;
        $maps = $mode ? array_values($mm->getByMode($mode)) : array_values($mm->getAll());
        $stats = $mm->getAllStats();
        foreach ($maps as &$m) $m['stats'] = $stats[$m['id']] ?? [];
        $c->send(json_encode(['type'=>'admin_maps','maps'=>$maps]));
    }
    private function doAdminSaveMap(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $map = $d['map'] ?? null;
        if (!$map || !isset($map['id'])) { $this->err($c,'Invalid map data'); return; }
        $mapsDir = defined('MAPS_DIR') ? MAPS_DIR : dirname(__DIR__).'/maps';
        if (!is_dir($mapsDir)) @mkdir($mapsDir, 0755, true);
        $file = $mapsDir . '/' . preg_replace('/[^a-z0-9_]/', '_', $map['id']) . '.json';
        file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        MapManager::getInstance()->reload();
        $c->send(json_encode(['type'=>'admin_ok','action'=>'save_map','id'=>$map['id']]));
    }
    private function doAdminReloadMaps(ConnectionInterface $c): void {
        if (!$this->requireAdmin($c)) return;
        MapManager::getInstance()->reload();
        $mm   = MapManager::getInstance();
        $maps = array_values($mm->getAll());
        $c->send(json_encode(['type'=>'admin_maps_reloaded','count'=>count($maps),'maps'=>$maps]));
    }
    private function doAdminBroadcast(ConnectionInterface $c, array $d): void {
        if (!$this->requireAdmin($c)) return;
        $msg = trim($d['message'] ?? '');
        if (!$msg) return;
        $pkg = json_encode(['type'=>'server_announcement','message'=>$msg]);
        foreach ($this->conns as $info) try{ $info['conn']->send($pkg); }catch(\Exception $e){}
        $c->send(json_encode(['type'=>'admin_ok','action'=>'broadcast']));
    }

    public function onClose(ConnectionInterface $c): void {
        $rid=$c->resourceId; $info=$this->conns[$rid]??null; if(!$info) return;
        $pid=$info['pid']; $roomId=$info['rid']; $accId=$info['accId'];
        if($roomId&&isset($this->rooms[$roomId])){
            $room=$this->rooms[$roomId]; $room->removePlayer($pid??'');
            if($room->isEmpty()){$this->gl->stopRoomLoop($roomId);unset($this->rooms[$roomId]);}
            else $room->broadcast(['type'=>'player_left_room','room'=>$room->getLobbyInfo()]);
            $this->broadcastRooms();
        }
        if($accId){ unset($this->onlineMap[$accId]); $fs=Database::getFriends($accId); foreach($fs as $f){$fp=$this->getPlayerByAcc((int)$f['id']);if($fp)$fp->conn->send(json_encode(['type'=>'friend_offline','id'=>$accId]));} }
        if($pid) unset($this->players[$pid]); unset($this->conns[$rid]);
    }
    public function onError(ConnectionInterface $c,\Exception $e): void { echo "[Error] {$e->getMessage()}\n"; $c->close(); }
    public function getRoom(string $id): ?Room { return $this->rooms[$id]??null; }
    public function removeRoom(string $id): void { unset($this->rooms[$id]); }
    public function getRooms(): array { return $this->rooms; }
}
