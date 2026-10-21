# 08 Bloklar ve sayfa düzenleyici

Panelin **Sayfa düzenleyici**si (`/panel/sayfa-duzenleyici`) sitenin gerçek sayfasını çerçevede gösterir; kullanıcı
bölümleri (blokları) sürükleyip sıralar, yazıya çift tıklayıp yazar, Jarvis'e yazdırır ve yayınlar. Geliştirici iki
şey verir: **bloklar** (hazır bölümler) ve şablonda **bölgeler** (blokların konabileceği yerler).

## Bölge

Şablonda `{{ zone('anahtar', 'Etiket') }}`. Her sayfada dört bölge hazırdır (`tv/src/layouts/base.njk`):
`site.top` / `site.bottom` (bütün sayfalar) ve `<sayfa>.top` / `<sayfa>.bottom` (yalnızca o sayfa; kayıt sayfasında
bütün kayıtlarda ortak). Kendi bölgeniz:

```njk
{# pages/home/index.njk #}
{% block content %}
{{ zone('home.main', 'Ana sayfa bölümleri') }}
{% endblock %}
```

Bölgenin ilk blokları profilin `site.config.js`'inde (`@anahtar` sözlükten; verilmeyen alan bloğun varsayılanı):

```js
zones: {
    'home.main': [
        { type: 'hero', data: { title: '@home.hero.title', lead: '@home.hero.lead' } },
        { type: 'posts' },
    ],
},
```

