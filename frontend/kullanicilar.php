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

// GET parametresinden mesaj kontrolü
if (isset($_GET['mesaj'])) {
    if ($_GET['mesaj'] === 'kullanici_eklendi') {
        $mesaj = 'Kullanıcı başarıyla eklendi.';
    }
}

// Kullanıcı ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'kullanici_ekle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        $kullanici_adi = trim($_POST['kullanici_adi'] ?? '');
        $sifre = trim($_POST['sifre'] ?? '');
        $rol = trim($_POST['rol'] ?? 'analist');
        $aktif = isset($_POST['aktif']) ? 1 : 0;
        
        if (empty($kullanici_adi) || empty($sifre)) {
            $hata = 'Kullanıcı adı ve şifre gereklidir.';
        } elseif (strlen($sifre) < 8) {
            $hata = 'Şifre en az 8 karakter olmalıdır.';
        } elseif (!in_array($rol, ['admin', 'analist'])) {
            $hata = 'Geçersiz rol.';
        } else {
            try {
                // Kullanıcı adı kontrolü
                $stmt = $db->prepare("SELECT COUNT(*) FROM kullanicilar WHERE kullanici_adi = ?");
                $stmt->execute([$kullanici_adi]);
                if ($stmt->fetchColumn() > 0) {
                    $hata = 'Bu kullanıcı adı zaten kullanılıyor.';
                } else {
                    $sifre_hash = password_hash($sifre, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre_hash, rol, aktif) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$kullanici_adi, $sifre_hash, $rol, $aktif]);
                    logKaydet("Yeni kullanıcı eklendi: " . $kullanici_adi . " (Rol: " . $rol . ")");
                    
                    // Redirect to prevent form resubmission
                    header("Location: kullanicilar.php?mesaj=kullanici_eklendi");
                    exit;
                }
            } catch (PDOException $e) {
                $hata = 'Kullanıcı eklenirken hata oluştu.';
                logKaydet("Kullanıcı ekleme hatası: " . $e->getMessage(), 'ERROR');
            }
        }
    }
}

// Rol güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'rol_guncelle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        $id = intval($_POST['id'] ?? 0);
        $yeni_rol = trim($_POST['rol'] ?? '');
        
        if ($id <= 0) {
            $hata = 'Geçersiz kullanıcı.';
        } elseif (!in_array($yeni_rol, ['admin', 'analist'])) {
            $hata = 'Geçersiz rol.';
        } else {
            try {
                $stmt = $db->prepare("SELECT kullanici_adi FROM kullanicilar WHERE id = ?");
                $stmt->execute([$id]);
                $kullanici_adi = $stmt->fetchColumn();
                
                if (!$kullanici_adi) {
                    $hata = 'Kullanıcı bulunamadı.';
                } else {
                    $stmt = $db->prepare("UPDATE kullanicilar SET rol = ? WHERE id = ?");
                    $stmt->execute([$yeni_rol, $id]);
                    $mesaj = 'Kullanıcı rolü güncellendi.';
                    logKaydet("Kullanıcı rolü güncellendi: $kullanici_adi (Yeni Rol: $yeni_rol)");
                }
            } catch (PDOException $e) {
                $hata = 'Rol güncellenirken hata oluştu.';
                logKaydet("Rol güncelleme hatası: " . $e->getMessage(), 'ERROR');
            }
        }
    }
}

// Kullanıcı silme işlemi
if (isset($_GET['islem']) && isset($_GET['id'])) {
    $islem = $_GET['islem'];
    $id = intval($_GET['id']);
    
    if ($islem === 'kullanici_sil') {
        // Kendi hesabını silemesin
        if ($id == $_SESSION['kullanici_id']) {
            $hata = 'Kendi hesabınızı silemezsiniz.';
        } else {
            try {
                $stmt = $db->prepare("SELECT kullanici_adi FROM kullanicilar WHERE id = ?");
                $stmt->execute([$id]);
                $kullanici_adi = $stmt->fetchColumn();
                
                $stmt = $db->prepare("DELETE FROM kullanicilar WHERE id = ?");
                $stmt->execute([$id]);
                $mesaj = 'Kullanıcı silindi.';
                logKaydet("Kullanıcı silindi: $kullanici_adi (ID: $id)");
            } catch (PDOException $e) {
                $hata = 'Kullanıcı silinirken hata oluştu.';
                logKaydet("Kullanıcı silme hatası: " . $e->getMessage(), 'ERROR');
            }
        }
    }
}

