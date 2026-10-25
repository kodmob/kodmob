# Alt sitelerin kendi derlemeleri

Çok site (docs/gelistirici/02-mimari.md): alt siteler panelden (Yönetim > Siteler) kurulur ve varsayılan olarak
ana sitenin derlemesini kullanır (ortak tema; site adı ve logo yazısı panelde Ayarlar > Kimlik'ten). Kendi
metinleri, şablonları ya da sayfa listesi olan bir alt site için burada **kayıttaki anahtarla aynı adda**
bir klasör açılır:

```
kodmob-frontend/sites/<anahtar>/
  site.config.js          profilin site.config.js'inin üstüne (url, organization, exclude: derlenmeyecek rotalar)
  i18n/<dil>.json         sözlüğün üstüne, derin birleşir (yalnızca değişen anahtarlar)
  templates/…             aynı yoldaki şablonun yerine (ör. templates/shell/site-header.njk)
  url-history.json        derleme yazar (sitenin eski adresleri → 301), git'te kalmalı
```

`npm run build` (build:dev, build:prod) ana siteden sonra her klasörü `KM_SITE=<anahtar>` ile derler
(scripts/build-sites.mjs): sayfalar `dist/_sites/<anahtar>/`, varlıklar ortak `dist/assets/`. Sunucuda
`public_html/_sites/<anahtar>/km-manifest.json` varsa site onu, yoksa ana sitenin derlemesini kullanır.
Panel her zaman ortaktır. Yalnızca bir site: `npm run build:sites -- <anahtar>`.

Alt çizgiyle başlayan klasörler derlenmez: `_ornek/` kopyalanıp yeniden adlandırılarak başlanır.
