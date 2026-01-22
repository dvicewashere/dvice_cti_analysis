<?php
require_once '/var/www/backend/config/config.php';
kullaniciGirisKontrol();

$db = dbBaglanti();

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

$ana_kategori_kolon_var = false;
$alt_kategori_kolon_var = false;
try {
    $test_sql = "SELECT ana_kategori, alt_kategori FROM cti_kayitlari LIMIT 1";
    $db->query($test_sql);
    $ana_kategori_kolon_var = true;
    $alt_kategori_kolon_var = true;
} catch (PDOException $e) {
    $ana_kategori_kolon_var = false;
    $alt_kategori_kolon_var = false;
}

$sayfa = max(1, intval($_GET['sayfa'] ?? 1));
$sayfa_basina = 20;
$offset = ($sayfa - 1) * $sayfa_basina;

$ana_kategori_filtre = $_GET['ana_kategori'] ?? '';
$alt_kategori_filtre = $_GET['alt_kategori'] ?? '';
$kategori_filtre = $_GET['kategori'] ?? ''; 
$kritiklik_filtre = $_GET['kritiklik'] ?? '';
$kaynak_filtre = $_GET['kaynak'] ?? '';
$tarih_baslangic = $_GET['tarih_baslangic'] ?? '';
$tarih_bitis = $_GET['tarih_bitis'] ?? '';

$where = [];
$params = [];

// Kritik ve yüksek seviyeli tehditler için temel filtre
$where[] = "(kritiklik = 'kritik' OR kritiklik = 'yuksek')";

if ($ana_kategori_filtre) {
    if ($ana_kategori_kolon_var) {
        $where[] = "COALESCE(ana_kategori, kategori) = ?";
        $params[] = $ana_kategori_filtre;
    } else {
        $where[] = "kategori = ?";
        $params[] = $ana_kategori_filtre;
    }
}
if ($alt_kategori_filtre && $ana_kategori_kolon_var) {
    $where[] = "alt_kategori = ?";
    $params[] = $alt_kategori_filtre;
}
if ($kategori_filtre && !$ana_kategori_filtre && !$alt_kategori_filtre) {
    $where[] = "kategori = ?";
    $params[] = $kategori_filtre;
}
if ($kritiklik_filtre) {
    // Sadece kritik veya yüksek seçenekleri
    if ($kritiklik_filtre === 'kritik' || $kritiklik_filtre === 'yuksek') {
        $where[] = "kritiklik = ?";
        $params[] = $kritiklik_filtre;
    }
}
if ($kaynak_filtre) {
    $where[] = "c.kaynak_adi ILIKE ?";
    $params[] = "%$kaynak_filtre%";
}
if ($tarih_baslangic) {
    $where[] = "toplama_tarihi >= ?";
    $params[] = $tarih_baslangic . ' 00:00:00';
}
if ($tarih_bitis) {
    $where[] = "toplama_tarihi <= ?";
    $params[] = $tarih_bitis . ' 23:59:59';
}

$where_sql = "WHERE " . implode(" AND ", $where);

$count_sql = "SELECT COUNT(*) FROM cti_kayitlari c $where_sql";
$stmt = $db->prepare($count_sql);
$stmt->execute($params);
$toplam_kayit = $stmt->fetchColumn();
$toplam_sayfa = ceil($toplam_kayit / $sayfa_basina);

$sql = "SELECT c.*, o.adres as onion_adres, o.kaynak_adi 
        FROM cti_kayitlari c
        LEFT JOIN onion_adresleri o ON c.onion_id = o.id
        $where_sql
        ORDER BY c.toplama_tarihi DESC
        LIMIT ? OFFSET ?";
