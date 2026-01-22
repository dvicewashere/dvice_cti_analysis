package main

import (
	"compress/flate"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/chromedp/cdproto/page"
	"github.com/chromedp/chromedp"
	_ "github.com/lib/pq"
	"golang.org/x/net/proxy"
)

var (
	dbHost     = getEnv("DB_HOST", "localhost")
	dbPort     = getEnv("DB_PORT", "5432")
	dbUser     = getEnv("DB_USER", "dvice")
	dbPassword = getEnv("DB_PASSWORD", "dvice")
	dbName     = getEnv("DB_NAME", "dvice")
	torProxy   = getEnv("TOR_PROXY", "socks5://tor:9050")
	torProxy1  = "127.0.0.1:9050"
	torProxy2  = "127.0.0.1:9150"

	safeMode = strings.ToLower(getEnv("SAFE_MODE", "true")) == "true"
)

var activeTorProxy string

type CTIKayit struct {
	OnionID           int
	KaynakAdi         string
	KaynakURL         string
	Baslik            string
	Ozet              string
	TemizIcerik       string
	PaylasimTarihi    *time.Time
	AnaKategori       string
	AltKategori       string
	Kategori          string
	Kritiklik         string
	KritiklikSkor     int
	KritiklikAciklama string
}

var metaverseAnahtarKelimeler = []string{
	"metaverse", "virtual", "reality", "nft", "blockchain", "crypto",
	"vr", "ar", "augmented", "sanal", "gerçeklik", "dijital", "avatar",
	"web3", "decentraland", "sandbox", "voxels", "cryptovoxels",
}

var globalDB *sql.DB

func main() {
	log.Println("CTI Collector başlatılıyor...")

	db, err := baglantiOlustur()
	if err != nil {
		log.Fatalf("Veritabanı bağlantı hatası: %v", err)
	}
	defer db.Close()

	globalDB = db

	log.Println("Veritabanı bağlantısı başarılı")

	envProxy := getEnv("TOR_PROXY", "")
	if envProxy != "" {
		u, _ := url.Parse(envProxy)
		activeTorProxy = u.Host
	} else {
		activeTorProxy = ensureTorAvailable()
	}

	if activeTorProxy != "" {
		client, err := torClientOlustur()
		if err == nil {
			verifyTorIP(client)
		}
	}

	log.Println("HTTP API sunucusu başlatılıyor...")
	go httpAPISunucuBaslat(db)

	time.Sleep(1 * time.Second)

	log.Println("İlk tarama başlatılıyor...")
	taramaYap(db)

	ticker := time.NewTicker(1 * time.Hour)
	defer ticker.Stop()

	log.Println("Saat başı tarama zamanlayıcısı aktif")

	for range ticker.C {
		log.Println("Zamanlanmış tarama başlatılıyor...")
		taramaYap(db)
	}
}

func httpAPISunucuBaslat(db *sql.DB) {
	http.HandleFunc("/api/tarama", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")

		onionID := r.URL.Query().Get("id")

		if onionID != "" {
			id, err := strconv.Atoi(onionID)
			if err != nil {
				json.NewEncoder(w).Encode(map[string]interface{}{
					"basarili": false,
					"mesaj":    "Geçersiz ID",
				})
				return
			}

			go belirliOnionTara(globalDB, id)
			json.NewEncoder(w).Encode(map[string]interface{}{
				"basarili": true,
				"mesaj":    "Tarama başlatıldı",
			})
		} else {
			go taramaYap(globalDB)
			json.NewEncoder(w).Encode(map[string]interface{}{
				"basarili": true,
				"mesaj":    "Tüm aktif onion'lar taranıyor",
			})
		}
	})

	http.HandleFunc("/api/durum", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Access-Control-Allow-Origin", "*")

		json.NewEncoder(w).Encode(map[string]interface{}{
			"durum": "aktif",
		})
	})

	log.Println("HTTP API sunucusu başlatıldı: :8081")
	log.Println("API endpoint'leri hazır: /api/tarama, /api/durum")

	if err := http.ListenAndServe(":8081", nil); err != nil {
		log.Fatalf("HTTP sunucu hatası: %v", err)
	}
}

func belirliOnionTara(db *sql.DB, onionID int) {
	stmt := db.QueryRow("SELECT id, adres, kaynak_adi FROM onion_adresleri WHERE id = $1", onionID)

	var onion OnionAdres
	if err := stmt.Scan(&onion.ID, &onion.Adres, &onion.KaynakAdi); err != nil {
		log.Printf("Onion bulunamadı (ID: %d): %v", onionID, err)
		return
	}

	log.Printf("Manuel tarama başlatılıyor: %s (%s)", onion.Adres, onion.KaynakAdi)

	kayitlar, err := onionAdresiniTara(onion.Adres, onion.ID, onion.KaynakAdi, db)
	if err != nil {
		log.Printf("Tarama hatası (%s): %v", onion.Adres, err)
		return
	}

	for _, kayit := range kayitlar {
		if err := kayitKaydet(db, kayit); err != nil {
			log.Printf("Kayıt kaydedilirken hata: %v", err)
		}
	}

	sonTaramaGuncelle(db, onion.ID)

	log.Printf("Manuel tarama tamamlandı: %s", onion.Adres)
}

