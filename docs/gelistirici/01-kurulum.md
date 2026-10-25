# 01 Kurulum

## Gerekenler

- **PHP 8.4** (8.2 ve üstü çalışır): `pdo_sqlite`, `mbstring`, `openssl`, `zip`, `curl`, `fileinfo`, `gd`.
  MySQL / PostgreSQL için `pdo_mysql` / `pdo_pgsql`.
- **Composer**, **Node.js 20+** (npm).
- Yerelde veritabanı SQLite dosyasıdır: MAMP, Apache ya da MySQL gerekmez.
- PHP PATH'te değilse betikler `KM_PHP` ortam değişkenine bakar (Windows: `set KM_PHP=C:\php\php.exe`).

## Yeni proje

```bash
git clone https://github.com/kodmob/kodmob.git benim-projem && cd benim-projem
cd kodmob-core && composer install
php artisan kodmob:install --profile=benim-sitem
cd ../kodmob-frontend && npm ci && npm run deploy:local && npm run serve:local
```

`kodmob:install` şunları yapar: `.env` (yoksa) ve anahtarlar (`APP_KEY`, `JWT_SECRET`), SQLite dosyası, tablolar,
ilk yönetici (sorar; `--admin`, `--email`, `--password`), başlangıç profilinin kopyası
`kodmob-frontend/src/profiles/benim-sitem/` ve onu etkin yapma (`kodmob-frontend/profile.js`). Var olan `.env`'e dokunmaz (`--local` ile yerel
değerleri yazar). Şifre verilmezse rastgele üretilir ve bir kez gösterilir.

Sonra:

- Site: http://127.0.0.1:8090/ · Panel: http://127.0.0.1:8090/panel/
- İlk düzenlenecek dosya: `kodmob-frontend/src/profiles/benim-sitem/site.config.js` (adres, açık modüller, kurum bilgisi);
  metinler `i18n/tr.json`, sayfalar `pages/` ([04 Sayfalar](04-sayfalar.md)).
- Panelde Proje Ayarları > Kimlik: site adı, logo, renkler (derleme gerekmez).

## Yerel site nasıl çalışır

`npm run deploy:local` canlıdaki yerleşimin aynısını `.local/` altında kurar:

```
.local/public_html/    derlenmiş site ve panel (kodmob-frontend/dist) + api/ (Laravel giriş dosyaları) + media/
.local/kodmob-core     → kodmob-core klasörüne bağlantı (PHP değişikliği hemen geçer)
```

Her seferinde ön yüzü derler (`--no-build` ile atlar), tabloları günceller (ana ve alt projeler) ve dil kaydını
derlemeyle eşitler. `npm run serve:local` PHP'nin kendi sunucusunu açar (8090; başka port `KM_PORT`). Ön yüzde anlık
yenileme için `npm run dev` (5173; `/api` istekleri 8090'a gider).

## Testler

```bash
cd kodmob-core && php artisan test          # backend (SQLite, bellek içi)
cd kodmob-core && vendor/bin/pint           # PHP biçimi
cd kodmob-core && vendor/bin/phpstan analyse --memory-limit=1G
cd kodmob-frontend && npm test                          # ön yüz birim testleri
cd kodmob-frontend && npm run test:db                   # backend üç veritabanında (PostgreSQL kendiliğinden kurulur)
cd kodmob-frontend && npm run test:db -- sqlite pgsql   # yerelde MySQL yoksa
cd kodmob-frontend && npm run e2e                       # uçtan uca (kurulu Chrome; ayrı .e2e/ ortamı, ~7 dk)
cd kodmob-frontend && npm run e2e -- --starter          # başlangıç profiliyle (sonra npm run build:dev)
cd kodmob-frontend && npm run e2e -- --shop | --tools   # başlangıç profili + Mağaza / Jarvis, Domain, SMS, Siteler açık
```

Uçtan uca testler kendi veritabanını ve rastgele şifreli `e2e` yöneticisini kurar; yerel sitenize dokunmaz.
Testler kodla birlikte yazılır: hata düzeltmesinde önce hatayı yakalayan test.

## Windows notları

- Klasör bağlantıları junction'dır (yönetici izni gerekmez).
- Git Bash'te heredoc içindeki ters eğik çizgi (PHP ad alanı) bozulabilir: PHP dosyasını düzenleyiciyle yazın.
- `*.localhost` adları (alt projeler: `demo.localhost:8090`) Chrome'da kendiliğinden bilgisayarın kendisine gider.
