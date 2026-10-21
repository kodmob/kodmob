# 03 Modül ve içerik türü

## İçerik türü (en sık iş)

Panelden yönetilen bir liste (Kurslar, Ekip, Referanslar; ürün satışı için Mağaza modülü var):

```bash
cd kodmob-api
php artisan kodmob:module courses --label=Kurslar --singular=Kurs [--slug=kurslar] [--icon=book] [--no-order]
```

Üretilenler (hepsi projenin dosyası): tür sınıfı `app/Project/Courses/CourseType.php`, kaydı
`config/project.php` `content_types`, profilde açılması (`site.config.js` `profile.contentTypes`), panel tanımı
(profilin `panel.js` `TYPES`), test `tests/Project/CoursesContentTest.php`. Panelde liste, form, sıralama,
çeviri, silinenler, yedek, arama, yetki (`km_manage_courses`) ve işlem geçmişi kendiliğinden gelir.

**Niyet dosyasından:** alanları ve amacı tek JSON dosyasında anlatın (yapay zekâ yazabilir ya da inceleyebilir),
üretici kodu aynı biçimde kurar. Örnek: [docs/sablonlar/modul-niyeti.json](../sablonlar/modul-niyeti.json).

```bash
php artisan kodmob:module --intent=kurslar.intent.json [--dry-run]
```

Alan türleri: `text`, `textarea`, `html`, `url`, `bool`, `int`, `money`, `date`, `select` / `multiselect` (`options`
gerekli), `icon`, `image`, `gallery`, `list`; anahtarlar `type`, `label`, `help`, `section`, `width`, `max`, `min`,
`options`. Bilinmeyen tür ya da anahtar hatadır (uydurulmuş alan sessizce geçmez). `title` ve `slug` dil başına
alandır; yazılmazsa eklenir. `purpose` tür sınıfının açıklamasına yazılır. Komut satırındaki seçenek niyet dosyasını
ezer. Rezervasyonlu türde (`bookable`) alanlar profilden gelir, `fields` ile birlikte verilmez.

Tür sınıfı alanları tanımlar (`shared()`: dilden bağımsız, `localized()`: dile göre) ve sitede ne görüneceğini
(`publicItem()`). Türe özgü davranış kancalardadır (`App\Core\Content\ContentType`):

| Kanca | Ne için |
|---|---|
| `seoDefaults()`, `seoText()` | kayıt sayfasının varsayılan başlık / açıklaması |
| `structuredData()` | kayıt sayfasının schema.org verisi |
| `llmsText()` | `llms.txt` satırı |
| `searchItem()` | site içi arama sonucu |
| `imagesOf()` | site haritasındaki görseller |

**Kendi sayfası olan tür:** profilin rotasında `dynamic: '<kayıt adı>'` (ör. `{ name: 'course', dynamic:
'course', paths: { tr: '/kurslar/:slug/' }, … }`). Adres geçmişi (301), site haritası, SEO, IndexNow, 404
önerileri ve arama bu listeyi (`Types::paged()`) dolaşır. Sayfanın içerik ihtiyacı rotanın `needs`'inde
(`needs: ['courses']`). Bkz. [04 Sayfalar](04-sayfalar.md).

### Rezervasyonlu tür (tur, otel, kiralama, transfer…)

