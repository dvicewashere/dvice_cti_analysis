Dvice CTI – Siber Tehdit İstihbarat Platformu

Dvice CTI, onion ağları başta olmak üzere farklı kaynaklardan siber tehdit verilerini toplayan, analiz eden ve anlamlı hale getirerek sunan bir Cyber Threat Intelligence (CTI) platformudur.


## Özellikler

### Gösterge Paneli ve Analitik
*   **Gerçek Zamanlı İstatistikler:** Toplam kayıtları, günlük veri giriş hızlarını ve kritik tehdit sayılarını izleyin.
*   **Kategori Analizi:** Tehditlerin kategorilere göre görsel dağılımı (örn. Fidye Yazılımı, Kimlik Avı, Botnet).
*   **Trend Analizi:** Son 24 saat ve 7 günlük tehdit aktivitelerini görüntüleyin.
*   **Kritik Uyarılar:** Yüksek önem seviyesindeki tehditleri anında fark edin.
*   **Gelişmiş Arama:** Verileri tarih aralığı, kritiklik, kategori ve kaynak adına göre filtreleyin.
*   **PDF Dışa Aktarma:** Belirli tehdit detayları için temiz, yazdırmaya hazır raporlar oluşturun.

### Yönetim Paneli
*   **Kaynak Yönetimi:** Çoklu onion adreslerini topluca ekleyin ve otomatik doğrulama sağlayın.
*   **Kullanıcı Yönetimi:** Rol tabanlı erişim ile analist hesapları oluşturun, silin ve yönetin.
*   **Sistem Logları:** Sistem izleme için ham metin tabanlı log görüntüleyici. Tüm logları indirme özelliği dahildir.
*   **Bütünleşik Tasarım:** Ana gösterge paneliyle uyumlu, tutarlı ve responsive arayüz.

### Backend ve Tarayıcı 
*   **Go Collector:** Tor proxy üzerinden güvenli `.onion` site taraması için yüksek performanslı crawler.
*   **Otomatik Kaynak Tespiti:** Site başlıklarından kaynak isimlerini otomatik olarak çıkarır ve günceller.
*   **İçerik Analizi:** Anahtar kelimelere dayalı güven skoru ve kritiklik seviyesi atamak için otomatik puanlama sistemi.
*   **PDF Uyum Modu (SAFE_MODE):** Varsayılan olarak ham tarama çıktıları (HTML/MHTML/PNG/link listesi) dosyaya kaydedilmez.


- **Kaynak adı**: Otomatik tespit edilir ve güncellenir
- **Kaynak URL**: Her kayıt için saklanır
- **Ham içerik (temizlenmiş metin)**: Veritabanında `temiz_icerik` alanında saklanır 
- **Paylaşım tarihi (varsa)**: İçerikten otomatik tespit edilmeye çalışılır, bulunursa kaydedilir
- **Kaynak kritikliği**: Otomatik hesaplanır ve saklanır

- **Başlıkların listelenmesi**: Ana sayfada başlıklar gösterilir
- **Kayıtların tarihinin belirtilmesi**: Toplama tarihi ve paylaşım tarihi gösterilir
- **Kayıtların kaynaklarının belirtilmesi**: Kaynak adı ve URL gösterilir
- **Veri detay ekranında tam içerik görüntüleme**: Detay sayfasında ham içerik (temizlenmiş metin) görüntülenir (PDF 3.4 gereksinimi)
- **Kategori dağılımı ve kritiklik derecelerinin gösterilmesi**: Grafikler ve tablolarla gösterilir
- **Kritik kategori düzenleme paneli**: Admin panelinde kategori ve kritiklik eşikleri yönetilebilir


## Teknolojiler

*   **Backend:** Go (Golang)
*   **Frontend:** PHP 8.x, HTML5, CSS3, Chart.js
*   **Veritabanı:** PostgreSQL 15
*   **Ağ:** Tor Proxy (Socks5)
*   **Konteynerizasyon:** Docker & Docker Compose

## Kurulum

### Gereksinimler
*   Docker
*   Docker Compose

### Hızlı Başlangıç
1.  **Depoyu Klonlayın**
    ```bash
    git clone https://github.com/dvicewashere/dvice_cti_analysis
    cd dvice_cti_analysis
    ```

2.  **Servisleri Başlatın**
    Tüm yapıyı derlemek ve başlatmak için aşağıdaki komutu çalıştırın:
    ```bash
    docker-compose up -d --build
    ```
    Bu komut PostgreSQL veritabanını, Tor proxy'sini, Go collector'ı ve PHP web sunucusunu başlatacaktır.

3.  **Uygulamaya Erişim**
    *   **Web Arayüzü:** http://localhost:8080
    *   **Go Collector:** http://localhost:8081 (Dahili API)

### Varsayılan Giriş Bilgileri
*   **Kullanıcı Adı:** `Dvice`
*   **Şifre:** `harunseker`


## Proje Yapısı

```
dvice-cti/
├── frontend/
│   ├── assets/
│   │   ├── css/
│   │   │   └── style.css
│   │   ├── js/
│   │   │   ├── starsBackground.js
│   │   │   ├── matrixBackground.js
│   │   │   └── tableCarousel.js
│   │   └── images/
│   │       ├── logo.png
│   │       └── web.ico
│   ├── output/
│   ├── index.php
│   ├── loglar.php
│   ├── detay.php
│   ├── kayitlar.php
│   ├── tehditler.php
│   ├── adresekle.php
│   ├── kategori.php
│   ├── kullanicilar.php
│   ├── login.php
│   ├── logout.php
│   └── Dockerfile
├── backend/
│   ├── api/
│   │   ├── api_tarama.php
│   │   └── api_alt_kategoriler.php
│   ├── config/
│   │   └── config.php
│   ├── go_collector/      
│   │   ├── main.go
│   │   ├── go.mod
│   │   ├── go.sum
│   │   └── Dockerfile
│   ├── sql/
│   │   └── init.sql
│   └── logs/
│       └── app.log
├── docker-compose.yml
├── README.md 
```

---

