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


$where_sql = "";
$where_sql_for_stats = "";
$params_stats = [];
$ana_kategori_filtre = '';
$alt_kategori_filtre = '';
$kritiklik_filtre = '';
$kaynak_filtre = '';
$tarih_baslangic = '';
$tarih_bitis = '';

// Toplam kayıt sayısı (istatistikler için)
$count_sql = "SELECT COUNT(*) FROM cti_kayitlari c";
$stmt = $db->prepare($count_sql);
$stmt->execute();
$toplam_kayit = $stmt->fetchColumn();

$istatistik_sql = "SELECT 
    COUNT(*) as toplam,
    COUNT(DISTINCT kategori) as kategori_sayisi,
    COUNT(DISTINCT kaynak_adi) as kaynak_sayisi
    FROM cti_kayitlari c $where_sql_for_stats";
$stmt = $db->prepare($istatistik_sql);
$stmt->execute($params_stats); 
$istatistikler = $stmt->fetch(PDO::FETCH_ASSOC);

$son_24h_where = $where_sql ? $where_sql . " AND " : "WHERE ";
$son_24h_where .= "toplama_tarihi > NOW() - INTERVAL '24 hours'";
$son_24h_sql = "SELECT COUNT(*) FROM cti_kayitlari c $son_24h_where";
$stmt = $db->prepare($son_24h_sql);
$stmt->execute($params_stats);
$son_24h = $stmt->fetchColumn();

$onceki_24h_where = $where_sql ? $where_sql . " AND " : "WHERE ";
$onceki_24h_where .= "toplama_tarihi > NOW() - INTERVAL '48 hours' AND toplama_tarihi <= NOW() - INTERVAL '24 hours'";
$onceki_24h_sql = "SELECT COUNT(*) FROM cti_kayitlari c $onceki_24h_where";
$stmt = $db->prepare($onceki_24h_sql);
$stmt->execute($params_stats);
$onceki_24h = $stmt->fetchColumn();

$degisim_yuzde = 0;
$degisim_yon = '';
if ($onceki_24h > 0) {
    $degisim_yuzde = (($son_24h - $onceki_24h) / $onceki_24h) * 100;
    $degisim_yon = $degisim_yuzde >= 0 ? 'YUKARI' : 'ASAGI';
} elseif ($son_24h > 0) {
    $degisim_yuzde = 100;
    $degisim_yon = 'YUKARI';
}

