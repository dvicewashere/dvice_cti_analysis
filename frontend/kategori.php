<?php
require_once '/var/www/backend/config/config.php';
rolKontrol('admin');

$db = dbBaglanti();
$mesaj = '';
$hata = '';

// Kullanıcı bilgilerini getir
$kullanici_bilgileri = null;
if (isset($_SESSION['kullanici_id'])) {
    try {
        $stmt = $db->prepare("SELECT kullanici_adi, rol, olusturma_tarihi, son_giris FROM kullanicilar WHERE id = ?");
        $stmt->execute([$_SESSION['kullanici_id']]);
        $kullanici_bilgileri = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Hata durumunda sessizce devam et
    }
}

// Kategori ekleme/güncelleme/silme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && in_array($_POST['islem'], ['kategori_ekle', 'kategori_guncelle', 'kategori_sil'], true)) {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        try {
            if ($_POST['islem'] === 'kategori_ekle') {
                $kategori_adi = trim($_POST['kategori_adi'] ?? '');
                $aciklama = trim($_POST['aciklama'] ?? '');
                $renk = trim($_POST['renk'] ?? '#3498db');
                if ($kategori_adi === '') {
                    $hata = 'Kategori adı zorunludur.';
                } else {
                    $stmt = $db->prepare("INSERT INTO kategoriler (kategori_adi, ana_kategori, aciklama, renk) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$kategori_adi, $kategori_adi, $aciklama, $renk]);
                    $mesaj = 'Kategori eklendi.';
                    logKaydet("Kategori eklendi: $kategori_adi");
                }
            } elseif ($_POST['islem'] === 'kategori_guncelle') {
                $id = intval($_POST['id'] ?? 0);
                $aciklama = trim($_POST['aciklama'] ?? '');
                $renk = trim($_POST['renk'] ?? '#3498db');
                if ($id <= 0) {
                    $hata = 'Geçersiz kategori.';
                } else {
                    $stmt = $db->prepare("UPDATE kategoriler SET aciklama = ?, renk = ? WHERE id = ?");
                    $stmt->execute([$aciklama, $renk, $id]);
                    $mesaj = 'Kategori güncellendi.';
                }
            } elseif ($_POST['islem'] === 'kategori_sil') {
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $hata = 'Geçersiz kategori.';
                } else {
                    $stmt = $db->prepare("DELETE FROM kategoriler WHERE id = ?");
                    $stmt->execute([$id]);
                    $mesaj = 'Kategori silindi.';
                }
            }
        } catch (PDOException $e) {
            $hata = 'Kategori işlemi sırasında hata oluştu.';
            logKaydet("Kategori işlem hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Log renkleri güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'log_renk_guncelle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        try {
            $log_seviye = trim($_POST['log_seviye'] ?? '');
            $renk = trim($_POST['renk'] ?? '');
            if (!in_array($log_seviye, ['ERROR', 'WARNING', 'INFO'], true)) {
                $hata = 'Geçersiz log seviyesi.';
            } else {
                
                $stmt = $db->prepare("INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri, aciklama) VALUES (?, ?, ?) ON CONFLICT (ayar_adi) DO UPDATE SET ayar_degeri = EXCLUDED.ayar_degeri");
                $stmt->execute(['log_renk_' . strtolower($log_seviye), $renk, 'Log seviyesi ' . $log_seviye . ' için arka plan rengi']);
                $mesaj = 'Log rengi güncellendi.';
                logKaydet("Log rengi güncellendi: $log_seviye -> $renk");
            }
        } catch (PDOException $e) {
            $hata = 'Log rengi güncellenirken hata oluştu.';
            logKaydet("Log rengi güncelleme hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Kritiklik eşikleri güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'kritiklik_guncelle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        try {
            $id = intval($_POST['id'] ?? 0);
            $min_skor = intval($_POST['min_skor'] ?? 0);
            $max_skor = intval($_POST['max_skor'] ?? 100);
            $renk = trim($_POST['renk'] ?? '#95a5a6');
            if ($id <= 0) {
                $hata = 'Geçersiz eşik.';
            } else {
                $stmt = $db->prepare("UPDATE kritiklik_esikleri SET min_skor = ?, max_skor = ?, renk = ? WHERE id = ?");
                $stmt->execute([$min_skor, $max_skor, $renk, $id]);
                $mesaj = 'Kritiklik eşiği güncellendi.';
            }
        } catch (PDOException $e) {
            $hata = 'Kritiklik eşiği güncellenirken hata oluştu.';
            logKaydet("Kritiklik eşiği güncelleme hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}

$kategoriler = $db->query("SELECT * FROM kategoriler ORDER BY kategori_adi")->fetchAll(PDO::FETCH_ASSOC);
$kritiklik_esikleri = $db->query("SELECT * FROM kritiklik_esikleri ORDER BY min_skor")->fetchAll(PDO::FETCH_ASSOC);

// Log renklerini getir
$log_renkleri = [
    'ERROR' => '#ff4444',
    'WARNING' => '#ffaa00',
    'INFO' => '#39ff14'
];
foreach (['error', 'warning', 'info'] as $seviye) {
    $stmt = $db->prepare("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = ?");
    $stmt->execute(['log_renk_' . $seviye]);
    $renk = $stmt->fetchColumn();
    if ($renk) {
        $log_renkleri[strtoupper($seviye)] = $renk;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dvice CTI - Kategori Ayarları</title>
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
</head>
<body>
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <h1>Dvice CTI - Kategori Ayarları</h1>
        <div class="header-info">
            <a href="index.php">Ana Sayfa</a>
            <a href="loglar.php" class="kayitlar-btn">Loglar</a>
            <a href="kayitlar.php" class="kayitlar-btn">Kayıtlar</a>
            <a href="adresekle.php" class="kayitlar-btn">Adres Ayarları</a>
            <a href="kullanicilar.php" class="kayitlar-btn">Kullanıcı İşlemleri</a>
            <a href="kategori.php" class="kayitlar-btn">Kategori Ayarları</a>
            <a href="tehditler.php" class="kritik-tehditler-btn">Son 24 Saat: En Kritik Tehditler</a>
            
            <!-- Kullanıcı Dropdown -->
            <div class="kullanici-dropdown-wrapper">
                <button class="kullanici-btn" id="kullaniciBtn">
                    <span class="kullanici-icon">👤</span>
                </button>
                <div class="kullanici-dropdown" id="kullaniciDropdown">
                    <div class="kullanici-dropdown-header">
                        <div class="kullanici-icon-large">👤</div>
                        <div class="kullanici-bilgi">
                            <div class="kullanici-adi"><?= guvenliCikti($kullanici_bilgileri['kullanici_adi'] ?? $_SESSION['kullanici_adi'] ?? 'Kullanıcı') ?></div>
                            <div class="kullanici-rol"><?= ucfirst(guvenliCikti($kullanici_bilgileri['rol'] ?? $_SESSION['rol'] ?? 'Kullanıcı')) ?></div>
                        </div>
                    </div>
                    <div class="kullanici-dropdown-content">
                        <div class="kullanici-detay-item">
                            <span class="detay-label">Kayıt Tarihi:</span>
                            <span class="detay-deger"><?= $kullanici_bilgileri && $kullanici_bilgileri['olusturma_tarihi'] ? tarihFormatla($kullanici_bilgileri['olusturma_tarihi']) : 'Bilinmiyor' ?></span>
                        </div>
                        <div class="kullanici-detay-item">
                            <span class="detay-label">Son Giriş:</span>
                            <span class="detay-deger"><?= $kullanici_bilgileri && $kullanici_bilgileri['son_giris'] ? tarihFormatla($kullanici_bilgileri['son_giris']) : 'İlk giriş' ?></span>
                        </div>
                    </div>
                    <div class="kullanici-dropdown-footer">
                        <a href="logout.php" class="logout-btn">Çıkış Yap</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <?php if ($mesaj): ?>
            <div class="alert alert-success">
                <div style="flex-shrink: 0">✓</div>
                <div><?= guvenliCikti($mesaj) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($hata): ?>
            <div class="alert alert-danger">
                <div style="flex-shrink: 0">⚠</div>
                <div><?= guvenliCikti($hata) ?></div>
            </div>
        <?php endif; ?>

        <div class="admin-panel-card">
            <h2>Kategoriler</h2>
            <form method="POST" style="margin-bottom: 15px;">
                <input type="hidden" name="islem" value="kategori_ekle">
                <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                <div class="form-group">
                    <label>Yeni Kategori Adı</label>
                    <input type="text" name="kategori_adi" class="form-control" placeholder="Örn: Örnek Kategori" required>
                </div>
                <div class="form-group">
                    <label>Açıklama</label>
                    <input type="text" name="aciklama" class="form-control" placeholder="Kısa açıklama">
                </div>
                <div class="form-group">
                    <label>Renk</label>
                    <input type="color" name="renk" value="#3498db" class="form-control" style="height: 42px; padding: 6px;">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Kategori Ekle</button>
                </div>
            </form>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Kategori Adı</th>
                        <th>Açıklama</th>
                        <th>Renk</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kategoriler as $kategori): ?>
                        <tr>
                            <td><?= guvenliCikti($kategori['kategori_adi']) ?></td>
                            <td>
                                <form method="POST" style="display:flex; gap:10px; align-items:center; margin:0;">
                                    <input type="hidden" name="islem" value="kategori_guncelle">
                                    <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                                    <input type="hidden" name="id" value="<?= intval($kategori['id']) ?>">
                                    <input type="text" name="aciklama" value="<?= guvenliCikti($kategori['aciklama'] ?? '') ?>" class="form-control" style="min-width: 240px;">
                            </td>
                            <td>
                                    <input type="color" name="renk" value="<?= guvenliCikti($kategori['renk'] ?? '#3498db') ?>" style="height: 32px; width: 44px; padding:0; border: none; background: transparent;">
                            </td>
                            <td style="white-space: nowrap;">
                                    <button type="submit" class="action-btn edit" style="border:none; cursor:pointer;">Kaydet</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="islem" value="kategori_sil">
                                    <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                                    <input type="hidden" name="id" value="<?= intval($kategori['id']) ?>">
                                    <button type="submit" class="action-btn delete" style="border:none; cursor:pointer;" onclick="return confirm('Bu kategoriyi silmek istediğinizden emin misiniz?')">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-panel-card">
            <h2>Kritiklik Eşikleri</h2>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Eşik Adı</th>
                        <th>Min Skor</th>
                        <th>Max Skor</th>
                        <th>Renk</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kritiklik_esikleri as $esik): ?>
                        <tr>
                            <td><?= ucfirst(guvenliCikti($esik['esik_adi'])) ?></td>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="islem" value="kritiklik_guncelle">
                                <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                                <input type="hidden" name="id" value="<?= intval($esik['id']) ?>">
                                <td><input type="number" name="min_skor" value="<?= intval($esik['min_skor']) ?>" class="form-control" style="width: 100px;"></td>
                                <td><input type="number" name="max_skor" value="<?= intval($esik['max_skor']) ?>" class="form-control" style="width: 100px;"></td>
                                <td><input type="color" name="renk" value="<?= guvenliCikti($esik['renk'] ?? '#95a5a6') ?>" style="height: 32px; width: 44px; padding:0; border: none; background: transparent;"></td>
                                <td><button type="submit" class="action-btn edit" style="border:none; cursor:pointer;">Kaydet</button></td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-panel-card">
            <h2>Log Renk Ayarları</h2>
            <p style="color: var(--color-text-muted); margin-bottom: 20px; font-size: 0.9rem;">
                Sistem loglarında görüntülenen uyarı seviyelerinin arka plan renklerini özelleştirebilirsiniz.
            </p>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Log Seviyesi</th>
                        <th>Emoji</th>
                        <th>Arka Plan Rengi</th>
                        <th>Önizleme</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $log_seviyeler = [
                        'ERROR' => ['emoji' => '❌', 'aciklama' => 'Hata mesajları'],
                        'WARNING' => ['emoji' => '⚠️', 'aciklama' => 'Uyarı mesajları'],
                        'INFO' => ['emoji' => 'ℹ️', 'aciklama' => 'Bilgi mesajları']
                    ];
                    foreach ($log_seviyeler as $seviye => $detay): 
                    ?>
                        <tr>
                            <td>
                                <strong><?= $seviye ?></strong><br>
                                <small style="color: var(--color-text-muted);"><?= $detay['aciklama'] ?></small>
                            </td>
                            <td style="font-size: 24px; text-align: center;"><?= $detay['emoji'] ?></td>
                            <td>
                                <form method="POST" style="display:flex; gap:10px; align-items:center; margin:0;">
                                    <input type="hidden" name="islem" value="log_renk_guncelle">
                                    <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                                    <input type="hidden" name="log_seviye" value="<?= $seviye ?>">
                                    <input type="color" name="renk" value="<?= guvenliCikti($log_renkleri[$seviye]) ?>" style="height: 40px; width: 60px; padding:0; border: 1px solid var(--color-border); border-radius: 4px; cursor: pointer;">
                                    <button type="submit" class="action-btn edit" style="border:none; cursor:pointer;">Kaydet</button>
                                </form>
                            </td>
                            <td>
                                <div class="log-renk-onizleme" style="padding: 8px 12px; border-radius: 4px; background: <?= guvenliCikti($log_renkleri[$seviye]) ?>20; border-left: 3px solid <?= guvenliCikti($log_renkleri[$seviye]) ?>; color: <?= guvenliCikti($log_renkleri[$seviye]) ?>; font-family: monospace; font-size: 12px;">
                                    <?= $detay['emoji'] ?> [<?= date('Y-m-d H:i:s') ?>] [<?= $seviye ?>] Örnek log mesajı
                                </div>
                            </td>
                            <td>
                                <span style="color: var(--color-text-muted); font-size: 0.85rem;"><?= guvenliCikti($log_renkleri[$seviye]) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Kullanıcı Dropdown
        document.addEventListener('DOMContentLoaded', function() {
            const kullaniciBtn = document.getElementById('kullaniciBtn');
            const kullaniciDropdown = document.getElementById('kullaniciDropdown');
            
            if (kullaniciBtn && kullaniciDropdown) {
                kullaniciBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    kullaniciDropdown.classList.toggle('active');
                });
                
                // Dışarı tıklandığında kapat
                document.addEventListener('click', function(e) {
                    if (!kullaniciBtn.contains(e.target) && !kullaniciDropdown.contains(e.target)) {
                        kullaniciDropdown.classList.remove('active');
                    }
                });
            }
        });
    </script>
    
    <div class="footer-banner">
        <a href="https://github.com/dvicewashere" target="_blank" style="color: var(--color-primary); text-shadow: 0 0 8px rgba(57, 255, 20, 0.4); text-decoration: none;">Dvice was here ❤</a>
    </div>
</body>
</html>
