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
    } elseif ($_GET['mesaj'] === 'sifre_degisti') {
        $mesaj = 'Şifreniz başarıyla değiştirildi.';
    }
}




// Log indirme işlemi
if (isset($_GET['islem']) && $_GET['islem'] === 'log_indir') {
    $log_dosya = '/var/www/backend/logs/app.log';
    if (file_exists($log_dosya)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d_H-i') . '.log"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($log_dosya));
        readfile($log_dosya);
        exit;
    }
}

// Log temizleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'log_temizle') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        $log_dosya = '/var/www/backend/logs/app.log';
        try {
            // Log dosyasını temizle (boş string yaz)
            if (file_exists($log_dosya)) {
                file_put_contents($log_dosya, '');
                $mesaj = 'Tüm loglar temizlendi.';
                logKaydet("Log dosyası temizlendi (admin tarafından)", 'WARNING');
            } else {
                $hata = 'Log dosyası bulunamadı.';
            }
        } catch (Exception $e) {
            $hata = 'Log temizleme işlemi başarısız.';
            logKaydet("Log temizleme hatası: " . $e->getMessage(), 'ERROR');
        }
    }
}


// Şifre değiştirme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['islem']) && $_POST['islem'] === 'sifre_degistir') {
    if (!csrfTokenDogrula($_POST['csrf_token'] ?? '')) {
        $hata = 'Güvenlik hatası.';
    } else {
        $eski_sifre = trim($_POST['eski_sifre'] ?? '');
        $yeni_sifre = trim($_POST['yeni_sifre'] ?? '');
        $yeni_sifre_tekrar = trim($_POST['yeni_sifre_tekrar'] ?? '');
        
        if (empty($eski_sifre) || empty($yeni_sifre) || empty($yeni_sifre_tekrar)) {
            $hata = 'Tüm alanları doldurun.';
        } elseif ($yeni_sifre !== $yeni_sifre_tekrar) {
            $hata = 'Yeni şifreler eşleşmiyor.';
        } elseif (strlen($yeni_sifre) < 8) {
            $hata = 'Yeni şifre en az 8 karakter olmalıdır.';
        } else {
            try {
                $stmt = $db->prepare("SELECT sifre_hash FROM kullanicilar WHERE id = ?");
                $stmt->execute([$_SESSION['kullanici_id']]);
                $mevcut_sifre_hash = $stmt->fetchColumn();
                
                if (!password_verify($eski_sifre, $mevcut_sifre_hash)) {
                    $hata = 'Eski şifre yanlış.';
                } else {
                    $yeni_sifre_hash = password_hash($yeni_sifre, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE kullanicilar SET sifre_hash = ? WHERE id = ?");
                    $stmt->execute([$yeni_sifre_hash, $_SESSION['kullanici_id']]);
                    logKaydet("Kullanıcı şifresini değiştirdi: " . $_SESSION['kullanici_adi']);
                    
                 
                    header("Location: loglar.php?mesaj=sifre_degisti");
                    exit;
                }
            } catch (PDOException $e) {
                $hata = 'Şifre değiştirilirken hata oluştu.';
                logKaydet("Şifre değiştirme hatası: " . $e->getMessage(), 'ERROR');
            }
        }
    }
}



// Log renklerini getir
$log_renkleri = [
    'ERROR' => '#ff4444',
    'WARNING' => '#ffaa00',
    'INFO' => '#39ff14'
];
foreach (['error', 'warning', 'info'] as $seviye) {
    try {
        $stmt = $db->prepare("SELECT ayar_degeri FROM sistem_ayarlari WHERE ayar_adi = ?");
        $stmt->execute(['log_renk_' . $seviye]);
        $renk = $stmt->fetchColumn();
        if ($renk) {
            $log_renkleri[strtoupper($seviye)] = $renk;
        }
    } catch (PDOException $e) {
        // Hata durumunda varsayılan renkleri kullan
    }
}

