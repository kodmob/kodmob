# 06 Yayın

Hedef: paylaşımlı hosting (Hostinger gibi), sunucuda derleme yok, SSH olmayabilir. Ayrıntılı adımlar:
[docs/build.md](../build.md) ve paketle gelen `OKUBENI.txt`.

## Paket

```bash
node build.mjs --bump minor          # sürümü yükseltir (kodmob-frontend/package.json, config/kodmob.php, kodmob.json), test eder, paketler
node build.mjs --no-content          # içeriksiz güncelleme paketi
```

`release/` altında:

| Dosya | Nereye |
|---|---|
| `kodmob-site-v<sürüm>.zip` | `public_html/` (derlenmiş site ve panel, `api/` giriş dosyaları, `.htaccess`) |
| `kodmob-core-v<sürüm>.zip` | `public_html`'in yanına: `kodmob-core/` (Laravel, vendor dahil) |
| `ilk-kurulum/.env` | yalnızca ilk kurulumda `kodmob-core/.env` (anahtarlar hazır; gizli, paylaşmayın) |

Paket testleri (backend ve ön yüz) ve bağlantı denetimini geçmeden üretilmez.

## İlk kurulum

1. İki zip'i yerlerine açın, `.env`'de veritabanı bilgilerini doldurun.
2. PHP 8.4 / 8.5 seçin; eklentiler: `pdo_mysql`, `mbstring`, `gd`, `curl`, `fileinfo`, `openssl`.
3. Kurulum adresi: `https://alanadi/api/v1/setup?token=<SETUP_TOKEN>` → sunucu denetimi, tablolar, ilk yönetici.
4. Bitince `.env`'de `SETUP_TOKEN=` boşaltın.
5. Cron (önerilir), her dakika: `php /home/<kullanıcı>/…/kodmob-core/artisan schedule:run`. Cron yoksa işler panel
   isteklerinde ve yanıttan sonra çalışır.

## Güncelleme

Zip'leri aynı yerlere açın (`.env`, `storage/`, `public_html/media/` korunur); tablo değişikliği varsa kurulum
adresini bir kez açın (alt projelerin tablolarını da günceller). Sürüm notları [CHANGELOG](../../CHANGELOG.md).

## Alt projeler

Panelde Projeler > Yeni proje (ya da `php artisan kodmob:site create …`). Alan adı sunucuda ana siteyle aynı
klasöre yönlendirilmelidir (alt alan adı → `public_html`; başka alan adı takma ad olarak). Projeyi ayrı kuruluma
taşımak: `php artisan kodmob:site export <anahtar> --to=<klasör>`.

## Canlıda dikkat

- `php artisan config:cache` çok projeyle kullanılmaz.
- `APP_KEY` değişirse mühürlü gizli değerler (SMTP şifresi, API anahtarları, alt projelerin veritabanı şifresi)
  açılamaz: yeniden girilmeli.
- Açık riskler ve bilinen sınırlar: [dikkat.md](../dikkat.md).
