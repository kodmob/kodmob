# 04 Sayfalar

## Site sayfası

Projenin sitesi profil klasöründedir (`kodmob-frontend/src/profiles/<ad>/`; etkin profil `kodmob-frontend/profile.js`, ortamda `KM_PROFILE`):

```
site.config.js      adres, açık modüller ve içerik türleri (profile), kurum bilgisi, sözlükler
routes.config.js    sayfa listesi (tek kaynak)
locales.js          diller, önekler, varsayılan dil
i18n/<dil>.json     metinler · i18n/url-history.json (derleme yazar; adres değişince 301)
pages/<ad>/         index.njk (şablon) + main.js (giriş betiği)
shell/              üst bar, alt bilgi
panel.js            projenin panel türleri ve sayfaları
```

**Yeni sayfa** üç adım:

1. `routes.config.js`'e satır:
   ```js
   { name: 'pricing', paths: { tr: '/fiyatlar/', en: '/pricing/' }, ...page('pricing'),
     titleKey: 'pricing.meta.title', descriptionKey: 'pricing.meta.description' },
   ```
   Alanlar: `paths` (dil → yol; olmayan dilde üretilmez), `dynamic: '<kayıt adı>'` (kayıt sayfası, `:slug`),
   `needs` (sayfaya gelen içerik: `['posts', 'courses']`), `module` (modül kapalıyken 404), `sitemap: false`,
   `noindex: true`, `data(locale)` (şablona ek veri).
2. `pages/pricing/index.njk` + `main.js` (`import { startPage } from '@/app.js'; startPage();`).
3. Metinler `i18n/tr.json`, `i18n/en.json`.

**Şablonda:** `url('blog')` adres; `t('anahtar')` derlemede sabit metin; `tx('anahtar')` panelden değişebilen metin
(Sayfalar ve Diller > Metinler). Çatının bileşenleri `kodmob-frontend/src/ui/` (ör. iletişim formu `ui/contact/contact-form.njk`).
CSP satır içi stil ve betiğe kapalıdır: stil Tailwind sınıfı ya da belirteç (`theme.css` renkleri), betik `main.js`.

**İçerik:** kabuk derlemede üretilir, içeriği (kayıtlar, listeler, SEO, yapısal veri) sunucu doldurur; JavaScript
olmadan da okunur. Önbellek yolu `render.php`'de Laravel'siz çalışır; içerik değişince önbellek temizlenir.

Çatının rotaları (panel, bileşen vitrini) `kodmob-frontend/vite/framework-routes.js`; çatı sözlüğü `kodmob-frontend/src/i18n/` (profilinki
üstüne yazar). Alt projenin kendi şablon katmanı `kodmob-frontend/sites/<anahtar>/`.

## Panel sayfası (projenin)

Çatıya dokunmadan profilin `panel.js` `PAGES`'ine yazılır (biçim `kodmob-frontend/src/panel/extension.js`):

```js
export const PAGES = [{
    key: 'reports', path: 'raporlar/:tab?', label: 'Raporlar', icon: 'chart', group: 'project',
    can: 'km_view_reports',                        // projenin modülü verir: Module::capabilities()
    guide: 'Turdaki kısa tanıtım.',
    load: () => import('./panel/reports.js'),      // export function kmReports() { return { … } }
    template: 'panel/_reports.njk',                // profil klasöründe: <div x-data="kmReports">…</div>
}];
```

Menü (yan menünün bölümünde sekme: `section`, varsayılan `content` İçerik; `inbox`, `growth`, `settings`), Tüm İşlemler ("Siteye özel" kartı), panel araması, Kullanım Kılavuzu turu, adres tablosu
(sunucu `PanelUrl` dahil) ve tembel yükleme kendiliğinden. Anahtar küçük harf ve tire; çatının sayfalarıyla
çakışamaz. Uçları çağırmak: `import { http } from '@/services/http.js'` (`http.get('/raporlar')` → `/api/v1/raporlar`).
Tablolar için dfGrid (`kodmob-frontend/src/ui/grid`), bildirim için `toast` (`kodmob-frontend/src/ui/toast.js`).

İçerik türünün panel tanımı (`TYPES`) `kodmob:module` ile yazılır; alanları (`label`, `singular`, `icon`, `slug`,
`record`, `can`, `orderable`, `featured`, `site`, `guide`) dosyanın başında açıklanır.
