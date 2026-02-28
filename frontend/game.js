const Game = (() => {
    const canvas = document.getElementById('game-canvas');
    const ctx = canvas.getContext('2d');
    let myId = null, gameState = null, platforms = [], running = false;
    const cam = { x: 0, y: 0, w: 0, h: 0 };
    const keys = {};
    let mouseX = 0, mouseY = 0, lastSend = 0;
    const particles = [], shakes = [], popups = [];
    let legendaryTimer = 0, legendaryText = '';
    let prevCombo = 0;

    const WEAPON_COLORS = { rifle: '#f39c12', sniper: '#9b59b6', shotgun: '#e67e22', smg: '#3498db', pistol: '#2ecc71' };
    const TRAIL_COLORS = { 'trail_fire': '#f39c12', 'trail_elec': '#00ffff', 'trail_void': '#a855f7', default: '#fffde7' };
    const SKIN_COLORS = {
        char_default: { b: '#5d6d7e', h: '#4a5568' },
        char_shadow: { b: '#2d3748', h: '#1a202c' },
        char_crimson: { b: '#e53e3e', h: '#c53030' },
        char_neon: { b: '#00bcd4', h: '#0097a7' },
        char_inferno: { b: '#dd6b20', h: '#c05621' },
        char_cyber: { b: '#38a169', h: '#276749' },
    };

    function setPlayerId(id) { myId = id; }
    
    function getMyPlayer() { 
        return gameState?.players?.find(p => p.id === myId) || null; 
    }

    function init(pls) {
        platforms = pls || [];
        canvas.width = window.innerWidth; 
        canvas.height = window.innerHeight;
        cam.w = canvas.width; 
        cam.h = canvas.height;
        running = true;
        
        window.addEventListener('keydown', kd); 
        window.addEventListener('keyup', ku);
        canvas.addEventListener('mousemove', mm); 
        canvas.addEventListener('click', mc);
        window.addEventListener('resize', onResize);
        
        requestAnimationFrame(loop);
    }

    function stop() {
        running = false;
        window.removeEventListener('keydown', kd); 
        window.removeEventListener('keyup', ku);
        canvas.removeEventListener('mousemove', mm); 
        canvas.removeEventListener('click', mc);
        window.removeEventListener('resize', onResize);
        gameState = null; 
        particles.length = 0; 
        shakes.length = 0; 
        popups.length = 0;
    }

    function updateState(s) { gameState = s; }

    function loop() {
        if (!running) return;
        requestAnimationFrame(loop);
        update();
        render();
        sendInput();
    }

    function update() {
        if (legendaryTimer > 0) {
            legendaryTimer -= 0.016;
        }
        for (let i = shakes.length - 1; i >= 0; i--) {
            shakes[i].t -= 0.016; 
            if (shakes[i].t <= 0) {
                shakes.splice(i, 1);
            }
        }
    }

    function getShake() {
        let ox = 0, oy = 0; 
        shakes.forEach(s => {
            ox += (Math.random() - 0.5) * s.i;
            oy += (Math.random() - 0.5) * s.i;
        }); 
        return { ox, oy };
    }
    
    function addShake(i) { shakes.push({ t: 0.3, i }); }

    function render() {
        if (!gameState) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        updateCam();
        const { ox, oy } = getShake();
        drawBG();
        
        ctx.save(); 
        ctx.translate(-cam.x + ox, -cam.y + oy);
        
        drawPlatforms(); 
        drawFlags(); 
        drawBullets(); 
        drawPlayers(); 
        drawParticles(); 
        drawPopups();
        
        ctx.restore();
        if (legendaryTimer > 0) drawLegendary();
    }

    function updateCam() {
        const me = getMyPlayer(); 
        if (!me) return;
        const tx = me.x + 16 - cam.w / 2;
        const ty = me.y + 24 - cam.h / 2;
        cam.x += (tx - cam.x) * 0.1; 
        cam.y += (ty - cam.y) * 0.1;
        cam.x = Math.max(0, Math.min(2400 - cam.w, cam.x)); 
        cam.y = Math.max(0, Math.min(600 - cam.h, cam.y));
    }

    function drawBG() {
        const g = ctx.createLinearGradient(0, 0, 0, canvas.height);
        g.addColorStop(0, '#0d1117');
        g.addColorStop(1, '#161b22');
        ctx.fillStyle = g; 
        ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    function drawPlatforms() {
        platforms.forEach(p => {
            // Shadow
            ctx.fillStyle = 'rgba(0,0,0,0.3)'; 
            ctx.fillRect(p.x + 3, p.y + 3, p.w, p.h);
            const g = ctx.createLinearGradient(p.x, p.y, p.x, p.y + p.h);
            g.addColorStop(0, '#5d6d7e'); 
            g.addColorStop(1, '#2c3e50');
            ctx.fillStyle = g; 
            ctx.fillRect(p.x, p.y, p.w, p.h);
            ctx.fillStyle = '#7f8c8d'; 
            ctx.fillRect(p.x, p.y, p.w, 3);
            ctx.strokeStyle = 'rgba(108,122,137,0.3)'; 
            ctx.lineWidth = 1; 
            ctx.strokeRect(p.x, p.y, p.w, p.h);
        });
    }

    function drawFlags() {
        if (!gameState?.flags) return;
        gameState.flags.forEach(f => {
            if (f.status === 'carried') return;
            const col = f.team === 'red' ? '#e74c3c' : '#3498db';
            const glow = f.status === 'dropped' ? '#f1c40f' : col;
            
            // Glow
            ctx.save(); 
            ctx.shadowColor = glow; 
            ctx.shadowBlur = f.status === 'dropped' ? 16 : 8;
            
            // Pole
            ctx.fillStyle = '#95a5a6'; 
            ctx.fillRect(f.x, f.y - 36, 3, 36);
            
            // Flag
            ctx.fillStyle = col;
            ctx.beginPath(); 
            ctx.moveTo(f.x + 3, f.y - 36); 
            ctx.lineTo(f.x + 26, f.y - 28); 
            ctx.lineTo(f.x + 3, f.y - 18); 
            ctx.closePath(); 
            ctx.fill();
            
            // Star base
            ctx.fillStyle = glow; 
            ctx.font = '14px serif'; 
            ctx.textAlign = 'center'; 
            ctx.fillText('★', f.x + 1, f.y + 4);
            ctx.restore();
            
            if (f.status === 'dropped') {
                ctx.fillStyle = 'rgba(241,196,15,0.9)';
                ctx.font = '10px monospace';
                ctx.textAlign = 'center';
                ctx.fillText('⚠ DROPPED', f.x + 14, f.y - 44);
            }
        });
    }

    function drawPlayers() {
        if (!gameState?.players) return;
        const me = getMyPlayer();
        // Draw others first, then me on top
        gameState.players.filter(p => p.id !== myId).forEach(p => drawPlayer(p, false));
        if (me) drawPlayer(me, true);
    }

    function drawPlayer(p, isMe) {
        const pw = 32, ph = 48;
        if (!p.alive) {
            ctx.save(); 
            ctx.globalAlpha = 0.25; 
            ctx.textAlign = 'center'; 
            ctx.font = '20px serif';
            ctx.fillText('✝', p.x + pw / 2, p.y + ph / 2); 
            ctx.restore(); 
            return;
        }
        ctx.save();
        
        // Shadow on ground
        ctx.fillStyle = 'rgba(0,0,0,0.2)';
        ctx.beginPath(); 
        ctx.ellipse(p.x + pw / 2, p.y + ph + 2, 12, 4, 0, 0, Math.PI * 2); 
        ctx.fill();

        if (p.isWolf) { 
            drawWolf(p); 
        } else { 
            drawHuman(p, isMe, pw, ph); 
        }

        // Flag icon
        if (p.hasFlag) {
            ctx.textAlign = 'center';
            ctx.font = '16px serif';
            ctx.fillText('🚩', p.x + pw / 2, p.y - 22);
        }
        
        // Emote bubble
        if (p.emote && window.EMOTES_MAP) {
            const ic = window.EMOTES_MAP[p.emote] || '';
            if (ic) {
                ctx.fillStyle = 'rgba(13,17,23,0.8)'; 
                roundRect(ctx, p.x + pw / 2 - 16, p.y - 54, 32, 24, 6); 
                ctx.fill();
                ctx.textAlign = 'center'; 
                ctx.font = '16px serif'; 
                ctx.fillText(ic, p.x + pw / 2, p.y - 38);
            }
        }
        
        // HP bar
        const maxHp = p.maxHp || 100;
        ctx.fillStyle = '#1a1a2e'; 
        ctx.fillRect(p.x, p.y - 14, pw, 5);
        ctx.fillStyle = p.hp > 60 * maxHp / 100 ? '#2ecc71' : p.hp > 30 * maxHp / 100 ? '#f39c12' : '#e74c3c';
        ctx.fillRect(p.x, p.y - 14, pw * (p.hp / maxHp), 5);
        
        // Name
        ctx.textAlign = 'center'; 
        ctx.font = isMe ? 'bold 10px monospace' : '9px monospace';
        ctx.strokeStyle = 'rgba(0,0,0,0.95)'; 
        ctx.lineWidth = 3;
        ctx.strokeText(p.name, p.x + pw / 2, p.y - 17); 
        ctx.fillStyle = isMe ? '#f1c40f' : '#fff';
        ctx.fillText(p.name, p.x + pw / 2, p.y - 17);
        
        // Combo indicator
        if (p.combo >= 3) {
            const cc = p.combo >= 10 ? '#8b5cf6' : p.combo >= 8 ? '#f1c40f' : p.combo >= 5 ? '#e74c3c' : '#f97316';
            ctx.font = `bold 9px monospace`; 
            ctx.fillStyle = cc; 
            ctx.textAlign = 'center';
            ctx.fillText(`${p.combo}×`, p.x + pw / 2, p.y - 28);
        }
        
        // Me highlight
        if (isMe) {
            ctx.strokeStyle = 'rgba(241,196,15,0.4)';
            ctx.lineWidth = 2;
            ctx.strokeRect(p.x - 2, p.y - 2, pw + 4, ph + 4);
        }
        ctx.restore();
    }

    function drawHuman(p, isMe, pw, ph) {
        const sc = SKIN_COLORS[p.skinChar] || SKIN_COLORS.char_default;
        const tb = p.team === 'red' ? '#e74c3c' : '#3498db';
        const bodyCol = sc.b; 
        const headCol = sc.h;

        // Combo: 5+ = fire glow, 8+ = aura
        if (p.combo >= 8) {
            ctx.save(); 
            ctx.globalAlpha = 0.2 + Math.sin(Date.now() * 0.005) * 0.08;
            const ag = ctx.createRadialGradient(p.x + pw / 2, p.y + ph / 2, 4, p.x + pw / 2, p.y + ph / 2, 30);
            ag.addColorStop(0, p.combo >= 10 ? '#8b5cf6' : p.combo >= 8 ? '#f1c40f' : '#e74c3c'); 
            ag.addColorStop(1, 'transparent');
            ctx.fillStyle = ag; 
            ctx.fillRect(p.x - 12, p.y - 12, pw + 24, ph + 24); 
            ctx.restore();
        }
        if (p.combo >= 5) {
            ctx.save();
            ctx.shadowColor = '#e74c3c';
            ctx.shadowBlur = 18;
        }

        // Body
        ctx.fillStyle = bodyCol; 
        ctx.fillRect(p.x + 4, p.y + 16, 24, 28);
        // Team stripe
        ctx.fillStyle = tb; 
        ctx.fillRect(p.x + 4, p.y + 16, 24, 4);
        ctx.strokeStyle = 'rgba(0,0,0,0.4)';
        ctx.lineWidth = 1;
        ctx.strokeRect(p.x + 4, p.y + 16, 24, 28);
        
        // Head
        ctx.fillStyle = headCol; 
        ctx.fillRect(p.x + 6, p.y + 2, 20, 18);
        ctx.strokeStyle = 'rgba(0,0,0,0.4)';
        ctx.lineWidth = 1;
        ctx.strokeRect(p.x + 6, p.y + 2, 20, 18);
        
        // Eyes
        if (p.facingRight) {
            ctx.fillStyle = '#fff'; ctx.fillRect(p.x + 18, p.y + 6, 6, 5);
            ctx.fillStyle = '#111'; ctx.fillRect(p.x + 21, p.y + 7, 3, 3);
        } else {
            ctx.fillStyle = '#fff'; ctx.fillRect(p.x + 8, p.y + 6, 6, 5);
            ctx.fillStyle = '#111'; ctx.fillRect(p.x + 8, p.y + 7, 3, 3);
        }
        
        // Legs animation
        const la = Math.sin(Date.now() * 0.012) * (p.combo >= 5 ? 6 : 4);
        ctx.fillStyle = 'rgba(0,0,0,0.5)'; 
        ctx.fillRect(p.x + 5, p.y + 42, 9, 12 + (p.facingRight ? la : -la)); 
        ctx.fillRect(p.x + 18, p.y + 42, 9, 12 + (p.facingRight ? -la : la));
        
        // Weapon
        drawWeapon(p, pw, ph);
        if (p.combo >= 5) ctx.restore();
    }

    function drawWeapon(p, pw, ph) {
        const wc = WEAPON_COLORS[p.weapon] || '#95a5a6';
        ctx.fillStyle = wc; 
        ctx.strokeStyle = 'rgba(0,0,0,0.5)'; 
        ctx.lineWidth = 1;
        const wy = p.y + 22;
        
        if (p.facingRight) {
            switch (p.weapon) {
                case 'sniper': 
                    ctx.fillRect(p.x + pw, wy, 24, 4); ctx.fillRect(p.x + pw + 20, wy - 3, 3, 3); 
                    break;
                case 'shotgun': 
                    ctx.fillRect(p.x + pw, wy, 15, 8); ctx.fillRect(p.x + pw + 12, wy + 2, 6, 3); 
                    break;
                case 'smg': 
                    ctx.fillRect(p.x + pw, wy + 1, 13, 5); ctx.fillRect(p.x + pw + 6, wy + 5, 7, 3); 
                    break;
                case 'pistol': 
                    ctx.fillRect(p.x + pw, wy + 1, 10, 6); 
                    break;
                default: 
                    ctx.fillRect(p.x + pw, wy, 16, 5); ctx.fillRect(p.x + pw + 10, wy + 4, 7, 3);
            }
        } else {
            switch (p.weapon) {
                case 'sniper': 
                    ctx.fillRect(p.x - 24, wy, 24, 4); 
                    break;
                case 'shotgun': 
                    ctx.fillRect(p.x - 15, wy, 15, 8); 
                    break;
                case 'smg': 
                    ctx.fillRect(p.x - 13, wy + 1, 13, 5); 
                    break;
                default: 
                    ctx.fillRect(p.x - 16, wy, 16, 5);
            }
        }
    }

    function drawWolf(p) {
        const t = Date.now() * 0.004;
        const ox = p.x;
        const oy = p.y;
        const isRight = p.facingRight;

        const breath = Math.sin(t) * 1.5;
        const walk = Math.sin(t * 6) * 6;

        ctx.save();

        if (!isRight) {
            ctx.translate(ox + 40, 0);
            ctx.scale(-1, 1);
        }

        const x = isRight ? ox : 0;
        const y = oy;

        // =========================
        // 🔥 STRONGER REAL AURA
        // =========================
        ctx.save();
        ctx.globalAlpha = 0.15 + Math.sin(t * 2) * 0.05;

        const grd = ctx.createRadialGradient(
            x + 20, y + 25, 10,
            x + 20, y + 25, 60
        );

        grd.addColorStop(0, "rgba(139,92,246,0.4)");
        grd.addColorStop(1, "rgba(0,0,0,0)");

        ctx.fillStyle = grd;
        ctx.beginPath();
        ctx.ellipse(x + 20, y + 30, 45, 35, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        ctx.shadowColor = "#7c3aed";
        ctx.shadowBlur = 20;

        // =========================
        // 🧠 BODY (More natural shape)
        // =========================
        ctx.fillStyle = "#2e1065";
        ctx.beginPath();
        ctx.moveTo(x + 8, y + 35);
        ctx.lineTo(x + 15, y + 15);
        ctx.lineTo(x + 35, y + 18);
        ctx.lineTo(x + 38, y + 30);
        ctx.lineTo(x + 30, y + 45);
        ctx.lineTo(x + 12, y + 45);
        ctx.closePath();
        ctx.fill();

        // Chest highlight (breathing effect)
        ctx.fillStyle = "#4c1d95";
        ctx.fillRect(x + 18, y + 22 + breath, 12, 10);

        // =========================
        // 🐺 HEAD (Better proportion)
        // =========================
        ctx.fillStyle = "#3b0764";
        ctx.beginPath();
        ctx.moveTo(x + 5, y + 18);
        ctx.lineTo(x + 15, y + 5);
        ctx.lineTo(x + 30, y + 8);
        ctx.lineTo(x + 28, y + 20);
        ctx.lineTo(x + 10, y + 22);
        ctx.closePath();
        ctx.fill();

        // Ears
        ctx.fillStyle = "#1e1b4b";
        ctx.beginPath();
        ctx.moveTo(x + 14, y + 6);
        ctx.lineTo(x + 12, y - 8);
        ctx.lineTo(x + 20, y + 5);
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(x + 24, y + 7);
        ctx.lineTo(x + 28, y - 6);
        ctx.lineTo(x + 30, y + 10);
        ctx.fill();

        // Snout
        ctx.fillStyle = "#111827";
        ctx.fillRect(x + 30, y + 12, 14, 8);

        ctx.fillStyle = "#000";
        ctx.fillRect(x + 40, y + 14, 4, 4);

        // =========================
        // 👁️ REALISTIC GLOW EYE
        // =========================
        ctx.shadowColor = "#ff0000";
        ctx.shadowBlur = 12;

        ctx.fillStyle = "#ff2a2a";
        ctx.beginPath();
        ctx.arc(x + 26, y + 12, 3, 0, Math.PI * 2);
        ctx.fill();

        ctx.shadowBlur = 0;

        // =========================
        // 🐾 DIGITIGRADE LEGS (Walking)
        // =========================
        ctx.fillStyle = "#1f1147";

        // Back leg
        ctx.beginPath();
        ctx.moveTo(x + 12, y + 45);
        ctx.lineTo(x + 8, y + 60 + walk);
        ctx.lineTo(x + 15, y + 60 + walk);
        ctx.lineTo(x + 20, y + 45);
        ctx.fill();

        // Front leg
        ctx.beginPath();
        ctx.moveTo(x + 30, y + 45);
        ctx.lineTo(x + 26, y + 60 - walk);
        ctx.lineTo(x + 33, y + 60 - walk);
        ctx.lineTo(x + 36, y + 45);
        ctx.fill();

        ctx.restore();

        // =========================
        // 🏷️ NAME
        // =========================
        ctx.fillStyle = "#c4b5fd";
        ctx.font = "bold 11px monospace";
        ctx.textAlign = "center";
        ctx.fillText("🐺 ULTIMATE WOLF", p.x + 20, p.y - 15);
    }

    function drawBullets() {
        if (!gameState?.bullets) return;
        gameState.bullets.forEach(b => {
            if (!b.active) return;
            const tc = TRAIL_COLORS[b.trail] || TRAIL_COLORS.default;
            ctx.save(); 
            ctx.shadowColor = tc; 
            ctx.shadowBlur = 10;
            ctx.fillStyle = tc;
            ctx.beginPath(); 
            ctx.arc(b.x + 4, b.y + 2, b.trail === 'trail_void' ? 6 : b.trail === 'trail_elec' ? 5 : 4, 0, Math.PI * 2); 
            ctx.fill();
            
            // Trail
            ctx.globalAlpha = 0.25; 
            ctx.beginPath(); 
            ctx.arc(b.x - b.dx * 8, b.y - b.dy * 8, 3, 0, Math.PI * 2); 
            ctx.fill();
            
            ctx.globalAlpha = 0.1;  
            ctx.beginPath(); 
            ctx.arc(b.x - b.dx * 16, b.y - b.dy * 16, 2, 0, Math.PI * 2); 
            ctx.fill();
            ctx.restore();
        });
    }

    function drawParticles() {
        for (let i = particles.length - 1; i >= 0; i--) {
            const p = particles[i]; 
            p.x += p.vx * 0.016; 
            p.y += p.vy * 0.016 + 200 * 0.016;
            p.vy -= 1; 
            p.life -= 0.04;
            
            if (p.life <= 0) {
                particles.splice(i, 1); 
                continue;
            }
            
            ctx.save(); 
            ctx.globalAlpha = p.life; 
            ctx.fillStyle = p.c; 
            ctx.shadowColor = p.c; 
            ctx.shadowBlur = 4;
            ctx.fillRect(p.x, p.y, p.s, p.s); 
            ctx.restore();
        }
    }

    function drawPopups() {
        for (let i = popups.length - 1; i >= 0; i--) {
            const p = popups[i]; 
            p.y -= 1.2; 
            p.t -= 0.02;
            
            if (p.t <= 0) {
                popups.splice(i, 1); 
                continue;
            }
            
            ctx.save(); 
            ctx.globalAlpha = p.t; 
            ctx.font = `bold ${p.sz}px monospace`; 
            ctx.textAlign = 'center';
            ctx.strokeStyle = 'rgba(0,0,0,0.9)'; 
            ctx.lineWidth = 3; 
            ctx.strokeText(p.text, p.x, p.y);
            ctx.fillStyle = p.c; 
            ctx.fillText(p.text, p.x, p.y); 
            ctx.restore();
        }
    }

    function drawLegendary() {
        const alpha = Math.min(1, legendaryTimer * 0.6);
        ctx.save(); 
        ctx.globalAlpha = alpha * 0.06; 
        ctx.fillStyle = '#f1c40f'; 
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.globalAlpha = alpha;
        ctx.textAlign = 'center';
        ctx.shadowColor = '#f1c40f'; 
        ctx.shadowBlur = 40;
        ctx.font = `bold 50px monospace`;
        ctx.strokeStyle = 'rgba(0,0,0,0.9)'; 
        ctx.lineWidth = 6; 
        ctx.strokeText('⚡ ' + legendaryText + ' ⚡', canvas.width / 2, canvas.height / 2 - 20);
        ctx.fillStyle = '#f1c40f'; 
        ctx.fillText('⚡ ' + legendaryText + ' ⚡', canvas.width / 2, canvas.height / 2 - 20);
        ctx.shadowColor = '#e74c3c'; 
        ctx.shadowBlur = 20;
        ctx.font = 'bold 24px monospace'; 
        ctx.fillStyle = '#e74c3c';
        ctx.fillText('LEGENDARY COMBO!', canvas.width / 2, canvas.height / 2 + 20);
        ctx.restore();
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.arcTo(x + w, y, x + w, y + r, r);
        ctx.lineTo(x + w, y + h - r);
        ctx.arcTo(x + w, y + h, x + w - r, y + h, r);
        ctx.lineTo(x + r, y + h);
        ctx.arcTo(x, y + h, x, y + h - r, r);
        ctx.lineTo(x, y + r);
        ctx.arcTo(x, y, x + r, y, r);
        ctx.closePath();
    }

    function spawnKillFx(x, y, fxKey) {
        const sets = {
            kill_boom: ['#f39c12', '#e74c3c', '#f1c40f'],
            kill_lightning: ['#00ffff', '#0080ff', '#fff'],
            kill_galaxy: ['#8b5cf6', '#f1c40f', '#ff00ff'],
            default: ['#e74c3c', '#fff', '#f97316']
        };
        const cols = sets[fxKey] || sets.default;
        for (let i = 0; i < 22; i++) {
            const a = Math.random() * Math.PI * 2;
            const s = 80 + Math.random() * 150;
            particles.push({ 
                x, y, 
                vx: Math.cos(a) * s, 
                vy: Math.sin(a) * s - 50, 
                life: 1, 
                c: cols[i % cols.length], 
                s: 2 + Math.random() * 5 
            });
        }
    }

    function onPlayerKilled(d) {
        const p = gameState?.players?.find(pl => pl.id === d.victim);
        if (p) spawnKillFx(p.x + 16, p.y + 24, d.skin_kill_fx);
        
        // Combo
        if (d.combo >= 3) addShake(d.combo >= 10 ? 14 : d.combo >= 8 ? 9 : d.combo >= 5 ? 5 : 3);
        
        // Combo popup
        const texts = { triple: 'TRIPLE KILL!', inferno: '🔥 INFERNO!', aura: '⚡ AURA MODE!', legendary: '💥 GODLIKE!' };
        const cols = { triple: '#f97316', inferno: '#ef4444', aura: '#f1c40f', legendary: '#8b5cf6' };
        
        if (d.combo_event && texts[d.combo_event]) {
            popups.push({
                x: (p?.x || 800) + 16, 
                y: (p?.y || 400) + 24, 
                text: texts[d.combo_event], 
                c: cols[d.combo_event], 
                sz: 22, 
                t: 1.5
            });
        }
        
        // Legendary overlay
        if (d.combo >= 10) {
            legendaryTimer = 4;
            legendaryText = d.killer_name + ' ' + d.combo + ' KILL COMBO';
        }
    }

    function onPlayerHit(d) { 
        const p = gameState?.players?.find(pl => pl.id === d.id); 
        if (p) {
            for (let i = 0; i < 8; i++) {
                const a = Math.random() * Math.PI * 2;
                particles.push({
                    x: p.x + 16, 
                    y: p.y + 24, 
                    vx: Math.cos(a) * 60, 
                    vy: Math.sin(a) * 60, 
                    life: 0.7, 
                    c: p.team === 'red' ? '#e74c3c' : '#3498db', 
                    s: 3
                });
            }
        } 
    }

    // Input
    function kd(e) { 
        keys[e.code] = true; 
        if (['Space', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.code)) e.preventDefault(); 
    }
    
    function ku(e) { 
        keys[e.code] = false; 
    }
    
    function mm(e) { 
        mouseX = e.clientX; 
        mouseY = e.clientY; 
    }
    
    function mc(e) {
        const me = getMyPlayer(); 
        if (!me || !me.alive) return;
        const wx = me.x + 16 - cam.x, wy = me.y + 24 - cam.y;
        const dx = e.clientX - wx, dy = e.clientY - wy; 
        const len = Math.hypot(dx, dy); 
        if (len < 1) return;
        Socket.send({ type: 'input', action: 'shoot', dx: dx / len, dy: dy / len });
    }
    
    function onResize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        cam.w = canvas.width;
        cam.h = canvas.height;
    }

    function sendInput() {
        const now = performance.now(); 
        if (now - lastSend < 14) return; 
        lastSend = now;
        
        const me = getMyPlayer(); 
        if (!me) return;
        
        const wkey = keys['Digit1'] ? 'rifle' : keys['Digit2'] ? 'sniper' : keys['Digit3'] ? 'shotgun' : keys['Digit4'] ? 'smg' : keys['Digit5'] ? 'pistol' : me.weapon;
        
        Socket.send({
            type: 'input', 
            action: 'move', 
            left: !!(keys.ArrowLeft || keys.KeyA), 
            right: !!(keys.ArrowRight || keys.KeyD), 
            jump: !!(keys.ArrowUp || keys.KeyW || keys.Space), 
            weapon: wkey
        });
        
        if (keys.KeyR) Socket.send({ type: 'input', action: 'reload' });
    }

    // Export edilen nesneler
    return { init, stop, setPlayerId, getMyPlayer, updateState, onPlayerKilled, onPlayerHit, addShake, keys };
})();

// === EMOTES VE MOBİL KONTROLLER ===

window.EMOTES_MAP = { dance: '💃', laugh: '😂', cry: '😭', rage: '😡', wave: '👋', salute: '🫡' };

// Mobil Cihaz Kontrolü
const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

if (isMobile) {
    const mobileControlsEl = document.getElementById('mobile-controls');
    if(mobileControlsEl) mobileControlsEl.style.display = 'block';
    
    // Zıplama Butonu
    const jumpBtn = document.getElementById('btn-jump');
    if (jumpBtn) {
        jumpBtn.addEventListener('touchstart', (e) => { e.preventDefault(); Game.keys['Space'] = true; }, { passive: false });
        jumpBtn.addEventListener('touchend', (e) => { e.preventDefault(); Game.keys['Space'] = false; }, { passive: false });
    }

    // Ateş Butonu
    const shootBtn = document.getElementById('btn-shoot');
    if (shootBtn) {
        shootBtn.addEventListener('touchstart', (e) => { 
            e.preventDefault(); 
            const me = Game.getMyPlayer();
            if (me) Socket.send({ type: 'input', action: 'shoot', dx: me.facingRight ? 1 : -1, dy: 0 });
        }, { passive: false });
    }

    // Basit Joystick Mantığı (Sol/Sağ Hareket)
    const joyZone = document.getElementById('joystick-zone');
    if (joyZone) {
        joyZone.addEventListener('touchmove', (e) => {
            const touch = e.touches[0];
            const rect = joyZone.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;
            
            if (touch.clientX < centerX - 10) {
                Game.keys['KeyA'] = true; Game.keys['KeyD'] = false;
            } else if (touch.clientX > centerX + 10) {
                Game.keys['KeyD'] = true; Game.keys['KeyA'] = false;
            } else {
                Game.keys['KeyA'] = false; Game.keys['KeyD'] = false;
            }
        }, { passive: false });

        joyZone.addEventListener('touchend', (e) => {
            Game.keys['KeyA'] = false; 
            Game.keys['KeyD'] = false;
        }, { passive: false });
    }
}