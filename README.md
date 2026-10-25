# Kodmob

[![CI](https://github.com/kodmob/kodmob/actions/workflows/ci.yml/badge.svg)](https://github.com/kodmob/kodmob/actions/workflows/ci.yml)
[![Belgeler](https://img.shields.io/badge/belgeler-kodmob.github.io-0b5fff)](https://kodmob.github.io/kodmob/)
[![Lisans: MIT](https://img.shields.io/badge/lisans-MIT-green)](LICENSE)

Kurumsal site ve çok kullanıcılı yönetim paneli çatısı: Laravel 12 API ve Vite + Alpine.js + Tailwind ön yüz.
Kimlik, roller, çok dil, SEO, yedek, panel ve sistem sağlığı hazır; siz yalnızca projenize özgü kodu yazarsınız.
Paylaşımlı hostinge sunucuda derleme gerekmeden kurulur. [kodmob.com](https://kodmob.com) bu çatının ilk sitesidir.

**Geliştirici belgeleri: [kodmob.github.io/kodmob](https://kodmob.github.io/kodmob/)** · sürümler:
[CHANGELOG.md](CHANGELOG.md) · bilinen sınırlar: [docs/dikkat.md](docs/dikkat.md)

> **In English:** Kodmob is an MIT-licensed starter framework for company websites with a multi-user admin panel
> (Laravel 12 API, Vite + Alpine.js + Tailwind front end). It installs on shared hosting without a server-side build,
> runs several projects from one installation, and supports SQLite, MySQL and PostgreSQL. It ships users, roles,
> two-factor sign-in, an activity log, content types, multilingual URLs and translations, SEO, encrypted backups,
> mail and SMS, background jobs, a page builder, AI providers and a tool marketplace (shop, bookings, newsletter).
> The panel and the documentation are in Turkish for now; English issues and pull requests are welcome.
> Quick start below.

## Neler hazır

- **Panel:** kullanıcılar, roller ve yetkiler, iki adımlı doğrulama, işlem geçmişi, panel araması, Kullanım
  Kılavuzu (tur ve rehberler), Sistem Sağlığı, bildirimler, tablolar (süzgeç, toplu işlem, CSV).
- **İçerik:** içerik türleri (alanlar şemadan; liste, form, çeviri, yedek, arama kendiliğinden), blog, medya,
  Sayfa düzenleyici (sürükle-bırak bloklar, taslak ve yayın geçmişi), blok sayfaları, yasal belgeler.
- **Çok dil ve SEO:** dile göre adresler, 301 adres geçmişi, 404 önerileri, site haritası, yapısal veri, llms.txt,
  yönlendirmeler, yapay zekâ botları için görünürlük raporu, sunucuda HTML (SSR) ve sayfa önbelleği.
- **Altyapı:** otomatik yedek (uzak depoya şifreli), e-posta ve SMS, arka plan işleri, tek kurulumdan çok proje,
  SQLite / MySQL / PostgreSQL, çatı güncellemesi (`kodmob:upgrade`).
- **İsteğe bağlı araçlar:** Mağaza (Stripe, PayTR), Rezervasyon, Oteller, Bülten, talepler, Jarvis yapay zekâ
  asistanı (Claude, OpenAI, Gemini), domain arama; Araç Pazarı'ndan proje başına açılıp kapanır. Topluluk araç
  paketleri: [docs/arac-paketi.md](docs/arac-paketi.md).
- **Yapay zekâ ile geliştirmeye hazır:** asistan rehberi ([CLAUDE.md](CLAUDE.md), [AGENTS.md](AGENTS.md)), kayıt
  tabanlı mimari, koddan üretilen sözleşme ve OpenAPI (`docs/contracts/`), niyet dosyasından içerik türü.

## Hızlı başlangıç

Node.js 20+, PHP 8.4 (`pdo_sqlite`, `mbstring`, `openssl`, `zip`, `gd`) ve Composer. Yerelde veritabanı SQLite
dosyasıdır: MySQL ya da Apache gerekmez. PHP PATH'te değilse `KM_PHP` ortam değişkeniyle gösterilir.

```bash
git clone https://github.com/kodmob/kodmob.git benim-projem && cd benim-projem
cd kodmob-core && composer install
php artisan kodmob:install --profile=benim-sitem      # .env, anahtarlar, SQLite, tablolar, ilk yönetici, profil
cd ../kodmob-frontend && npm ci && npm run deploy:local && npm run serve:local
# site: http://127.0.0.1:8090/   panel: http://127.0.0.1:8090/panel/
```

`kodmob:install` sitenizin profilini başlangıçtan kopyalar (`kodmob-frontend/src/profiles/benim-sitem/`) ve etkin yapar;
yönetici adı ve e-postası sorulur (`--admin`, `--email`, `--password`; şifre verilmezse rastgele üretilip bir kez
gösterilir). Var olan `.env`'e ve anahtarlara dokunmaz. Sonrası: [01 Kurulum](docs/gelistirici/01-kurulum.md),
[02 Mimari](docs/gelistirici/02-mimari.md).

## Sık kullanılan komutlar

```bash
# Testler
cd kodmob-core && php artisan test        # backend (SQLite, bellek içi)
cd kodmob-frontend && npm run test:db                 # backend: SQLite + MySQL + PostgreSQL (yerel PostgreSQL kendiliğinden)
cd kodmob-frontend && npm test                        # ön yüz birim testleri
cd kodmob-frontend && npm run e2e                     # uçtan uca (kurulu Chrome)

# Yeni içerik türü (panelde liste, form, yetki, yedek, arama kendiliğinden)
cd kodmob-core && php artisan kodmob:module courses --label=Kurslar --singular=Kurs
cd kodmob-core && php artisan kodmob:module --intent=kurslar.intent.json        # niyet dosyasından

# Araç paketi (topluluk aracı)
cd kodmob-core && php artisan kodmob:tool new stok-takibi --label="Stok takibi"
cd kodmob-core && php artisan kodmob:add <klasör | zip | katalogdaki anahtar>

# Sistem sağlığı ve canlı paket (release/)
cd kodmob-core && php artisan kodmob:health && php artisan kodmob:links
node build.mjs --bump minor

# Çatı güncellemesi: yalnızca projede değişmemiş çatı dosyaları yenilenir
cd kodmob-core && php artisan kodmob:lock                     # projede bir kez
cd kodmob-core && php artisan kodmob:upgrade --from=kodmob-3.0.1.zip --dry-run
```

## Klasörler

| Klasör | İçerik |
|---|---|
| `kodmob-core/` | Laravel 12 API: çekirdek (`app/Core`), modüller (`app/Modules`), testler. Canlıda `kodmob-core/` |
| `kodmob-frontend/` | Ön yüz: site sayfaları ve panel (Vite 7, Alpine 3, Tailwind 4, Nunjucks), uçtan uca testler |
| `docs/` | Geliştirici belgeleri (`gelistirici/`, sitesi VitePress), sözleşme ve OpenAPI (`contracts/`), şablonlar |
| `build.mjs` | Tek komutla canlı paketi (`node build.mjs`) |

Projenin kendi dosyaları ayrıdır; çatı güncellemesi onlara dokunmaz:

| Yer | İçerik |
|---|---|
| `kodmob-frontend/src/profiles/<ad>/` | site: `site.config.js`, `routes.config.js`, `locales.js`, `i18n/`, `pages/`, `shell/`, `blocks/`; panel uzantısı `panel.js`; profilin testleri `tests/` |
| `kodmob-core/app/Project/` | projenin içerik türleri ve modülleri (ad alanı `App\Project`) |
| `kodmob-core/config/project.php` | kayıtlar: `content_types`, `modules` |
| `kodmob-core/routes/project.php` | projenin uçları (`/api/v1/…`) |
| `kodmob-core/tests/Project/` | projenin testleri |

Etkin profil `kodmob-frontend/profile.js` (ortamda `KM_PROFILE` üstündedir). Başlangıç profili `starter`: ana sayfa (bloklar),
blog, iletişim formu, yasal belgeler, 404; Türkçe ve İngilizce.

## Veritabanı ve barındırma

SQLite, MySQL / MariaDB ve PostgreSQL resmî olarak destekli; testler üçünde de çalışır (CI matrisi). Paylaşımlı
hostingde MySQL (ya da SQLite), VPS ve bulutta PostgreSQL önerilir. Taşıma: `php artisan kodmob:db-move
--to="pgsql://kullanici:sifre@sunucu:5432/veritabani"`. Tek kurulumdan alt projeler (kendi alan adıyla; veritabanı,
depolama ve oturum ayrı): panelde Yönetim › Siteler ya da `php artisan kodmob:site create`. Yayın:
[06 Yayın](docs/gelistirici/06-yayin.md).

## Katkı ve güvenlik

Katkı rehberi: [CONTRIBUTING.md](CONTRIBUTING.md). Güvenlik açığını herkese açık konu olarak değil, GitHub'daki
**Security › Report a vulnerability** ile bildirin: [SECURITY.md](SECURITY.md).

## Lisans ve ticari markalar

Kod [MIT](LICENSE) lisanslıdır. "Kodmob" adı ve logosu sahibinin markasıdır, lisansa dahil değildir. Belgelerde ve
kodda geçen Stripe, PayTR, Claude, OpenAI, Gemini, NetGSM, Hostinger ve benzeri adlar sahiplerinin ticari
markalarıdır; yalnızca uyumluluğu anlatmak için kullanılır. Kodmob bu şirketlerle bağlantılı değildir ve onlar
tarafından onaylanmamıştır. Tümleştirmeler herkese açık API belgelerine göre yazılmıştır. Yazı tipi Google Sans
Flex, SIL Open Font License 1.1 ile (`kodmob-frontend/public/assets/fonts/OFL.txt`).