$params[] = $sayfa_basina;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tehditler = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Kategorileri getir 
$kategori_where = "WHERE (kritiklik = 'kritik' OR kritiklik = 'yuksek')";
if ($ana_kategori_kolon_var) {
    $kategori_sql = "SELECT DISTINCT COALESCE(ana_kategori, kategori) as ana_kategori FROM cti_kayitlari $kategori_where AND COALESCE(ana_kategori, kategori) IS NOT NULL ORDER BY ana_kategori";
    $stmt = $db->prepare($kategori_sql);
    $stmt->execute();
    $ana_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if ($ana_kategori_filtre) {
        $alt_kategori_sql = "SELECT DISTINCT alt_kategori FROM cti_kayitlari WHERE (kritiklik = 'kritik' OR kritiklik = 'yuksek') AND ana_kategori = ? AND alt_kategori IS NOT NULL ORDER BY alt_kategori";
        $stmt = $db->prepare($alt_kategori_sql);
        $stmt->execute([$ana_kategori_filtre]);
        $alt_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $alt_kategoriler = [];
    }
} else {
    $kategori_sql = "SELECT DISTINCT kategori FROM cti_kayitlari $kategori_where AND kategori IS NOT NULL ORDER BY kategori";
    $stmt = $db->prepare($kategori_sql);
    $stmt->execute();
    $ana_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $alt_kategoriler = [];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <title>Dvice CTI - Tüm Kritik Tehditler</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
</head>
<body class="tehditler-page">
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <h1>Dvice CTI - Tüm Kritik Tehditler</h1>
        <div class="header-info">
            <a href="index.php">Ana Sayfa</a>
            <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin'): ?>
                <a href="loglar.php" class="kayitlar-btn">Loglar</a>
            <?php endif; ?>
            <a href="kayitlar.php" class="kayitlar-btn">Kayıtlar</a>
            <?php if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin'): ?>
                <a href="adresekle.php" class="kayitlar-btn">Adres Ayarları</a>
                <a href="kullanicilar.php" class="kayitlar-btn">Kullanıcı İşlemleri</a>
                <a href="kategori.php" class="kayitlar-btn">Kategori Ayarları</a>
            <?php endif; ?>
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
        <div style="margin-bottom: 24px; text-align: center;">
            <h2 style="color: var(--color-primary); font-size: 24px; margin-bottom: 8px; text-shadow: 0 0 8px rgba(57, 255, 20, 0.4);">Tüm Kritik Tehditler</h2>
            <p style="color: var(--color-text-muted); font-size: 14px; margin-bottom: 16px;">Kritik ve yüksek seviyeli tüm tehdit kayıtları</p>
            <div style="text-align: left;">
                <a href="index.php" class="geri-buton" style="display: inline-block;">← Ana Sayfaya Dön</a>
            </div>
        </div>

        <div class="filtreler">
            <h3>Detaylı Filtreleme</h3>
            <form method="GET" action="" id="filtre-form">
                <div class="filtre-grup">
                    <label>Ana Kategori</label>
                    <select name="ana_kategori" id="ana-kategori-select">
                        <option value="">Tümü</option>
                        <?php foreach ($ana_kategoriler as $kat): ?>
                            <option value="<?= guvenliCikti($kat) ?>" <?= $ana_kategori_filtre === $kat ? 'selected' : '' ?>>
                                <?= guvenliCikti($kat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($ana_kategori_filtre && !empty($alt_kategoriler)): ?>
                <div class="filtre-grup">
                    <label>Alt Kategori</label>
                    <select name="alt_kategori">
                        <option value="">Tümü</option>
                        <?php foreach ($alt_kategoriler as $kat): ?>
                            <option value="<?= guvenliCikti($kat) ?>" <?= $alt_kategori_filtre === $kat ? 'selected' : '' ?>>
                                <?= guvenliCikti($kat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="filtre-grup">
                    <label>Kritiklik</label>
                    <select name="kritiklik">
                        <option value="">Tümü</option>
                        <option value="kritik" <?= $kritiklik_filtre === 'kritik' ? 'selected' : '' ?>>Kritik</option>
                        <option value="yuksek" <?= $kritiklik_filtre === 'yuksek' ? 'selected' : '' ?>>Yüksek</option>
                    </select>
                </div>
                
                <div class="filtre-grup">
                    <label>Kaynak</label>
                    <input type="text" name="kaynak" value="<?= guvenliCikti($kaynak_filtre) ?>" placeholder="Örn: forum...">
                </div>
                
                <div class="filtre-grup">
                    <label>Başlangıç Tarihi</label>
                    <input type="date" name="tarih_baslangic" value="<?= guvenliCikti($tarih_baslangic) ?>">
                </div>
                
                <div class="filtre-grup">
                    <label>Bitiş Tarihi</label>
                    <input type="date" name="tarih_bitis" value="<?= guvenliCikti($tarih_bitis) ?>">
                </div>
                
                <div class="filtre-grup">
                    <button type="submit" id="filtrele-btn">Filtrele</button>
                    <button type="button" class="filtre-temizle" id="temizle-btn">Temizle</button>
                </div>
            </form>
            <!-- Filtre Mesajı -->
            <div id="filtre-mesaj" style="display: none; margin-top: 16px; padding: 12px 16px; background: rgba(57, 255, 20, 0.1); border: 1px solid var(--color-primary-glow); border-radius: var(--radius-sm); color: var(--color-primary); font-weight: 600; text-align: center; box-shadow: 0 0 10px rgba(57, 255, 20, 0.2);"></div>
        </div>

        <?php if (empty($tehditler)): ?>
            <div class="uyari-paneli">
                <div class="uyari-item uyari-normal">
                    <div class="uyari-icerik">
                        <strong>Kayıt Bulunamadı</strong>
                        <p>Filtre kriterlerinize uygun kritik veya yüksek seviyeli tehdit kaydı bulunmamaktadır.</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="kayit-tablosu">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 12%">Tarih</th>
                            <th style="width: 15%">Kaynak</th>
                            <th style="width: 40%">Başlık</th>
                            <th style="width: 15%">Kategori</th>
                            <th style="width: 10%">Kritiklik</th>
                            <th style="width: 8%">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tehditler as $tehdit): ?>
                            <tr>
                                <td><?= tarihFormatla($tehdit['toplama_tarihi']) ?></td>
                                <td><?= guvenliCikti($tehdit['kaynak_adi'] ?? 'Bilinmiyor') ?></td>
                                <td>
                                    <a href="detay.php?id=<?= $tehdit['id'] ?>" style="color: var(--color-error); text-decoration: none; font-weight: 600; text-shadow: 0 0 8px rgba(187, 68, 68, 0.4);">
                                        <?= guvenliCikti(substr($tehdit['baslik'], 0, 100)) . (strlen($tehdit['baslik']) > 100 ? '...' : '') ?>
                                    </a>
                                </td>
                                <td><?= guvenliCikti($tehdit['kategori']) ?></td>
                                <td>
                                    <span class="kritiklik-badge kritiklik-<?= guvenliCikti($tehdit['kritiklik']) ?>">
                                        <?= ucfirst(guvenliCikti($tehdit['kritiklik'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="detay.php?id=<?= $tehdit['id'] ?>" class="detay-link">Detay</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($toplam_sayfa > 1): ?>
                <div class="sayfalama">
                    <?php if ($sayfa > 1): ?>
                        <a href="?sayfa=1&ana_kategori=<?= urlencode($ana_kategori_filtre) ?>&alt_kategori=<?= urlencode($alt_kategori_filtre) ?>&kritiklik=<?= urlencode($kritiklik_filtre) ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&tarih_baslangic=<?= urlencode($tarih_baslangic) ?>&tarih_bitis=<?= urlencode($tarih_bitis) ?>"><< İlk</a>
                        <a href="?sayfa=<?= $sayfa - 1 ?>&ana_kategori=<?= urlencode($ana_kategori_filtre) ?>&alt_kategori=<?= urlencode($alt_kategori_filtre) ?>&kritiklik=<?= urlencode($kritiklik_filtre) ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&tarih_baslangic=<?= urlencode($tarih_baslangic) ?>&tarih_bitis=<?= urlencode($tarih_bitis) ?>">< Önceki</a>
                    <?php endif; ?>
                    
                    <?php
                    $baslangic = max(1, $sayfa - 2);
                    $bitis = min($toplam_sayfa, $sayfa + 2);
                    
                    for ($i = $baslangic; $i <= $bitis; $i++):
                    ?>
                        <a href="?sayfa=<?= $i ?>&ana_kategori=<?= urlencode($ana_kategori_filtre) ?>&alt_kategori=<?= urlencode($alt_kategori_filtre) ?>&kritiklik=<?= urlencode($kritiklik_filtre) ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&tarih_baslangic=<?= urlencode($tarih_baslangic) ?>&tarih_bitis=<?= urlencode($tarih_bitis) ?>" class="<?= $i == $sayfa ? 'aktif' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($sayfa < $toplam_sayfa): ?>
                        <a href="?sayfa=<?= $sayfa + 1 ?>&ana_kategori=<?= urlencode($ana_kategori_filtre) ?>&alt_kategori=<?= urlencode($alt_kategori_filtre) ?>&kritiklik=<?= urlencode($kritiklik_filtre) ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&tarih_baslangic=<?= urlencode($tarih_baslangic) ?>&tarih_bitis=<?= urlencode($tarih_bitis) ?>">Sonraki ></a>
                        <a href="?sayfa=<?= $toplam_sayfa ?>&ana_kategori=<?= urlencode($ana_kategori_filtre) ?>&alt_kategori=<?= urlencode($alt_kategori_filtre) ?>&kritiklik=<?= urlencode($kritiklik_filtre) ?>&kaynak=<?= urlencode($kaynak_filtre) ?>&tarih_baslangic=<?= urlencode($tarih_baslangic) ?>&tarih_bitis=<?= urlencode($tarih_bitis) ?>">Son >></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        // Filtrele butonuna basıldığında flag set et
        let filtreleButonunaBasildi = false;
        
        document.getElementById('filtrele-btn').addEventListener('click', function(e) {
            filtreleButonunaBasildi = true;
        });
        
        // Form submit event'ini yakala
        document.getElementById('filtre-form').addEventListener('submit', function(e) {
            // Sadece Filtrele butonuna basıldığında parametre ekle
            if (filtreleButonunaBasildi) {
                // Mevcut filtrele input'unu kontrol et ve yoksa ekle
                let filtreleInput = this.querySelector('input[name="filtrele"]');
                if (!filtreleInput) {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'filtrele';
                    hiddenInput.value = '1';
                    this.appendChild(hiddenInput);
                }
            }
            // Reset flag
            filtreleButonunaBasildi = false;
        });
        
        // Temizle butonu - URL parametresi ile yönlendir
        document.getElementById('temizle-btn').addEventListener('click', function(e) {
            window.location.href = 'tehditler.php?temizle=1';
        });
        
        // Sayfa yüklendiğinde mesaj kontrolü
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const mesajDiv = document.getElementById('filtre-mesaj');
            
            if (urlParams.get('filtrele') === '1') {
                mesajDiv.textContent = '✓ Filtreleme uygulandı!';
                mesajDiv.style.display = 'block';
                
                // 3 saniye sonra gizle
                setTimeout(function() {
                    mesajDiv.style.display = 'none';
                }, 3000);
                
                // URL'den parametreyi temizle
                urlParams.delete('filtrele');
                const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                window.history.replaceState({}, '', newUrl);
            }
            
            if (urlParams.get('temizle') === '1') {
                mesajDiv.textContent = '✓ Filtreler temizlendi!';
                mesajDiv.style.display = 'block';
                
                // 3 saniye sonra gizle
                setTimeout(function() {
                    mesajDiv.style.display = 'none';
                }, 3000);
                
                // URL'den parametreyi temizle
                urlParams.delete('temizle');
                const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
                window.history.replaceState({}, '', newUrl);
            }
        });
    </script>
    
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
