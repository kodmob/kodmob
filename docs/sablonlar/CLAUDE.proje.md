# <Proje adı> — yapay zekâ ve geliştirici rehberi

<!-- Kodmob ile başlayan projenin CLAUDE.md şablonu (docs/sablonlar/CLAUDE.proje.md). Kök klasöre CLAUDE.md
     olarak kopyalayın, <…> yerlerini doldurun; AGENTS.md "kurallar CLAUDE.md'de" diye buraya yönlendirir. -->

Bu dosya her oturumda okunur. Proje Kodmob çatısı üzerinde kurulu; çatının belgeleri `docs/gelistirici/`,
bilinen sınırlar `docs/dikkat.md`, araç paketleri `docs/arac-paketi.md`.

## Proje

<Ne yapıyor, kimin için, canlı adres, barındırma (paylaşımlı hosting / VPS).>

| Klasör | İçerik |
|---|---|
| `tv/src/profiles/<ad>/` | sitenin profili: `site.config.js`, `routes.config.js`, `locales.js`, `i18n/`, `pages/`, `shell/`, `panel.js` |
| `kodmob-api/app/Project/` | projenin içerik türleri ve modülleri (`App\Project`) |
| `kodmob-api/config/project.php` | kayıtlar: `content_types`, `modules` |
| `kodmob-api/routes/project.php` | projenin uçları (`/api/v1/…`) |
| `kodmob-api/tests/Project/` | projenin testleri |
| `kodmob-api/extensions/` | kurulu araç paketleri (`kodmob:add`) |

Çatının dosyaları (`app/Core`, `app/Modules`, `tv/src`'nin geri kalanı) `kodmob:upgrade` ile yenilenir:
değiştirmeyin; gerekirse kanca / kayıt kullanın ya da dosyayı `kodmob.json` `project` listesine alın.

## Komutlar

```
cd kodmob-api && php artisan test          # backend
cd kodmob-api && vendor/bin/pint           # PHP biçimi
cd tv && npm test                          # ön yüz
cd tv && npm run test:db                   # SQLite + MySQL + PostgreSQL
cd tv && npm run e2e                       # uçtan uca
cd tv && npm run deploy:local && npm run serve:local   # yerel site http://127.0.0.1:8090
cd kodmob-api && php artisan kodmob:module <ad> --label=… --singular=…   # içerik türü
cd kodmob-api && php artisan kodmob:upgrade --from=<zip> --dry-run      # çatı güncellemesi
node build.mjs --bump minor                # canlı paket
```

## Kurallar (çatıdan)

- Yeni şey çoğunlukla bir kayda bir satırdır: modül kancaları (`settings`, `capabilities`, `events`, `schedule`,
  `health`, `search`, `boot`), içerik türü `kodmob:module`, panel sayfası profilin `panel.js` `PAGES`'i.
- Her panel ucu `cap:` ile korunur; yazma rotasının adı modülün `events()`'inde; herkese açık uç gerekçeyle.
- Hata `ValidationException::fields()` / `DomainException` (Türkçe ileti + kod); gizli değer `Secret::seal()`.
- Veritabanı: SQLite, MySQL, PostgreSQL; migration'lar taşınabilir; canlıdaki tabloya değişiklik yeni migration'la.
- Çok proje: yol için `storage_path()`, `Paths::…`; istek içi statik önbellek `Sites::forgetMemory()`'ye.
- Site sayfası: şablonda panelden değişen metin `tx()`, sabit `t()`; CSP satır içi stil ve betiğe kapalı.
- Testler kodla birlikte; hata düzeltmesinde önce hatayı yakalayan test.

## Projeye özel kurallar

<Marka, dil, içerik kuralları; dış servisler; dokunulmaması gerekenler; gizli değerlerin yeri (yanıtlara ve
belgelere yazılmaz).>