Derleme bu blokları kabuğa yazar (JavaScript'siz ve geliştirme sunucusunda da görünür). Panelde yayınlanan düzen
bunların yerine geçer; boş liste bölgeyi boşaltır.

## Blok

Klasör: `tv/src/blocks/<ad>/` (çatı), `tv/src/profiles/<ad>/blocks/<ad>/` (proje; aynı adla çatınınkinin yerine
geçer) ya da araç paketinde `blocks/<ad>/`.

```
block.json    etiket, açıklama, kategori, ikon, alanlar
block.html    şablon
block.css     (isteğe bağlı) src/blocks/blocks.css'e bir satırla eklenir
block.js      (isteğe bağlı) export function init(el) { …; return () => {/* bırak */} }
preview.svg   (isteğe bağlı) Keşfet ve kitaplıktaki küçük resim
```

`block.json`:

```json
{
  "label": "Sık sorulanlar",
  "description": "Açılır kapanır sorular.",
  "category": "content",
  "icon": "help",
  "library": true,
  "needs": [],
  "module": null,
  "fields": [
    { "name": "title", "type": "text", "label": "Başlık", "localized": true, "max": 120, "default": "@blocks.faq.title" },
    { "name": "items", "type": "rows", "label": "Sorular", "localized": true, "max": 12, "columns": [
      { "name": "q", "type": "text", "label": "Soru" },
      { "name": "a", "type": "textarea", "label": "Yanıt" }
    ], "default": "@blocks.faq.items" },
    { "name": "tone", "type": "select", "label": "Zemin", "options": { "plain": "Sade", "soft": "Açık" }, "default": "plain" }
  ]
}
```

- `category`: `section` (Bölümler), `content` (İçerik), `animation` (Animasyon), `commerce` (Mağaza). Keşfet ve
  kitaplık bu kategoriyle süzer.
- `library: false`: blok kitaplıkta başlamaz; Araç Pazarı › Keşfet'ten ya da düzenleyicide ilk kullanımda eklenir.
- `needs`: bloğun sayfa verisi ihtiyacı (ör. Son yazılar `["posts"]`); sunucu sayfanınkine ekler.
- `module`: modül kapalıyken blok görünmez.
- Alan türleri: `text`, `textarea`, `html` (HtmlSanitizer'dan geçer), `link` (`route:<rota>` sitenin sayfası, `/yol`,
  `#çapa`, `https://`, `mailto:`, `tel:`), `bool`, `int`, `select`, `icon` (sprite), `image` (medya), `rows` (sütunları
  bunlardan). `localized: true` alan dil başınadır; diğerleri her dilde aynı.
- `default`: düz değer ya da `@sözlük.anahtarı` (çatı sözlüğü `tv/src/i18n/<dil>.json` → `blocks.*`; profilin
  sözlüğü de olur). Eksik çeviri derlemeyi durdurur.

`block.html` küçük bir Mustache alt kümesidir (sunucuda `Modules\Site\Blocks\Template`, derlemede
`tv/src/blocks/template.js`; ikisi aynı örneklerle sınanır):

```html
<section class="container-site section-sm">
  <h2 class="h2" data-km-field="title">{{title}}</h2>
{{#items.length}}
  <div class="mt-6 flex flex-col gap-3">
{{#items}}
    <details class="card"><summary data-km-field="items.{{@index}}.q">{{q}}</summary><p data-km-field="items.{{@index}}.a">{{a}}</p></details>
{{/items}}
  </div>
{{/items.length}}
</section>
```

- `{{alan}}` kaçışlı; `{{{alan}}}` yalnızca `html` alanı için. `{{#liste}}…{{/liste}}` döngü (`@index`, `@number`,
  `@first`, `@last`), nesnede bir kez, doğru değerde bir kez; `{{^alan}}` boşsa. `{{#liste.length}}` sarmalayıcıyı bir
  kez yazar.
- `data-km-field="yol"`: düzenleyicide çift tıkla yerinde yazma (satırda `items.{{@index}}.q`). Ziyaretçiye gitmez.
- Satır içi `style` ve `<script>` yok (CSP; derleme reddeder). Değişken görünüm sınıfla: `is-{{tone}}`.
- Derlenmiş sayfada etiketler arası boşluk silinir: iki öğe arasında boşluk gerekiyorsa `&#32;`.
- Tailwind sınıfları `block.html`'den kendiliğinden toplanır; ikon `<use href="#i-ad"/>`.

Blok sunucuda çizilir (`<div class="km-block" data-km-block data-km-id>` sarmalayıcısıyla) ve sayfa önbelleğine girer;
sitede yalnızca sayfadaki blokların `block.js`'i iner (`tv/src/blocks/index.js`). Düzenleyici bloğu değiştirince
`km:blocks` olayıyla `init` yeniden çağrılır; `init`'in döndürdüğü işlev eski öğeyi bırakır.

## Sunucu

`kodmob-api/app/Modules/Site/Blocks`: `BlockLibrary` (km-blocks.json), `BlockData` (temizleme), `BlockRenderer`,
`Layouts` (taslak / yayın / geçmiş; tablo `page_layouts`), `ZoneSsr` (bölgeye yazma), `PreviewToken`, `BlockAssist`
(Jarvis). Uçlar `/admin/layouts*` ve `/admin/blocks*` (yetki `km_edit_layout`; Keşfet'te `km_manage_settings` da).
Yedek bölgeleri taşır. Testler: `tests/Feature/BlocksTest.php`, `BlockPagesTest.php`, `tv/tests/blocks.test.mjs`,
`panel-builder.test.mjs`, `e2e/specs/builder.spec.js`.

## Kayıt sayfası blokları

`block.json`'da `"scope": "entry"`: içeriği sayfadaki kayıttan (`$store.km.entry`) alan blok; düzenleyici onu yalnızca
kayıt sayfası şablonlarında (rotada `dynamic`) kitaplıkta gösterir, Jarvis sayfa önerisine almaz. Çatınınkiler
"Kayıt sayfası" kategorisinde (Rezervasyon açıkken): `entry-facts` (bilgiler), `entry-highlights`,
`entry-amenities`, `entry-itinerary`, `entry-includes`, `entry-faq`. Başlıkları düzenlenir; kayıtta o bölüm boşsa
görünmez. Tur ve otel sayfasının bölgesi `tour.sections` / `hotel.sections` (bütün kayıtlarda ortak).

Şablon bölgeye ilk blokları kendisi verebilir (profilin `zones`'u yoksa geçerli):

```njk
{{ zone('tour.sections', 'Tur bölümleri', ['entry-facts', 'entry-highlights', 'entry-itinerary']) }}
```

## Sayfa metninden bloğa geçiş

`tx()` ile yazılmış bir sayfa bloklara çevrilirken (ör. kodmob.com ana sayfası) bölgenin ilk blokları metinlerini
aynı sözlük anahtarlarından alır (`@home.hero.title`). Derleme her ilk bloğa `texts` yazar (alan yolu → anahtar:
`title` → `home.hero.title`, satırda `checks.0.text` → `home.hero.checks.0`; düz metin listesi tek metin sütunlu
satırlara dönüşür). Sunucu panelde değiştirilmiş metinleri (`Content\PageTexts`) bununla ilk bloklara taşır
(`Blocks\ZoneDefaults`): bölge düzenlenmemişse sitede, düzenleyicide de ilk halinde görünürler.

## Blok sayfası

Panelden açılan, içeriği yalnızca bloklardan oluşan sayfa (kampanya, etkinlik…): içerik türü `pages`
(`Modules/Site/Pages/PageType`; her projede). Şablonu çatının `tv/src/pages/page/index.njk`'si; tek bölgesi
`page.main` ve her kayıt kendi bölgesini kullanır: `page.main@<kimlik>`. Düzenleyicinin uçları bu adı alır
(`PUT /admin/layouts/page.main@12`); bölge yalnızca o kayıt varken yazılır.

- Adres kökte (`/kampanya/`, `/en/campaign/`): rota `page`, kalıp `/:slug/`, `last: true`. Sunucu `last` kalıbını
  en son dener (`Router::findLast`), böylece sitenin sayfaları ve eski adres yönlendirmeleri önce eşlenir. Kayıt
  yoksa sayfa üretimi 404 verir.
- Sitenin sayfasının, klasörlerinin (`LanguageService::RESERVED`) ve dil öneklerinin adresi verilemez
  (`PageType::validate`).
- Bağlantı alanında blok sayfası `page:<kimlik>`: `BlockRenderer` o dildeki yayındaki adrese çevirir; sayfa o dilde
  yayında değilse bağlantı boştur.
- Düzenleyici `GET /admin/layouts` yanıtında blok sayfalarını `pages` listesinde `page@<kimlik>` adıyla (`record`:
  kimlik, durum, diller) ve bölgelerini `zones`'da verir; `POST /admin/layouts/publish` `{ pages: [kimlik] }` taslak
  sayfayı da yayına alır.
- Sayfanın üst / alt bölgesi (`<rota>.top|bottom`) temel düzenin `zoneTop` / `zoneBottom` bloklarındadır; blok
  sayfası şablonu bunları boşaltır (içeriği zaten bloklar).
