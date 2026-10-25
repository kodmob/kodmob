# 05 Ayar, yetki, uç

## Ayar

Modülün `settings()`'i `App\Core\Settings\SettingDefinition` listesi döner; panel formu, doğrulama, arama ve
işlem geçmişi kendiliğinden gelir:

```php
new SettingDefinition(
    key: 'reports.weekly_day', type: 'int', default: 1, label: 'Rapor günü', group: 'Raporlar',
    help: '1 = pazartesi', min: 1, max: 7, scope: 'project',
);
```

- `key`: `grup.ad` (küçük harf, rakam, `_`, `.`) · `type`: `int`, `float`, `bool`, `string`
- `options` (seçim listesi), `format: 'color' | 'image'` (renk / görsel seçici), `unit`
- `scope`: `project` (Proje Ayarları) ya da `system` (Sistem Ayarları); verilmezse `Güvenlik` ve `Sistem sağlığı`
  grupları sistem, gerisi proje.
- Okumak: `SettingsService::instance()->get('reports.weekly_day')`.

Yapılandırılmış değer (liste, nesne) için anahtar-değer deposu `App\Core\Support\Options` (`get`, `set`). Gizli
değer `Secret::seal()` ile saklanır ve panele geri gönderilmez (`password_set: true` gibi bir bayrak döner).

## Yetki ve roller

- Modülün yetkisi `capabilities()`'de: anahtar `km_` ile başlar (`km_view_reports`), etiket ve grup Türkçe.
- Yönetici bütün yetkilere sahiptir; diğer roller panelde Kullanıcılar ve Roller'den düzenlenir. Hazır rollerdeki
  yeri çatının yetkileri için `Roles::DEFAULTS`.
- Kapalı modülün yetkisi yoktur: yönetici dahil kimse göremez, menü ve sayfalar kendiliğinden gizlenir.
- Yalnızca ana projede olan özellik: yetkisini `Sites::isMain()` ile süzün.

## Uç (API adresi)

```php
// routes/project.php (/api/v1 altında)
Route::middleware(['auth.jwt', 'activity', 'cap:km_access_panel'])->group(function () {
    Route::get('raporlar', [ReportsController::class, 'index'])->middleware('cap:km_view_reports');
    Route::post('raporlar/gonder', [ReportsController::class, 'send'])->middleware('cap:km_view_reports')->name('reports.sent');
});
```

Kurallar (`ArchitectureTest` denetler):

- Her panel ucu `cap:` ister. Herkese açık uç (form gönderimi gibi) testteki izin listesine gerekçeyle eklenir ve
  istek sınırı (`throttle:`) alır.
- Yazma rotasının adı İşlem Geçmişi'nde başlığı olan bir olay olmalı: çatı için `Core\Activity\ActivityCatalog`,
  modül için `events()`.
- İsteğe bağlı modülün rotası `module:<ad>` ile (kapalıyken 404).
- Hata: `throw ValidationException::fields(['alan' => 'Türkçe ileti'])` (422) ya da
  `throw new DomainException('km_kod', 'Türkçe ileti', 409)`; ön yüz kodları `kodmob-frontend/src/domain/errors.js`'te okur.
- Controller dizi döner (JSON); girdi `$this->input($request)`.

## İşlem geçmişi ve olaylar

Adı olan yazma rotası kendiliğinden İşlem Geçmişi'ne düşer (kim, ne zaman, hangi kayıt). Ayrıntı eklemek:
`Activity::subject('tur', $id, 'Başlık')`, `Activity::with([...])`. Bildirim ve canlı şerit olayları modülün
`events()`'inde başlık ve tonla tanımlanır.
