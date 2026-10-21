# Merhaba

Kodmob aracı. Ne işe yaradığını, kimin için olduğunu buraya yazın.

## Kurulum

```
cd kodmob-api
php artisan kodmob:add <bu paketin klasörü ya da zip'i>
php artisan migrate          # aracın tabloları varsa
cd ../tv && npm run build    # panel sayfası derlemeye girer
```

Kurulunca Araç Pazarı'nda görünür; her projede ayrı açılıp kapanır.

## Geliştirme

- `php/`: modül (`MerhabaModule`: yetkiler, ayarlar, olaylar), uçlar (`routes.php`), controller'lar
- `database/migrations/`: tablolar (SQLite, MySQL ve PostgreSQL'de çalışacak biçimde)
- `panel/`: sayfalar (`index.js` `PAGES`), bileşenler, şablonlar
- `tests/`: `cd kodmob-api && php artisan test --filter=Merhaba`

Kurallar ve katkı kontrol listesi: Kodmob'un `docs/arac-paketi.md` belgesi.

## Lisans

MIT, bkz. LICENSE.
