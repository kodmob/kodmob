# 02 Mimari

## Üç sahip: çatı, proje, paket

| Sahip | Nerede | Kim değiştirir |
|---|---|---|
| **Çatı** (framework) | `kodmob-api/app/Core`, `app/Modules`, `config/`, `routes/api.php`, `database/`; `tv/src` (profiller hariç), `tv/vite`, `tv/scripts` | `kodmob:upgrade` yeniler (`kodmob.json` `framework`) |
| **Proje** | `kodmob-api/app/Project` (`App\Project`), `config/project.php`, `routes/project.php`, `tests/Project`; `tv/src/profiles/<ad>/` | siz; güncelleme dokunmaz (`kodmob.json` `project`) |
| **Araç paketi** | `kodmob-api/extensions/<anahtar>/` | paketin yazarı; `kodmob:add` kurar ([arac-paketi.md](../arac-paketi.md)) |

Kural: çatı projenin ya da paketin sınıfını bilmez (`ArchitectureTest` denetler). Proje çatıya kayıtlarla ve
kancalarla eklenir; çatı dosyasını değiştirmeniz gerekiyorsa önce bir kanca isteyin (ya da dosyayı `kodmob.json`
`project` listesine alın: güncelleme yenisini yanına yazar).

## İstek akışı

```
Ziyaretçi  → public_html/.htaccess → api/render.php
             önbellekte varsa Laravel'siz döner; yoksa Laravel: Modules/Site/Render (Router → Renderer)
             derlenmiş kabuk (_pages/<dil>/…) + içerik (kayıt, liste, SEO, yapısal veri) → HTML
Panel      → /panel/ (derlenmiş Alpine uygulaması) → /api/v1/… (JSON; erişim jetonu + HttpOnly yenileme çerezi)
Cron       → php artisan schedule:run (her dakika; yoksa panel istekleri tetikler)
```

**Derleme / sunucu ayrımı (karar A):** adres yapısı derlemenindir. Profilin `routes.config.js` ve `locales.js`'i
Vite'ta `km-manifest.json`'a yazılır (rotalar, diller, profil, panel adresleri); sunucu adresleri yalnızca oradan
okur (`Core\Support\SiteManifest`). Sunucuda derleme yoktur: yeni sayfa ya da dil derleme + yükleme ister; metin,
içerik, renk, logo panelden değişir.

## Modüller

`App\Core\Modules`:

- **Çekirdek** (her zaman açık): sistem, site, medya, bildirimler, canlı şerit, güvenlik, SEO, çoklu dil; ayrıca
  kullanıcılar, roller, işlem geçmişi, yedek.
- **İsteğe bağlı** (`Modules::OPTIONAL`; profilin `site.config.js` `profile.modules`): `blog`, `briefs` (talepler),
  `estimate`, `jarvis`, `domains`, `sms`, `sites`, `newsletter`, Mağaza ve takılıları. `kind: base` temel yapı, `kind: tool` araç (Araç Pazarı'nda
  proje başına kapanır, `Core\ModuleState`; çatının aracı Keşfet'ten derlemesiz eklenir: `ModuleState::added()`,
  `Modules::installed()` = profil + eklenenler). Araçların site rotaları her derlemede (`tv/vite/framework-routes.js`
  `toolRoutes()`; profil `toolPages: false` ile almaz). Kapalı modülün sınıfı yüklenmez: yetkisi, ayarı, menüsü, uçları (404,
  `module:` ara katmanı) ve site sayfası (rotanın `module`'ü) yoktur.
- **Projenin modülleri** (`config/project.php` `modules`): her zaman açık.
- **Araç paketleri**: klasördeki geçerli paket (`Core\Extensions`).

Modül ne katar: [03 Modül](03-modul.md).

## Çok proje (alt siteler)

Tek kurulum, proje isteğin alan adından seçilir (`Core\Sites\SiteContext`; kayıt `storage/sites.json`). Her
projenin veritabanı, depolaması (`storage/sites/<anahtar>/`), medyası (`media/<anahtar>/`) ve oturum anahtarı
ayrıdır; ana projenin derlemesini kullanır (kendi derlemesi `tv/sites/<anahtar>/`). E-posta, SMS ve yapay zekâ
ayarları ana projeden gelir (`Options::SHARED`). Kod yazarken:

- Yol ve adres: `storage_path()`, `Paths::siteRoot()`, `Paths::mediaRoot()/mediaUrl()`; sabit yol yazmayın.
- İstek içi statik önbellek (`private static array $cache`) ekleyen sınıf `Sites::forgetMemory()`'ye eklenir.
- Başka projenin işi: `Sites::run($anahtar, fn)`; komut satırı `KODMOB_SITE=<anahtar> php artisan …`.
- Yalnızca ana projede olan özellik: yetkisini `Sites::isMain()` ile süzün.

## Veritabanı

SQLite, MySQL / MariaDB ve PostgreSQL. Migration'lar taşınabilir yazılır; sürüme özgü SQL yalnızca
`app/Core/Database` altında (`Sql::like()` büyük/küçük harften bağımsız arama). Canlıdaki tabloya değişiklik yeni
migration'la. Taşıma: `php artisan kodmob:db-move --to=<adres>`. Testler üçünde de geçmeli (`npm run test:db`).

İçerik türlerinin kayıtları tek tabloda (`contents`; tür `type`, alanlar JSON `data`), blog yazıları `posts`'ta;
kendi tablosu gereken veri (talepler, oturumlar, işler) ayrı tablolarda.

## Güvenlik

- Her panel ucu `cap:` ile korunur; herkese açık uç `ArchitectureTest`'teki izin listesinde gerekçeyle.
- Oturum: kısa ömürlü erişim jetonu (sessionStorage) + HttpOnly yenileme çerezi; iki adımlı doğrulama; hatalı
  girişte kilit; istek sınırları.
- CSP satır içi stil ve betiğe kapalı: stil sınıfla, betik dosyayla.
- Gizli değer (`Secret::seal()`, `APP_KEY` ile) saklanır, panele geri gönderilmez.
- Talep sahiplerinin kişisel verisi yapay zekâ sağlayıcısına gönderilmez.

## Ön yüz

Vite 7 + Alpine 3 + Tailwind 4 + Nunjucks. Site sayfaları derlemede HTML kabuk olur (dil başına), içeriği sunucu
doldurur; panel tek sayfa uygulamasıdır (`tv/src/panel`, görünümler tembel yüklenir, tablolar dfGrid
`tv/src/ui/grid`, formlar alan şemasından). İkonlar tek SVG dosyasında (`shell/icons.html`).
