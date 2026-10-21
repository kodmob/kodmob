# Kodmob — yapay zekâ ve geliştirici rehberi

Bu dosya her oturumda okunur (Claude Code; diğer araçlar için `AGENTS.md` buraya bakar). Kod yazmadan önce
buradaki kurallara ve ilgili rapora bakın. Ayrıntılı geçmiş: `CHANGELOG.md` ve (varsa) `docs/*-raporu.md`; açık
riskler: `docs/dikkat.md`. Bu bilgisayara ya da projeye özgü notlar (git dışı): `CLAUDE.local.md`.
Biten rapor `done-` önekiyle kapatılır (`docs/done-*.md`), bitmeden geçerliliğini yitiren (yerine başkası geçen) `old-`
önekiyle: ikisi de yalnızca geçmiş için okunur, yeni iş açık raporlara ya da yeni rapora.

## Proje

Kodmob: kurumsal site ve çok kullanıcılı yönetim paneli çatısı (framework, MIT); yeni projenin başlangıcıdır
(kodmob.com bu çatının ilk sitesidir). Tek depo:

| Klasör | İçerik |
|---|---|
| `kodmob-api/` | Laravel 12, PHP 8.4: API, sayfa üretimi (render.php), panel uçları, komutlar |
| `tv/` | Vite 7 + Alpine 3 + Tailwind 4 + Nunjucks: site sayfa kabukları ve yönetim paneli |
| `tv/sites/<anahtar>/` | Çok site: alt sitenin kendi derleme katmanı (README orada) |
| `docs/` | Raporlar, `dikkat.md` ("yapıldı, ama…" listesi), `build.md` |
| `build.mjs` | Kökten tek komutla paket (`node build.mjs`) |
| `release/` | Paketler; **git dışı**, `release/ilk-kurulum/.env` canlının gizli anahtarlarını taşır: okunmaz, paylaşılmaz |

Barındırma varsayımı: paylaşımlı hosting (Hostinger), sunucuda derleme yok, SSH yok olabilir
(`/api/v1/setup?token=…` kurulum adresi tabloları kurar).

## Komutlar

```
cd kodmob-api && php artisan test          # backend (SQLite bellek içi)
cd kodmob-api && vendor/bin/pint           # PHP biçimi (CI --test ile denetler)
cd kodmob-api && vendor/bin/phpstan analyse --memory-limit=1G   # statik analiz (Larastan; phpstan-baseline.neon)
cd tv && npm test                          # ön yüz birim testleri
cd tv && npm run test:db                   # backend: SQLite + MySQL + PostgreSQL
cd tv && npm run e2e                       # uçtan uca (Playwright, .e2e/ ayrı ortam, ~6 dk)
cd tv && npm run e2e -- --starter          # başlangıç profiliyle (KM_PROFILE=starter) derleyip starter.spec
cd tv && npm run deploy:local && npm run serve:local   # yerel site http://127.0.0.1:8090 (.local/)
cd kodmob-api && php artisan kodmob:install --profile=<ad>   # yeni projenin yerel kurulumu (SQLite; MAMP gerekmez)
cd kodmob-api && php artisan kodmob:pack | kodmob:lock | kodmob:upgrade --from=<zip> --dry-run   # çatı paketi ve güncelleme
cd kodmob-api && php artisan kodmob:tool new <ad> | kodmob:tool check <ad> | kodmob:add <klasör|zip|anahtar>   # araç paketi
cd kodmob-api && php artisan kodmob:contracts        # sözleşme + OpenAPI → docs/contracts (uç, yetki, ayar değişince; ContractsTest)
```

PHP PATH'te değilse yerel betikler `KM_PHP` ortam değişkenine bakar (Windows'ta MAMP kuruluysa onun PHP'sine).
Yerel PHP yolu ve bu bilgisayarda dokunulmayacak yerler `CLAUDE.local.md`'de.

## Mimari kurallar

**Katmanlar.** `app/Core` çatıdır (her projede aynı); `app/Modules` çatının modülleridir. Modül listesi
`App\Core\Modules` (çekirdek her zaman açık; isteğe bağlılar ve içerik türleri projenin profilinde:
`tv/src/profiles/<ad>/site.config.js` `profile`, `KODMOB_MODULES` üstündedir). Çekirdekten modüle yeni bağımlılık
eklemeyin; gerekirse kayıt / kanca (ör. `Router::useProfile`) kullanın. `ArchitectureTest` izin listesini denetler.
Mimari: `docs/gelistirici/02-mimari.md`.

