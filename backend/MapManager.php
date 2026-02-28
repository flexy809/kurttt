<?php
namespace Game;

/**
 * MapManager
 * – maps/ klasöründeki *.json dosyalarını otomatik tarar
 * – Map verisi doğrulaması yapar
 * – Random map seçimi
 * – Map istatistik altyapısı (play_count, vb.)
 * – Room/config için platform & spawn verisi sunar
 */
class MapManager
{
    private static ?MapManager $instance = null;

    /** @var array<string, array> Yüklenen tüm map verileri [id => mapData] */
    private array $maps = [];

    /** @var string Maps klasörü yolu */
    private string $mapsDir;

    /** @var array Map istatistikleri (bellekte, DB'ye de yazılabilir) */
    private array $stats = [];

    // ──────────────────────────────────────────────────
    //  Singleton
    // ──────────────────────────────────────────────────
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->mapsDir = defined('MAPS_DIR') ? MAPS_DIR : dirname(__DIR__) . '/maps';
        $this->scanMaps();
    }

    // ──────────────────────────────────────────────────
    //  Tarama & Yükleme
    // ──────────────────────────────────────────────────

    /**
     * maps/ klasöründeki tüm *.json dosyalarını tara ve yükle.
     * Yeni map eklendiğinde sadece JSON dosyası bırakmak yeterli.
     */
    public function scanMaps(): void
    {
        $this->maps = [];

        if (!is_dir($this->mapsDir)) {
            echo "[MapManager] Maps dizini bulunamadı: {$this->mapsDir}\n";
            $this->loadFallbackMap();
            return;
        }

        $files = glob($this->mapsDir . '/*.json');
        if (empty($files)) {
            echo "[MapManager] Hiç JSON map bulunamadı, fallback kullanılıyor.\n";
            $this->loadFallbackMap();
            return;
        }

        foreach ($files as $file) {
            $this->loadMapFile($file);
        }

        echo "[MapManager] " . count($this->maps) . " map yüklendi: " . implode(', ', array_keys($this->maps)) . "\n";
    }

    private function loadMapFile(string $file): void
    {
        $raw = file_get_contents($file);
        if ($raw === false) {
            echo "[MapManager] Okunamadı: {$file}\n";
            return;
        }

        $data = json_decode($raw, true);
        if ($data === null) {
            echo "[MapManager] JSON parse hatası: {$file}\n";
            return;
        }

        $errors = $this->validate($data);
        if (!empty($errors)) {
            echo "[MapManager] Validasyon hatası ({$file}): " . implode(', ', $errors) . "\n";
            return;
        }

        $id = $data['id'];
        $this->maps[$id] = $data;

        // İstatistik başlangıcı
        if (!isset($this->stats[$id])) {
            $this->stats[$id] = [
                'play_count'   => 0,
                'red_wins'     => 0,
                'blue_wins'    => 0,
                'draws'        => 0,
                'total_kills'  => 0,
                'last_played'  => null,
            ];
        }
    }

    /**
     * Config'deki eski MAP_PLATFORMS sabitini fallback olarak kullan.
     */
    private function loadFallbackMap(): void
    {
        if (!defined('MAP_PLATFORMS')) return;

        $this->maps['default'] = [
            'id'          => 'default',
            'name'        => 'Default Map',
            'description' => 'Varsayılan harita',
            'thumbnail'   => '',
            'modes'       => ['tdm', 'ctf', 'wolf'],
            'width'       => defined('MAP_WIDTH')  ? MAP_WIDTH  : 4800,
            'height'      => defined('MAP_HEIGHT') ? MAP_HEIGHT : 800,
            'background'  => 'bg_default',
            'music'       => '',
            'spawns'      => [
                'red'  => defined('RED_SPAWNS')  ? RED_SPAWNS  : [['x'=>80,  'y'=>712]],
                'blue' => defined('BLUE_SPAWNS') ? BLUE_SPAWNS : [['x'=>4688,'y'=>712]],
            ],
            'ctf_flags'   => defined('CTF_FLAGS') ? CTF_FLAGS : [],
            'platforms'   => MAP_PLATFORMS,
            'wall_jump_zones'    => [],
            'climb_surfaces'     => [],
            'special_zones'      => [],
            'interactive_objects'=> [],
        ];

        $this->stats['default'] = [
            'play_count'  => 0, 'red_wins' => 0, 'blue_wins' => 0,
            'draws' => 0, 'total_kills' => 0, 'last_played' => null,
        ];
    }

    // ──────────────────────────────────────────────────
    //  Validasyon
    // ──────────────────────────────────────────────────
    private function validate(array $data): array
    {
        $errors = [];
        $required = ['id', 'name', 'modes', 'width', 'height', 'spawns', 'platforms'];

        foreach ($required as $key) {
            if (!isset($data[$key])) $errors[] = "Eksik alan: {$key}";
        }

        if (isset($data['spawns'])) {
            if (empty($data['spawns']['red']))  $errors[] = "Eksik red spawn";
            if (empty($data['spawns']['blue'])) $errors[] = "Eksik blue spawn";
        }

        if (isset($data['platforms']) && !is_array($data['platforms'])) {
            $errors[] = "platforms dizi olmalı";
        }

        return $errors;
    }

    // ──────────────────────────────────────────────────
    //  Erişim
    // ──────────────────────────────────────────────────

    /** Tüm map listesi (lobi seçimi için) */
    public function getAll(): array
    {
        return $this->maps;
    }

    /** Belirli bir map */
    public function get(string $id): ?array
    {
        return $this->maps[$id] ?? null;
    }

    /** Map var mı? */
    public function exists(string $id): bool
    {
        return isset($this->maps[$id]);
    }

    /** Desteklenen ID listesi */
    public function getIds(): array
    {
        return array_keys($this->maps);
    }

    /** Moda göre uygun map'leri getir */
    public function getByMode(string $mode): array
    {
        return array_filter($this->maps, fn($m) => in_array($mode, $m['modes'] ?? []));
    }

    // ──────────────────────────────────────────────────
    //  Random Seçim
    // ──────────────────────────────────────────────────

    /**
     * Rastgele bir map seç. Mode belirtilirse sadece uyumlu maplerden seç.
     */
    public function getRandom(?string $mode = null): ?array
    {
        $pool = $mode ? $this->getByMode($mode) : $this->maps;
        if (empty($pool)) return null;

        $keys = array_keys($pool);
        return $pool[$keys[array_rand($keys)]];
    }

    /**
     * En az oynanan maplerden rastgele seç (dengeli rotasyon).
     */
    public function getBalancedRandom(?string $mode = null): ?array
    {
        $pool = $mode ? $this->getByMode($mode) : $this->maps;
        if (empty($pool)) return null;

        // play_count'a göre ağırlıklı rastgele (az oynanan daha yüksek şans)
        $minPlayed = PHP_INT_MAX;
        foreach ($pool as $id => $_) {
            $minPlayed = min($minPlayed, $this->stats[$id]['play_count'] ?? 0);
        }

        $candidates = array_filter(
            array_keys($pool),
            fn($id) => ($this->stats[$id]['play_count'] ?? 0) <= $minPlayed + 2
        );

        if (empty($candidates)) $candidates = array_keys($pool);

        $picked = $candidates[array_rand($candidates)];
        return $pool[$picked];
    }

    // ──────────────────────────────────────────────────
    //  Room İçin Platform / Spawn Verisi
    // ──────────────────────────────────────────────────

    /** Sadece platform dizisi (Room::$platforms için) */
    public function getPlatforms(string $mapId): array
    {
        $map = $this->get($mapId);
        if (!$map) return defined('MAP_PLATFORMS') ? MAP_PLATFORMS : [];

        // Tip filtrele — sadece solid ve wall platformlar fizik etkiler
        return array_values(array_filter(
            $map['platforms'],
            fn($p) => in_array($p['type'] ?? 'solid', ['solid', 'wall'])
        ));
    }

    /** Spawn noktaları [red => [...], blue => [...]] */
    public function getSpawns(string $mapId): array
    {
        $map = $this->get($mapId);
        if (!$map) {
            return [
                'red'  => defined('RED_SPAWNS')  ? RED_SPAWNS  : [['x'=>80,  'y'=>712]],
                'blue' => defined('BLUE_SPAWNS') ? BLUE_SPAWNS : [['x'=>4688,'y'=>712]],
            ];
        }
        return $map['spawns'];
    }

    /** CTF bayrak konumları */
    public function getCtfFlags(string $mapId): array
    {
        $map = $this->get($mapId);
        if (!$map || empty($map['ctf_flags'])) {
            return defined('CTF_FLAGS') ? CTF_FLAGS : [];
        }
        return $map['ctf_flags'];
    }

    /** Wall-jump bölgeleri */
    public function getWallJumpZones(string $mapId): array
    {
        return $this->get($mapId)['wall_jump_zones'] ?? [];
    }

    /** Tırmanılabilir yüzeyler */
    public function getClimbSurfaces(string $mapId): array
    {
        return $this->get($mapId)['climb_surfaces'] ?? [];
    }

    /** İnteraktif objeler (başlangıç durumunda) */
    public function getInteractiveObjects(string $mapId): array
    {
        return $this->get($mapId)['interactive_objects'] ?? [];
    }

    /** Özel mekanik bölgeleri */
    public function getSpecialZones(string $mapId): array
    {
        return $this->get($mapId)['special_zones'] ?? [];
    }

    /** Map boyutları */
    public function getDimensions(string $mapId): array
    {
        $map = $this->get($mapId);
        return [
            'width'  => $map['width']  ?? (defined('MAP_WIDTH')  ? MAP_WIDTH  : 4800),
            'height' => $map['height'] ?? (defined('MAP_HEIGHT') ? MAP_HEIGHT : 800),
        ];
    }

    // ──────────────────────────────────────────────────
    //  İstatistikler
    // ──────────────────────────────────────────────────

    /** Oyun başladığında çağır */
    public function recordPlay(string $mapId): void
    {
        if (!isset($this->stats[$mapId])) return;
        $this->stats[$mapId]['play_count']++;
        $this->stats[$mapId]['last_played'] = date('Y-m-d H:i:s');
        $this->persistStats($mapId);
    }

    /** Oyun bittiğinde çağır */
    public function recordResult(string $mapId, string $winner, int $totalKills = 0): void
    {
        if (!isset($this->stats[$mapId])) return;

        if ($winner === 'red')       $this->stats[$mapId]['red_wins']++;
        elseif ($winner === 'blue')  $this->stats[$mapId]['blue_wins']++;
        else                         $this->stats[$mapId]['draws']++;

        $this->stats[$mapId]['total_kills'] += $totalKills;
        $this->persistStats($mapId);
    }

    /** İstatistikleri getir */
    public function getStats(string $mapId): array
    {
        return $this->stats[$mapId] ?? [];
    }

    /** Tüm istatistikler */
    public function getAllStats(): array
    {
        return $this->stats;
    }

    /**
     * İstatistikleri dosyaya yaz (basit JSON persistence).
     * DB entegrasyonu varsa Database::saveMapStats() çağrılabilir.
     */
    private function persistStats(string $mapId): void
    {
        if (!defined('MAPS_DIR')) return;

        $statsFile = MAPS_DIR . '/../data/map_stats.json';
        $dir = dirname($statsFile);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Mevcut dosyayı oku
        $all = [];
        if (file_exists($statsFile)) {
            $raw = file_get_contents($statsFile);
            if ($raw) $all = json_decode($raw, true) ?? [];
        }

        $all[$mapId] = $this->stats[$mapId];
        file_put_contents($statsFile, json_encode($all, JSON_PRETTY_PRINT));
    }

    /**
     * Kaydedilmiş istatistikleri dosyadan yükle.
     * bootstrap.php başlangıcında çağrılabilir.
     */
    public function loadPersistedStats(): void
    {
        if (!defined('MAPS_DIR')) return;
        $statsFile = MAPS_DIR . '/../data/map_stats.json';
        if (!file_exists($statsFile)) return;

        $raw = file_get_contents($statsFile);
        if (!$raw) return;

        $saved = json_decode($raw, true) ?? [];
        foreach ($saved as $mapId => $stat) {
            if (isset($this->stats[$mapId])) {
                $this->stats[$mapId] = array_merge($this->stats[$mapId], $stat);
            }
        }
    }

    // ──────────────────────────────────────────────────
    //  Lobi / UI İçin Özet
    // ──────────────────────────────────────────────────

    /** Lobi ekranı için harita listesi (thumbnail, mod bilgisi dahil) */
    public function getLobbyList(?string $filterMode = null): array
    {
        $pool = $filterMode ? $this->getByMode($filterMode) : $this->maps;
        $list = [];

        foreach ($pool as $id => $map) {
            $stats = $this->stats[$id] ?? [];
            $list[] = [
                'id'          => $id,
                'name'        => $map['name'],
                'description' => $map['description'] ?? '',
                'thumbnail'   => $map['thumbnail'] ?? '',
                'modes'       => $map['modes'] ?? [],
                'width'       => $map['width'],
                'height'      => $map['height'],
                'play_count'  => $stats['play_count'] ?? 0,
                'last_played' => $stats['last_played'] ?? null,
            ];
        }

        return $list;
    }

    /** Manuel yeniden tarama (hot-reload için) */
    public function reload(): void
    {
        echo "[MapManager] Yeniden taranıyor...\n";
        $this->scanMaps();
        $this->loadPersistedStats();
    }
}