func baglantiOlustur() (*sql.DB, error) {
	psqlInfo := fmt.Sprintf("host=%s port=%s user=%s password=%s dbname=%s sslmode=disable",
		dbHost, dbPort, dbUser, dbPassword, dbName)

	db, err := sql.Open("postgres", psqlInfo)
	if err != nil {
		return nil, err
	}

	if err = db.Ping(); err != nil {
		return nil, err
	}

	return db, nil
}

func taramaYap(db *sql.DB) {
	onionAdresleri, err := aktifOnionAdresleriniAl(db)
	if err != nil {
		log.Printf("Onion adresleri alınırken hata: %v", err)
		return
	}

	if len(onionAdresleri) == 0 {
		log.Println("Aktif onion adresi bulunamadı")
		return
	}

	log.Printf("%d aktif onion adresi bulundu", len(onionAdresleri))

	for _, onion := range onionAdresleri {
		log.Printf("Taranıyor: %s (%s)", onion.Adres, onion.KaynakAdi)

		kayitlar, err := onionAdresiniTara(onion.Adres, onion.ID, onion.KaynakAdi, db)
		if err != nil {
			log.Printf("Tarama hatası (%s): %v", onion.Adres, err)
			continue
		}

		for _, kayit := range kayitlar {
			if err := kayitKaydet(db, kayit); err != nil {
				log.Printf("Kayıt kaydedilirken hata: %v", err)
			}
		}

		sonTaramaGuncelle(db, onion.ID)

		time.Sleep(5 * time.Second)
	}

	log.Println("Tarama tamamlandı")
}

type OnionAdres struct {
	ID        int
	Adres     string
	KaynakAdi string
}

func aktifOnionAdresleriniAl(db *sql.DB) ([]OnionAdres, error) {
	query := `SELECT id, adres, kaynak_adi FROM onion_adresleri WHERE aktif = TRUE`
	rows, err := db.Query(query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var adresler []OnionAdres
	for rows.Next() {
		var a OnionAdres
		if err := rows.Scan(&a.ID, &a.Adres, &a.KaynakAdi); err != nil {
			continue
		}
		adresler = append(adresler, a)
	}

	return adresler, nil
}

func onionAdresiniTara(onionURL string, onionID int, kaynakAdi string, db *sql.DB) ([]CTIKayit, error) {
	client, err := torClientOlustur()
	if err != nil {
		return nil, fmt.Errorf("TOR client oluşturulamadı: %v", err)
	}

	if !strings.HasPrefix(onionURL, "http://") && !strings.HasPrefix(onionURL, "https://") {
		onionURL = "http://" + onionURL
	}

	req, err := http.NewRequest("GET", onionURL, nil)
	if err != nil {
		return nil, err
	}

	req.Header.Set("User-Agent", "Mozilla/5.0 (X11; Linux x86_64; rv:102.0) Gecko/20100101 Firefox/102.0")

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("HTTP isteği başarısız: %v", err)
	}
	defer resp.Body.Close()

	body, err := readResponseBody(resp)
	if err != nil {
		return nil, fmt.Errorf("İçerik okunamadı: %v", err)
	}

	// Site başlığını çek
	siteTitle := extractTitle(string(body))

	// Kaynak adını güncelle (eğer başlık varsa ve domain adından farklıysa)
	if siteTitle != "" && siteTitle != "Untitled" && siteTitle != kaynakAdi {
		updateSourceName(db, onionID, siteTitle)
		kaynakAdi = siteTitle // Güncel kaynak adını kullan
		log.Printf("Kaynak adı güncellendi: %s -> %s", onionURL, siteTitle)
	}

	if !safeMode {
		outDir := buildOutputDir(onionURL, siteTitle)
		_ = os.MkdirAll(outDir, 0755)

		safeHTML := sanitizeHTML(string(body))
		_ = os.WriteFile(filepath.Join(outDir, "site_data.html"), []byte(safeHTML), 0644)

		links := extractLinks(string(body))
		_ = os.WriteFile(filepath.Join(outDir, "links.txt"), []byte(strings.Join(links, "\n")), 0644)

		if err := takeScreenshotTor(onionURL, outDir); err != nil {
			log.Printf("[WARN] Screenshot alınamadı (%s): %v", onionURL, err)
		}

		taramaSonucuKaydet(db, onionID, outDir)
	} else {
		log.Printf("[Güvenli_mod] Dosya kaydı kapalı (HTML/MHTML/PNG/link listesi üretilmez), ancak DB'de temiz içerik saklanır: %s", onionURL)
	}

	kayitlar := icerikParseEt(string(body), onionURL, onionID, kaynakAdi, db)

	return kayitlar, nil
}