Mağaza ve Rezervasyon modülleri açıkken (Araç Pazarı › Keşfet'ten eklenir ya da `profile.modules`: `'commerce'`, `'booking'`) bir tür rezerve edilebilir
olur: `Bookable` uygular ve bir **rezervasyon profili** verir. Fiyat alanları, kurallar, sepet satırı, sitedeki
rezervasyon kutusu ve sayfa bölümleri profilden türer (`app/Modules/Commerce/Booking`).

```
php artisan kodmob:module cars --label=Araçlar --singular=Araç --bookable=day
```

```php
public function bookingProfile(): BookingProfile
{
    return new BookingProfile(BookingProfile::DAY, ['packages', 'seasons', 'early', 'capacity'], ['highlights', 'faq']);
}
public function shared(): array { return BookingFields::shared($this->bookingProfile()) + ['cover' => …]; }
public function localized(): array { return ['title' => …, 'slug' => …] + BookingFields::localized($this->bookingProfile()); }
protected function publicItem(array $item, string $locale): array { return BookingView::item($this->bookingProfile(), $item, $locale) + […]; }
```

| Birim | Fiyat | Ziyaretçi seçer | Kapasite |
|---|---|---|---|
| `person` | yetişkin / çocuk / bebek fiyatı | tarih, kişi sayıları | günlük kişi |
| `day` | günlük fiyat × gün × adet | alış – teslim tarihi, adet | günlük adet (aralığın her günü) |
| `fixed` | rezervasyon başı sabit | tarih, kişi (en çok `max_persons`) | günlük rezervasyon |
| `night` | gecelik oda fiyatı × gece × oda (oda tipi × pansiyon) | giriş – çıkış, oda, yetişkin / çocuk, oda tipi ve pansiyon | her gece oda: toplam (`capacity`) ve oda tipi başına (`rooms[].stock`) |

Gece biriminde paket yerine **oda tipleri** (`rooms`: kısa ad, kişi, adet, pansiyon başına gecelik fiyat
`price_ro|bb|hb|fb|ai`; adları dile göre `room_texts`). Fiyatı boş pansiyon o odada sunulmaz; hiç oda tipi yoksa
`price_night` ile tek seçenek. Oda başına kişi oda tipinin `persons`'ı, yoksa `room_persons`. Çatı hazır bir tür de
getirir: **Oteller** aracı (`hotels`, Rezervasyon'a takılı; `HotelType`, sitede `hotelRoutes()`).

Özellikler: `packages` (fiyat temel fiyatın yerine geçer; adları dile göre `package_texts`), `groups` (yalnızca
kişi başı), `seasons`, `early`, `capacity`, `dates` (gün biriminde yok). Sayfa bölümleri: `highlights`,
`itinerary`, `includes`, `excludes`, `faq`, `meeting`, `languages`. Sayfa şablonunda:
`{% include "pages/shop/_booking-sections.njk" %}` ve `{% include "pages/shop/_booking-box.njk" %}` (örnek
`tv/src/pages/shop/tour.njk`). Fiyatı her zaman sunucu hesaplar (`/shop/bookings/quote`).

## Modül

Bir alanın uygulamaya katkısı: `App\Core\Module` alt sınıfı, kancaları doldurur. Projenin modülü
`app/Project/<Ad>/<Ad>Module.php`'de yazılır, `config/project.php` `modules`'e eklenir; uçları `routes/project.php`.

```php
final class ReportsModule extends Module
{
    public function capabilities(): array   // yetkiler (rol düzenleyicide, cap: ara katmanında)
    {
        return ['km_view_reports' => ['label' => 'Raporları görme', 'group' => 'Proje']];
    }

    public function settings(): array       // panelde kendiliğinden çizilir ve aranır
    {
        return [new SettingDefinition('reports.weekly', 'bool', true, 'Haftalık rapor', 'Raporlar', 'Her pazartesi e-posta.')];
    }

    public function events(): array         // olaylar ve İşlem Geçmişi başlıkları
    {
        return ['reports.sent' => ['Rapor gönderildi', 'success']];
    }

    public function schedule(Schedule $schedule): void   // zamanlanmış görev (alt projelerde de çalışır)
    {
        $schedule->call(fn () => Reports::weekly())->name('reports:weekly')->weeklyOn(1, '08:00');
    }

    public function contentTypes(): array { return [ReportType::class]; }                // modülün içerik türleri
    public function health(): array { return [/* Core\Health\Health::section(...) */]; }  // Sistem Sağlığı kartı
    public function search(): array { return [/* SearchProvider */]; }                    // panel araması
    public function backup(): array                                                      // yedeğe giren tablolar
    {
        return [new BackupPart('reports', 'Raporlar', ['reports', 'report_rows'], personal: false)];
    }
    public function boot(): void {}                                                       // dinleyiciler, Jarvis araçları
}
```

Yedek: içerik türleri ve ayarlar kendiliğinden girer; modülün kendi tabloları `backup()` ile (satırlar kimlikleriyle,
geri yüklemede tablo yedektekiyle değişir; içerik kaydına bağlanan sütun `contentLinks` ile yeni kimliğe taşınır).
Kişisel veri taşıyan tablo (`personal: true`: sipariş, talep, abone) indirilen yedeğe ve geri yüklemeye ayrıca seçilerek
girer; uzak depoya giden yedek her zaman şifrelidir.

Jarvis aracı: `boot()`'ta `SkillRegistry::requires('km_view_reports', new Skill…)`; sohbet kuralı
`SkillRegistry::guide()`. Yapay zekâ sağlayıcısı: `AiProvider` + `Ai::PROVIDERS`.

## Hangisini seçmeli

| İhtiyaç | Yol |
|---|---|
| Panelden yönetilen liste | içerik türü (`kodmob:module`) |
| Bu projeye özel ekran, uç, ayar | projenin modülü + profilin `panel.js` `PAGES` |
| Başka projelerde de kullanılacak, açılıp kapanan araç | araç paketi ([arac-paketi.md](../arac-paketi.md)) |
| Her projede olması gereken temel yetenek | çatıya katkı (isteğe bağlı modül: `Modules::OPTIONAL` + `Capability::MODULE_OF` + `module:<ad>`) |

**Modülün modülü:** isteğe bağlı modül başka bir modüle takılabilir (`Modules::OPTIONAL` kaydında ya da paketin
tanımında `requires: ['commerce']`). Takıldığı modül kapalıyken o da yüklenmez; Araç Pazarı'nda "Mağaza kapalı
olduğu için çalışmıyor" görünür ve açılamaz; takıldığı modül yeniden açılınca kendi durumuyla döner.
