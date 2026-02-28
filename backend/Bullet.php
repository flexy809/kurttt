<?php
namespace Game;
class Bullet {
    public string $id,$ownerId,$ownerTeam,$trailKey='';
    public float $x,$y,$dx,$dy,$speed; public int $damage;
    public bool $active=true,$isPellet=false; public float $life=0.0;
    public function __construct(string $ownerId,string $ownerTeam,float $x,float $y,float $dx,float $dy,int $damage=25,float $speed=620,string $trail='',bool $pellet=false){
        $this->id=uniqid('b_'); $this->ownerId=$ownerId; $this->ownerTeam=$ownerTeam;
        $this->x=$x; $this->y=$y; $len=sqrt($dx*$dx+$dy*$dy);
        $this->dx=$len>0.001?$dx/$len:1.0; $this->dy=$len>0.001?$dy/$len:0.0;
        $this->damage=$damage; $this->speed=$speed; $this->trailKey=$trail; $this->isPellet=$pellet;
    }
    public function update(float $dt): void {
        $this->x+=$this->dx*$this->speed*$dt; $this->y+=$this->dy*$this->speed*$dt;
        $this->life+=$dt; if($this->life>BULLET_LIFETIME) $this->active=false;
        if($this->x<-50||$this->x>MAP_WIDTH+50||$this->y<-100||$this->y>MAP_HEIGHT+100) $this->active=false;
    }
    public function checkPlatforms(array $ps): bool {
        foreach($ps as $p){ if($this->x<$p['x']+$p['w']&&$this->x+BULLET_WIDTH>$p['x']&&$this->y<$p['y']+$p['h']&&$this->y+BULLET_HEIGHT>$p['y']){$this->active=false;return true;} }
        return false;
    }
    public function serialize(): array { return ['id'=>$this->id,'x'=>round($this->x,1),'y'=>round($this->y,1),'dx'=>round($this->dx,3),'dy'=>round($this->dy,3),'active'=>$this->active,'team'=>$this->ownerTeam,'trail'=>$this->trailKey]; }
}
