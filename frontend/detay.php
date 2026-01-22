<?php
require_once '/var/www/backend/config/config.php';
kullaniciGirisKontrol();

$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: index.php');
    exit;
}

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

$mesaj = '';
$hata = '';


$kaynak_guven_skoru_kolon_var = guvenSkoruKolonVarMi();

if ($kaynak_guven_skoru_kolon_var) {
    $stmt = $db->prepare("SELECT c.*, o.adres as onion_adres, o.kaynak_guven_skoru as kaynak_guven_skoru_onion, o.tarama_sonucu_yolu
                          FROM cti_kayitlari c 
                          LEFT JOIN onion_adresleri o ON c.onion_id = o.id 
                          WHERE c.id = ?");
} else {
    $stmt = $db->prepare("SELECT c.*, o.adres as onion_adres, o.tarama_sonucu_yolu
                          FROM cti_kayitlari c 
                          LEFT JOIN onion_adresleri o ON c.onion_id = o.id 
                          WHERE c.id = ?");
}
$stmt->execute([$id]);
$kayit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kayit) {
    header('Location: index.php');
    exit;
}

$kaynak_guven_skoru = guvenSkoruGetir($kayit);


$kaynak_istatistik_sql = "SELECT 
    COUNT(*) as toplam_kayit,
    COUNT(DISTINCT kategori) as kategori_sayisi,
    AVG(kritiklik_skor) as ortalama_kritiklik_skor,
    MAX(toplama_tarihi) as son_kayit_tarihi
    FROM cti_kayitlari 
    WHERE kaynak_adi = ?";
$stmt = $db->prepare($kaynak_istatistik_sql);
$stmt->execute([$kayit['kaynak_adi']]);
$kaynak_istatistik = $stmt->fetch(PDO::FETCH_ASSOC);

$kategori_aciklamalari = [
    'Zararlı Yazılım (Malware)' => [
        'aciklama' => 'İçerikte zararlı yazılım, virüs ve saldırı araçlarına dair teknik detaylar veya dağıtım faaliyetleri tespit edilmiştir.',
        'neden' => 'Analiz motoru, "malware", "virus", "ransomware" gibi tehdit göstergelerini (IoC) belirlemiştir.'
    ],
    'Kimlik Avı (Phishing)' => [
        'aciklama' => 'Kullanıcı kimlik bilgilerini ele geçirmeyi hedefleyen sahte giriş sayfaları veya oltalama kampanyaları ile ilgili veriler bulunmuştur.',
        'neden' => 'Metin içerisinde "phishing", "fake login", "spoofing" vb. oltalama terminolojisi saptanmıştır.'
    ],
    'Sosyal Mühendislik' => [
        'aciklama' => 'İnsan hatalarını istismar etmeye yönelik manipülasyon teknikleri ve dolandırıcılık senaryoları tespit edilmiştir.',
        'neden' => 'İçerikte güven istismarı ve kandırma odaklı (scam, social engineering) ifadeler yer almaktadır.'
    ],
    'Yetkisiz Erişim / Sızma' => [
        'aciklama' => 'Sistemlere izinsiz giriş, şifre kırma girişimleri veya hesap ele geçirme faaliyetlerine dair bulgular mevcuttur.',
        'neden' => '"Brute-force", "hack", "access denied" gibi yetkisiz erişim teşebbüslerini işaret eden terimler bulunmuştur.'
    ],
    'Veri İhlali / Veri Sızıntısı' => [
        'aciklama' => 'Hassas verilerin (kişisel bilgiler, kurumsal veriler, veritabanı dökümleri) yetkisiz paylaşımı veya sızıntısı tespit edilmiştir.',
        'neden' => '"Data breach", "leak", "veritabanı" gibi veri sızıntısını doğrulayan anahtar kelimeler yoğunluktadır.'
    ],
    'Dark Web Pazarları' => [
        'aciklama' => 'Yasa dışı ürün veya hizmetlerin (silah, uyuşturucu, sahte belge vb.) ticaretinin yapıldığı darknet pazar yeri aktivitesi görülmüştür.',
        'neden' => 'Marketplace yapısına özgü "vendor", "escrow", "price" ve yasa dışı ürün isimleri analiz edilmiştir.'
    ],
    'Exploit / Zafiyet Paylaşımları' => [
        'aciklama' => 'Sistem güvenlik açıklarını (CVE) istismar eden kodlar (exploit) veya teknik zafiyet analizleri paylaşılmıştır.',
        'neden' => 'İçerik, "exploit", "PoC", "vulnerability", "RCE" gibi teknik zafiyet terimlerini içermektedir.'
    ],
    'APT (Gelişmiş Kalıcı Tehditler)' => [
        'aciklama' => 'Devlet destekli veya organize siber casusluk gruplarının (APT) faaliyetlerine dair istihbarat verisi içermektedir.',
        'neden' => 'Belirli tehdit aktörleri veya sofistike saldırı teknikleri (APT, state-sponsored) ile eşleşmiştir.'
    ],
    'Finansal Dolandırıcılık' => [
        'aciklama' => 'Kredi kartı dolandırıcılığı, kripto para hırsızlığı veya finansal sahtekarlık girişimleri ile ilgili veriler tespit edilmiştir.',
        'neden' => '"Carding", "cc dump", "fraud" gibi finansal suç terminolojisi belirlenmiştir.'
    ],
    'Hacktivizm' => [
        'aciklama' => 'Politik veya ideolojik motivasyonla gerçekleştirilen siber saldırı (site tahrifatı, DDoS) çağrıları veya eylemleri bulunmuştur.',
        'neden' => '"Hacked by", "defaced", "dava", "boykot" gibi hacktivist jargon kullanımı saptanmıştır.'
    ],
    'İç Tehdit (Insider Threat)' => [
        'aciklama' => 'Kurum çalışanları tarafından gerçekleştirilen yetki kötüye kullanımı veya kasıtlı veri sızıntısı şüphesi taşıyan içerik.',
        'neden' => 'Kurumsal yapı içerisinden dışarıya bilgi aktarımı veya yetki aşımı belirten ifadeler analiz edilmiştir.'
    ],
    'Yeni Teknolojiler Kaynaklı Tehditler' => [
        'aciklama' => 'Yapay zeka, blockchain veya IoT gibi gelişmekte olan teknolojileri hedef alan veya bu teknolojileri kullanan tehditler.',
        'neden' => '"AI attack", "smart contract exploit", "deepfake" gibi modern teknoloji tehditleri tespit edilmiştir.'
    ]
];

$ana_kategori = isset($kayit['ana_kategori']) && $kayit['ana_kategori'] ? $kayit['ana_kategori'] : $kayit['kategori'];
$alt_kategori = isset($kayit['alt_kategori']) && $kayit['alt_kategori'] ? $kayit['alt_kategori'] : '';

$kategori_bilgi = $kategori_aciklamalari[$ana_kategori] ?? [
    'aciklama' => 'İçerik analizi sonucunda sistem tarafından otomatik sınıflandırma yapılmıştır.',
    'neden' => 'İçerikteki anahtar kelime yoğunluğuna göre en uygun kategori belirlenmiştir.'
];

$guven_skoru_aciklamalari = [
    1 => [
        'aciklama' => 'Güvenilmez',
        'renk' => '#c0392b',
        'detay' => 'Bu kaynak, genelde yanıltıcı veya güvenilir olmayan bilgiler paylaşmıştır. Analistler bu kaynaktan gelen bilgileri çok dikkatli değerlendirmelidir.'
    ],
    2 => [
        'aciklama' => 'Düşük Güvenilirlik',
        'renk' => '#e67e22',
        'detay' => 'Bu kaynağın güvenilirliği sınırlıdır. Bilgiler doğrulanmadan kullanılmamalıdır.'
    ],
    3 => [
        'aciklama' => 'Orta Güvenilirlik',
        'renk' => '#f1c40f',
        'detay' => 'Bu kaynak bazen doğru bazen yanlış bilgiler paylaşabilir. Çapraz doğrulama önerilir.'
    ],
    4 => [
        'aciklama' => 'Yüksek Güvenilirlik',
        'renk' => '#27ae60',
        'detay' => 'Bu kaynak, genelde güvenilir ve doğru bilgiler paylaşmıştır. Bilgiler genellikle doğrulanabilir niteliktedir.'
    ],
    5 => [
        'aciklama' => 'Doğrulanmış Kaynak',
        'renk' => '#27ae60',
        'detay' => 'Bu kaynak geçmişte tutarlı ve teyit edilmiş istihbarat sağlamıştır. Oldukça güvenilirdir.'
    ]
];

$guven_skoru_bilgi = $guven_skoru_aciklamalari[$kaynak_guven_skoru] ?? $guven_skoru_aciklamalari[3];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <?php
    // Title için güvenli başlık - CSS ve HTML kodlarını temizle
    $safe_title = $kayit['baslik'] ?? '';
    // Önce tüm HTML/CSS kodlarını kaldır
    $safe_title = strip_tags($safe_title);
    // CSS kodlarını kaldır { ... }
    $safe_title = preg_replace('/\{[^}]*\}/', '', $safe_title);
    // Style attribute'larını kaldır
    $safe_title = preg_replace('/style\s*=\s*["\'][^"\']*["\']/i', '', $safe_title);
    // CSS class/id tanımlamalarını kaldır
    $safe_title = preg_replace('/\.\w+\s*\{[^}]*\}/', '', $safe_title);
    $safe_title = preg_replace('/#\w+\s*\{[^}]*\}/', '', $safe_title);
    // Sadece  boşluk ve bazı özel karakterlere izin ver
    $safe_title = preg_replace('/[^a-zA-Z0-9\s\-_\.\,\:\;\!\?\(\)]/', '', $safe_title);
    // Fazla boşlukları temizle
    $safe_title = preg_replace('/\s+/', ' ', $safe_title);
    $safe_title = trim($safe_title);
    // 50 karakter sınırı
    $safe_title = mb_substr($safe_title, 0, 50, 'UTF-8');
    $safe_title = htmlspecialchars($safe_title, ENT_QUOTES, 'UTF-8');
    ?>
    <title>Dvice CTI - Kayıt Detayı<?= $safe_title ? ' - ' . $safe_title : '' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
</head>
<body>
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <h1>Dvice CTI - Kayıt Detayı</h1>
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
        <div style="position: relative; margin-bottom: 20px;">
            <button type="button" class="pdf-buton" onclick="printDetailCard()" style="position: absolute; top: 0; right: 0; z-index: 10;">PDF Olarak Kaydet</button>
        </div>
        <div class="detay-kart">
            <div class="bolum-baslik">Temel Bilgiler</div>
            
            <div style="background: var(--color-surface); padding: 24px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); margin-top: 15px; margin-bottom: 25px; border: 1px solid var(--color-border); max-width: 100%;">
                <div class="detay-alan" style="margin-bottom: 20px;">
                    <label>Toplama Tarihi</label>
                    <div class="deger"><?= tarihFormatla($kayit['toplama_tarihi']) ?></div>
                </div>

                <div class="detay-alan" style="margin-bottom: 20px;">
                    <label>Kaynak Adı</label>
                    <div class="deger"><?= guvenliCikti($kayit['kaynak_adi']) ?></div>
                </div>

                <div class="detay-alan" style="margin-bottom: 20px;">
                    <label>Kaynak Güven Skoru</label>
                    <div class="guven-skoru" style="border-left-color: <?= $guven_skoru_bilgi['renk'] ?>">
                        <div class="guven-skoru-yildizlar">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="guven-skoru-yildiz <?= $i <= $kaynak_guven_skoru ? 'aktif' : '' ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <div>
                            <strong><?= $kaynak_guven_skoru ?>/5</strong> - <?= $guven_skoru_bilgi['aciklama'] ?>
                        </div>
                    </div>
                </div>

                <div class="detay-alan" style="margin-bottom: 0;">
                    <label>Kaynak URL</label>
                    <div class="deger" style="word-break: break-all; color: #3498db; font-family: monospace; font-size: 13px;">
                        <?= guvenliCikti($kayit['kaynak_url']) ?>
                    </div>
                </div>
            </div>

            <?php
            
            $tarama_sonucu_yolu = $kayit['tarama_sonucu_yolu'] ?? null;
            ?>
            <?php 
            $host_tarama_yolu = null;
            $web_path = null;
            
            if ($tarama_sonucu_yolu && !empty(trim($tarama_sonucu_yolu))) {
      
                $container_path = str_replace('/root/output', '/var/www/html/output', $tarama_sonucu_yolu);
                
                if (is_dir($container_path)) {
                    $host_tarama_yolu = $container_path;
                    $web_path = str_replace('/var/www/html/', '', $container_path);
                } else {
             
                    $relative_path = str_replace('/root/output', '', $tarama_sonucu_yolu);
                    $relative_path = ltrim($relative_path, '/');
                    $local_output_path = __DIR__ . '/output/' . $relative_path;
                    
                    if (is_dir($local_output_path)) {
                        $host_tarama_yolu = $local_output_path;
                        $web_path = 'output/' . $relative_path;
                    } else {
                      
                        if (strpos($tarama_sonucu_yolu, 'output/') === 0) {
                            $web_path = $tarama_sonucu_yolu;
                            $host_tarama_yolu = __DIR__ . '/' . $web_path;
                        } else {
                         
                            $relative_path = str_replace('/root/output', '', $tarama_sonucu_yolu);
                            $relative_path = ltrim($relative_path, '/');
                            $web_path = 'output/' . $relative_path;
                            $host_tarama_yolu = __DIR__ . '/' . $web_path;
                        }
                    }
                }
            }
            ?>
            <?php if ($host_tarama_yolu && is_dir($host_tarama_yolu)): ?>
            <div class="bolum-baslik">Onion Tarama Sonuçları</div>
            
            <?php 
            $ss_path = $host_tarama_yolu . '/screenshot.png';
            $has_ss = file_exists($ss_path);
            ?>
            
            <?php if ($has_ss): ?>
            <div class="detay-alan">
                <label>Sayfa Ekran Görüntüsü (PNG)</label>
                <div style="margin-top: 15px; background: #000000; padding: 20px; border-radius: 8px; text-align: center;">
                    <?php
                    $screenshot_url = $web_path . '/screenshot.png';
                    ?>
                    <img src="<?= htmlspecialchars($screenshot_url) ?>" 
                         alt="Sayfa Ekran Görüntüsü" 
                         style="max-width: 100%; max-height: 600px; border: 2px solid #ddd; border-radius: 5px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.1);"
                         onclick="window.open(this.src, '_blank')"
                         title="Tam boyut için tıklayın">
                    <div style="margin-top: 15px;">
                        <a href="<?= htmlspecialchars($screenshot_url) ?>" 
                           download="screenshot_<?= $id ?>.png" 
                           class="btn btn-primary"
                           style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; font-weight: 600;">
                           📥 Ekran Görüntüsünü İndir (PNG)
                        </a>
                        <div style="margin-top: 5px; color: #7f8c8d; font-size: 12px;">
                            Tam boyut görüntülemek için resme tıklayın
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (file_exists($host_tarama_yolu . '/links.txt')): ?>
            <div class="detay-alan">
                <label>Çıkarılan Linkler</label>
                <div style="margin-top: 15px; background: #000000; padding: 20px; border-radius: 8px;">
                    <?php
                    $links = file($host_tarama_yolu . '/links.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $link_count = count($links);
                    ?>
                    <div style="margin-bottom: 15px; padding: 10px; background: #1a1a1a; border-radius: 5px; border-left: 4px solid #3498db;">
                        <strong style="color: #ffffff; font-size: 16px;"><?= number_format($link_count) ?></strong> 
                        <span style="color: #cccccc;">link bulundu</span>
                    </div>
                    <?php if ($link_count > 0): ?>
                        <div style="max-height: 400px; overflow-y: auto; background: #1a1a1a; padding: 15px; border-radius: 5px;">
                            <?php foreach ($links as $index => $link): ?>
                                <div style="padding: 10px 0; border-bottom: 1px solid #333; display: flex; align-items: center; gap: 10px;">
                                    <span style="color: #999; font-size: 12px; min-width: 30px;"><?= $index + 1 ?>.</span>
                                    <a href="<?= htmlspecialchars($link) ?>" 
                                       target="_blank" 
                                       style="color: #3498db; text-decoration: none; word-break: break-all; flex: 1;"
                                       onmouseover="this.style.textDecoration='underline'"
                                       onmouseout="this.style.textDecoration='none'">
                                        <?= htmlspecialchars($link) ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: #cccccc;">
                            Bu sayfada link bulunamadı.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (file_exists($host_tarama_yolu . '/site_snapshot.mhtml')): ?>
            <div class="detay-alan">
                <label>MHTML Snapshot (Tam Sayfa Görünümü)</label>
                <div style="margin-top: 15px; background: #000000; padding: 20px; border-radius: 8px;">
                    <div style="padding: 15px; background: #1a1a1a; border-radius: 5px; border-left: 4px solid #27ae60;">
                        <p style="margin: 0 0 15px 0; color: #ffffff;">
                            MHTML formatı, sayfanın tam görünümünü ve tüm kaynaklarını içeren bir arşiv dosyasıdır. 
                            Bu dosyayı indirip tarayıcınızda açarak sayfanın tam halini offline olarak görüntüleyebilirsiniz.
                        </p>
                        <?php
                        $mhtml_url = $web_path . '/site_snapshot.mhtml';
                        ?>
                        <a href="<?= htmlspecialchars($mhtml_url) ?>" 
                           download="site_snapshot_<?= $id ?>.mhtml"
                           class="btn btn-success"
                           style="display: inline-block; padding: 12px 25px; background: #27ae60; color: white; text-decoration: none; border-radius: 5px; font-weight: 500; transition: background 0.3s;"
                           onmouseover="this.style.background='#229954'"
                           onmouseout="this.style.background='#27ae60'">
                           📥 MHTML Dosyasını İndir
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (file_exists($host_tarama_yolu . '/site_data.html')): ?>
            <div class="detay-alan">
                <label>HTML İçerik (Temizlenmiş)</label>
                <div style="margin-top: 15px;">
                    <?php
                    $html_url = $web_path . '/site_data.html';
                    ?>
                    <a href="<?= htmlspecialchars($html_url) ?>" 
                       target="_blank" 
                       style="display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; font-weight: 500;">
                        HTML Dosyasını Görüntüle
                    </a>
                    <span style="margin-left: 10px; color: #7f8c8d; font-size: 12px;">
                        (Linkler ve kaynaklar temizlenmiş offline versiyon)
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <div class="detay-alan">
                <div style="padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px; color: #856404;">
                    <strong>Tarama Sonuçları Henüz Mevcut Değil</strong><br>
                    Bu onion adresi için henüz tarama yapılmamış veya tarama sonuçları hazır değil. 
                    Admin panelinden "Tara" butonuna tıklayarak tarama başlatabilirsiniz.
                </div>
            </div>
            <?php endif; ?>

            <div class="bolum-baslik">Kategori Analizi</div>
            
            <div class="detay-alan">
                <label>Ana Kategori</label>
                <div class="deger"><?= guvenliCikti($ana_kategori) ?></div>
                <?php if ($alt_kategori): ?>
                    <div class="detay-alan" style="margin-top: 15px;">
                        <label>Alt Kategori</label>
                        <div class="deger"><?= guvenliCikti($alt_kategori) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bolum-baslik">Kritiklik Analizi</div>
            
            <div class="detay-alan">
                <label>Kritiklik Seviyesi</label>
                <div class="deger">
                    <span class="kritiklik-badge kritiklik-<?= guvenliCikti($kayit['kritiklik']) ?>">
                        <?= ucfirst(guvenliCikti($kayit['kritiklik'])) ?>
                    </span>
                    <?php if (!empty($kayit['kritiklik_skor'])): ?>
                        <span style="margin-left: 15px; color: #7f8c8d; font-size: 14px;">
                            (Skor: <strong><?= $kayit['kritiklik_skor'] ?>/100</strong>)
                        </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($kayit['kritiklik_aciklama'])): ?>
                    <div class="aciklama-kutu" style="margin-top: 10px; background: #fff3cd; border-left-color: #f39c12; color: #000;">
                        <?= nl2br(guvenliCikti($kayit['kritiklik_aciklama'])) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bolum-baslik">İçerik Özeti</div>
            
            <div class="detay-alan">
                <label>Özet</label>
                <div class="ozet-kutu">
                    <?= nl2br(guvenliCikti($kayit['ozet'])) ?>
                </div>
            </div>

            <?php if ($kaynak_istatistik): ?>
            <div class="bolum-baslik">Kaynak İstatistikleri</div>
            <div class="kaynak-istatistik">
                <div class="kaynak-istatistik-item">
                    <div class="deger"><?= number_format($kaynak_istatistik['toplam_kayit']) ?></div>
                    <div class="label">Toplam Kayıt</div>
                </div>
                <div class="kaynak-istatistik-item">
                    <div class="deger"><?= $kaynak_istatistik['kategori_sayisi'] ?></div>
                    <div class="label">Kategori Sayısı</div>
                </div>
                <div class="kaynak-istatistik-item">
                    <div class="deger"><?= number_format($kaynak_istatistik['ortalama_kritiklik_skor'] ?? 0, 1) ?></div>
                    <div class="label">Ort. Kritiklik Skoru</div>
                </div>
                <?php if ($kaynak_istatistik['son_kayit_tarihi']): ?>
                <div class="kaynak-istatistik-item">
                    <div class="deger" style="font-size: 14px;"><?= tarihFormatla($kaynak_istatistik['son_kayit_tarihi']) ?></div>
                    <div class="label">Son Kayıt Tarihi</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <a href="index.php" class="geri-buton">← Ana Sayfaya Dön</a>
        </div>
    </div>
    
    <div class="footer-banner">
        <a href="https://github.com/dvicewashere" target="_blank" style="color: var(--color-primary); text-shadow: 0 0 8px rgba(57, 255, 20, 0.4); text-decoration: none;">Dvice was here ❤</a>
    </div>
    
    <script>
    function printDetailCard() {
        // Detay kartı içeriğini al
        const detayKart = document.querySelector('.detay-kart');
        if (!detayKart) {
            alert('Detay kartı bulunamadı!');
            return;
        }
        
        // Başlık için - header'dan veya title'dan al
        let baslik = 'Dvice CTI - Kayıt Detayı';
        const headerH1 = document.querySelector('.header h1');
        if (headerH1) {
            baslik = headerH1.textContent;
        } else {
            const titleElement = document.querySelector('title');
            if (titleElement) {
                baslik = titleElement.textContent.replace('Dvice CTI - ', ''); 
            }
        }
        
        // Yeni pencere aç
        const printWindow = window.open('', '', 'height=600,width=800');
        
        // HTML içeriği oluştur
        printWindow.document.write('<html><head><title>' + baslik + '</title>');
        printWindow.document.write('<style>');
        printWindow.document.write('@page { margin: 1.5cm; size: A4; }');
        printWindow.document.write('body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; background: white; color: #000; }');
        printWindow.document.write('h1 { font-size: 18pt; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #3498db; }');
        printWindow.document.write('.bolum-baslik { font-size: 14pt; margin: 15px 0 10px 0; padding-bottom: 5px; border-bottom: 1px solid #ddd; font-weight: 600; }');
        printWindow.document.write('.detay-alan { margin-bottom: 12px; }');
        printWindow.document.write('.detay-alan label { display: block; font-size: 9pt; text-transform: uppercase; margin-bottom: 4px; font-weight: 600; color: #666; }');
        printWindow.document.write('.detay-alan .deger { font-size: 10pt; color: #000; }');
        printWindow.document.write('.ozet-kutu, .aciklama-kutu { background: #f9f9f9; padding: 10px; border-left: 3px solid #3498db; margin: 8px 0; font-size: 9pt; }');
        printWindow.document.write('.kritiklik-badge { display: inline-block; padding: 4px 10px; border-radius: 15px; font-size: 9pt; font-weight: 600; }');
        printWindow.document.write('.kritiklik-dusuk { background: #27ae60; color: white; }');
        printWindow.document.write('.kritiklik-orta { background: #f39c12; color: white; }');
        printWindow.document.write('.kritiklik-yuksek { background: #e67e22; color: white; }');
        printWindow.document.write('.kritiklik-kritik { background: #e74c3c; color: white; }');
        printWindow.document.write('.guven-skoru { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f5f5f5; border-radius: 5px; border-left: 3px solid #3498db; }');
        printWindow.document.write('.guven-skoru-yildiz { font-size: 14px; color: #ddd; }');
        printWindow.document.write('.guven-skoru-yildiz.aktif { color: #f39c12; }');
        printWindow.document.write('.kaynak-istatistik { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 10px 0; }');
        printWindow.document.write('.kaynak-istatistik-item { padding: 10px; background: #f5f5f5; border-radius: 5px; text-align: center; }');
        printWindow.document.write('.kaynak-istatistik-item .deger { font-size: 16pt; font-weight: 700; }');
        printWindow.document.write('.kaynak-istatistik-item .label { font-size: 8pt; color: #666; margin-top: 4px; }');
        printWindow.document.write('img { max-width: 100%; height: auto; }');
        printWindow.document.write('form, button, .pdf-buton, .geri-buton { display: none !important; }');
        printWindow.document.write('</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write('<h1>' + baslik + '</h1>');
        printWindow.document.write(detayKart.innerHTML);
        printWindow.document.write('</body></html>');
        
        printWindow.document.close();
        printWindow.focus();
        
  
        setTimeout(function() {
            printWindow.print();
            printWindow.close();
        }, 250);
    }
    </script>
    
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
