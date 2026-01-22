<?php
require_once '/var/www/backend/config/config.php';

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kullanici_adi = trim($_POST['kullanici_adi'] ?? '');
    $sifre = $_POST['sifre'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!csrfTokenDogrula($csrf_token)) {
        $hata = 'Guvenlik hatasi. Lutfen tekrar deneyin.';
    } elseif (empty($kullanici_adi) || empty($sifre)) {
        $hata = 'Kullanici adi ve sifre gereklidir.';
    } else {
        try {
            $db = dbBaglanti();
            $stmt = $db->prepare("SELECT id, kullanici_adi, sifre_hash, rol, aktif FROM kullanicilar WHERE kullanici_adi = ?");
            $stmt->execute([$kullanici_adi]);
            $kullanici = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($kullanici && $kullanici['aktif'] && password_verify($sifre, $kullanici['sifre_hash'])) {
                $_SESSION['kullanici_id'] = $kullanici['id'];
                $_SESSION['kullanici_adi'] = $kullanici['kullanici_adi'];
                $_SESSION['rol'] = $kullanici['rol'];
                
                $stmt = $db->prepare("UPDATE kullanicilar SET son_giris = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$kullanici['id']]);
                
                logKaydet("Kullanici girisi: " . $kullanici_adi);
                
                header('Location: index.php');
                exit;
            } else {
                $hata = 'Kullanici adi veya sifre hatali.';
                logKaydet("Basarisiz giris denemesi: " . $kullanici_adi, 'WARNING');
            }
        } catch (PDOException $e) {
            $hata = 'Giris sirasinda bir hata olustu.';
            logKaydet("Giris hatasi: " . $e->getMessage(), 'ERROR');
        }
    }
}

if (isset($_SESSION['kullanici_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dvice CTI - Giriş</title>
    <link rel="icon" type="image/x-icon" href="assets/images/web.ico">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #000000;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(57, 255, 20, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(57, 255, 20, 0.03) 0%, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        /* Matrix Canvas - Behind everything */
        #matrix-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 0;
            pointer-events: none; /* Allow clicks to pass through */
        }
        
    
        .login-container {
            position: relative;
            z-index: 10;
        }
        .login-container {
            background: var(--color-surface);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(57, 255, 20, 0.3), 0 0 30px rgba(57, 255, 20, 0.2);
            width: 100%;
            max-width: 400px;
            border: 1px solid var(--color-border);
            overflow: hidden;
            position: relative;
            z-index: 10;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .login-logo img {
            max-width: 260px;
            max-height: 260px;
            width: auto;
            height: auto;
            filter: drop-shadow(0 0 15px rgba(57, 255, 20, 0.6));
            margin-bottom: 15px;
            object-fit: contain;
        }
        h1 {
            text-align: center;
            color: var(--color-primary);
            margin-bottom: 10px;
            font-size: 24px;
            text-shadow: 0 0 10px rgba(57, 255, 20, 0.5);
            font-family: 'Orbitron', sans-serif;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: var(--color-text-muted);
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid var(--color-border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            transition: all 0.3s ease;
            background: #000000 !important;
            background-color: #000000 !important;
            color: var(--color-text) !important;
            color: #C0FFC0 !important;
            font-family: 'Inter', sans-serif;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--color-primary-glow);
            box-shadow: var(--glow-primary);
            background: rgba(57, 255, 20, 0.05) !important;
            background-color: rgba(57, 255, 20, 0.05) !important;
            color: #C0FFC0 !important;
        }
        input[type="text"]::placeholder,
        input[type="password"]::placeholder {
            color: var(--color-text-muted) !important;
            opacity: 0.7;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #8BFF8B 0%, #39FF14 100%) !important;
            color: #000000 !important;
            border: 1px solid #39FF14 !important;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            box-shadow: 0 0 10px rgba(57, 255, 20, 0.3);
        }
        button:hover,
        button:focus,
        button:active {
            background: linear-gradient(135deg, #8BFF8B 0%, #39FF14 100%) !important;
            box-shadow: 0 0 15px rgba(57, 255, 20, 0.8), 0 0 30px rgba(57, 255, 20, 0.6);
            transform: translateY(-2px);
            color: #000000 !important;
            border-color: #39FF14 !important;
        }
        .login-accounts-btn {
            width: 100%;
            padding: 8px 12px;
            background: linear-gradient(135deg, #8BFF8B 0%, #39FF14 100%) !important;
            color: #000000 !important;
            border: 1px solid #39FF14 !important;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            box-shadow: 0 0 10px rgba(57, 255, 20, 0.3);
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 15px;
        }
        .login-accounts-btn:hover,
        .login-accounts-btn:focus,
        .login-accounts-btn:active {
            background: linear-gradient(135deg, #8BFF8B 0%, #39FF14 100%) !important;
            box-shadow: 0 0 15px rgba(57, 255, 20, 0.8), 0 0 30px rgba(57, 255, 20, 0.6);
            transform: translateY(-2px);
            color: #000000 !important;
            border-color: #39FF14 !important;
        }
        .login-accounts-icon {
            font-size: 16px;
        }
        .hata {
            background: rgba(187, 68, 68, 0.2);
            color: var(--color-error);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid var(--color-error);
            box-shadow: 0 0 10px rgba(187, 68, 68, 0.3);
        }
        .login-hint {
            margin-top: 20px;
            text-align: center;
            color: #8BFF8B;
            font-size: 12px;
        }
    </style>
</head>
<body class="login-page">
    <!-- Matrix Rain Background Canvas -->
    <canvas id="matrix-canvas"></canvas>
    
    <div class="login-container">
        <div class="login-logo">
            <img src="assets/images/logo.png" alt="Dvice CTI Logo">
        </div>
        <?php if ($hata): ?>
            <div class="hata"><?= guvenliCikti($hata) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrfTokenOlustur() ?>">
            <div class="form-group">
                <label>Kullanıcı Adı</label>
                <input type="text" name="kullanici_adi" required autofocus>
            </div>
            <div class="form-group">
                <label>Şifre</label>
                <input type="password" name="sifre" required>
            </div>
            <button type="submit">Giriş Yap</button>
        </form>
        
        <a href="https://github.com/dvicewashere/dvice_cti_analysis/blob/main/Kullan%C4%B1c%C4%B1%20hesaplar%C4%B1.txt" target="_blank" class="login-accounts-btn">
            <span class="login-accounts-icon">👤</span>
            Kullanıcı Hesapları
        </a>
    </div>
    
    <!-- Matrix Rain Background Script -->
    <script src="assets/js/matrixBackground.js"></script>
    
    <div class="footer-banner">
        <a href="https://github.com/dvicewashere" target="_blank" style="color: var(--color-primary); text-shadow: 0 0 8px rgba(57, 255, 20, 0.4); text-decoration: none;">Dvice was here ❤</a>
    </div>
</body>
</html>