**Proje kodu ayrı** (çatı güncellemesi dokunmaz): `app/Project/` (ad alanı `App\Project`; ör. portföy
türleri), kayıtları `config/project.php` (`content_types`, `modules`), uçları
`routes/project.php`, testleri `tests/Project/`; site ve panel uzantısı `tv/src/profiles/<ad>/` (`panel.js`: panel
içerik türleri). Çatı (`app/Core`, `app/Modules`, `tv/src` geri kalanı) projenin sınıfına bağlanmaz (`ArchitectureTest`);
türe özgü davranış türün kancasındadır: `ContentType::seoDefaults/seoText/structuredData/llmsText/searchItem`.
Kendi sayfası olan tür: profilin rotasında `dynamic: '<kayıt adı>'` (`Types::paged()`; adres geçmişi, site haritası,
SEO, llms.txt, IndexNow, 404 önerileri, arama bunu dolaşır).

**Kayıtlar, dağınık kod değil.** Yeni bir şey çoğunlukla bir kayda bir satırdır:

- Modül kancaları: `Core\Module` → `settings()`, `events()`, `search()`, `boot()`, `schedule()`, `health()`
  (Sistem Sağlığı kartı: `Core\Health\Health::section()`; panelde kod gerekmez), `capabilities()` (modülün yetkileri),
  `contentTypes()` (modülün içerik türleri; modülle açılıp kapanır), `legal()` (yasal metne değer), `backup()`
  (yedeğe giren tabloları: `Core\Database\BackupPart`; kişisel veri `personal: true`, geri yüklemede ayrıca seçilir)
- Yetki: `Core\Security\Capability` (sabit + etiket) ve hazır rollerde yeri (`Roles::DEFAULTS`)
- Ayar: `SettingDefinition` (anahtar `grup.ad`, etiket, grup, açıklama; `format: 'color' | 'image'` renk / görsel
  seçici); panelde kendiliğinden çizilir ve aranır
- İşlem geçmişi: yazma rotasının adı (`->name('x.y')`) `Core\Activity\ActivityCatalog`'da tanımlı olmalı
- İçerik türü: `php artisan kodmob:module <ad> --label=… --singular=…` ya da `--intent=<niyet.json>` (alanlar ve amaç;
  örnek `docs/sablonlar/modul-niyeti.json`) (tür `app/Project`, kayıt
  `config/project.php`, panel profilin `panel.js`'i, test `tests/Project`; hepsi projenin dosyaları)
- Zamanlanmış görev: `Core\Jobs\Scheduler::define()` ya da modülün `schedule()`'ı (alt siteler aynı listeyi çalıştırır)
- Rezervasyonlu tür: `Modules\Commerce\Booking\Bookable` (`bookingProfile()`: birim person | day | fixed | night, özellikler,
  sayfa bölümleri); alanlar `BookingFields`, sitedeki çıktı `BookingView`; `kodmob:module … --bookable=<birim>`
- Yasal belge (gizlilik, KVKK, sözleşme…): `tv/src/legal/<ad>/` (`doc.json`: grup, `module`, adresler; `<dil>.html`
  şablon), başlığı sözlükte `legal.<ad>.title`; modülün metne verdiği değerler `Module::legal()`; sunucu
  `Modules/Site/Legal`, panel Yasal belgeler (docs/gelistirici/09-yasal-belgeler.md)
- Mağaza ödeme yöntemi: `Modules\Commerce\Payments\PaymentMethod` + modülün `boot()`'unda `Payments::register()` (Stripe,
  PayTR: Mağaza'ya takılı ayrı modüller). API anahtarı gibi ayar `SettingDefinition` `format: 'secret'` (mühürlü; panele,
  işlem geçmişine, yedeğe girmez)
- Yapay zekâ sağlayıcısı: `AiProvider` + `Ai::PROVIDERS`; Jarvis aracı: modülün `boot()`'unda
  `SkillRegistry::requires(yetki, new Skill…)`, sohbet kuralları `SkillRegistry::guide()`. Panel ucundan araç:
  `Jarvis\Skills\ContractTools`'a `RouteSkill` (uç kullanıcının adına çağrılır; `write: true` onaylı, Operatör).
  Kişisel veri taşıyan uç (talep, sipariş, abone, kullanıcı) araç yapılmaz
