# 07 Güncelleme (çatının yeni sürümünü projeye almak)

Projeniz Kodmob'un bir kopyasından başlar; çatının yeni sürümü dosya listesi ve parmak iziyle alınır. Git geçmişi
gerekmez.

```
kodmob.json        çatının dosyaları: framework (desenler), project (projenin; dokunulmaz), exclude
kodmob.lock.json   projede kurulu çatı sürümü ve her çatı dosyasının sha256 özeti (satır sonundan bağımsız)
```

## Adımlar

```bash
cd kodmob-api
php artisan kodmob:lock                                 # bir kez: kurulu sürümü işaretle (değiştirilmemiş kurulumda)
php artisan kodmob:upgrade --from=kodmob-2.6.0.zip --dry-run   # ne olacağını göster
php artisan kodmob:upgrade --from=kodmob-2.6.0.zip      # uygula (Git'te kaydedilmemiş değişiklik varken çalışmaz; --force)
```

| Durum | Sonuç |
|---|---|
| Çatı dosyası projede değişmemiş | yenisi yazılır |
| Projede değiştirilmiş | yenisi yanına `<dosya>.kodmob-yeni` yazılır, uyarı verilir (elle birleştirin) |
| Yeni sürümde eklenen dosya | eklenir |
| Sürümden kalkan, projede değişmemiş dosya | silinir |
| Projenin dosyası (`project` desenleri), araç paketleri (`kodmob-api/extensions/`) | dokunulmaz |

Sonra: `composer install`, `cd tv && npm ci`, testler (`php artisan test`, `npm test`), derleme ve yayın
([06 Yayın](06-yayin.md)). Sürüm notları ve yükseltme adımları [CHANGELOG](../../CHANGELOG.md)'da.

## Çatı paketi üretmek (çatıyı geliştirenler için)

```bash
php artisan kodmob:pack        # release/kodmob-<sürüm>.zip: kodmob.json'daki çatı dosyaları + kilit
```

## Çatı dosyasını bilerek değiştirdiyseniz

Dosyayı `kodmob.json` `project` listesine ekleyin: güncelleme onu hiç yenilemez (yenisini de yazmaz). Daha iyisi
değişikliği kanca ya da kayıtla yapmak ([02 Mimari](02-mimari.md)); gereken kanca çatıda yoksa önerin.
