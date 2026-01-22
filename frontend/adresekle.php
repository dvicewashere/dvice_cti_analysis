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
    if ($_GET['mesaj'] === 'adres_eklendi') {
        $mesaj = 'Onion adresi başarıyla eklendi.';
    }
}

// Onion adresi ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'onion_ekle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        $adresler_text = trim($_POST['adresler'] ?? '');
        
        if (empty($adresler_text)) {
            $hata = 'En az bir onion adresi giriniz.';
        } else {
            // Her satırı ayrı adres olarak işle
            $adresler = array_filter(array_map('trim', explode("\n", $adresler_text)));
            $basarili = 0;
            $hatali = 0;
            $hata_mesajlari = [];
            
            foreach ($adresler as $adres) {
                // .onion kontrolü
                if (!preg_match('/\.onion(\/|$)/i', $adres)) {
                    $hatali++;
                    $hata_mesajlari[] = "$adres - Geçerli bir .onion adresi değil";
                    continue;
                }
                
                // Domain adını kaynak adı olarak kullan
                $kaynak_adi = parse_url($adres, PHP_URL_HOST);
                if (!$kaynak_adi) {
                    // URL parse edilemezse, sadece domain'i al http/https kısmını kaldırarak
                    $kaynak_adi = preg_replace('/^https?:\/\//', '', $adres);
                    $kaynak_adi = preg_replace('/\/.*$/', '', $kaynak_adi);
                }
                
                try {
                    // Güven skoru varsayılan 3 olarak veritabanında ayarlanacak
                    if (guvenSkoruKolonVarMi()) {
                        $stmt = $db->prepare("INSERT INTO onion_adresleri (adres, kaynak_adi, kaynak_guven_skoru) VALUES (?, ?, 3)");
                    } else {
                        $stmt = $db->prepare("INSERT INTO onion_adresleri (adres, kaynak_adi) VALUES (?, ?)");
                    }
                    $stmt->execute([$adres, $kaynak_adi]);
                    $basarili++;
                    logKaydet("Onion adresi eklendi: $adres (Kaynak: $kaynak_adi)");
                } catch (PDOException $e) {
                    $hatali++;
                    if (strpos($e->getMessage(), 'duplicate key') !== false) {
                        $hata_mesajlari[] = "$adres - Bu adres zaten kayıtlı";
                    } else {
                        $hata_mesajlari[] = "$adres - Veritabanı hatası";
                        logKaydet("Onion ekleme hatası: " . $e->getMessage(), 'ERROR');
                    }
                }
            }
            
            // Sonuç mesajı
            if ($basarili > 0) {
                $mesaj = "$basarili adres başarıyla eklendi.";
                if ($hatali > 0) {
                    $mesaj .= " $hatali adres eklenemedi.";
                }
            }
            if (!empty($hata_mesajlari)) {
                $hata = implode('<br>', $hata_mesajlari);
            }
        }
    }
}

