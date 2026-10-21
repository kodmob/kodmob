# Araç paketi: yazma, kurma, kataloğa ekleme

Araç, Kodmob projesine eklenen ve Araç Pazarı'nda proje başına açılıp kapanan modüldür. Çatının kendi araçları
(Jarvis, Domain arama, Teklif hesaplayıcı, SMS, Çok proje) çatının içindedir; topluluğun araçları **paket**
olarak gelir. Paket ne çatının ne projenin dosyasıdır: `kodmob:upgrade` ona dokunmaz, proje dosyalarınız da
onunla karışmaz. Tasarım ve kararlar:  §6, §9.

## 1. Hızlı başlangıç

```
cd kodmob-api
php artisan kodmob:tool new stok-takibi --label="Stok takibi" --author="Adınız"
php artisan test --filter=StokTakibi        # paketin kendi testi
cd ../tv && npm run build                   # panel sayfası derlemeye girer
```

İskelet `kodmob-api/extensions/stok-takibi/`'ye yazılır ve hemen kuruludur: Araç Pazarı'nda "Topluluk" rozetiyle
görünür, menüde "Proje modülleri" altında sayfası, Proje Ayarları'nda ayarı, Kullanıcılar ve Roller'de yetkisi
vardır.

## 2. Paketin yapısı

```
stok-takibi/
  kodmob-tool.json        tanım (aşağıda)
  php/                    ad alanının kökü (PSR-4): StokTakibiModule.php, controller'lar, servisler
  php/routes.php          (isteğe bağlı) uçlar: /api/v1 altında, araç kapalıyken 404
  database/migrations/    (isteğe bağlı) tablolar
  panel/index.js          (isteğe bağlı) panel sayfaları (PAGES)
  panel/*.js, panel/*.njk bileşenler ve şablonlar
  tests/                  PHP testleri (phpunit "Extensions" takımı)
  README.md · LICENSE
```

**Tanım (`kodmob-tool.json`):**

| Alan | Açıklama |
|---|---|
| `key` | Klasörün adı; küçük harf, rakam, tire (2–31). Çatının modül anahtarlarıyla çakışamaz. |
| `label`, `description` | Araç Pazarı'ndaki ad ve tek cümlelik açıklama (Türkçe). |
| `version` | Paketin sürümü, `x.y.z`. |
| `kodmob` | Uyumlu çatı sürümü: `">=2.5 <3"`, `"^2.5"`. Uyumsuz paket yüklenmez, Araç Pazarı'nda nedeni yazar. |
| `author`, `license`, `homepage` | Yazar, lisans (topluluk kataloğu için MIT önerilir), adres. |
| `namespace` | PHP ad alanı, ör. `KodmobTools\\StokTakibi` (JSON'da çift ters eğik çizgi). `App`, `Tests`, `Illuminate`… olamaz. |
| `module` | `php/`'deki modül sınıfının adı (`App\Core\Module`'ü genişletir). |
| `requires` | (isteğe bağlı) takıldığı modüller, ör. `["commerce"]`: o modül açık değilken paket çalışmaz. |

Composer gerekmez: ad alanı çalışma anında `php/`'ye bağlanır (paylaşımlı hostingde sunucuda komut çalışmayabilir).
Paketin dışarıdan kütüphane ihtiyacı varsa bugün desteklenmiyor (bkz. §7).

## 3. Kurallar (çatınınkiyle aynı)

- **Modül kancaları** (`php/<Ad>Module.php`): `capabilities()` (yetkiler, `km_` ile başlar), `settings()`
  (`SettingDefinition`; Proje Ayarları'nda kendiliğinden çizilir, `scope: 'system'` Sistem Ayarları'na),
  `events()` (olaylar ve İşlem Geçmişi başlıkları), `boot()`, `schedule()`, `health()` (Sistem Sağlığı kartı).
  Araç kapalıyken modül hiç yüklenmez: yetkisi, ayarı, olayı, zamanlanmış görevi yoktur.
