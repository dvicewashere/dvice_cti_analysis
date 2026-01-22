<?php
require_once __DIR__ . '/../config/config.php';
rolKontrol('admin');

$onionId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$url = 'http://go_collector:8081/api/tarama';
if ($onionId > 0) {
    $url .= '?id=' . $onionId;
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json');

if ($error) {
    echo json_encode([
        'basarili' => false,
        'mesaj' => 'Go collector servisine baglanilamadi: ' . $error
    ]);
} else {
    if ($httpCode === 200) {
        echo $response;
    } else {
        echo json_encode([
            'basarili' => false,
            'mesaj' => 'HTTP ' . $httpCode . ' hatasi'
        ]);
    }
}
