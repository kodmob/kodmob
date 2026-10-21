# Kodmob — Build ve Yayın

Sürüm: **3.0.0** (1.x WordPress; 2.0 Laravel API + çok kullanıcılı panel + SEO; 2.1 #'sız panel adresleri + dil kaydı; 2.2 çoklu dil: öneksiz Türkçe adresler, içerik çevirileri, kapsamlı 404, Çoklu Dil sayfası; 2.3 iskelet: site profili, panel kod bölme, ikon dosyası, arka plan işleri + SMTP, uçtan uca testler, modül üretici, iki adımlı doğrulama, çerezli oturum, liste SSR, otomatik/uzak yedek, SMS (NetGSM); 2.4 çok site (alt siteler, Siteler sayfası, kodmob:site), SQLite/MySQL/PostgreSQL ve kodmob:db-move, yapay zekâ sağlayıcıları (Claude, OpenAI, Gemini), Diğer İşlemler ve panel araması, site kimliği ve renkler (theme.css), kural testi ve Larastan; 2.5 açık kaynak başlangıç çatısı: proje / çatı ayrımı, Araç Pazarı, araç paketleri; 3.0 ilk açık kaynak sürüm: Sayfa düzenleyici, Mağaza, Rezervasyon, Bülten, yasal belgeler, Jarvis Operatör). Sürümlerin ayrıntısı: [CHANGELOG](../CHANGELOG.md).
## Gerekenler

| Araç | Not |
|---|---|
| Node.js 20+ | `tv/` ön yüz derlemesi |
| PHP 8.4 | Varsayılan `C:/MAMP/bin/php/php8.4.1/php.exe`; başka yer için `KM_PHP` |
| Composer | Varsayılan `C:/ProgramData/ComposerSetup/bin/composer.phar`; başka yer için `KM_COMPOSER` |
| Yerel MySQL | Paketle içerik gidecekse `kodmob-api/.env` veritabanı açık olmalı (MAMP) |

## Paket almak

Kök dizinde:

```bash
node build.mjs                  # testler + ön yüz + Laravel + yereldeki içerik
node build.mjs --no-content     # içeriksiz (canlı sitenin güncellemesi)
node build.mjs --bump minor     # önce sürümü yükselt: major | minor | patch
node build.mjs --skip-tests     # testleri atla
node build.mjs --ignore-links   # bağlantı denetimindeki hatalarda durma (eski adı --ignore-health)
```

Windows'ta aynısı: `build`, `build --no-content` … (build.cmd)

> Arama motorları: `tv/.env.production` içinde `SITE_INDEXABLE=false` iken bütün sayfalar `noindex` olur ve robots.txt her şeyi kapatır. Yayına çıkarken `true` yapıp yeniden build alın.

Betik sırasıyla şunları yapar: sürümleri karşılaştırır (`tv/package.json` ile `kodmob-api/config/kodmob.php` aynı olmalı), eksikse bağımlılıkları kurar, `php artisan test` ve `npm test` (tv) çalıştırır, içerikli pakette `php artisan kodmob:links` ile çoklu dil ve bağlantı sağlığını denetler (Çoklu Dil > Sağlık ile aynı liste; hata varsa durur), ardından `tv` içinde `npm run release` ile paketi üretir. Test geçmezse paket üretilmez.

## Çıktı (`release/`)

| Dosya | Nereye |
|---|---|
| `kodmob-site-v3.0.0.zip` | Hostinger `public_html/` içine açılır (sayfalar, panel, assets, `api/`) |
| `kodmob-core-v3.0.0.zip` | `public_html`'in **yanına** açılır (`kodmob-core/`: Laravel + vendor) |
| `ilk-kurulum/.env` | Yalnızca ilk kurulumda `kodmob-core/.env` olur. Gizli; silmeyin, paylaşmayın |
| `OKUBENI.txt` | Yükleme adımları (kurulum adresi dahil) |

`release/.km-release` ve `release/ilk-kurulum/` korunur, gerisi her build'de yeniden üretilir.

## Canlıya yükleme (özet)

1. İki zip'i yukarıdaki yerlere açın. `.env`, `storage/` ve `public_html/media/` üzerine yazılmaz.
2. İlk kurulumda `ilk-kurulum/.env` dosyasını `kodmob-core/.env` olarak koyup DB bilgilerini doldurun.
3. Kurulum adresini bir kez açın: `https://kodmob.com/api/v1/setup?token=…` (token `OKUBENI.txt`'de). Yeni tabloları bu adım oluşturur; her sürüm geçişinde bir kez açın.
4. Kontrol: `/tr/`, `/panel/`, `/api/v1/site`.

## Yerel geliştirme

```bash
cd tv
npm run dev            # Vite (yalnızca ön yüz)
npm run deploy:local   # derleyip .local/public_html'e dağıtır
npm run serve:local    # http://127.0.0.1:8090 (php -S + kodmob-api/dev/router.php)

cd kodmob-api
php artisan test       # backend testleri
vendor/bin/pint        # kod biçimi

cd tv
npm test               # panel rota tablosu (PHP ile ortak vektörler) ve elle yazılmış bağlantı denetimi
```

Panel adresleri `/panel/…` yollarıdır (ör. `/panel/projeler/12`); tek kaynak `tv/src/panel/routes.js`. Yeni panel sayfası: rota tablosuna bir satır, `kodmob-api/tests/fixtures/panel-urls.json`'a bir örnek.

Site adresleri: varsayılan dil (Türkçe) öneksiz (`/projeler/x/`), diğerleri önekli (`/en/projects/x/`). Önekler profilin `locales.js`'i, bölüm adları `routes.config.js` (`tv/src/profiles/<ad>/`). Adres değişirse build eski adresi profilin `i18n/url-history.json`'u'a yazar ve site onu 301 ile yeniye yönlendirir (bu dosya git'e girmeli). Yerelde dil kaydı `npm run deploy:local` ile (ya da `php artisan kodmob:i18n`) build'e eşitlenir.

## Dizin yapısı

```text
6.Kodmob/                       tek git deposu (dal: main; eski iki deponun geçmişi alt klasörlerde)
├── build.mjs · build.cmd       tek komutla paket
├── docs/                       build.md (bu dosya), dikkat.md (açık riskler), raporlar
├── .github/workflows/ci.yml    CI: backend + ön yüz + uçtan uca
│
├── kodmob-api/                 Laravel 12 API → canlıda kodmob-core/
│   ├── app/
│   │   ├── Core/               çekirdek: Database (TableRepository, Row), Content (içerik türü altyapısı),
│   │   │                       Security (yetkiler, roller), Activity (işlem kaydı), Settings, Setup, Http
│   │   ├── Modules/            her modül kendi rotası, servisi ve ayarlarıyla
│   │   │   ├── Auth            giriş (JWT), cihaz oturumları, profil
│   │   │   ├── Users           çoklu kullanıcı, roller ve izinler
│   │   │   ├── Projects · Blog · Services · References   içerik türleri
│   │   │   ├── Content         sayfa metinleri, teklif ayarları, yedek / geri yükleme
│   │   │   ├── Media           görseller (WebP varyantları)
│   │   │   ├── Briefs          siteden gelen talepler
│   │   │   ├── History         tüm işlemler (etkinlik kaydı)
│   │   │   ├── Records         silinenler ve geri alma
│   │   │   ├── Notifications · Live   bildirimler, canlı nabız (pulse)
│   │   │   ├── Jarvis          yapay zekâ asistanı ve taramalar
│   │   │   ├── Security        WAF, IP engeli, kilitlenmeler, güvenlik olayları
│   │   │   ├── Seo             meta, analiz, schema, sitemap, robots, llms.txt,
│   │   │   │                   yönlendirmeler, 404 izleme, IndexNow (tamamen kapatılabilir)
│   │   │   ├── Site            sayfa render'ı (render.php + Renderer), sitemap
│   │   │   └── System          ayarlar, sistem durumu
│   │   └── Providers/
│   ├── config/kodmob.php       uygulama ayarları + sürüm ('version')
│   ├── database/migrations/    tablolar (kurulum adresi çalıştırır)
│   ├── public/                 index.php (API), render.php (sayfalar, Laravel'siz hızlı katman)
│   ├── dev/router.php          yerel php -S yönlendirici (.htaccess karşılığı)
│   ├── routes/api.php          /v1/... uç noktaları
│   └── tests/                  Feature + Unit testleri
│
├── tv/                         ön yüz: Vite + Alpine 3 + Tailwind 4
│   ├── src/
│   │   ├── i18n/               sözlükler (tr/en/de.json), locales.js (önekler), url-history.json (eski adresler)
│   │   ├── pages/              site sayfaları (home, projects, blog, …) + panel/ + _ui/ (bileşen vitrini)
│   │   ├── panel/              panel mantığı: routes.js (adresler), meta.js (menü/yetki), api.js, sayfa başına modül
│   │   ├── ui/                 ortak bileşenler: grid/ (kmGrid), records/, confirm, toast, sheet, …
│   │   ├── shell/ · layouts/   site başlığı, alt bilgi, ikonlar, temel şablon
│   │   ├── stores/ · services/ Alpine store'ları, http/auth/config
│   │   ├── domain/ · features/ biçimlendirme, durumlar, teklif hesaplayıcı, brif sihirbazı
│   │   ├── i18n/ · legal/      tr/en/de çeviriler, yasal metinler
│   │   └── styles/             tokens, components, panel/
│   ├── public/                 .htaccess, assets
│   ├── vite/ · vite.config.js  çok sayfalı derleme, dil sayfaları, site-urls.js (adres kuralları)
│   ├── tests/                  node --test (npm test)
│   └── scripts/                deploy-local · serve-local · package-release
│
├── .local/                     yerel test sitesi (canlı yerleşimin aynısı; git'e girmez)
│   ├── public_html/            deploy:local çıktısı (_pages/ kabuklar, panel/, assets/) + media/
│   └── kodmob-core → ../kodmob-api   (junction)
│
├── release/                    build çıktısı (zip'ler, OKUBENI.txt, ilk-kurulum/.env; git'e girmez)
├── mockup/                     tasarım görselleri (git'e girmez)
└── .agents/ · .claude/         yapay zekâ aracı yetenekleri (projeye dahil değil, git'e girmez)
```
