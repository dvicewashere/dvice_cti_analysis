CREATE TABLE IF NOT EXISTS kullanicilar (
    id SERIAL PRIMARY KEY,
    kullanici_adi VARCHAR(100) UNIQUE NOT NULL,
    sifre_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL CHECK (rol IN ('admin', 'analist')),
    olusturma_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    son_giris TIMESTAMP,
    aktif BOOLEAN DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS onion_adresleri (
    id SERIAL PRIMARY KEY,
    adres VARCHAR(255) UNIQUE NOT NULL,
    kaynak_adi VARCHAR(255) NOT NULL,
    aktif BOOLEAN DEFAULT TRUE,
    ekleme_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    son_tarama TIMESTAMP,
    toplam_kayit INTEGER DEFAULT 0,
    kaynak_guven_skoru INTEGER DEFAULT 3 CHECK (kaynak_guven_skoru >= 1 AND kaynak_guven_skoru <= 5),
    tarama_sonucu_yolu TEXT
);

CREATE TABLE IF NOT EXISTS cti_kayitlari (
    id SERIAL PRIMARY KEY,
    onion_id INTEGER REFERENCES onion_adresleri(id) ON DELETE CASCADE,
    kaynak_adi VARCHAR(255) NOT NULL,
    kaynak_url TEXT NOT NULL,
    baslik VARCHAR(500) NOT NULL,
    ozet TEXT NOT NULL,
    -- PDF gereksinimi: "Ham içerik (temizlenmiş metin)". UYARI: Eğitim/uyum modu için uygulama bu alanı boş bırakabilir.
    temiz_icerik TEXT,
    -- PDF gereksinimi: "Paylaşım tarihi (varsa)" - kaynağın kendi yayın tarihi tespit edilebilirse doldurulur.
    paylasim_tarihi TIMESTAMP NULL,
    ana_kategori VARCHAR(100) NOT NULL,
    alt_kategori VARCHAR(100),
    kategori VARCHAR(100) NOT NULL,
    kritiklik VARCHAR(20) NOT NULL CHECK (kritiklik IN ('dusuk', 'orta', 'yuksek', 'kritik')),
    kritiklik_skor INTEGER DEFAULT 0,
    kritiklik_aciklama TEXT,
    toplama_tarihi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    analist_notu TEXT,
    analist_not_tarihi TIMESTAMP,
    analist_not_kullanici_id INTEGER REFERENCES kullanicilar(id),
    kaynak_guven_skoru INTEGER DEFAULT 3 CHECK (kaynak_guven_skoru >= 1 AND kaynak_guven_skoru <= 5),
    UNIQUE(kaynak_url, toplama_tarihi)
);

CREATE TABLE IF NOT EXISTS kategoriler (
    id SERIAL PRIMARY KEY,
    kategori_adi VARCHAR(100) UNIQUE NOT NULL,
    ana_kategori VARCHAR(100),
    alt_kategori VARCHAR(100),
    aciklama TEXT,
    renk VARCHAR(7) DEFAULT '#3498db',
    sira INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS alt_kategoriler (
    id SERIAL PRIMARY KEY,
    ana_kategori_id INTEGER REFERENCES kategoriler(id) ON DELETE CASCADE,
    alt_kategori_adi VARCHAR(100) NOT NULL,
    aciklama TEXT,
    renk VARCHAR(7) DEFAULT '#95a5a6',
    sira INTEGER DEFAULT 0,
    UNIQUE(ana_kategori_id, alt_kategori_adi)
);

CREATE TABLE IF NOT EXISTS kritiklik_esikleri (
    id SERIAL PRIMARY KEY,
    esik_adi VARCHAR(20) UNIQUE NOT NULL,
    min_skor INTEGER DEFAULT 0,
    max_skor INTEGER DEFAULT 100,
    renk VARCHAR(7) DEFAULT '#95a5a6'
);

CREATE TABLE IF NOT EXISTS sistem_ayarlari (
    id SERIAL PRIMARY KEY,
    ayar_adi VARCHAR(100) UNIQUE NOT NULL,
    ayar_degeri TEXT,
    aciklama TEXT
);

CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_tarih ON cti_kayitlari(toplama_tarihi DESC);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_kategori ON cti_kayitlari(kategori);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_ana_kategori ON cti_kayitlari(ana_kategori);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_alt_kategori ON cti_kayitlari(alt_kategori);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_kritiklik ON cti_kayitlari(kritiklik);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_onion_id ON cti_kayitlari(onion_id);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_kritiklik_skor ON cti_kayitlari(kritiklik_skor DESC);
CREATE INDEX IF NOT EXISTS idx_cti_kayitlari_kaynak_guven_skoru ON cti_kayitlari(kaynak_guven_skoru);
CREATE INDEX IF NOT EXISTS idx_onion_adresleri_aktif ON onion_adresleri(aktif);

INSERT INTO kullanicilar (kullanici_adi, sifre_hash, rol) 
VALUES ('admin', '$2y$10$p5t9NzjVdMQEUuTpSXxJKOuKMTJZQEdXTo08XazaAb79JY1IT4z4m', 'admin')
ON CONFLICT (kullanici_adi) DO UPDATE SET sifre_hash = EXCLUDED.sifre_hash;

INSERT INTO kullanicilar (kullanici_adi, sifre_hash, rol) 
VALUES ('analist', '$2y$10$n8M3wNn/.Kr4DwcOVlMc1.R7hBh4xo7gluNWyfsCeJQKZnu49u0T2', 'analist')
ON CONFLICT (kullanici_adi) DO UPDATE SET sifre_hash = EXCLUDED.sifre_hash;

INSERT INTO kategoriler (kategori_adi, ana_kategori, aciklama, renk, sira) VALUES
('Zararlı Yazılım (Malware)', 'Zararlı Yazılım (Malware)', 'Kötü amaçlı yazılım tehditleri ve saldırıları', '#e74c3c', 1),
('Kimlik Avı (Phishing)', 'Kimlik Avı (Phishing)', 'Kimlik avı ve oltalama saldırıları', '#e67e22', 2),
('Sosyal Mühendislik', 'Sosyal Mühendislik', 'Sosyal mühendislik teknikleri ve manipülasyon', '#f39c12', 3),
('Yetkisiz Erişim / Sızma', 'Yetkisiz Erişim / Sızma', 'Yetkisiz erişim ve sistem sızma girişimleri', '#c0392b', 4),
('Veri İhlali / Veri Sızıntısı', 'Veri İhlali / Veri Sızıntısı', 'Veri ihlalleri ve sızıntıları', '#8e44ad', 5),
('Dark Web Pazarları', 'Dark Web Pazarları', 'Dark web üzerindeki yasa dışı pazarlar', '#34495e', 6),
('Exploit / Zafiyet Paylaşımları', 'Exploit / Zafiyet Paylaşımları', 'Exploit kodları ve zafiyet paylaşımları', '#d35400', 7),
('APT (Gelişmiş Kalıcı Tehditler)', 'APT (Gelişmiş Kalıcı Tehditler)', 'Gelişmiş kalıcı tehdit grupları ve saldırıları', '#c0392b', 8),
('Finansal Dolandırıcılık', 'Finansal Dolandırıcılık', 'Finansal dolandırıcılık ve sahtekarlık', '#27ae60', 9),
('Hacktivizm', 'Hacktivizm', 'Hacktivist saldırılar ve aktivizm', '#16a085', 10),
('İç Tehdit (Insider Threat)', 'İç Tehdit (Insider Threat)', 'Kurum içi tehditler ve sızıntılar', '#95a5a6', 11),
('Yeni Teknolojiler Kaynaklı Tehditler', 'Yeni Teknolojiler Kaynaklı Tehditler', 'Yeni teknolojilerden kaynaklanan tehditler', '#3498db', 12)
ON CONFLICT (kategori_adi) DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Virüs', 'Bilgisayar virüsleri ve bulaşıcı yazılımlar', 1 FROM kategoriler WHERE kategori_adi = 'Zararlı Yazılım (Malware)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Truva Atı', 'Trojan yazılımları ve arka kapılar', 2 FROM kategoriler WHERE kategori_adi = 'Zararlı Yazılım (Malware)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Fidye Yazılımı', 'Ransomware saldırıları ve fidye talepleri', 3 FROM kategoriler WHERE kategori_adi = 'Zararlı Yazılım (Malware)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Casus Yazılım', 'Spyware ve izleme yazılımları', 4 FROM kategoriler WHERE kategori_adi = 'Zararlı Yazılım (Malware)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Botnet', 'Botnet ağları ve komuta-kontrol sunucuları', 5 FROM kategoriler WHERE kategori_adi = 'Zararlı Yazılım (Malware)'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'E-posta Oltalama', 'E-posta tabanlı kimlik avı saldırıları', 1 FROM kategoriler WHERE kategori_adi = 'Kimlik Avı (Phishing)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Sahte Giriş Sayfaları', 'Sahte web siteleri ve login sayfaları', 2 FROM kategoriler WHERE kategori_adi = 'Kimlik Avı (Phishing)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Sosyal Medya Oltalama', 'Sosyal medya platformlarında kimlik avı', 3 FROM kategoriler WHERE kategori_adi = 'Kimlik Avı (Phishing)'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Sahte Destek Mesajları', 'Sahte teknik destek ve yardım mesajları', 1 FROM kategoriler WHERE kategori_adi = 'Sosyal Mühendislik'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Kandırma Senaryoları', 'Pretexting ve kandırma teknikleri', 2 FROM kategoriler WHERE kategori_adi = 'Sosyal Mühendislik'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Psikolojik Manipülasyon', 'Psikolojik manipülasyon ve ikna teknikleri', 3 FROM kategoriler WHERE kategori_adi = 'Sosyal Mühendislik'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Hesap Ele Geçirme', 'Hesap çalma ve yetkisiz erişim', 1 FROM kategoriler WHERE kategori_adi = 'Yetkisiz Erişim / Sızma'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Brute-force Saldırılar', 'Kaba kuvvet saldırıları ve şifre kırma', 2 FROM kategoriler WHERE kategori_adi = 'Yetkisiz Erişim / Sızma'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Zayıf Parola İstismarı', 'Zayıf parolaların istismar edilmesi', 3 FROM kategoriler WHERE kategori_adi = 'Yetkisiz Erişim / Sızma'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Çalıntı Veriler', 'Çalınmış veri setleri ve bilgiler', 1 FROM kategoriler WHERE kategori_adi = 'Veri İhlali / Veri Sızıntısı'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Satılan Veritabanları', 'Satışa çıkarılan veritabanları', 2 FROM kategoriler WHERE kategori_adi = 'Veri İhlali / Veri Sızıntısı'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Leak Paylaşımları', 'Sızdırılmış veriler ve leak paylaşımları', 3 FROM kategoriler WHERE kategori_adi = 'Veri İhlali / Veri Sızıntısı'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Yasa Dışı Ürünler', 'Yasa dışı ürün satışları', 1 FROM kategoriler WHERE kategori_adi = 'Dark Web Pazarları'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Hizmet Satışları', 'Yasa dışı hizmet satışları', 2 FROM kategoriler WHERE kategori_adi = 'Dark Web Pazarları'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Silah, Uyuşturucu, Veri Ticareti', 'Yasa dışı ticaret faaliyetleri', 3 FROM kategoriler WHERE kategori_adi = 'Dark Web Pazarları'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, '0-day Paylaşımları', 'Sıfır gün açıkları ve exploit kodları', 1 FROM kategoriler WHERE kategori_adi = 'Exploit / Zafiyet Paylaşımları'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'CVE İstismarları', 'CVE numaralı zafiyet istismarları', 2 FROM kategoriler WHERE kategori_adi = 'Exploit / Zafiyet Paylaşımları'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Proof of Concept (PoC) Kodları', 'PoC exploit kodları ve kanıtlar', 3 FROM kategoriler WHERE kategori_adi = 'Exploit / Zafiyet Paylaşımları'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Devlet Destekli Tehdit Grupları', 'Nation-state APT grupları', 1 FROM kategoriler WHERE kategori_adi = 'APT (Gelişmiş Kalıcı Tehditler)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Uzun Süreli ve Hedefli Saldırılar', 'Uzun vadeli hedefli saldırı kampanyaları', 2 FROM kategoriler WHERE kategori_adi = 'APT (Gelişmiş Kalıcı Tehditler)'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Kart Bilgisi Satışı', 'Çalıntı kredi kartı bilgileri', 1 FROM kategoriler WHERE kategori_adi = 'Finansal Dolandırıcılık'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Kripto Dolandırıcılığı', 'Kripto para dolandırıcılığı', 2 FROM kategoriler WHERE kategori_adi = 'Finansal Dolandırıcılık'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Sahte Yatırım İlanları', 'Sahte yatırım fırsatları ve dolandırıcılık', 3 FROM kategoriler WHERE kategori_adi = 'Finansal Dolandırıcılık'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Politik Saldırılar', 'Politik motivasyonlu saldırılar', 1 FROM kategoriler WHERE kategori_adi = 'Hacktivizm'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Web Site Tahrifatı (Defacement)', 'Web sitesi tahrifatı ve değiştirme', 2 FROM kategoriler WHERE kategori_adi = 'Hacktivizm'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'DDoS Çağrıları', 'DDoS saldırı çağrıları ve koordinasyonu', 3 FROM kategoriler WHERE kategori_adi = 'Hacktivizm'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Çalışan Kaynaklı Veri Sızıntıları', 'Kurum içi veri sızıntıları', 1 FROM kategoriler WHERE kategori_adi = 'İç Tehdit (Insider Threat)'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Yetki Kötüye Kullanımı', 'Yetki kötüye kullanımı ve içeriden saldırılar', 2 FROM kategoriler WHERE kategori_adi = 'İç Tehdit (Insider Threat)'
ON CONFLICT DO NOTHING;

INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Yapay Zeka Tehditleri', 'AI tabanlı saldırılar ve manipülasyon', 1 FROM kategoriler WHERE kategori_adi = 'Yeni Teknolojiler Kaynaklı Tehditler'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'IoT Tabanlı Saldırılar', 'IoT cihazlarından kaynaklanan tehditler', 2 FROM kategoriler WHERE kategori_adi = 'Yeni Teknolojiler Kaynaklı Tehditler'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Artırılmış Gerçeklik (AR) Tehditleri', 'AR teknolojilerinden kaynaklanan güvenlik riskleri', 3 FROM kategoriler WHERE kategori_adi = 'Yeni Teknolojiler Kaynaklı Tehditler'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Metaverse Güvenlik Riskleri', 'Metaverse platformlarındaki güvenlik açıkları', 4 FROM kategoriler WHERE kategori_adi = 'Yeni Teknolojiler Kaynaklı Tehditler'
ON CONFLICT DO NOTHING;
INSERT INTO alt_kategoriler (ana_kategori_id, alt_kategori_adi, aciklama, sira) 
SELECT id, 'Blockchain ve Kripto Tehditleri', 'Blockchain ve kripto para güvenlik riskleri', 5 FROM kategoriler WHERE kategori_adi = 'Yeni Teknolojiler Kaynaklı Tehditler'
ON CONFLICT DO NOTHING;

INSERT INTO kritiklik_esikleri (esik_adi, min_skor, max_skor, renk) VALUES
('dusuk', 0, 30, '#27ae60'),
('orta', 31, 60, '#f39c12'),
('yuksek', 61, 85, '#e67e22'),
('kritik', 86, 100, '#e74c3c')
ON CONFLICT (esik_adi) DO NOTHING;

INSERT INTO sistem_ayarlari (ayar_adi, ayar_degeri, aciklama) VALUES
('ozet_uzunluk', '500', 'CTI kayıt özet uzunluğu (karakter)'),
('tarama_araligi', '3600', 'Tarama aralığı (saniye)'),
('baslik_anahtar_kelimeler', 'metaverse,virtual,reality,nft,blockchain,crypto,vr,ar,augmented', 'Başlık üretimi için anahtar kelimeler')
ON CONFLICT (ayar_adi) DO NOTHING;