- **Uçlar** (`php/routes.php`): panel ucu `auth.jwt` + `cap:<yetki>` ister; yazma rotasının adı
  (`->name('stok_takibi.updated')`) modülün `events()`'inde tanımlı olmalı. Herkese açık uç topluluk paketinde
  önerilmez (gerekirse README'de gerekçesiyle). Hata: `DomainException` / `ValidationException::fields()` (Türkçe).
  Gizli değer `Core\Support\Secret::seal()` ile saklanır, panele geri gönderilmez.
- **Veritabanı**: migration'lar SQLite, MySQL/MariaDB ve PostgreSQL'de çalışmalı; tablo adı anahtarla başlasın
  (`stok_takibi_urunler`). Sürüme özgü SQL yazmayın (`App\Core\Database\Sql`).
- **Çok proje**: paket kurulumun tamamınındır, her proje kendi veritabanında tablolarını ve verisini tutar.
  Yol ve adres için `storage_path()`, `Paths::…`; istek içi statik önbellek tutmayın (site değişince kalır).
- **Panel** (`panel/index.js`): biçim projeninkiyle aynı (`tv/src/panel/extension.js`):

  ```js
  export const PAGES = [{
      key: 'stok-takibi', path: 'stok-takibi/:tab?', label: 'Stok takibi', icon: 'package',
      can: 'km_use_stok_takibi',                       // zorunlu: araç kapalıyken sayfa görünmez
      guide: 'Turdaki kısa tanıtım.',
      load: () => import('./stok-takibi.js'),          // export function kmStokTakibi() { return { … } }
      template: 'panel/_stok-takibi.njk',              // paketin klasöründe
  }];
  ```

  Sayfa anahtarı aracın anahtarıyla başlar (`stok-takibi`, `stok-takibi-rapor`). Bileşen çatının modüllerini
  `@/` ile alır (`import { http } from '@/services/http.js'`). `index.js` yalnızca veri dışa açar; içe aktarma
  `load` içinde (derleme onu Node'da da okur). CSP: satır içi stil ve betik yok.
- **Kişisel veri** yapay zekâ sağlayıcısına gönderilmez; ayar kapalıyken dışarıya istek atılmaz.

## 4. Kurma ve güncelleme

```
php artisan kodmob:add ../indirilenler/stok-takibi            # klasör
php artisan kodmob:add ../indirilenler/stok-takibi-main.zip   # zip (tek üst klasörlü olabilir)
php artisan kodmob:add stok-takibi                            # katalogdan: indirir, sha256 özetini denetler
php artisan kodmob:add stok-takibi --update                   # kurulu paketin yerine yenisi
php artisan kodmob:tool list                                  # kurulu ve yüklenemeyen paketler
php artisan kodmob:tool check stok-takibi                     # tanım, uyum, testler var mı
```

Sonra: tablolar için `php artisan migrate` (canlıda kurulum adresi `/api/v1/setup?token=…` bütün projelerin
tablolarını kurar), panel sayfası için `tv`'de derleme, paketi yükleme. Kurulum panelden yapılmaz: sunucuya kod
yazan iş geliştirme bilgisayarında, gözden geçirilerek yapılır.

**Kaldırma:** önce Araç Pazarı'nda kapatın, sonra `kodmob-api/extensions/<anahtar>/` klasörünü silip derleyin.
Tabloları ve verisi kalır (elle silinir).

## 5. Kataloğa ekleme (topluluk)

Katalog açık depoda `kodmob-api/resources/marketplace/catalog.json`; araç **çekme isteğiyle** (PR) eklenir.
Panel kataloğu dışarıya istek atmadan bu dosyadan okur (çatıyla güncellenir).

```json
{
    "key": "stok-takibi", "label": "Stok takibi", "description": "…", "author": "…", "license": "MIT",
    "version": "0.1.0", "kodmob": ">=2.5 <3", "repository": "https://github.com/…",
    "download": "https://github.com/…/releases/download/v0.1.0/stok-takibi-0.1.0.zip",
    "sha256": "<zip'in sha256 özeti>", "verified": false
}
```

Özet: `sha256sum stok-takibi-0.1.0.zip` (Windows: `certutil -hashfile stok-takibi-0.1.0.zip SHA256`). Yeni sürüm
yeni zip ve yeni özetle ayrı PR'dır.

**Kontrol listesi** (PR'da işaretlenir; inceleyen `verified: true` yapar):

- [ ] `php artisan kodmob:tool check <anahtar>` geçiyor; tanımda lisans ve yazar dolu
- [ ] Paket kuruluyken `php artisan test` geçiyor (çatının `ArchitectureTest`'i paketin uçlarını da denetler:
      her uç `cap:`, yazma rotalarının adı İşlem Geçmişi'nde) ve `npm run test:db` üç veritabanında geçiyor
- [ ] Paketin kendi testleri var (`tests/`), panel sayfası varsa derleme hatasız
- [ ] Gizli değerler `Secret` ile; kişisel veri yapay zekâya gitmiyor; ayar kapalıyken dışarıya istek yok
- [ ] Arayüz metinleri Türkçe, kullanıcının dilinde; README kurulumu ve ne yaptığını anlatıyor
- [ ] `download` https, `sha256` zip'in özeti; zip'te `../` ya da mutlak yol yok

**"Doğrulanmış"** yalnızca incelenen sürüm içindir; paket sunucuda çatıyla aynı yetkiyle çalışır (veritabanı,
dosyalar). Yalnızca güvendiğiniz paketi kurun.

## 6. Geliştirirken

- Paketi kendi deposunda geliştirip denemek için: `kodmob-api/extensions/<anahtar>` klasörüne bağlantı
  (Windows: `New-Item -ItemType Junction`, Linux/macOS: `ln -s`) ya da `KODMOB_EXTENSIONS` (sunucu) ve
  `KM_EXTENSIONS` (derleme) ortam değişkenleriyle başka klasör.
- Çatının testleri kurulu paketlerden bağımsızdır (`Tests\TestCase::$extensions`); paketin testleri (`Tests\`
  dışındaki ad alanı) ve `ArchitectureTest` paketleri yükler.
- Örnek paket: `kodmob-api/tests/fixtures/extensions/merhaba` (`kodmob:tool new` çıktısı; uçtan uca
  `extensions.spec` onu yükler).

## 7. Bugün olmayanlar

- Paketten içerik türü (Ürünler gibi listeler) eklemek: içerik türü projede (`kodmob:module`) kalır.
- Paketin site sayfası (ziyaretçinin gördüğü sayfa) ve Composer bağımlılıkları.
- Panelden kurma / kaldırma; katalogdan otomatik güncelleme bildirimi.
