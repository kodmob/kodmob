# Araç paketleri

Bu klasördeki her alt klasör bir araç paketidir (`<anahtar>/kodmob-tool.json`). Paketler ne çatının ne
projenin dosyalarıdır: `kodmob:upgrade` dokunmaz, `kodmob:add` kurar.

```
php artisan kodmob:tool new stok-takibi --label="Stok takibi"   # yeni araç iskeleti
php artisan kodmob:add ../indirilenler/stok-takibi.zip           # paket kur (klasör, zip ya da katalog anahtarı)
php artisan kodmob:tool list                                     # kurulu ve yüklenemeyen paketler
```

Kurulu araç Araç Pazarı'nda görünür ve her projede ayrı açılıp kapanır. Panel sayfası olan paket için
`kodmob-frontend`'de yeniden derleyin. Paket biçimi, kurallar ve katkı kontrol listesi: `docs/arac-paketi.md`.
