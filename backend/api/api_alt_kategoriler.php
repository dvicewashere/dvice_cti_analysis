<?php
require_once __DIR__ . '/../config/config.php';
kullaniciGirisKontrol();

header('Content-Type: application/json');

$ana_kategori = $_GET['ana_kategori'] ?? '';

if (empty($ana_kategori)) {
    echo json_encode([]);
    exit;
}

$db = dbBaglanti();

$ana_kategori_kolon_var = false;
try {
    $test_sql = "SELECT ana_kategori FROM cti_kayitlari LIMIT 1";
    $db->query($test_sql);
    $ana_kategori_kolon_var = true;
} catch (PDOException $e) {
    $ana_kategori_kolon_var = false;
}

if ($ana_kategori_kolon_var) {
    $sql = "SELECT DISTINCT alt_kategori 
            FROM cti_kayitlari 
            WHERE COALESCE(ana_kategori, kategori) = ? AND alt_kategori IS NOT NULL 
            ORDER BY alt_kategori";
} else {
    $sql = "SELECT DISTINCT NULL as alt_kategori 
            FROM cti_kayitlari 
            WHERE 1=0";
}

$stmt = $db->prepare($sql);
$stmt->execute([$ana_kategori]);
$alt_kategoriler = $stmt->fetchAll(PDO::FETCH_COLUMN);

$alt_kategoriler = array_filter($alt_kategoriler, function($val) {
    return $val !== null;
});

echo json_encode(array_values($alt_kategoriler));