- İsteğe bağlı modül: `Modules::OPTIONAL` (`kind`: `base` | `tool`; `requires`: takıldığı modüller, "modülün modülü") + yetkisi `Capability::MODULE_OF` + rotaları
  `module:<ad>` + site sayfası rotada `module: '<ad>'`. Araç projede çalışma anında kapanır (Araç Pazarı,
  `Core\ModuleState`); kurulu olanlar profilden ve Keşfet'ten eklenenler (`Modules::installed()`, `ModuleState::added()`).
  Aracın site sayfası her derlemede (`tv/vite/framework-routes.js` `toolRoutes()`; araç kapalıyken 404)
- Topluluk aracı (paket): `kodmob-api/extensions/<anahtar>/` (`kodmob-tool.json`, `php/`, `panel/index.js` `PAGES`,
  `tests/`); `php artisan kodmob:tool new <ad>` iskelet, `kodmob:add` kurar. Biçim ve kurallar `docs/arac-paketi.md`;
  çalışma anı `Core\Extensions`. Çatının testleri paketlerden bağımsız (`TestCase::$extensions`)

**Uçlar.** Her panel ucu `cap:` ara katmanıyla korunur; herkese açık uç `ArchitectureTest`'teki izin listesine
gerekçeyle eklenir. Uç, yetki, ayar ya da olay değişince `php artisan kodmob:contracts` (docs/contracts; test kilitli).
Yeni ya da dokunulan uçta gövde biçimi bildirimsel: `Core\Http\Schema` + `Field` (aynı Türkçe mesajlar; iş kuralları
serviste), uç `Core\Contracts\RequestSchemas`'a ("YÖNTEM /yol") yazılır, OpenAPI oradan. Hata: `DomainException` / `ValidationException::fields()` (Türkçe mesaj + kod; ön yüz
`tv/src/domain/errors.js`). Gizli değerler `Core\Support\Secret::seal()` ile saklanır, panele geri gönderilmez.

**Veritabanı.** SQLite, MySQL/MariaDB ve PostgreSQL desteklenir; testler üçünde de geçmeli (`npm run test:db`).
Sürüme özgü SQL yalnızca `app/Core/Database` altında (`Sql::like()` büyük/küçük harften bağımsız arama).
Migration'lar taşınabilir yazılır. Canlıda yayında olan tabloya değişiklik yeni migration'la yapılır.

**Site sayfaları.** Projenin sitesi profil klasöründe: `tv/src/profiles/<ad>/` (etkin profil `tv/profile.js`, `KM_PROFILE`):
`site.config.js` (`profile.needs`: her sayfada gelen türler), `routes.config.js`, `locales.js`, `i18n/`, `pages/`,
`shell/`, `panel.js`. Tarayıcıda `@profile/…`; Node testlerinde aynı ad `tests/profile-hook.mjs` ile. Çatı sözlüğü (ortak bileşen metinleri)
`tv/src/i18n/`, profilinki üstüne yazar; çatının rotaları `tv/vite/framework-routes.js`. Adres yapısı derlemenindir
(karar A): profilin `routes.config.js` ve `locales.js` →
`km-manifest.json`. Sunucu kabuğu (`_pages/…`) içerikle tamamlar: `public/render.php` (önbellek yolunda Laravel'siz),
`Modules/Site/Render` (Router, Renderer, SitePage). Panelden değişen metin: şablonda `tx('anahtar')`; değişmeyen
`t()`. CSP satır içi stil ve betiğe kapalı: stil sınıf / belirteçle, betik dosyayla. Sayfa düzenleyici
(docs/gelistirici/08-bloklar.md): blok `tv/src/blocks/<ad>/` (`block.json` + `block.html`; profilin ve paketin
`blocks/`'u da), bölge şablonda `zone('x.y')`, ilk blokları profilin `site.config.js` `zones`'u; derleme
`km-blocks.json` (vite/blocks.js), sunucu `Modules/Site/Blocks` (şablon dili JS ve PHP'de aynı; ortak örnekler
`tests/fixtures/blocks/template.json`). Blok sayfası (panelden açılan sayfa): tür `pages` (`Modules/Site/Pages`),
çatının rotası `page` kökte `/:slug/` ve `last: true` (sunucu en son dener), blokları `page.main@<kimlik>` bölgesinde.

**Panel.** Menü proje odaklı ve sadedir (docs/gelistirici/04-sayfalar.md): üstte proje seçici, Genel bakış, **Temel**
(`base`: Blog, Talepler, Sayfalar ve Diller, Sayfa düzenleyici, SEO), **Proje modülleri** (`project`: projenin türleri, sayfaları,
araçlar), Proje Ayarları, en altta **Sistem** (`system`). Seyrek sayfa `PAGES`'te `menu: false` (Tüm İşlemler'de
kalır; Tüm İşlemler üst bardan açılır). Ayarlar iki sayfa: Proje Ayarları (`projectSettings`) ve Sistem Ayarları
(rota `settings`); ayar tanımının kapsamı `SettingDefinition` `scope` (`project` | `system`). Sayfa anahtarları
küçük harf ve tire (Hızlı İşlem anahtarı olur). **Projenin** panel sayfası çatıya dokunmadan profilin `panel.js`
`PAGES`'ine yazılır (`key`, `path`, `label`, `group`, `can`, `guide`, `load`, `template`; biçim
`tv/src/panel/extension.js`): menü, Tüm İşlemler ("Siteye özel"), tur, arama, adresler ve tembel yükleme
kendiliğinden; yetkisi projenin modülünün `capabilities()`'inden. **Çatının** yeni sayfası: `tv/src/panel/routes.js` (rota), `meta.js` (`PAGES`, yetki), `views.js` (tembel yükleme),
`src/pages/panel/index.njk` + `_<ad>.njk`, `api.js`, `operations.js` (Diğer İşlemler; test her sayfayı arar),
`kodmob-api/tests/fixtures/panel-urls.json` ve `tests/fixtures/site/km-manifest.json` (`panel.routes`). Menüde
görünen sayfanın Kullanım Kılavuzu turu metni `panel/guide.js` `NAV_TEXT`'te (test arar); ekranı değişen işin
rehberi `panel/guides/*.html` (bağlantı adres yazmaz: `data-route` / `data-tab`). Tablolar dfGrid (`tv/src/ui/grid`),
formlar alan şemasından.