func ensureTorAvailable() string {
	if checkPort(torProxy1) {
		log.Println("[INFO] Tor aktif:", torProxy1)
		return torProxy1
	}
	if checkPort(torProxy2) {
		log.Println("[INFO] Tor aktif:", torProxy2)
		return torProxy2
	}
	envProxy := getEnv("TOR_PROXY", "")
	if envProxy != "" {
		u, _ := url.Parse(envProxy)
		return u.Host
	}

	log.Println("[WARN] Tor SOCKS5 yerel portlarda (9050, 9150) bulunamadı, varsayılan kullanılıyor")
	return "127.0.0.1:9050"
}

func checkPort(addr string) bool {
	conn, err := net.DialTimeout("tcp", addr, 3*time.Second)
	if err != nil {
		return false
	}
	conn.Close()
	return true
}

func verifyTorIP(client *http.Client) {
	resp, err := client.Get("https://check.torproject.org/api/ip")
	if err != nil {
		log.Println("[TOR] IP doğrulama başarısız:", err)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	log.Println("[TOR] check.torproject.org:", string(body))
}

func torClientOlustur() (*http.Client, error) {
	if activeTorProxy == "" {
		envProxy := getEnv("TOR_PROXY", "")
		if envProxy != "" {
			u, _ := url.Parse(envProxy)
			activeTorProxy = u.Host
		} else {
			activeTorProxy = ensureTorAvailable()
		}
	}

	dialer, err := proxy.SOCKS5("tcp", activeTorProxy, nil, proxy.Direct)
	if err != nil {
		return nil, err
	}

	transport := &http.Transport{
		Dial: dialer.Dial,
	}

	client := &http.Client{
		Transport: transport,
		Timeout:   30 * time.Second,
	}

	return client, nil
}

func icerikParseEt(icerik string, kaynakURL string, onionID int, kaynakAdi string, db *sql.DB) []CTIKayit {
	var kayitlar []CTIKayit

	icerikKucuk := strings.ToLower(icerik)

	metaverseIcerikVar := false
	for _, kelime := range metaverseAnahtarKelimeler {
		if strings.Contains(icerikKucuk, kelime) {
			metaverseIcerikVar = true
			break
		}
	}

	if !metaverseIcerikVar {
		return kayitlar
	}

	htmlTagRegex := regexp.MustCompile(`<[^>]*>`)
	temizIcerik := htmlTagRegex.ReplaceAllString(icerik, " ")

	spaceRegex := regexp.MustCompile(`\s+`)
	temizIcerik = spaceRegex.ReplaceAllString(temizIcerik, " ")
	temizIcerik = strings.TrimSpace(temizIcerik)

	baslik := baslikUret(temizIcerik)

	ozet := ozetOlustur(temizIcerik, 500)

	anaKategori, altKategori := kategoriBelirle(temizIcerik)

	kategoriStr := anaKategori
	if altKategori != "" {
		kategoriStr = anaKategori + " > " + altKategori
	}

	kritiklik, skor, aciklama := kritiklikBelirleGelismis(temizIcerik, baslik, kaynakAdi, db, onionID, anaKategori)

	kayit := CTIKayit{
		OnionID:   onionID,
		KaynakAdi: kaynakAdi,
		KaynakURL: kaynakURL,
		Baslik:    baslik,
		Ozet:      ozet,

		TemizIcerik: func() string {
			// En fazla 10000 karakter sakla
			if len(temizIcerik) > 10000 {
				return temizIcerik[:10000]
			}
			return temizIcerik
		}(),

		PaylasimTarihi:    paylasimTarihiTespitEt(temizIcerik),
		AnaKategori:       anaKategori,
		AltKategori:       altKategori,
		Kategori:          kategoriStr,
		Kritiklik:         kritiklik,
		KritiklikSkor:     skor,
		KritiklikAciklama: aciklama,
	}

	kayitlar = append(kayitlar, kayit)

	return kayitlar
}

func baslikUret(icerik string) string {
	teknikGurultu := []string{
		"javascript", "cookie", "modal", "html", "css", "script", "function",
		"var ", "const ", "let ", "document", "window", "onclick", "onload",
		"button", "div", "span", "class=", "id=", "href=", "src=",
		"<!--", "-->", "&nbsp;", "&amp;", "&lt;", "&gt;",
		"console.log", "alert", "prompt", "confirm",
	}

	satirlar := strings.Split(icerik, ".")

	for _, satir := range satirlar {
		satirKucuk := strings.ToLower(strings.TrimSpace(satir))

		gurultuVar := false
		for _, gurultu := range teknikGurultu {
			if strings.Contains(satirKucuk, gurultu) {
				gurultuVar = true
				break
			}
		}
		if gurultuVar {
			continue
		}

		if len(satirKucuk) < 20 {
			continue
		}

		for _, kelime := range metaverseAnahtarKelimeler {
			if strings.Contains(satirKucuk, kelime) {
				baslik := baslikTemizle(strings.TrimSpace(satir))
				if len(baslik) > 200 {
					baslik = baslik[:200] + "..."
				}
				return baslik
			}
		}
	}

	for _, satir := range satirlar {
		satir = strings.TrimSpace(satir)

		satirKucuk := strings.ToLower(satir)
		gurultuVar := false
		for _, gurultu := range teknikGurultu {
			if strings.Contains(satirKucuk, gurultu) {
				gurultuVar = true
				break
			}
		}
		if gurultuVar {
			continue
		}

		if len(satir) > 30 {
			baslik := baslikTemizle(satir)
			if len(baslik) > 200 {
				baslik = baslik[:200] + "..."
			}
			return baslik
		}
	}

	return "Metaverse ile ilgili içerik tespit edildi"
}

func baslikTemizle(baslik string) string {
	baslik = strings.ReplaceAll(baslik, "&nbsp;", " ")
	baslik = strings.ReplaceAll(baslik, "&amp;", "&")
	baslik = strings.ReplaceAll(baslik, "&lt;", "<")
	baslik = strings.ReplaceAll(baslik, "&gt;", ">")
	baslik = strings.ReplaceAll(baslik, "&quot;", "\"")

	spaceRegex := regexp.MustCompile(`\s+`)
	baslik = spaceRegex.ReplaceAllString(baslik, " ")
	baslik = strings.TrimSpace(baslik)

	if len(baslik) < 10 {
		return "Metaverse içeriği tespit edildi"
	}

	return baslik
}

func ozetOlustur(icerik string, uzunluk int) string {
	if len(icerik) <= uzunluk {
		return icerik
	}

	ozet := icerik[:uzunluk]
	sonNokta := strings.LastIndex(ozet, ".")
	if sonNokta > uzunluk/2 {
		ozet = ozet[:sonNokta+1]
	} else {
		ozet += "..."
	}

	return ozet
}

func paylasimTarihiTespitEt(icerik string) *time.Time {
	icerikKucuk := strings.ToLower(icerik)

	tarihPatterns := []string{
		`(\d{1,2})[./-](\d{1,2})[./-](\d{4})`,
		`(\d{4})[./-](\d{1,2})[./-](\d{1,2})`,
		`(\d{1,2})\s+(ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık)\s+(\d{4})`,
		`(ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık)\s+(\d{1,2}),?\s+(\d{4})`,
		`(january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{1,2}),?\s+(\d{4})`,
	}

	aylar := map[string]int{
		"ocak": 1, "şubat": 2, "mart": 3, "nisan": 4, "mayıs": 5, "haziran": 6,
		"temmuz": 7, "ağustos": 8, "eylül": 9, "ekim": 10, "kasım": 11, "aralık": 12,
		"january": 1, "february": 2, "march": 3, "april": 4, "may": 5, "june": 6,
		"july": 7, "august": 8, "september": 9, "october": 10, "november": 11, "december": 12,
	}

	for _, pattern := range tarihPatterns {
		re := regexp.MustCompile(`(?i)` + pattern)
		matches := re.FindStringSubmatch(icerikKucuk)
		if len(matches) > 0 {
			var yil, ay, gun int

			if strings.Contains(pattern, `(\d{4})`) && len(matches) >= 4 {

				if y, err := strconv.Atoi(matches[1]); err == nil && y >= 2000 && y <= 2100 {
					yil = y
					if m, err := strconv.Atoi(matches[2]); err == nil && m >= 1 && m <= 12 {
						ay = m
						if d, err := strconv.Atoi(matches[3]); err == nil && d >= 1 && d <= 31 {
							gun = d
						}
					}
				}
			} else if len(matches) >= 4 {

				if ayNum, ok := aylar[matches[1]]; ok {
					ay = ayNum
					if g, err := strconv.Atoi(matches[2]); err == nil && g >= 1 && g <= 31 {
						gun = g
					}
					if y, err := strconv.Atoi(matches[3]); err == nil && y >= 2000 && y <= 2100 {
						yil = y
					}
				}
			} else if len(matches) >= 4 {

				if d, err := strconv.Atoi(matches[1]); err == nil && d >= 1 && d <= 31 {
					gun = d
				}
				if m, err := strconv.Atoi(matches[2]); err == nil && m >= 1 && m <= 12 {
					ay = m
				}
				if y, err := strconv.Atoi(matches[3]); err == nil && y >= 2000 && y <= 2100 {
					yil = y
				}
			}

			if yil > 0 && ay > 0 && gun > 0 {
				tarih := time.Date(yil, time.Month(ay), gun, 0, 0, 0, 0, time.UTC)

				if tarih.Before(time.Now().AddDate(1, 0, 0)) && tarih.After(time.Now().AddDate(-20, 0, 0)) {
					return &tarih
				}
			}
		}
	}

	return nil
}

func kategoriBelirle(icerik string) (string, string) {
	icerikKucuk := strings.ToLower(icerik)

	kategoriEslesmeleri := map[string]map[string][]string{
		"Zararlı Yazılım (Malware)": {
			"Virüs":          {"virus", "virüs", "malware", "trojan", "worm", "rootkit"},
			"Truva Atı":      {"trojan", "truva", "backdoor", "arka kapı", "rat", "remote access trojan", "stealer", "info stealer"},
			"Fidye Yazılımı": {"ransomware", "fidye", "encrypt", "locker", "crypto locker", "file encryptor", "decryptor"},
			"Casus Yazılım":  {"spyware", "casus", "keylogger", "tracker", "screen logger", "password stealer"},
			"Botnet":         {"botnet", "zombie", "command control", "c&c", "c2", "command and control", "loader", "dropper"},
		},
		"Kimlik Avı (Phishing)": {
			"E-posta Oltalama":      {"phishing", "email", "e-posta", "spoofing", "phishing kit", "email spoofing", "spear phishing"},
			"Sahte Giriş Sayfaları": {"fake login", "sahte giriş", "clone", "spoof", "phishing page", "fake page", "login page"},
			"Sosyal Medya Oltalama": {"social media", "sosyal medya", "facebook", "twitter", "instagram phishing", "social engineering"},
		},
		"Sosyal Mühendislik": {
			"Sahte Destek Mesajları":  {"fake support", "sahte destek", "scam", "fraud", "tech support scam", "vishing", "voice phishing"},
			"Kandırma Senaryoları":    {"pretexting", "kandırma", "deception", "baiting", "quid pro quo", "tailgating"},
			"Psikolojik Manipülasyon": {"manipulation", "manipülasyon", "psychological", "social engineering", "se attack"},
		},
		"Yetkisiz Erişim / Sızma": {
			"Hesap Ele Geçirme":      {"account takeover", "hesap çalma", "hijack"},
			"Brute-force Saldırılar": {"brute force", "bruteforce", "password crack"},
			"Zayıf Parola İstismarı": {"weak password", "zayıf parola", "default password"},
		},
		"Veri İhlali / Veri Sızıntısı": {
			"Çalıntı Veriler":       {"stolen data", "çalıntı veri", "data breach", "breached data", "compromised data", "exposed data"},
			"Satılan Veritabanları": {"database sale", "veritabanı satışı", "data dump", "db dump", "database dump", "leaked database", "breach database"},
			"Leak Paylaşımları":     {"leak", "sızıntı", "data leak", "breach", "leaked", "dox", "doxxing", "pastebin", "hashes", "credentials"},
		},
		"Dark Web Pazarları": {
			"Yasa Dışı Ürünler":                {"illegal products", "yasa dışı", "marketplace", "darknet market", "darknet marketplace", "onion market", "onion marketplace", "underground market", "black market", "hidden service market"},
			"Hizmet Satışları":                 {"service sale", "hizmet satışı", "hacking service", "vendor", "seller", "escrow", "pgp", "bitcoin payment", "monero payment", "crypto payment"},
			"Silah, Uyuşturucu, Veri Ticareti": {"weapon", "drug", "silah", "uyuşturucu", "carding", "dumps", "fullz", "cvv", "bank account", "identity theft"},
		},
		"Exploit / Zafiyet Paylaşımları": {
			"0-day Paylaşımları":             {"0-day", "zero day", "zeroday", "0day", "zero-day", "0-day exploit", "unpatched", "undisclosed"},
			"CVE İstismarları":               {"cve-", "cve ", "exploit", "poc", "cve exploit", "vulnerability exploit", "rce", "remote code execution"},
			"Proof of Concept (PoC) Kodları": {"poc", "proof of concept", "exploit code", "exploit kit", "malware kit", "builder"},
		},
		"APT (Gelişmiş Kalıcı Tehditler)": {
			"Devlet Destekli Tehdit Grupları":   {"apt", "nation state", "state sponsored"},
			"Uzun Süreli ve Hedefli Saldırılar": {"advanced persistent", "targeted attack", "campaign"},
		},
		"Finansal Dolandırıcılık": {
			"Kart Bilgisi Satışı":    {"credit card", "kart bilgisi", "card dump", "cc", "cvv", "dumps", "fullz", "carding", "track1", "track2", "bins"},
			"Kripto Dolandırıcılığı": {"crypto scam", "kripto dolandırıcılık", "bitcoin scam", "crypto fraud", "bitcoin fraud", "monero scam", "crypto ponzi"},
			"Sahte Yatırım İlanları": {"investment scam", "sahte yatırım", "ponzi", "pyramid scheme", "investment fraud", "fake investment"},
		},
		"Hacktivizm": {
			"Politik Saldırılar":              {"hacktivism", "political", "aktivist"},
			"Web Site Tahrifatı (Defacement)": {"defacement", "tahrifat", "website hack"},
			"DDoS Çağrıları":                  {"ddos", "distributed denial", "attack call"},
		},
		"İç Tehdit (Insider Threat)": {
			"Çalışan Kaynaklı Veri Sızıntıları": {"insider threat", "iç tehdit", "employee leak"},
			"Yetki Kötüye Kullanımı":            {"privilege abuse", "yetki kötüye kullanım", "insider"},
		},
		"Yeni Teknolojiler Kaynaklı Tehditler": {
			"Yapay Zeka Tehditleri":                {"ai threat", "artificial intelligence", "machine learning attack"},
			"IoT Tabanlı Saldırılar":               {"iot", "internet of things", "smart device"},
			"Artırılmış Gerçeklik (AR) Tehditleri": {"ar threat", "augmented reality", "ar security"},
			"Metaverse Güvenlik Riskleri":          {"metaverse", "virtual world", "vr security"},
			"Blockchain ve Kripto Tehditleri":      {"blockchain", "crypto threat", "smart contract exploit"},
		},
	}

	for anaKategori, altKategoriler := range kategoriEslesmeleri {
		for altKategori, kelimeler := range altKategoriler {
			for _, kelime := range kelimeler {
				if strings.Contains(icerikKucuk, kelime) {
					return anaKategori, altKategori
				}
			}
		}
	}

	genelAnahtarKelimeler := map[string][]string{
		"Zararlı Yazılım (Malware)":            {"malware", "virus", "trojan"},
		"Kimlik Avı (Phishing)":                {"phishing", "spoof"},
		"Sosyal Mühendislik":                   {"social engineering", "manipulation"},
		"Yetkisiz Erişim / Sızma":              {"unauthorized access", "breach", "hack"},
		"Veri İhlali / Veri Sızıntısı":         {"data breach", "leak", "sızıntı"},
		"Dark Web Pazarları":                   {"dark market", "marketplace", "darknet market", "darknet marketplace", "onion market", "underground market", "black market", "hidden service", "vendor", "escrow"},
		"Exploit / Zafiyet Paylaşımları":       {"exploit", "vulnerability", "cve"},
		"APT (Gelişmiş Kalıcı Tehditler)":      {"apt", "advanced persistent"},
		"Finansal Dolandırıcılık":              {"fraud", "scam", "financial"},
		"Hacktivizm":                           {"hacktivism", "activist"},
		"İç Tehdit (Insider Threat)":           {"insider", "employee"},
		"Yeni Teknolojiler Kaynaklı Tehditler": {"ai", "iot", "blockchain", "metaverse"},
	}

	for anaKategori, kelimeler := range genelAnahtarKelimeler {
		for _, kelime := range kelimeler {
			if strings.Contains(icerikKucuk, kelime) {
				return anaKategori, ""
			}
		}
	}

	return "Yeni Teknolojiler Kaynaklı Tehditler", "Diğer"
}

func kritiklikBelirleGelismis(icerik string, baslik string, kaynakAdi string, db *sql.DB, onionID int, anaKategori string) (string, int, string) {
	skor := 0
	var aciklamaParcalari []string
	icerikKucuk := strings.ToLower(icerik + " " + baslik)

	kritikKelimeSkorlari := map[string]int{
		"exploit": 30, "hack": 25, "vulnerability": 25, "security": 20,
		"breach": 30, "attack": 25, "malware": 30, "ransomware": 35,
		"critical": 20, "urgent": 15, "important": 10,
		"leak": 25, "data breach": 30, "zero day": 40,
		"sızıntı": 25, "açık": 20, "güvenlik açığı": 25,
		"darknet market": 25, "onion market": 25, "underground market": 25,
		"vendor": 15, "escrow": 15, "carding": 30, "dumps": 30, "fullz": 35,
		"cvv": 30, "card dump": 30, "bank account": 25,
		"rat": 30, "stealer": 30, "loader": 25, "dropper": 25, "c2": 30,
		"botnet": 30, "keylogger": 30, "spyware": 25,
		"phishing kit": 25, "fake login": 25, "spoofing": 20,
		"dox": 30, "doxxing": 30, "pastebin": 20, "hashes": 25, "credentials": 30,
	}

	for kelime, puan := range kritikKelimeSkorlari {
		if strings.Contains(icerikKucuk, kelime) {
			skor += puan
			aciklamaParcalari = append(aciklamaParcalari, fmt.Sprintf("'%s' kelimesi tespit edildi (+%d)", kelime, puan))
		}
	}

	kategoriSkorlari := map[string]int{
		"Zararlı Yazılım (Malware)":            25,
		"Kimlik Avı (Phishing)":                20,
		"Sosyal Mühendislik":                   15,
		"Yetkisiz Erişim / Sızma":              30,
		"Veri İhlali / Veri Sızıntısı":         35,
		"Dark Web Pazarları":                   20,
		"Exploit / Zafiyet Paylaşımları":       40,
		"APT (Gelişmiş Kalıcı Tehditler)":      45,
		"Finansal Dolandırıcılık":              30,
		"Hacktivizm":                           15,
		"İç Tehdit (Insider Threat)":           35,
		"Yeni Teknolojiler Kaynaklı Tehditler": 20,
	}
	if kategoriSkoru, varMi := kategoriSkorlari[anaKategori]; varMi {
		skor += kategoriSkoru
		aciklamaParcalari = append(aciklamaParcalari, fmt.Sprintf("Ana kategori: %s (+%d)", anaKategori, kategoriSkoru))
	}

	kaynakKucuk := strings.ToLower(kaynakAdi)
	if strings.Contains(kaynakKucuk, "forum") {
		skor += 5
		aciklamaParcalari = append(aciklamaParcalari, "Forum kaynağı (+5)")
	} else if strings.Contains(kaynakKucuk, "market") || strings.Contains(kaynakKucuk, "pazar") ||
		strings.Contains(kaynakKucuk, "darknet") || strings.Contains(kaynakKucuk, "onion market") ||
		strings.Contains(kaynakKucuk, "underground") || strings.Contains(kaynakKucuk, "black market") {
		skor += 10
		aciklamaParcalari = append(aciklamaParcalari, "Market kaynağı (dark web) (+10)")
	} else if strings.Contains(kaynakKucuk, "leak") || strings.Contains(kaynakKucuk, "sızıntı") ||
		strings.Contains(kaynakKucuk, "pastebin") || strings.Contains(kaynakKucuk, "dox") {
		skor += 20
		aciklamaParcalari = append(aciklamaParcalari, "Sızıntı platformu (+20)")
	}

	if db != nil {
		var tekrarSayisi int
		query := `SELECT COUNT(*) FROM cti_kayitlari 
		          WHERE onion_id = $1 AND toplama_tarihi > NOW() - INTERVAL '24 hours'`
		db.QueryRow(query, onionID).Scan(&tekrarSayisi)
		if tekrarSayisi > 0 {
			ekPuan := tekrarSayisi * 3
			if ekPuan > 15 {
				ekPuan = 15
			}
			skor += ekPuan
			aciklamaParcalari = append(aciklamaParcalari, fmt.Sprintf("Son 24 saatte %d kayıt (+%d)", tekrarSayisi, ekPuan))
		}
	}

	if skor > 100 {
		skor = 100
	}
	if skor < 0 {
		skor = 0
	}

	var kritiklik string
	if skor >= 86 {
		kritiklik = "kritik"
	} else if skor >= 61 {
		kritiklik = "yuksek"
	} else if skor >= 31 {
		kritiklik = "orta"
	} else {
		kritiklik = "dusuk"
	}

	aciklama := fmt.Sprintf("Toplam skor: %d/100. ", skor)
	if len(aciklamaParcalari) > 0 {
		aciklama += strings.Join(aciklamaParcalari, "; ")
	} else {
		aciklama += "Genel metaverse içeriği."
	}

	return kritiklik, skor, aciklama
}

func kayitKaydet(db *sql.DB, kayit CTIKayit) error {
	query := `
		INSERT INTO cti_kayitlari 
		(onion_id, kaynak_adi, kaynak_url, baslik, ozet, temiz_icerik, paylasim_tarihi, ana_kategori, alt_kategori, kategori, kritiklik, kritiklik_skor, kritiklik_aciklama)
		VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)
		ON CONFLICT (kaynak_url, toplama_tarihi) DO NOTHING
	`

	_, err := db.Exec(query,
		kayit.OnionID,
		kayit.KaynakAdi,
		kayit.KaynakURL,
		kayit.Baslik,
		kayit.Ozet,
		nullIfEmpty(kayit.TemizIcerik),
		kayit.PaylasimTarihi,
		kayit.AnaKategori,
		kayit.AltKategori,
		kayit.Kategori,
		kayit.Kritiklik,
		kayit.KritiklikSkor,
		kayit.KritiklikAciklama,
	)

	return err
}

func nullIfEmpty(s string) interface{} {
	if strings.TrimSpace(s) == "" {
		return nil
	}
	return s
}

func sonTaramaGuncelle(db *sql.DB, onionID int) {
	query := `UPDATE onion_adresleri SET son_tarama = CURRENT_TIMESTAMP WHERE id = $1`
	db.Exec(query, onionID)
}

func getEnv(key, defaultValue string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return defaultValue
}

func readResponseBody(resp *http.Response) ([]byte, error) {
	var reader io.Reader = resp.Body

	switch strings.ToLower(resp.Header.Get("Content-Encoding")) {
	case "gzip":
		gz, err := gzip.NewReader(resp.Body)
		if err != nil {
			return nil, err
		}
		defer gz.Close()
		reader = gz
	case "deflate":
		reader = flate.NewReader(resp.Body)
		defer reader.(io.ReadCloser).Close()
	}
	return io.ReadAll(reader)
}

func sanitizeHTML(html string) string {
	notice := `<!--
OFFLINE
-->`

	reBase := regexp.MustCompile(`(?i)<base[^>]*>`)
	html = reBase.ReplaceAllString(html, "")

	reHref := regexp.MustCompile(`(?i)href=["'][^"']+["']`)
	html = reHref.ReplaceAllString(html, `href="#"`)

	reSrc := regexp.MustCompile(`(?i)src=["'][^"']+["']`)
	html = reSrc.ReplaceAllString(html, `src=""`)

	return notice + "\n" + html
}

func extractTitle(html string) string {
	re := regexp.MustCompile(`(?i)<title>(.*?)</title>`)
	m := re.FindStringSubmatch(html)
	if len(m) > 1 {
		t := strings.TrimSpace(m[1])
		t = regexp.MustCompile(`[^a-zA-Z0-9 _\-]`).ReplaceAllString(t, "")
		return strings.ReplaceAll(t, " ", "_")
	}
	return "unknown_title"
}

func extractLinks(html string) []string {
	re := regexp.MustCompile(`href=["'](http[^"']+)`)
	matches := re.FindAllStringSubmatch(html, -1)
	seen := make(map[string]bool)
	var links []string
	for _, m := range matches {
		if !seen[m[1]] {
			seen[m[1]] = true
			links = append(links, m[1])
		}
	}
	return links
}

func buildOutputDir(rawURL, title string) string {
	u, _ := url.Parse(rawURL)
	host := u.Host
	if strings.Contains(host, ":") {
		host, _, _ = net.SplitHostPort(host)
	}
	site := strings.ReplaceAll(host, ".onion", "")
	ts := time.Now().Format("2006-01-02_15-04-05")

	hash := sha256.Sum256([]byte(rawURL))
	short := hex.EncodeToString(hash[:])[:6]

	return fmt.Sprintf("/root/output/%s/%s_%s_%s", site, title, ts, short)
}

func takeScreenshotTor(target, outDir string) error {
	proxyAddr := activeTorProxy
	if proxyAddr == "" {
		proxyURL, _ := url.Parse(torProxy)
		if proxyURL != nil {
			proxyAddr = proxyURL.Host
		}
	}
	if proxyAddr == "" {
		proxyAddr = "127.0.0.1:9050"
	}

	opts := append(chromedp.DefaultExecAllocatorOptions[:],
		chromedp.ProxyServer("socks5://"+proxyAddr),
		chromedp.Headless,
		chromedp.DisableGPU,
	)
	allocCtx, cancel := chromedp.NewExecAllocator(context.Background(), opts...)
	defer cancel()
	ctx, cancel := chromedp.NewContext(allocCtx)
	defer cancel()
	ctx, cancel = context.WithTimeout(ctx, 40*time.Second)
	defer cancel()
	var screenshot []byte
	var mhtml string
	err := chromedp.Run(ctx,
		chromedp.EmulateViewport(1920, 1080),
		chromedp.Navigate(target),
		chromedp.Sleep(5*time.Second),
		chromedp.FullScreenshot(&screenshot, 90),
		chromedp.ActionFunc(func(ctx context.Context) error {
			var err error
			mhtml, err = page.CaptureSnapshot().Do(ctx)
			return err
		}),
	)
	if err != nil {
		return err
	}
	os.WriteFile(filepath.Join(outDir, "screenshot.png"), screenshot, 0644)
	os.WriteFile(filepath.Join(outDir, "site_snapshot.mhtml"), []byte(mhtml), 0644)
	return nil
}

func taramaSonucuKaydet(db *sql.DB, onionID int, outDir string) {
	var kolonVar bool
	err := db.QueryRow("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='onion_adresleri' AND column_name='tarama_sonucu_yolu'").Scan(&kolonVar)
	if err != nil || !kolonVar {
		db.Exec("ALTER TABLE onion_adresleri ADD COLUMN IF NOT EXISTS tarama_sonucu_yolu TEXT")
	}

	query := `UPDATE onion_adresleri SET tarama_sonucu_yolu = $1 WHERE id = $2`
	db.Exec(query, outDir, onionID)
}

func updateSourceName(db *sql.DB, onionID int, newName string) {

	query := `UPDATE onion_adresleri SET kaynak_adi = $1 WHERE id = $2`
	_, err := db.Exec(query, newName, onionID)
	if err != nil {
		log.Printf("[WARN] Kaynak adı güncellenemedi (ID: %d): %v", onionID, err)
	}
}