if ($ana_kategori_kolon_var) {
    $where_clause = $where_sql_for_stats ? $where_sql_for_stats . " AND COALESCE(ana_kategori, kategori) IS NOT NULL" : "WHERE COALESCE(ana_kategori, kategori) IS NOT NULL";
    $ana_kategoriler_sql = "SELECT DISTINCT COALESCE(ana_kategori, kategori) as ana_kategori FROM cti_kayitlari c $where_clause ORDER BY ana_kategori";
    $stmt = $db->prepare($ana_kategoriler_sql);
    $stmt->execute($params_stats);
    $ana_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $where_clause = $where_sql_for_stats ? $where_sql_for_stats . " AND kategori IS NOT NULL" : "WHERE kategori IS NOT NULL";
    $ana_kategoriler_sql = "SELECT DISTINCT kategori as ana_kategori FROM cti_kayitlari c $where_clause ORDER BY kategori";
    $stmt = $db->prepare($ana_kategoriler_sql);
    $stmt->execute($params_stats);
    $ana_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$alt_kategoriler = [];
if ($ana_kategori_kolon_var) {
    if ($ana_kategori_filtre) {
        $alt_kategoriler_sql = "SELECT DISTINCT alt_kategori FROM cti_kayitlari c WHERE COALESCE(ana_kategori, kategori) = ? AND alt_kategori IS NOT NULL ORDER BY alt_kategori";
        $stmt = $db->prepare($alt_kategoriler_sql);
        $stmt->execute([$ana_kategori_filtre]);
        $alt_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $where_clause = $where_sql_for_stats ? $where_sql_for_stats . " AND alt_kategori IS NOT NULL" : "WHERE alt_kategori IS NOT NULL";
        $alt_kategoriler_sql = "SELECT DISTINCT alt_kategori FROM cti_kayitlari c $where_clause ORDER BY alt_kategori";
        $stmt = $db->prepare($alt_kategoriler_sql);
        $stmt->execute($params_stats);
        $alt_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

$kategoriler_sql = "SELECT DISTINCT kategori FROM cti_kayitlari c $where_sql_for_stats ORDER BY kategori";
$stmt = $db->prepare($kategoriler_sql);
$stmt->execute($params_stats);
$kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);

$kritiklik_sql = "SELECT kritiklik, COUNT(*) as sayi FROM cti_kayitlari c $where_sql_for_stats GROUP BY kritiklik";
$stmt = $db->prepare($kritiklik_sql);
$stmt->execute($params_stats);
$kritiklik_dagilim = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kritiklik_toplam = array_sum(array_column($kritiklik_dagilim, 'sayi'));
$kritiklik_analiz = [];
foreach ($kritiklik_dagilim as $item) {
    $kritiklik_analiz[$item['kritiklik']] = [
        'sayi' => $item['sayi'],
        'yuzde' => $kritiklik_toplam > 0 ? round(($item['sayi'] / $kritiklik_toplam) * 100, 1) : 0
    ];
}

if ($ana_kategori_kolon_var) {
    $ana_kategori_dagilim_sql = "SELECT COALESCE(ana_kategori, kategori) as ana_kategori, COUNT(*) as sayi FROM cti_kayitlari c $where_sql_for_stats GROUP BY COALESCE(ana_kategori, kategori) ORDER BY sayi DESC";
    $stmt = $db->prepare($ana_kategori_dagilim_sql);
    $stmt->execute($params_stats);
    $ana_kategori_dagilim = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $ana_kategori_dagilim_sql = "SELECT kategori as ana_kategori, COUNT(*) as sayi FROM cti_kayitlari c $where_sql_for_stats GROUP BY kategori ORDER BY sayi DESC";
    $stmt = $db->prepare($ana_kategori_dagilim_sql);
    $stmt->execute($params_stats);
    $ana_kategori_dagilim = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$kategori_dagilim_sql = "SELECT kategori, COUNT(*) as sayi FROM cti_kayitlari c $where_sql_for_stats GROUP BY kategori ORDER BY sayi DESC";
$stmt = $db->prepare($kategori_dagilim_sql);
$stmt->execute($params_stats);
$kategori_dagilim = $stmt->fetchAll(PDO::FETCH_ASSOC);

$zaman_trend_24h_where = $where_sql ? $where_sql . " AND " : "WHERE ";
$zaman_trend_24h_where .= "toplama_tarihi > NOW() - INTERVAL '24 hours'";
$zaman_trend_24h_sql = "SELECT DATE_TRUNC('hour', toplama_tarihi) as saat, COUNT(*) as sayi 
    FROM cti_kayitlari c 
    $zaman_trend_24h_where
    GROUP BY saat 
    ORDER BY saat";
$stmt = $db->prepare($zaman_trend_24h_sql);
$stmt->execute($params_stats);
$zaman_trend_24h = $stmt->fetchAll(PDO::FETCH_ASSOC);

$zaman_trend_7g_where = $where_sql ? $where_sql . " AND " : "WHERE ";
$zaman_trend_7g_where .= "toplama_tarihi > NOW() - INTERVAL '7 days'";
$zaman_trend_7g_sql = "SELECT DATE_TRUNC('day', toplama_tarihi) as gun, COUNT(*) as sayi 
    FROM cti_kayitlari c 
    $zaman_trend_7g_where
    GROUP BY gun 
    ORDER BY gun";
$stmt = $db->prepare($zaman_trend_7g_sql);
$stmt->execute($params_stats);
$zaman_trend_7g = $stmt->fetchAll(PDO::FETCH_ASSOC);

$zaman_trend_24h_ortalama = count($zaman_trend_24h) > 0 ? array_sum(array_column($zaman_trend_24h, 'sayi')) / count($zaman_trend_24h) : 0;
$zaman_trend_24h_max = count($zaman_trend_24h) > 0 ? max(array_column($zaman_trend_24h, 'sayi')) : 0;
$zaman_trend_24h_anomali = $zaman_trend_24h_max > ($zaman_trend_24h_ortalama * 2);

$kaynaklar_sql = "SELECT DISTINCT kaynak_adi FROM cti_kayitlari c $where_sql_for_stats ORDER BY kaynak_adi";
$stmt = $db->prepare($kaynaklar_sql);
$stmt->execute($params_stats);
$kaynaklar = $stmt->fetchAll(PDO::FETCH_COLUMN);

$kritik_seviye_where = $where_sql_for_stats ? $where_sql_for_stats . " AND " : "WHERE ";
$kritik_seviye_where .= "(kritiklik = 'kritik' OR kritiklik = 'yuksek')";
$kritik_seviye_sql = "SELECT COUNT(*) FROM cti_kayitlari c $kritik_seviye_where";
$stmt = $db->prepare($kritik_seviye_sql);
$stmt->execute($params_stats);
$kritik_seviye_sayisi = $stmt->fetchColumn();

$en_kritik_where = $where_sql_for_stats ? $where_sql_for_stats . " AND " : "WHERE ";
$en_kritik_where .= "(kritiklik = 'kritik' OR kritiklik = 'yuksek') AND toplama_tarihi > NOW() - INTERVAL '24 hours'";
$en_kritik_sql = "SELECT c.*, o.adres as onion_adres 
                  FROM cti_kayitlari c 
                  LEFT JOIN onion_adresleri o ON c.onion_id = o.id 
                  $en_kritik_where 
                  ORDER BY c.toplama_tarihi DESC 
                  LIMIT 10";
$stmt = $db->prepare($en_kritik_sql);
$stmt->execute($params_stats);
$en_kritik_kayitlar = $stmt->fetchAll(PDO::FETCH_ASSOC);

$son_24h_kritik = $db->query("SELECT COUNT(*) FROM cti_kayitlari WHERE (kritiklik = 'kritik' OR kritiklik = 'yuksek') AND toplama_tarihi > NOW() - INTERVAL '24 hours'")->fetchColumn();
$onceki_24h_kritik = $db->query("SELECT COUNT(*) FROM cti_kayitlari WHERE (kritiklik = 'kritik' OR kritiklik = 'yuksek') AND toplama_tarihi > NOW() - INTERVAL '48 hours' AND toplama_tarihi <= NOW() - INTERVAL '24 hours'")->fetchColumn();
$kritik_artis = $onceki_24h_kritik > 0 ? (($son_24h_kritik - $onceki_24h_kritik) / $onceki_24h_kritik) * 100 : ($son_24h_kritik > 0 ? 100 : 0);

// Son 24 saatteki kritiklik dağılımı
$son_24h_kritiklik_sql = "SELECT kritiklik, COUNT(*) as sayi 
                          FROM cti_kayitlari 
                          WHERE toplama_tarihi > NOW() - INTERVAL '24 hours' 
                          GROUP BY kritiklik";
$stmt = $db->prepare($son_24h_kritiklik_sql);
$stmt->execute();
$son_24h_kritiklik_dagilim = $stmt->fetchAll(PDO::FETCH_ASSOC);

$son_24h_kritiklik_sayilari = [
    'kritik' => 0,
    'yuksek' => 0,
    'orta' => 0,
    'dusuk' => 0
];

foreach ($son_24h_kritiklik_dagilim as $item) {
    $kritiklik = strtolower($item['kritiklik']);
    if (isset($son_24h_kritiklik_sayilari[$kritiklik])) {
        $son_24h_kritiklik_sayilari[$kritiklik] = (int)$item['sayi'];
    }
}

$kaynak_yogunluk_sql = "SELECT kaynak_adi, COUNT(*) as sayi 
                        FROM cti_kayitlari c 
                        WHERE toplama_tarihi > NOW() - INTERVAL '24 hours' 
                        GROUP BY kaynak_adi 
                        HAVING COUNT(*) > 10 
                        ORDER BY sayi DESC 
                        LIMIT 5";
$kaynak_yogunluk = $db->query($kaynak_yogunluk_sql)->fetchAll(PDO::FETCH_ASSOC);

if ($ana_kategori_kolon_var) {
    $yeni_kategori_sql = "SELECT DISTINCT COALESCE(ana_kategori, kategori) as ana_kategori 
                          FROM cti_kayitlari c 
                          WHERE toplama_tarihi > NOW() - INTERVAL '24 hours' 
                          AND COALESCE(ana_kategori, kategori) NOT IN (
                              SELECT DISTINCT COALESCE(ana_kategori, kategori) 
                              FROM cti_kayitlari c 
                              WHERE toplama_tarihi <= NOW() - INTERVAL '24 hours'
                          )
                          LIMIT 5";
} else {
    $yeni_kategori_sql = "SELECT DISTINCT kategori as ana_kategori 
                          FROM cti_kayitlari c 
                          WHERE toplama_tarihi > NOW() - INTERVAL '24 hours' 
                          AND kategori NOT IN (
                              SELECT DISTINCT kategori 
                              FROM cti_kayitlari c 
                              WHERE toplama_tarihi <= NOW() - INTERVAL '24 hours'
                          )
                          LIMIT 5";
}
$yeni_kategoriler = $db->query($yeni_kategori_sql)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <title>Dvice CTI - Cyber Threat Intelligence Analiz Platformu</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
    <script src="assets/js/tableCarousel.js"></script>
</head>
<body>
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
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
        <!-- İstatistik Kartları - Üst Kısım ) -->
        <div class="istatistikler istatistikler-compact">
            <div class="istatistik-kart istatistik-kart-compact">
                <h3>Günlük Kayıt</h3>
                <div class="deger"><?= number_format($son_24h) ?></div>
                <div class="degisim">
                    <span class="<?= $degisim_yon === 'YUKARI' ? 'degisim-artis' : 'degisim-azalis' ?>">
                        <?= $degisim_yon === 'YUKARI' ? '↑' : '↓' ?> %<?= number_format(abs($degisim_yuzde), 1) ?>
                    </span>
                </div>
            </div>
            
            <div class="istatistik-kart istatistik-kart-compact">
                <h3>Toplam Kayıt</h3>
                <div class="deger"><?= number_format($toplam_kayit) ?></div>
            </div>
            
            <div class="istatistik-kart istatistik-kart-compact">
                <h3>Aktif Kaynak</h3>
                <div class="deger"><?= count($kaynaklar) ?></div>
            </div>
            
            <div class="istatistik-kart istatistik-kart-compact">
                <h3>Izlenen Kaynak</h3>
                <div class="deger"><?= number_format($istatistikler['kaynak_sayisi']) ?></div>
            </div>
        </div>

        <!--  Sol - Sistem Analizleri, Sağ - Kritik Tehditler -->
        <div class="dashboard-layout">
            <!-- Sol Taraf: Sistem Analizleri ve Öngörüler -->
            <div class="uyari-paneli uyari-paneli-compact">
            <h3>Sistem Analizleri ve Öngörüler</h3>
            <div class="panel-aciklama">
                Bu alan, sistem tarafından tespit edilen anormallikleri ve önemli gelişmeleri özetler.
            </div>
            
            <div class="uyari-liste">
                <div class="uyari-item uyari-kritik">
                    <div class="uyari-icerik">
                        <strong>Kritik Tehdit Artışı</strong>
                        <p>Son 24 saat içerisinde kritik seviyeli tehdit kayıtlarında <strong>%<?= round($kritik_artis) ?></strong> oranında artış gözlemlendi. Bu durum, devam eden bir saldırı kampanyasının göstergesi olabilir.</p>
                    </div>
                </div>
                
                <!-- Son 24 Saat Kritiklik Sayacı -->
                <div class="kritiklik-sayac-container">
                    <a href="kayitlar.php?kritiklik=kritik" class="kritiklik-sayac-item kritiklik-kritik" style="text-decoration: none; display: block;">
                        <div class="sayac-label">Kritik</div>
                        <div class="sayac-deger" data-count="<?= $son_24h_kritiklik_sayilari['kritik'] ?>">0</div>
                    </a>
                    <a href="kayitlar.php?kritiklik=yuksek" class="kritiklik-sayac-item kritiklik-yuksek" style="text-decoration: none; display: block;">
                        <div class="sayac-label">Yüksek</div>
                        <div class="sayac-deger" data-count="<?= $son_24h_kritiklik_sayilari['yuksek'] ?>">0</div>
                    </a>
                    <a href="kayitlar.php?kritiklik=orta" class="kritiklik-sayac-item kritiklik-orta" style="text-decoration: none; display: block;">
                        <div class="sayac-label">Orta</div>
                        <div class="sayac-deger" data-count="<?= $son_24h_kritiklik_sayilari['orta'] ?>">0</div>
                    </a>
                    <a href="kayitlar.php?kritiklik=dusuk" class="kritiklik-sayac-item kritiklik-dusuk" style="text-decoration: none; display: block;">
                        <div class="sayac-label">Düşük</div>
                        <div class="sayac-deger" data-count="<?= $son_24h_kritiklik_sayilari['dusuk'] ?>">0</div>
                    </a>
                </div>
                <div class="sayac-aciklama">
                    <p>Bu sayaçlar son 24 saat içinde tespit edilen tehdit kayıtlarını kritiklik seviyelerine göre gösterir. Her sayaca tıklayarak ilgili seviyedeki tüm kayıtları detaylı olarak görüntüleyebilirsiniz.</p>
                </div>
            </div>
            </div>

            <!-- Sağ Taraf: En Kritik Tehditler  -->
            <?php if (!empty($en_kritik_kayitlar)): ?>
            <div class="kritik-kayitlar kritik-kayitlar-compact">
                <h3>Son 24 Saat: En Kritik Tehditler</h3>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 20%">Tarih</th>
                            <th style="width: 20%">Kaynak</th>
                            <th style="width: 40%">Baslik</th>
                            <th style="width: 20%">Kritiklik</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Sadece ilk 3 kaydı göster
                        $gosterilecek_kayitlar = array_slice($en_kritik_kayitlar, 0, 3);
                        foreach ($gosterilecek_kayitlar as $kayit): ?>
                            <tr>
                                <td><?= tarihFormatla($kayit['toplama_tarihi']) ?></td>
                                <td><?= guvenliCikti($kayit['kaynak_adi']) ?></td>
                                <td>
                                    <a href="detay.php?id=<?= $kayit['id'] ?>" style="color: var(--color-error); text-decoration: none; font-weight: 600; text-shadow: 0 0 8px rgba(187, 68, 68, 0.4);">
                                        <?= guvenliCikti(substr($kayit['baslik'], 0, 60)) . (strlen($kayit['baslik']) > 60 ? '...' : '') ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="kritiklik-badge kritiklik-<?= guvenliCikti($kayit['kritiklik']) ?>">
                                        <?= ucfirst(guvenliCikti($kayit['kritiklik'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top: 16px; text-align: center;">
                    <a href="tehditler.php" class="tumunu-gor-btn">Tümünü Gör</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

       
        <div class="table-carousel-wrapper">
            <div class="table-carousel-topbar">
                <div class="table-carousel-title" id="activeTableTitle">Tehdit Zaman Analizi (Son 7 Gün)</div>
                <div class="table-carousel-steps" id="tableSteps">
                    <span class="step-dot active" data-index="0"></span>
                    <span class="step-dot" data-index="1"></span>
                    <span class="step-dot" data-index="2"></span>
                    <span class="step-dot" data-index="3"></span>
                </div>
            </div>

            <div class="table-carousel" id="tableCarousel">
                <div class="table-slide active" data-title="Tehdit Zaman Analizi (Son 7 Gün)" data-index="0">
                    <div class="grafik-kart">
                        <h3>Tehdit Zaman Analizi (Son 7 Gün)</h3>
                        <div class="grafik-aciklama">Tehditlerin günlere göre dağılımı ve yoğunluk trendi.</div>
                        <canvas id="zamanGrafigi"></canvas>
                        <div class="analiz-yorumu">
                            <?php
                            $trend_sonuc = "Veri yetersiz.";
                            if (count($zaman_trend_7g) >= 2) {
                                $son_gun = end($zaman_trend_7g)['sayi'];
                                $ilk_gun = reset($zaman_trend_7g)['sayi'];
                                if ($son_gun > $ilk_gun * 1.5) {
                                    $trend_sonuc = "<strong>Yükseliş Trendi:</strong> Son bir haftada tehdit aktivitesinde belirgin bir artış var.";
                                } elseif ($ilk_gun > $son_gun * 1.5) {
                                    $trend_sonuc = "<strong>Düşüş Trendi:</strong> Tehdit aktivitesi son günlerde azalma eğiliminde.";
                                } else {
                                    $trend_sonuc = "<strong>Yatay Seyir:</strong> Tehdit aktivitesi stabil bir seyir izliyor.";
                                }
                            }
                            echo $trend_sonuc;
                            ?>
                        </div>
                    </div>
                </div>

                <div class="table-slide" data-title="Kategori Dağılımı (Ana Kategoriler)" data-index="1">
                    <div class="grafik-kart">
                        <h3>Kategori Dağılımı (Ana Kategoriler)</h3>
                        <div class="grafik-aciklama">Tehditlerin ana kategorilere göre oransal dağılımı.</div>
                        <canvas id="kategoriGrafigi"></canvas>
                        <div class="analiz-yorumu">
                            <?php
                            if (!empty($ana_kategori_dagilim)) {
                                $en_cok = $ana_kategori_dagilim[0];
                                echo "<strong>En Yaygın Tehdit:</strong> " . guvenliCikti($en_cok['ana_kategori']) . " (" . $en_cok['sayi'] . " kayıt)";
                                echo "<br>Bu kategori toplam tehditlerin önemli bir kısmını oluşturmaktadır.";
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="table-slide" data-title="Kritiklik Seviyesi Dağılımı" data-index="2">
                    <div class="grafik-kart">
                        <h3>Kritiklik Seviyesi Dağılımı</h3>
                        <div class="grafik-aciklama">Tespit edilen tehditlerin önem derecesine göre dağılımı.</div>
                        <canvas id="kritiklikGrafigi"></canvas>
                    </div>
                </div>
                
                <div class="table-slide" data-title="Son 24 Saat Aktivitesi" data-index="3">
                    <div class="grafik-kart">
                        <h3>Son 24 Saat Aktivitesi</h3>
                        <div class="grafik-aciklama">Saatlik bazda tehdit tespit sayıları.</div>
                        <canvas id="saatlikGrafik"></canvas>
                    </div>
                </div>
            </div>

            <button class="carousel-nav prev" aria-label="Previous table">‹</button>
            <button class="carousel-nav next" aria-label="Next table">›</button>
        </div>

    <script>
        const zamanTrendData = {
            labels: [<?php foreach ($zaman_trend_7g as $item) echo "'" . date('d.m', strtotime($item['gun'])) . "',"; ?>],
            datasets: [{
                label: 'Günlük Tespit Sayısı',
                data: [<?php foreach ($zaman_trend_7g as $item) echo $item['sayi'] . ","; ?>],
                borderColor: '#39FF14',
                backgroundColor: 'rgba(57, 255, 20, 0.2)',
                tension: 0.3,
                fill: true,
                borderWidth: 2,
                pointBackgroundColor: '#39FF14',
                pointBorderColor: '#000000',
                pointHoverBackgroundColor: '#8BFF8B',
                pointHoverBorderColor: '#39FF14'
            }]
        };

        const anaKategoriData = {
            labels: [<?php foreach ($ana_kategori_dagilim as $item) echo "'" . guvenliCikti($item['ana_kategori']) . "',"; ?>],
            datasets: [{
                data: [<?php foreach ($ana_kategori_dagilim as $item) echo $item['sayi'] . ","; ?>],
                backgroundColor: [
                    '#39FF14', '#8BFF8B', '#40FF40', '#1F6F67', '#66FF66',
                    '#C0FFC0', '#2E6A60', '#1A3030', '#66BB66', '#99FF99'
                ],
                borderColor: '#000000',
                borderWidth: 1
            }]
        };
        
        const kritiklikData = {
            labels: ['Düşük', 'Orta', 'Yüksek', 'Kritik'],
            datasets: [{
                label: 'Kritiklik Seviyesi',
                data: [
                    <?= $kritiklik_analiz['dusuk']['sayi'] ?? 0 ?>, 
                    <?= $kritiklik_analiz['orta']['sayi'] ?? 0 ?>, 
                    <?= $kritiklik_analiz['yuksek']['sayi'] ?? 0 ?>, 
                    <?= $kritiklik_analiz['kritik']['sayi'] ?? 0 ?>
                ],
                backgroundColor: [
                    '#40FF40', '#E8E833', '#FF6B35', '#BB4444'
                ],
                borderColor: '#000000',
                borderWidth: 1
            }]
        };

        const saatlikData = {
            labels: [<?php foreach ($zaman_trend_24h as $item) echo "'" . date('H:i', strtotime($item['saat'])) . "',"; ?>],
            datasets: [{
                label: 'Saatlik Aktivite',
                data: [<?php foreach ($zaman_trend_24h as $item) echo $item['sayi'] . ","; ?>],
                backgroundColor: '#39FF14',
                borderColor: '#8BFF8B',
                borderWidth: 1,
                barPercentage: 0.6
            }]
        };

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    labels: {
                        color: '#C0FFC0'
                    }
                }
            },
            scales: {
                x: {
                    ticks: { color: '#66BB66' },
                    grid: { color: 'rgba(57, 255, 20, 0.1)' }
                },
                y: {
                    ticks: { color: '#66BB66' },
                    grid: { color: 'rgba(57, 255, 20, 0.1)' }
                }
            }
        };

       
        window.zamanChart = new Chart(document.getElementById('zamanGrafigi'), {
            type: 'line',
            data: zamanTrendData,
            options: chartOptions
        });

        window.kategoriChart = new Chart(document.getElementById('kategoriGrafigi'), {
            type: 'doughnut',
            data: anaKategoriData,
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: '#C0FFC0' }
                    }
                }
            }
        });
        
        window.kritiklikChart = new Chart(document.getElementById('kritiklikGrafigi'), {
            type: 'bar',
            data: kritiklikData,
            options: { 
                ...chartOptions,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        ticks: { color: '#66BB66' },
                        grid: { color: 'rgba(57, 255, 20, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#66BB66' },
                        grid: { color: 'rgba(57, 255, 20, 0.1)' }
                    }
                }
            }
        });
        
        window.saatlikChart = new Chart(document.getElementById('saatlikGrafik'), {
            type: 'bar',
            data: saatlikData,
            options: { 
                ...chartOptions,
                plugins: { legend: { display: false } },
                scales: { 
                    y: { 
                        beginAtZero: true,
                        ticks: { color: '#66BB66' },
                        grid: { color: 'rgba(57, 255, 20, 0.1)' }
                    },
                    x: {
                        ticks: { color: '#66BB66' },
                        grid: { color: 'rgba(57, 255, 20, 0.1)' }
                    }
                }
            }
        });
        
     
        document.addEventListener('carouselChange', () => {
            setTimeout(() => {
                if (window.zamanChart) window.zamanChart.resize();
                if (window.kategoriChart) window.kategoriChart.resize();
                if (window.kritiklikChart) window.kritiklikChart.resize();
                if (window.saatlikChart) window.saatlikChart.resize();
            }, 100);
        });
        
        // Kritiklik Sayacı Animasyonu
        function animateCounter(element, target, duration = 2000) {
            const start = 0;
            const increment = target / (duration / 16);
            let current = start;
            
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    element.textContent = target;
                    clearInterval(timer);
                } else {
                    element.textContent = Math.floor(current);
                }
            }, 16);
        }
        
        // Sayacları başlat
        document.addEventListener('DOMContentLoaded', () => {
            const sayacDegerler = document.querySelectorAll('.sayac-deger');
            sayacDegerler.forEach(el => {
                const target = parseInt(el.getAttribute('data-count')) || 0;
                animateCounter(el, target, 2000);
            });
        });
    </script>
    
    <div class="footer-banner">
        <a href="https://github.com/dvicewashere" target="_blank" style="color: var(--color-primary); text-shadow: 0 0 8px rgba(57, 255, 20, 0.4); text-decoration: none;">Dvice was here ❤</a>
    </div>
    
    <script>
     
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
</body>
</html>