// Tüm adresleri temizle işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'tumunu_temizle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        try {
       
            $stmt = $db->query("SELECT COUNT(*) FROM onion_adresleri");
            $toplam = $stmt->fetchColumn();
            
         
            $stmt = $db->prepare("DELETE FROM onion_adresleri");
            $stmt->execute();
            
            $mesaj = "Tüm onion adresleri temizlendi. ($toplam adres silindi)";
            logKaydet("Tüm onion adresleri temizlendi. Toplam: $toplam adres", 'WARNING');
        } catch (PDOException $e) {
            $hata = 'Temizleme işlemi başarısız.';
            logKaydet("Tüm adresleri temizleme hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Tümünü aktif et işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'tumunu_aktif_et') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        try {
            $stmt = $db->prepare("UPDATE onion_adresleri SET aktif = TRUE");
            $stmt->execute();
            $etkilenen = $stmt->rowCount();
            $mesaj = "Tüm onion adresleri aktif edildi. ($etkilenen adres)";
            logKaydet("Tüm onion adresleri aktif edildi. Toplam: $etkilenen adres", 'INFO');
        } catch (PDOException $e) {
            $hata = 'Aktif etme işlemi başarısız.';
            logKaydet("Tüm adresleri aktif etme hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Tümünü pasif et işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'tumunu_pasif_et') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        try {
            $stmt = $db->prepare("UPDATE onion_adresleri SET aktif = FALSE");
            $stmt->execute();
            $etkilenen = $stmt->rowCount();
            $mesaj = "Tüm onion adresleri pasif edildi. ($etkilenen adres)";
            logKaydet("Tüm onion adresleri pasif edildi. Toplam: $etkilenen adres", 'INFO');
        } catch (PDOException $e) {
            $hata = 'Pasif etme işlemi başarısız.';
            logKaydet("Tüm adresleri pasif etme hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}

// Silme/Aktif/Pasif işlemleri
if (isset($_GET['islem']) && isset($_GET['id'])) {
    $islem = $_GET['islem'];
    $id = intval($_GET['id']);
    
    if ($islem === 'sil') {
        try {
            $stmt = $db->prepare("DELETE FROM onion_adresleri WHERE id = ?");
            $stmt->execute([$id]);
            $mesaj = 'Onion adresi silindi.';
            logKaydet("Onion adresi silindi: ID $id");
        } catch (PDOException $e) {
            $hata = 'Silme işlemi başarısız.';
        }
    } elseif ($islem === 'aktif') {
        try {
            $stmt = $db->prepare("UPDATE onion_adresleri SET aktif = TRUE WHERE id = ?");
            $stmt->execute([$id]);
            $mesaj = 'Onion adresi aktif edildi.';
        } catch (PDOException $e) {
            $hata = 'Güncelleme başarısız.';
        }
    } elseif ($islem === 'pasif') {
        try {
            $stmt = $db->prepare("UPDATE onion_adresleri SET aktif = FALSE WHERE id = ?");
            $stmt->execute([$id]);
            $mesaj = 'Onion adresi pasif edildi.';
        } catch (PDOException $e) {
            $hata = 'Güncelleme başarısız.';
        }
    }
}

// Filtreleme parametreleri
$kaynak_filtre = $_GET['kaynak'] ?? '';
$durum_filtre = $_GET['durum'] ?? '';
$guven_skoru_filtre = $_GET['guven_skoru'] ?? '';

// Filtreleme sorgusu
$where = [];
$params = [];

if ($kaynak_filtre) {
    $where[] = "kaynak_adi ILIKE ?";
    $params[] = "%$kaynak_filtre%";
}

if ($durum_filtre !== '') {
    $where[] = "aktif = ?";
    $params[] = $durum_filtre === 'aktif' ? '1' : '0';
}

if ($guven_skoru_filtre !== '') {
    if (guvenSkoruKolonVarMi()) {
        $where[] = "kaynak_guven_skoru = ?";
        $params[] = intval($guven_skoru_filtre);
    }
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";


$sql = "SELECT * FROM onion_adresleri $where_sql ORDER BY ekleme_tarihi DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$onion_adresleri = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtre mesajı
$filtre_mesaji = '';
if ($kaynak_filtre || $durum_filtre !== '' || $guven_skoru_filtre !== '') {
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
    <title>Dvice CTI - Adres Ayarları</title>
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
    <style>
        #taramaModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        #taramaModal > div {
            background: var(--color-surface);
            border: 2px solid var(--color-primary-glow);
            border-radius: var(--radius-lg);
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: var(--glow-strong);
            text-align: center;
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>
<body>
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <h1>Dvice CTI - Adres Ayarları</h1>
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
            <h2>Onion Adresi Ekle</h2>
            <form method="POST">
                <input type="hidden" name="islem" value="onion_ekle">
                <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                <div class="form-group">
                    <label for="adresler">
                        Onion Adresleri
                        <span style="font-size:12px;color: var(--color-text-muted);">(Her satıra bir adres)</span>
                    </label>

                    <textarea
                        id="adresler"
                        name="adresler"
                        rows="8"
                        placeholder="http://2gzyxa5ihm7nsggfxv52d7nxm32c5x3i3g6nzkm7i6sawozl6pcsqd.onion&#10;http://3g2upl4pq6kufc4m.onion&#10;http://j6im4v42ur6dpic3.onion/forum&#10;http://zqktlwiuavvvqqt4ybvgvi7tyo4kjt7x3gy3s6k2ujss2xuzfpgzqd.onion/wiki&#10;http://expyuzz4wqqyqhjn.onion"
                        required
                        spellcheck="false"
                        autocomplete="off"
                        class="form-control onion-textarea"
                    ></textarea>

                    <div class="adres-info">
                        <span id="adresSayisi">0 adres</span>
                        <span id="gecersizSayisi" class="error-text"></span>
                    </div>

                    <small class="form-help">
                        -> v3 Onion adresleri otomatik doğrulanır<br>
                        -> http/https yazmanız zorunlu değildir<br>
                    </small>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Ekle</button>
                </div>
            </form>
        </div>

        <div class="admin-panel-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid var(--color-border); padding-bottom: 12px;">
                <h2 style="margin: 0; border: none; padding: 0;">Onion Adresleri</h2>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-sm" onclick="tumunuTara()" style="background: linear-gradient(135deg, var(--color-success) 0%, var(--color-secondary) 100%); border-color: var(--color-success); color: white;">
                        🔍 Tümünü Tara
                    </button>
                    <form method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Tüm onion adreslerini aktif etmek istediğinizden emin misiniz?');">
                        <input type="hidden" name="islem" value="tumunu_aktif_et">
                        <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                        <button type="submit" class="btn btn-sm" style="background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-secondary) 100%); border-color: var(--color-primary-glow); color: white;">
                            ✓ Tümünü Aktif Et
                        </button>
                    </form>
                    <form method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Tüm onion adreslerini pasif etmek istediğinizden emin misiniz?');">
                        <input type="hidden" name="islem" value="tumunu_pasif_et">
                        <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                        <button type="submit" class="btn btn-sm" style="background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); border-color: #f39c12; color: white;">
                            ⚠ Tümünü Pasif Et
                        </button>
                    </form>
                    <form method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Tüm onion adreslerini silmek istediğinizden emin misiniz? Bu işlem geri alınamaz!');">
                        <input type="hidden" name="islem" value="tumunu_temizle">
                        <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                        <button type="submit" class="btn btn-sm" style="background: linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%); border-color: var(--color-error); color: white;">
                            🗑️ Tüm Siteleri Temizle
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Filtreleme Formu -->
            <div class="filtreler">
                <h3>Filtreleme</h3>
                <form method="GET" action="" id="filtre-form">
                    <div class="filtre-grup">
                        <label>Kaynak Adı</label>
                        <input type="text" name="kaynak" value="<?= guvenliCikti($kaynak_filtre) ?>" class="form-control" placeholder="Kaynak adı ara...">
                    </div>

                    <div class="filtre-grup">
                        <label>Durum</label>
                        <select name="durum" class="form-control">
                            <option value="">Tümü</option>
                            <option value="aktif" <?= $durum_filtre === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                            <option value="pasif" <?= $durum_filtre === 'pasif' ? 'selected' : '' ?>>Pasif</option>
                        </select>
                    </div>

                    <?php if (guvenSkoruKolonVarMi()): ?>
                    <div class="filtre-grup">
                        <label>Güven Skoru</label>
                        <select name="guven_skoru" class="form-control">
                            <option value="">Tümü</option>
                            <option value="1" <?= $guven_skoru_filtre === '1' ? 'selected' : '' ?>>1 Yıldız</option>
                            <option value="2" <?= $guven_skoru_filtre === '2' ? 'selected' : '' ?>>2 Yıldız</option>
                            <option value="3" <?= $guven_skoru_filtre === '3' ? 'selected' : '' ?>>3 Yıldız</option>
                            <option value="4" <?= $guven_skoru_filtre === '4' ? 'selected' : '' ?>>4 Yıldız</option>
                            <option value="5" <?= $guven_skoru_filtre === '5' ? 'selected' : '' ?>>5 Yıldız</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="filtre-grup">
                        <button type="submit" class="btn btn-primary">Filtrele</button>
                        <a href="adresekle.php" class="btn btn-secondary">Temizle</a>
                    </div>
                </form>
                
                <?php if ($filtre_mesaji): ?>
                    <div class="filtre-mesaj" style="margin-top: 12px; padding: 8px 12px; background: rgba(57, 255, 20, 0.1); border: 1px solid var(--color-primary); border-radius: 4px; color: var(--color-primary);">
                        <?= guvenliCikti($filtre_mesaji) ?>
                    </div>
                <?php endif; ?>
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Adres</th>
                        <th>Kaynak Adı</th>
                        <th>Güven Skoru</th>
                        <th>Durum</th>
                        <th>Son Tarama</th>
                        <th>Toplam Kayıt</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($onion_adresleri)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                Henüz onion adresi eklenmemiş.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($onion_adresleri as $onion): ?>
                            <tr>
                                <td><?= $onion['id'] ?></td>
                                <td style="font-family: monospace;"><?= guvenliCikti($onion['adres']) ?></td>
                                <td><?= guvenliCikti($onion['kaynak_adi']) ?></td>
                                <td>
                                    <?php 
                                    $guven_skoru = guvenSkoruGetir($onion);
                                    echo guvenSkoruYildizlar($guven_skoru) . ' (' . $guven_skoru . '/5)';
                                    ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $onion['aktif'] ? 'aktif' : 'pasif' ?>">
                                        <?= $onion['aktif'] ? 'Aktif' : 'Pasif' ?>
                                    </span>
                                </td>
                                <td><?= $onion['son_tarama'] ? tarihFormatla($onion['son_tarama']) : 'Henüz taranmadı' ?></td>
                                <td><?= $onion['toplam_kayit'] ?></td>
                                <td>
                                    <a href="#" class="action-btn edit tara-buton" data-id="<?= $onion['id'] ?>" 
                                       style="background: linear-gradient(135deg, var(--color-success) 0%, var(--color-secondary) 100%); color: var(--color-bg); padding: 5px 10px; border-radius: 3px; border: 1px solid var(--color-success);">
                                        Tara
                                    </a>
                                    <?php if ($onion['aktif']): ?>
                                        <a href="?islem=pasif&id=<?= $onion['id'] ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&durum=<?= urlencode($durum_filtre) ?>&guven_skoru=<?= urlencode($guven_skoru_filtre) ?>" class="action-btn edit">Pasif Et</a>
                                    <?php else: ?>
                                        <a href="?islem=aktif&id=<?= $onion['id'] ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&durum=<?= urlencode($durum_filtre) ?>&guven_skoru=<?= urlencode($guven_skoru_filtre) ?>" class="action-btn edit">Aktif Et</a>
                                    <?php endif; ?>
                                    <a href="?islem=sil&id=<?= $onion['id'] ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&durum=<?= urlencode($durum_filtre) ?>&guven_skoru=<?= urlencode($guven_skoru_filtre) ?>" class="action-btn delete" 
                                       onclick="return confirm('Bu onion adresini silmek istediğinizden emin misiniz?')">Sil</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tarama İlerleme Modal -->
    <div id="taramaModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.9); z-index: 10000; justify-content: center; align-items: center;">
        <div style="background: var(--color-surface); border: 2px solid var(--color-primary-glow); border-radius: var(--radius-lg); padding: 30px; max-width: 500px; width: 90%; box-shadow: var(--glow-strong); text-align: center;">
            <h2 style="color: var(--color-primary-glow); margin-bottom: 20px; text-shadow: 0 0 10px rgba(57, 255, 20, 0.5);">
                🔍 Tarama İşlemi Devam Ediyor
            </h2>
            <div style="margin-bottom: 20px;">
                <div style="font-size: 48px; font-weight: bold; color: var(--color-primary-glow); text-shadow: 0 0 15px rgba(57, 255, 20, 0.8); margin-bottom: 10px;">
                    <span id="tarananSayisi">0</span> / <span id="toplamSayisi">0</span>
                </div>
                <div style="color: var(--color-text-muted); font-size: 14px; margin-bottom: 20px;">
                    Taranan / Toplam
                </div>
                <div style="background: rgba(0, 0, 0, 0.5); border-radius: 10px; height: 20px; overflow: hidden; margin-bottom: 20px;">
                    <div id="ilerlemeBar" style="background: linear-gradient(90deg, var(--color-primary-glow) 0%, var(--color-success) 100%); height: 100%; width: 0%; transition: width 0.3s ease; box-shadow: 0 0 10px rgba(57, 255, 20, 0.6);"></div>
                </div>
                <div id="durumMesaji" style="color: var(--color-primary); font-size: 14px; margin-bottom: 20px;">
                    Hazırlanıyor...
                </div>
            </div>
            <div style="padding: 15px; background: rgba(255, 170, 0, 0.1); border: 1px solid #ffaa00; border-radius: var(--radius-sm); margin-bottom: 20px;">
                <p style="color: #ffaa00; margin: 0; font-size: 13px; line-height: 1.5;">
                    ⚠️ <strong>Önemli:</strong> İşlem bitene kadar bu sayfadan ayrılmayın!<br>
                    Tarama işlemi arka planda devam edecektir.
                </p>
            </div>
            <div id="tamamlandiMesaji" style="display: none; padding: 15px; background: rgba(57, 255, 20, 0.1); border: 1px solid var(--color-primary-glow); border-radius: var(--radius-sm); margin-bottom: 20px;">
                <p style="color: var(--color-primary-glow); margin: 0; font-size: 14px; font-weight: bold;">
                    ✓ Tüm taramalar başlatıldı!
                </p>
                <p style="color: var(--color-text-muted); margin: 5px 0 0 0; font-size: 12px;">
                    Sayfa 3 saniye sonra yenilenecek...
                </p>
            </div>
            <div id="taramaButonlari" style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                <button id="duraklatButon" onclick="duraklatTara()" style="display: none; padding: 10px 20px; background: linear-gradient(135deg, #ffaa00 0%, #ff8800 100%); border: 2px solid #ffaa00; color: white; border-radius: var(--radius-sm); cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.3s ease;">
                    ⏸️ Duraklat
                </button>
                <button id="devamEtButon" onclick="devamEtTara()" style="display: none; padding: 10px 20px; background: linear-gradient(135deg, var(--color-success) 0%, var(--color-secondary) 100%); border: 2px solid var(--color-success); color: white; border-radius: var(--radius-sm); cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.3s ease;">
                    ▶️ Devam Et
                </button>
                <button id="iptalEtButon" onclick="iptalEtTara()" style="padding: 10px 20px; background: linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%); border: 2px solid var(--color-error); color: white; border-radius: var(--radius-sm); cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.3s ease;">
                    ❌ İptal Et
                </button>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('.tara-buton').forEach(function(buton) {
            buton.addEventListener('click', function(e) {
                e.preventDefault();
                const onionId = this.getAttribute('data-id');
                const buton = this;
                const orijinalText = buton.textContent;
                
                buton.style.opacity = '0.6';
                buton.style.pointerEvents = 'none';
                buton.textContent = 'Taranıyor...';
                
                fetch('backend/api/api_tarama.php?id=' + onionId, {
                    method: 'GET'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.basarili) {
                        buton.textContent = 'Tarama Başlatıldı ✓';
                        buton.style.background = 'linear-gradient(135deg, var(--color-success) 0%, var(--color-secondary) 100%)';
                        
                        setTimeout(() => {
                            buton.textContent = orijinalText;
                            buton.style.opacity = '1';
                            buton.style.pointerEvents = 'auto';
                        }, 3000);
                        
                        const mesajDiv = document.createElement('div');
                        mesajDiv.className = 'mesaj basarili';
                        mesajDiv.textContent = 'Tarama başlatıldı: ' + data.mesaj;
                        document.querySelector('.container').insertBefore(mesajDiv, document.querySelector('.container').firstChild);
                        
                        setTimeout(() => {
                            mesajDiv.remove();
                        }, 5000);
                    } else {
                        buton.textContent = 'Hata!';
                        buton.style.background = 'linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%)';
                        alert('Tarama başlatılamadı: ' + (data.mesaj || 'Bilinmeyen hata'));
                    }
                })
                .catch(error => {
                    console.error('Hata:', error);
                    buton.textContent = 'Hata!';
                    buton.style.background = 'linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%)';
                    alert('Tarama başlatılamadı. Go collector servisinin çalıştığından emin olun.');
                    
                    setTimeout(() => {
                        buton.textContent = orijinalText;
                        buton.style.opacity = '1';
                        buton.style.pointerEvents = 'auto';
                        buton.style.background = 'linear-gradient(135deg, var(--color-success) 0%, var(--color-secondary) 100%)';
                    }, 3000);
                });
            });
        });
    </script>
    
    <script>
    const textarea = document.getElementById('adresler');
    const adresSayisi = document.getElementById('adresSayisi');
    const gecersizSayisi = document.getElementById('gecersizSayisi');


    const onionRegex = /^((http|https):\/\/)?[a-z2-7]{56}\.onion(\/.*)?$/;

    textarea.addEventListener('input', () => {
        const satirlar = textarea.value
            .split('\n')
            .map(s => s.trim())
            .filter(Boolean);

        let gecersiz = 0;

        satirlar.forEach(adres => {
            if (!onionRegex.test(adres)) {
                gecersiz++;
            }
        });

        adresSayisi.textContent = `${satirlar.length} adres`;
        gecersizSayisi.textContent = gecersiz
            ? `${gecersiz} geçersiz adres`
            : '';
    });
    </script>
    
    <script>
        // Global değişkenler
        let taramaDuraklatildi = false;
        let taramaIptalEdildi = false;
        let taramaTimeout = null;
        let aktifOnionIdler = [];
        let taramaIndex = 0;
        let taramaBasarili = 0;
        let taramaHatali = 0;
        let taramaBeforeUnloadHandler = null;
        
        // Tümünü tara fonksiyonu
        function tumunuTara() {
            if (!confirm('Tüm aktif onion adreslerini taramak istediğinizden emin misiniz? Bu işlem zaman alabilir.')) {
                return;
            }
            
            // Tüm aktif onion ID'lerini al (sadece aktif olanlar)
            const taraButonlari = document.querySelectorAll('.tara-buton');
            aktifOnionIdler = [];
            
            taraButonlari.forEach(buton => {
                const row = buton.closest('tr');
                if (!row) return;
                
                // Badge'i bul - "Aktif" yazısını içeren 
                const badge = row.querySelector('.badge');
                if (badge && badge.textContent.trim() === 'Aktif') {
                    const id = buton.getAttribute('data-id');
                    if (id) {
                        aktifOnionIdler.push({
                            id: id,
                            buton: buton,
                            row: row
                        });
                    }
                }
            });
            
            console.log('Bulunan aktif adresler:', aktifOnionIdler.length);
            
            if (aktifOnionIdler.length === 0) {
                alert('Aktif onion adresi bulunamadı!');
                return;
            }
            
            // Modal'ı göster
            const modal = document.getElementById('taramaModal');
            const toplamSayisi = document.getElementById('toplamSayisi');
            const tarananSayisi = document.getElementById('tarananSayisi');
            const ilerlemeBar = document.getElementById('ilerlemeBar');
            const durumMesaji = document.getElementById('durumMesaji');
            const tamamlandiMesaji = document.getElementById('tamamlandiMesaji');
            const duraklatButon = document.getElementById('duraklatButon');
            const devamEtButon = document.getElementById('devamEtButon');
            const iptalEtButon = document.getElementById('iptalEtButon');
            
            modal.style.display = 'flex';
            toplamSayisi.textContent = aktifOnionIdler.length;
            tarananSayisi.textContent = '0';
            ilerlemeBar.style.width = '0%';
            durumMesaji.textContent = 'Hazırlanıyor...';
            tamamlandiMesaji.style.display = 'none';
            
            // Butonları göster/gizle
            duraklatButon.style.display = 'inline-block';
            devamEtButon.style.display = 'none';
            iptalEtButon.style.display = 'inline-block';
            
            // Sayfadan ayrılmayı engelle
            taramaBeforeUnloadHandler = function(e) {
                e.preventDefault();
                e.returnValue = 'Tarama işlemi devam ediyor. Sayfadan ayrılmak istediğinize emin misiniz?';
                return e.returnValue;
            };
            window.addEventListener('beforeunload', taramaBeforeUnloadHandler);
            
            taramaIndex = 0;
            taramaBasarili = 0;
            taramaHatali = 0;
            
            siradakiTara();
        }
        
        function siradakiTara() {
            // İptal kontrolü
            if (taramaIptalEdildi) {
                return;
            }
            
            // Duraklat kontrolü
            if (taramaDuraklatildi) {
                return;
            }
            
            if (taramaIndex >= aktifOnionIdler.length) {
                // İşlem tamamlandı
                const durumMesaji = document.getElementById('durumMesaji');
                const tamamlandiMesaji = document.getElementById('tamamlandiMesaji');
                const ilerlemeBar = document.getElementById('ilerlemeBar');
                const duraklatButon = document.getElementById('duraklatButon');
                const devamEtButon = document.getElementById('devamEtButon');
                const iptalEtButon = document.getElementById('iptalEtButon');
                
                durumMesaji.textContent = `Tamamlandı! Başarılı: ${taramaBasarili}, Hatalı: ${taramaHatali}`;
                tamamlandiMesaji.style.display = 'block';
                ilerlemeBar.style.width = '100%';
                
                // Butonları gizle
                duraklatButon.style.display = 'none';
                devamEtButon.style.display = 'none';
                iptalEtButon.style.display = 'none';
                
                // Sayfadan ayrılma engelini kaldır
                if (taramaBeforeUnloadHandler) {
                    window.removeEventListener('beforeunload', taramaBeforeUnloadHandler);
                    taramaBeforeUnloadHandler = null;
                }
                
                setTimeout(() => {
                    location.reload();
                }, 3000);
                return;
            }
            
            const { id, buton, row } = aktifOnionIdler[taramaIndex];
            const mevcutIndex = taramaIndex + 1;
            
            // İlerlemeyi güncelle
            const tarananSayisi = document.getElementById('tarananSayisi');
            const ilerlemeBar = document.getElementById('ilerlemeBar');
            const durumMesaji = document.getElementById('durumMesaji');
            
            tarananSayisi.textContent = mevcutIndex;
            const yuzde = (mevcutIndex / aktifOnionIdler.length) * 100;
            ilerlemeBar.style.width = yuzde + '%';
            durumMesaji.textContent = `Taranıyor: ${mevcutIndex}/${aktifOnionIdler.length} (ID: ${id})`;
            
            console.log(`Tarama başlatılıyor: ID ${id} (${mevcutIndex}/${aktifOnionIdler.length})`);
            
            buton.textContent = 'Taranıyor...';
            buton.style.opacity = '0.6';
            buton.style.pointerEvents = 'none';
            row.style.backgroundColor = 'rgba(57, 255, 20, 0.1)';
            
            fetch('backend/api/api_tarama.php?id=' + id, {
                method: 'POST'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                // İptal kontrolü
                if (taramaIptalEdildi) {
                    return;
                }
                
                console.log(`Tarama sonucu (ID ${id}):`, data);
                if (data.basarili) {
                    buton.textContent = 'Tarama Başlatıldı ✓';
                    buton.style.background = 'linear-gradient(135deg, var(--color-success) 0%, var(--color-secondary) 100%)';
                    row.style.backgroundColor = 'rgba(57, 255, 20, 0.15)';
                    taramaBasarili++;
                    durumMesaji.textContent = `✓ Başarılı: ${mevcutIndex}/${aktifOnionIdler.length} (ID: ${id})`;
                } else {
                    buton.textContent = 'Hata!';
                    buton.style.background = 'linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%)';
                    row.style.backgroundColor = 'rgba(255, 68, 68, 0.1)';
                    taramaHatali++;
                    durumMesaji.textContent = `✗ Hata: ${mevcutIndex}/${aktifOnionIdler.length} (ID: ${id})`;
                    console.error('Tarama hatası:', data.mesaj);
                }
                
                taramaIndex++;
                // Her tarama arasında 3 saniye bekle (Tor bağlantısı için)
                taramaTimeout = setTimeout(siradakiTara, 3000);
            })
            .catch(error => {
                // İptal kontrolü
                if (taramaIptalEdildi) {
                    return;
                }
                
                console.error('Tarama hatası (ID ' + id + '):', error);
                buton.textContent = 'Hata!';
                buton.style.background = 'linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%)';
                row.style.backgroundColor = 'rgba(255, 68, 68, 0.1)';
                taramaHatali++;
                durumMesaji.textContent = `✗ Hata: ${mevcutIndex}/${aktifOnionIdler.length} (ID: ${id})`;
                taramaIndex++;
                taramaTimeout = setTimeout(siradakiTara, 3000);
            });
        }
        
        function duraklatTara() {
            taramaDuraklatildi = true;
            const duraklatButon = document.getElementById('duraklatButon');
            const devamEtButon = document.getElementById('devamEtButon');
            const durumMesaji = document.getElementById('durumMesaji');
            
            duraklatButon.style.display = 'none';
            devamEtButon.style.display = 'inline-block';
            durumMesaji.textContent = '⏸️ Tarama duraklatıldı...';
        }
        
        function devamEtTara() {
            taramaDuraklatildi = false;
            const duraklatButon = document.getElementById('duraklatButon');
            const devamEtButon = document.getElementById('devamEtButon');
            const durumMesaji = document.getElementById('durumMesaji');
            
            duraklatButon.style.display = 'inline-block';
            devamEtButon.style.display = 'none';
            durumMesaji.textContent = '▶️ Tarama devam ediyor...';
            
            // Devam et
            siradakiTara();
        }
        
        function iptalEtTara() {
            if (!confirm('Tarama işlemini iptal etmek istediğinize emin misiniz? Devam eden taramalar durdurulacak.')) {
                return;
            }
            
            taramaIptalEdildi = true;
            taramaDuraklatildi = false;
            
            // Timeout'u temizle
            if (taramaTimeout) {
                clearTimeout(taramaTimeout);
                taramaTimeout = null;
            }
            
            // Sayfadan ayrılma engelini kaldır
            if (taramaBeforeUnloadHandler) {
                window.removeEventListener('beforeunload', taramaBeforeUnloadHandler);
                taramaBeforeUnloadHandler = null;
            }
            
            // Modal'ı kapat
            const modal = document.getElementById('taramaModal');
            const durumMesaji = document.getElementById('durumMesaji');
            
            durumMesaji.textContent = '❌ Tarama iptal edildi.';
            
            setTimeout(() => {
                modal.style.display = 'none';
                location.reload();
            }, 1000);
        }
    </script>
    
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
