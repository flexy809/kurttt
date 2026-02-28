# WolfTeam2D v3 — Admin Panel

## Kurulum
1. Bu klasörü web sunucunuza yükleyin (ayrı bir subdomain önerilir)
2. `config.php` dosyasında `ADMIN_DB_PATH`'i game.db'ye işaret edin
3. `ADMIN_SECRET`'i değiştirin
4. Tarayıcıda `index.php`'yi açın

## Varsayılan Giriş
- Kullanıcı: **admin**
- Şifre: **admin123**
- **GİRİŞ YAPTIKTAN SONRA HEMEN ŞİFREYİ DEĞİŞTİRİN!**

## Özellikler
- Dashboard — istatistik, gelir, top oyuncular
- Players — arama, ban, coin ekleme, skin verme
- Skins — yeni skin ekleme, BP seviyesi, drop oranı
- Crates — sandık oluşturma, drop ağırlığı ayarlama
- Tasks — günlük/haftalık görev ekleme/silme
- Battle Pass — 60 seviye track görüntüleme
- Game Settings — hasar, hız, canlar
- Map Editor — canvas tabanlı platform editörü
- Ranked — sıralama listesi, sezon yönetimi
- Anti-Cheat — log görüntüleme, hızlı ban
- Admin Log — tüm admin işlemleri
- Admins — yeni admin ekleme (superadmin)

## Map Sistemi

Yeni map eklemek için `maps/` klasörüne bir JSON dosyası bırakın. Sunucu yeniden başlatıldığında otomatik algılar.

### Zorunlu JSON alanları:
- `id`, `name`, `modes`, `width`, `height`, `spawns` (red/blue), `platforms`

### Opsiyonel:
- `wall_jump_zones`, `climb_surfaces`, `special_zones`, `interactive_objects`
- `ctf_flags`, `thumbnail`, `background`, `music`

### İnteraktif obje tipleri:
- `explosive_barrel` — Mermiyle patlar, alan hasarı verir
- `breakable_glass` — Platform, kırılınca geçilebilir
- `health_pack` — Üzerine basınca HP doldurur
- `ammo_box` — Üzerine basınca mermi doldurur

### Map istatistikleri:
`data/map_stats.json` dosyasında tutulur (play_count, red_wins, blue_wins, total_kills).

### Random map:
- `MapManager::getInstance()->getRandom('tdm')` — mod bazlı rastgele
- `MapManager::getInstance()->getBalancedRandom()` — az oynanan önce
