# Katkı

Kodmob'a katkınız için teşekkürler. Hata bildirimi, belge düzeltmesi, yeni blok ya da araç paketi, çatıya özellik:
hepsi değerli.

## Başlamadan

- **Hata:** konu (issue) açın; sürüm, adımlar, beklenen ve görülen sonuç. Güvenlik açığıysa konu açmayın:
  [SECURITY.md](SECURITY.md).
- **Özellik:** önce konu açıp kısaca anlatın; büyük değişiklikte birlikte karar verelim (çatıya mı, araç paketine mi).
  Çatıya girecek iş için etki analizi şablonu: [docs/sablonlar/etki-analizi.md](docs/sablonlar/etki-analizi.md).
- **Kendi aracınız:** çoğu zaman çatıya değil araç paketine yazılır ([docs/arac-paketi.md](docs/arac-paketi.md)).

## Geliştirme ortamı

Node.js 20+, PHP 8.4 (pdo_sqlite), Composer. Ayrıntı: [docs/gelistirici/01-kurulum.md](docs/gelistirici/01-kurulum.md).

```bash
cd kodmob-api && composer install && php artisan kodmob:install
cd ../tv && npm ci && npm run deploy:local && npm run serve:local   # http://127.0.0.1:8090
```

## Kurallar

Mimarinin kuralları [CLAUDE.md](CLAUDE.md)'de (yapay zekâ araçları da onu okur); kısaca:

- Çatı (`app/Core`, `app/Modules`, `tv/src`) projenin koduna bağlanmaz; yeni şey çoğunlukla bir **kayda** bir satırdır
  (modül kancası, yetki, ayar tanımı, içerik türü). `ArchitectureTest` denetler.
- Testler kodla birlikte; hata düzeltmesinde önce hatayı yakalayan test. SQLite, MySQL ve PostgreSQL'de geçmeli.
- Arayüz metinleri, yorumlar ve belgeler **Türkçe**; arayüzde teknik jargon yerine kullanıcının dili. Commit
  mesajları Türkçe, kısa başlık + gerekirse madde madde ayrıntı.
- Gizli değer (`.env`, anahtar, şifre) koda, teste ya da belgeye girmez.

## Göndermeden önce

```bash
cd kodmob-api && vendor/bin/pint && vendor/bin/phpstan analyse --memory-limit=1G && php artisan test
cd tv && npm test && npm run e2e
```

Değişiklik kullanıcıya görünüyorsa `CHANGELOG.md`'nin **Yayımlanmadı** bölümüne bir satır, geliştiriciyi
ilgilendiriyorsa `docs/gelistirici/`'ye not ekleyin. Çekme isteği (pull request) küçük ve tek konulu olsun.

Katkınız deponun lisansıyla (MIT, [LICENSE](LICENSE)) yayımlanır.

---

**English:** issues and pull requests are welcome. Please open an issue before large changes, keep pull requests
small, add tests (they must pass on SQLite, MySQL and PostgreSQL) and run `pint`, `phpstan`, `php artisan test`,
`npm test` and `npm run e2e` before submitting. UI texts, comments and docs are in Turkish; English issues are fine.
Security issues: see [SECURITY.md](SECURITY.md). Contributions are licensed under MIT.
