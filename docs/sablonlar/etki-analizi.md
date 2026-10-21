# Etki analizi: <değişikliğin adı>

Tarih: · İsteyen: · Durum: öneri / onaylandı / uygulandı

Büyük değişiklikten önce doldurulur (yeni modül, şema değişikliği, yetki ya da adres değişikliği, çok siteyi ya da
veritabanını etkileyen iş). Uygulanmayan satır "yok" diye geçilir; boş bırakılmaz.

## 1. Niyet

Ne yapılacak ve neden, bir paragraf. Kullanıcı açısından sonuç.

## 2. Etkilenenler

| Alan | Etki | Not |
|---|---|---|
| Tablolar / migration | | Canlıda var mı? (varsa yeni migration) |
| Uçlar (adres, yöntem) | | Yeni uç `cap:` ile korunuyor mu? Herkese açıksa gerekçe |
| Yetkiler | | `Capability` + `Roles::DEFAULTS`; değişmiş rollere gelmez |
| Ayarlar | | `SettingDefinition`; panel araması kendiliğinden |
| İşlem geçmişi / bildirim olayları | | Rota adı `ActivityCatalog`'da |
| Panel sayfaları | | routes.js, meta.js, views.js, operations.js, test manifestleri |
| Site sayfaları / adresler | | Derleme (karar A); eski adres 301; `km-manifest.json` |
| Çok site | | Her sitede mi, yalnızca ana sitede mi? Statik önbellek → `Sites::forgetMemory()` |
| Veritabanı motorları | | SQLite / MySQL / PostgreSQL farkı; sürüme özgü SQL `Core\Database`'de |
| Yedek ve geri yükleme | | `Backup` yeni veriyi taşıyor mu? |
| Önbellek | | Sayfa önbelleği (`PageCache::flush`), derlenmiş yapılandırmalar (SEO, dil) |
| Güvenlik | | Kişisel veri, gizli değer (`Secret`), yapay zekâya giden veri |
| Yapay zekâ / Jarvis | | Yeni araç mı? Yetki ve onay |
| Belgeler | | `dikkat.md`, ilgili rapor, README |

## 3. Kararlar

Kullanıcıya sorulacaklar, her biri için öneri: `Kn: soru (öneri: …)`.

## 4. Testler

Hangi testler eklenecek; `npm run test:db` ve uçtan uca gerekiyor mu.

## 5. Geri dönüş

Sorun çıkarsa nasıl geri alınır (migration geri dönüşü, ayar, yapılandırma).
