<?php
// Veritabanı dosyası yolu
$db_file = 'game.db';

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. ADIM: Veritabanındaki tüm tabloları listele (Hangi tablo olduğunu bulmak için)
    $tables_query = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    $tables = $tables_query->fetchAll(PDO::FETCH_COLUMN);

    // WolfTeam2D için muhtemel tablo isimleri: 'players' veya 'accounts'
    // Eğer 'users' yoksa listedeki ilk tabloyu varsayılan seçelim
    $target_table = in_array('users', $tables) ? 'users' : (in_array('players', $tables) ? 'players' : $tables[0]);

    // 2. ADIM: Güncelleme İşlemi (Admin Yap/Al)
    if (isset($_GET['action']) && isset($_GET['id'])) {
        $id = $_GET['id'];
        // Sütun isminin 'is_admin' veya 'role' olma ihtimaline göre burayı düzenleyebilirsin
        if ($_GET['action'] == 'make_admin') {
            $stmt = $db->prepare("UPDATE $target_table SET is_admin = 1 WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($_GET['action'] == 'remove_admin') {
            $stmt = $db->prepare("UPDATE $target_table SET is_admin = 0 WHERE id = ?");
            $stmt->execute([$id]);
        }
        header("Location: editor.php");
        exit;
    }

    // 3. ADIM: Verileri Çek
    $users = $db->query("SELECT * FROM $target_table LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Hata: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Game.db Düzenleyici</title>
    <style>
        body { font-family: sans-serif; background: #121212; color: #e0e0e0; padding: 20px; }
        .info { background: #1e1e1e; padding: 10px; border-left: 4px solid #4f8ef7; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 12px; text-align: left; }
        th { background: #252525; color: #4f8ef7; }
        tr:hover { background: #1a1a1a; }
        .btn { padding: 6px 12px; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: bold; }
        .btn-make { background: #3ecf6e; color: #000; }
        .btn-remove { background: #e05555; color: #fff; }
        .status { font-weight: bold; color: #3ecf6e; }
    </style>
</head>
<body>
    <h2>🚀 Veritabanı Düzenleyici</h2>
    
    <div class="info">
        <strong>Mevcut Tablolar:</strong> <?php echo implode(', ', $tables); ?><br>
        <strong>Şu an düzenlenen tablo:</strong> <span style="color: #f5c842;"><?php echo $target_table; ?></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Kullanıcı Adı</th>
                <th>Admin mi?</th>
                <th>İşlemler</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username'] ?? $u['name'] ?? 'Bilinmiyor'); ?></td>
                <td><?php echo (isset($u['is_admin']) && $u['is_admin']) ? '<span class="status">EVET</span>' : 'Hayır'; ?></td>
                <td>
                    <?php if (isset($u['is_admin']) && $u['is_admin']): ?>
                        <a href="?action=remove_admin&id=<?php echo $u['id']; ?>" class="btn btn-remove">Adminliği Al</a>
                    <?php else: ?>
                        <a href="?action=make_admin&id=<?php echo $u['id']; ?>" class="btn btn-make">Admin Yap</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>