**Çok site** (docs/gelistirici/02-mimari.md). Tek kurulum, site isteğin alan adından seçilir (`Core\Sites\SiteContext`);
her sitenin veritabanı, depolaması, medyası ve oturum anahtarı ayrıdır.

- Yol ve adres için `storage_path()`, `Paths::siteRoot()`, `Paths::mediaRoot()/mediaUrl()` kullanın; sabit yol yazmayın.
- Yapılandırmada bağlantı adı ortamdan geliyorsa (`env('DB_CONNECTION')`) alt sitede `Sites::apply()`'da `site`'a çevirin.
- İstek içi statik önbellek (`private static array $cache`) ekleyen her sınıf `Sites::forgetMemory()`'ye eklenir
  (yoksa site değişiminde eski site verisi kalır; `ArchitectureTest` denetler). Kapsayıcıda tek örnek (singleton)
  olup veritabanından okuduğunu saklayan servis de `Sites::reconnect()`'te `forgetInstance` ile unutulur (ör.
  `SettingsService`); bunu kural testi göremez.
- Yalnızca ana sitede olan özellik: yetkisini `Capability::all()`'da `Sites::isMain()` ile süzün.
- Kurulumun tamamının ayarı (e-posta, SMS, yapay zekâ): seçenek adı `Options::SHARED`'da; alt proje ana projeninkini
  okur, yazamaz (panelde sekmesi `shared: true`).
- Başka sitenin işini yapmak: `Sites::run($anahtar, fn)`; komut satırı: `KODMOB_SITE=<anahtar> php artisan …`.

## Çalışma biçimi

- **Büyük değişiklikten önce etki analizi**: `docs/sablonlar/etki-analizi.md` (tablolar, uçlar, yetkiler, olaylar,
  panel, alt siteler, veritabanları, yedek, önbellek). Kullanıcının kararı gereken noktaları önerinizle sorun.
- Testler kodla birlikte yazılır; bir hata düzeltmesinde önce hatayı yakalayan test.
- Kullanıcıya görünen her "yapıldı, ama…" `docs/dikkat.md`'ye; plan ve kararlar ilgili rapora.
- Dil: arayüz metinleri, yorumlar, belgeler ve commit mesajları **Türkçe**; çevredeki kodun yorum yoğunluğuna ve
  adlandırmasına uyun. Arayüzde teknik jargon yerine kullanıcının dili ("uç" değil "adres", "endpoint" değil).
- Gizli değerler (`.env`, şifreler, API anahtarları, yerel yöneticinin şifresi) yanıtlara, belgelere ya da hafızaya
  yazılmaz.
- Windows / Bash notu: heredoc içindeki ters eğik çizgi (PHP ad alanları) bozulabilir; PHP dosyalarını düzenleme
  aracıyla yazın.
- İstem kalıpları: `docs/ai/istemler.md`.