// Tüm loglar için kopyalama özelliği
$log_kayitlari = [];
$tum_loglar = ''; // Tüm loglar için
$log_dosya = '/var/www/backend/logs/app.log';
if (file_exists($log_dosya)) {
    $log_lines = file($log_dosya, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $tum_loglar = implode("\n", $log_lines); // Tüm logları birleştir
    $log_lines = array_reverse($log_lines); // En yeni önce
    $log_kayitlari = array_slice($log_lines, 0, 100); // Son 100 kayıt
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <title>Dvice CTI - Loglar</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/starsBackground.js"></script>
</head>
<body>
    <canvas id="stars-canvas"></canvas>
    <div class="header">
        <div class="logo-container">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <h1>Dvice CTI - Loglar</h1>
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 12px;">
                <h2 style="margin: 0; border: none; padding: 0;">Sistem Logları (Son 100)</h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div class="log-controls" style="display: flex; gap: 2px;">
                        <button type="button" class="btn btn-sm btn-light" onclick="setFontSize('small')" title="Küçük Yazı">A</button>
                        <button type="button" class="btn btn-sm btn-light" onclick="setFontSize('medium')" title="Orta Yazı">A</button>
                        <button type="button" class="btn btn-sm btn-light" onclick="setFontSize('large')" title="Büyük Yazı">A</button>
                    </div>
                    <a href="?islem=log_indir" class="btn btn-sm btn-primary">
                        Tüm Logları İndir
                    </a>
                    <button type="button" class="btn btn-sm btn-primary" onclick="kopyalaLoglar()" id="kopyalaBtn">
                        📋 Logları Kopyala
                    </button>
                    <form method="POST" style="display: inline-block; margin: 0;" onsubmit="return confirm('Tüm logları temizlemek istediğinizden emin misiniz? Bu işlem geri alınamaz!');">
                        <input type="hidden" name="islem" value="log_temizle">
                        <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
                        <button type="submit" class="btn btn-sm" style="background: linear-gradient(135deg, var(--color-error) 0%, #8B0000 100%); border-color: var(--color-error); color: white;">
                            🗑️ Logları Temizle
                        </button>
                    </form>
                </div>
            </div>

            <?php if (empty($log_kayitlari)): ?>
                <p style="text-align: center; padding: 40px; color: var(--color-text-muted);">
                    Henüz log kaydı bulunmuyor.
                </p>
            <?php else: ?>
                <div id="logViewer" class="log-content log-medium">
                    <?php foreach ($log_kayitlari as $log): ?>
                        <?php
                        // Log seviyesini belirle ve class ata
                        $log_class = '';
                        $emoji = '';
                        $bg_color = '';
                        if (strpos($log, '[ERROR]') !== false) {
                            $log_class = 'log-error';
                            $emoji = '❌';
                            $bg_color = $log_renkleri['ERROR'];
                        } elseif (strpos($log, '[WARNING]') !== false) {
                            $log_class = 'log-warning';
                            $emoji = '⚠️';
                            $bg_color = $log_renkleri['WARNING'];
                        } elseif (strpos($log, '[INFO]') !== false) {
                            $log_class = 'log-info';
                            $emoji = 'ℹ️';
                            $bg_color = $log_renkleri['INFO'];
                        }
                        ?>
                        <div class="log-entry <?= $log_class ?>" style="<?= $bg_color ? 'background: ' . htmlspecialchars($bg_color) . '20 !important; border-left-color: ' . htmlspecialchars($bg_color) . '; color: ' . htmlspecialchars($bg_color) . ' !important;' : '' ?>"><?php echo $emoji ? $emoji : ''; ?><?= guvenliCikti($log) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tüm logları JavaScript'e aktarmak -->
    <textarea id="tumLoglar" style="position: absolute; left: -9999px; opacity: 0;"><?= htmlspecialchars($tum_loglar) ?></textarea>

    <script>
        function setFontSize(size) {
            const viewer = document.getElementById('logViewer');
            viewer.classList.remove('log-small', 'log-medium', 'log-large');
            viewer.classList.add('log-' + size);
            
      
            localStorage.setItem('logFontSize', size);
        }

        // Logları kopyalama fonksiyonu
        function kopyalaLoglar() {
            const tumLoglarTextarea = document.getElementById('tumLoglar');
            const kopyalaBtn = document.getElementById('kopyalaBtn');
            
            if (!tumLoglarTextarea || !tumLoglarTextarea.value) {
                alert('Kopyalanacak log bulunamadı.');
                return;
            }

            tumLoglarTextarea.style.position = 'fixed';
            tumLoglarTextarea.style.opacity = '1';
            tumLoglarTextarea.select();
            tumLoglarTextarea.setSelectionRange(0, 99999); 

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    // Başarılı mesajı göster
                    const originalText = kopyalaBtn.innerHTML;
                    kopyalaBtn.innerHTML = '✓ Kopyalandı!';
                    kopyalaBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                    
                    setTimeout(function() {
                        kopyalaBtn.innerHTML = originalText;
                        kopyalaBtn.style.background = '';
                    }, 2000);
                } else {
                 
                    navigator.clipboard.writeText(tumLoglarTextarea.value).then(function() {
                        const originalText = kopyalaBtn.innerHTML;
                        kopyalaBtn.innerHTML = '✓ Kopyalandı!';
                        kopyalaBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                        
                        setTimeout(function() {
                            kopyalaBtn.innerHTML = originalText;
                            kopyalaBtn.style.background = '';
                        }, 2000);
                    }).catch(function(err) {
                        alert('Loglar kopyalanırken hata oluştu: ' + err);
                    });
                }
            } catch (err) {
            
                navigator.clipboard.writeText(tumLoglarTextarea.value).then(function() {
                    const originalText = kopyalaBtn.innerHTML;
                    kopyalaBtn.innerHTML = '✓ Kopyalandı!';
                    kopyalaBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                    
                    setTimeout(function() {
                        kopyalaBtn.innerHTML = originalText;
                        kopyalaBtn.style.background = '';
                    }, 2000);
                }).catch(function(err) {
                    alert('Loglar kopyalanırken hata oluştu: ' + err);
                });
            } finally {
               
                tumLoglarTextarea.style.position = 'absolute';
                tumLoglarTextarea.style.opacity = '0';
            }
        }


        document.addEventListener('DOMContentLoaded', function() {
            const savedSize = localStorage.getItem('logFontSize');
            if (savedSize) {
                setFontSize(savedSize);
            }
        });
    </script>
    
    <div class="footer-banner">
        <a href="https://github.com/dvicewashere" target="_blank" style="color: var(--color-primary); text-shadow: 0 0 8px rgba(57, 255, 20, 0.4); text-decoration: none;">Dvice was here ❤</a>
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
</body>
</html>