// Filtreleme parametreleri
$kullanici_adi_filtre = $_GET['kullanici_adi'] ?? '';
$rol_filtre = $_GET['rol'] ?? '';

// Filtreleme sorgusu
$where = [];
$params = [];

if ($kullanici_adi_filtre) {
    $where[] = "kullanici_adi ILIKE ?";
    $params[] = "%$kullanici_adi_filtre%";
}

if ($rol_filtre) {
    $where[] = "rol = ?";
    $params[] = $rol_filtre;
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// Kullanıcıları getir
$sql = "SELECT id, kullanici_adi, rol, aktif, son_giris FROM kullanicilar $where_sql ORDER BY id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$kullanicilar = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtre mesajı
$filtre_mesaji = '';
if ($kullanici_adi_filtre || $rol_filtre) {
    $filtre_mesaji = 'Filtreler uygulandı.';
}
if (isset($_GET['temizle'])) {
    $filtre_mesaji = 'Filtreler temizlendi.';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dvice CTI - Kullanıcı İşlemleri</title>
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
    <style>
        @media (max-width: 1024px) {
            .kullanicilar-layout {
                grid-template-columns: 1fr !important;
            }
        }
        
        /* Scrollbar Styling */
        .kullanicilar-layout div[style*="overflow-y: auto"]::-webkit-scrollbar {
            width: 8px;
        }
        
        .kullanicilar-layout div[style*="overflow-y: auto"]::-webkit-scrollbar-track {
            background: var(--color-surface);
            border-radius: 4px;
        }
        
        .kullanicilar-layout div[style*="overflow-y: auto"]::-webkit-scrollbar-thumb {
            background: var(--color-border);
            border-radius: 4px;
        }
        
        .kullanicilar-layout div[style*="overflow-y: auto"]::-webkit-scrollbar-thumb:hover {
            background: var(--color-primary);
        }
    </style>
</head>
<body>
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <h1>Dvice CTI - Kullanıcı İşlemleri</h1>
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

        <!-- Yan Yana  -->
        <div class="kullanicilar-layout" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
            <!-- Sol Taraf: Yeni Kullanıcı Ekle -->
            <div class="admin-panel-card" style="padding: 16px;">
                <h2 style="font-size: 18px; margin-bottom: 12px;">Yeni Kullanıcı Ekle</h2>
                <form method="POST">
                    <input type="hidden" name="islem" value="kullanici_ekle">
                    <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="font-size: 12px; margin-bottom: 4px;">Kullanıcı Adı</label>
                        <input type="text" name="kullanici_adi" class="form-control" placeholder="Kullanıcı adı" required style="padding: 6px 10px; font-size: 13px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="font-size: 12px; margin-bottom: 4px;">Şifre (min 8 karakter)</label>
                        <input type="password" name="sifre" class="form-control" placeholder="Şifre" required minlength="8" style="padding: 6px 10px; font-size: 13px;">
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="font-size: 12px; margin-bottom: 4px;">Rol</label>
                        <select name="rol" class="form-control" style="padding: 6px 10px; font-size: 13px;">
                            <option value="analist">Analist</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 10px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px;">
                            <input type="checkbox" name="aktif" checked style="width: auto;">
                            Aktif
                        </label>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <button type="submit" class="btn btn-primary" style="padding: 6px 16px; font-size: 13px; width: 100%;">Kullanıcı Ekle</button>
                    </div>
                </form>
            </div>

            <!-- Sağ Taraf: Kullanıcılar -->
            <div class="admin-panel-card" style="padding: 16px;">
                <h2 style="font-size: 18px; margin-bottom: 12px;">Kullanıcılar</h2>
                
              
                <div style="margin-bottom: 12px; padding: 10px; background: rgba(31, 111, 103, 0.1); border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                    <form method="GET" action="" id="filtre-form" style="display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 120px;">
                            <label style="display: block; margin-bottom: 4px; font-size: 11px; color: var(--color-text-muted);">Kullanıcı Adı</label>
                            <input type="text" name="kullanici_adi" value="<?= guvenliCikti($kullanici_adi_filtre) ?>" class="form-control" placeholder="Ara..." style="padding: 5px 8px; font-size: 12px;">
                        </div>

                        <div style="flex: 0 0 100px;">
                            <label style="display: block; margin-bottom: 4px; font-size: 11px; color: var(--color-text-muted);">Rol</label>
                            <select name="rol" class="form-control" style="padding: 5px 8px; font-size: 12px;">
                                <option value="">Tümü</option>
                                <option value="admin" <?= $rol_filtre === 'admin' ? 'selected' : '' ?>>Admin</option>
                                <option value="analist" <?= $rol_filtre === 'analist' ? 'selected' : '' ?>>Analist</option>
                            </select>
                        </div>

                        <div style="display: flex; gap: 6px;">
                            <button type="submit" class="btn btn-primary" style="padding: 5px 12px; font-size: 12px;">Filtrele</button>
                            <a href="kullanicilar.php" class="btn btn-secondary" style="padding: 5px 12px; font-size: 12px;">Temizle</a>
                        </div>
                    </form>
                    
                    <?php if ($filtre_mesaji): ?>
                        <div class="filtre-mesaj" style="margin-top: 8px; padding: 6px 10px; background: rgba(57, 255, 20, 0.1); border: 1px solid var(--color-primary); border-radius: 4px; color: var(--color-primary); font-size: 11px;">
                            <?= guvenliCikti($filtre_mesaji) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3 satır yüksekliği -->
                <div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--radius-md);">
                    <table class="admin-table" style="margin: 0; font-size: 12px;">
                        <thead style="position: sticky; top: 0; background: var(--color-surface); z-index: 10;">
                            <tr>
                                <th style="padding: 6px 8px; font-size: 11px;">ID</th>
                                <th style="padding: 6px 8px; font-size: 11px;">Kullanıcı Adı</th>
                                <th style="padding: 6px 8px; font-size: 11px;">Rol</th>
                                <th style="padding: 6px 8px; font-size: 11px;">Durum</th>
                                <th style="padding: 6px 8px; font-size: 11px;">Son Giriş</th>
                                <th style="padding: 6px 8px; font-size: 11px;">İşlemler</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($kullanicilar)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px; font-size: 12px;">
                                        Kullanıcı bulunamadı.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($kullanicilar as $kullanici): ?>
                                    <tr>
                                        <td style="padding: 5px 8px;"><?= $kullanici['id'] ?></td>
                                        <td style="padding: 5px 8px;"><?= guvenliCikti($kullanici['kullanici_adi']) ?></td>
                                        <td style="padding: 5px 8px;">
                                            <form method="POST" style="display: inline-flex; align-items: center; gap: 4px; margin: 0;" action="kullanicilar.php<?= ($kullanici_adi_filtre || $rol_filtre) ? '?' . http_build_query(['kullanici_adi' => $kullanici_adi_filtre, 'rol' => $rol_filtre]) : '' ?>">
                                                <input type="hidden" name="islem" value="rol_guncelle">
                                                <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                                                <input type="hidden" name="id" value="<?= $kullanici['id'] ?>">
                                                <select name="rol" class="form-control" style="width: auto; min-width: 80px; padding: 3px 6px; font-size: 11px;" onchange="this.form.submit()">
                                                    <option value="analist" <?= $kullanici['rol'] === 'analist' ? 'selected' : '' ?>>Analist</option>
                                                    <option value="admin" <?= $kullanici['rol'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                            </form>
                                        </td>
                                        <td style="padding: 5px 8px;">
                                            <span class="badge badge-<?= $kullanici['aktif'] ? 'aktif' : 'pasif' ?>" style="font-size: 10px; padding: 2px 6px;">
                                                <?= $kullanici['aktif'] ? 'Aktif' : 'Pasif' ?>
                                            </span>
                                        </td>
                                        <td style="padding: 5px 8px; font-size: 11px;"><?= $kullanici['son_giris'] ? tarihFormatla($kullanici['son_giris']) : 'Henüz giriş yapmadı' ?></td>
                                        <td style="padding: 5px 8px;">
                                            <?php if ($kullanici['id'] != $_SESSION['kullanici_id']): ?>
                                                <a href="?islem=kullanici_sil&id=<?= $kullanici['id'] ?>&kullanici_adi=<?= urlencode($kullanici_adi_filtre) ?>&rol=<?= urlencode($rol_filtre) ?>" class="action-btn delete" 
                                                   style="padding: 3px 8px; font-size: 11px;"
                                                   onclick="return confirm('Bu kullanıcıyı silmek istediğinizden emin misiniz?')">Sil</a>
                                            <?php else: ?>
                                                <span style="color: var(--color-text-muted); font-size: 11px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Kullanıcı Dropdown - DOMContentLoaded içinde
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
