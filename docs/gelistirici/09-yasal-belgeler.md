# 09 · Yasal belgeler

Gizlilik politikası, KVKK aydınlatma metni, çerez politikası ve kullanım koşulları her projede vardır. Modüller kendi
belgelerini getirir: Talepler iletişim aydınlatma metnini, Bülten ticari ileti onayını, Mağaza mesafeli satış
sözleşmesini, ön bilgilendirme formunu, teslimat ve iade koşullarını ve müşteri aydınlatma metnini, Rezervasyon
rezervasyon koşullarını. Modül projede kapalıyken belgesi 404 döner, listelerde ve site haritasında görünmez; Araç
Pazarı'ndan eklenince derlemesiz gelir. Genel belgelerdeki modül bölümleri de (ör. gizlilik politikasında "Alışveriş
yaptığınızda") modül açıkken görünür.

## Adresler

| Sayfa | tr | en | de |
|---|---|---|---|
| Özet (rota `legal`) | `/yasal/` | `/en/legal/` | `/de/rechtliches/` |
| Belge (rota = belgenin adı) | `/yasal/<slug>/` | `/en/legal/<slug>/` | `/de/rechtliches/<slug>/` |

Rota adları kalıcıdır (`privacy`, `kvkk`, `cookies`, `terms`, `contactNotice`, `newsletterConsent`, `shopTerms`,
`preInformation`, `delivery`, `returns`, `orderNotice`, `bookingTerms`): şablonda `url('privacy')`. Adres değişirse
adres geçmişi eskisini 301 ile yeniye yönlendirir.

## Belge yazmak

```
kodmob-frontend/src/legal/<ad>/doc.json      { "group": "commerce", "order": 10, "module": "commerce", "updated": "2026-10-21",
                                  "paths": { "tr": "mesafeli-satis-sozlesmesi", "en": "…", "de": "…" } }
kodmob-frontend/src/legal/<ad>/tr.html       şablon (blokların şablon dili: {{yol}}, {{{yol}}}, {{#yol}}…{{/yol}}, {{^yol}}…{{/yol}})
kodmob-frontend/src/legal/<ad>/en.html …
```

- Başlık, açıklama ve sayfa girişi sözlükte: `legal.<ad>.title`, `.description`, `.lead` (kodmob-frontend/src/i18n).
- Bölümler `<h2>`: sunucu kimlik verir, içindekileri ve numaraları üretir. Tablo `<div class="table-wrap">` içinde.
- Grup: `general`, `contact`, `newsletter`, `commerce`, `booking` (`legal.groups.<grup>`).
- Satır içi stil ve betik olamaz (CSP); derleme hatalı tanımda durur (`vite/legal.js`).

**Profil** aynı adlı klasörle çatının belgesini değiştirir (doc.json alanları üstüne yazar, şablon dil başına) ya da
yeni belge ekler (`kodmob-frontend/src/profiles/<ad>/legal/<belge>/`). Profil rotalarında aynı adla kendi sayfasını yazarsa çatının
rotası alınmaz.

## Şablonun verisi

| Yol | Nereden |
|---|---|
| `site.name`, `site.url` | sitenin adı ve adresi |
| `controller.name`, `.address`, `.email`, `.court` | Proje Ayarları › Yasal (boşsa o dilde köşeli parantezli uyarı) |
| `controller.phone`, `.tax`, `.mersis`, `.kep` | Proje Ayarları (boşsa boş: `{{#controller.kep}}…{{/controller.kep}}`) |
| `{{{controllerCard}}}` | veri sorumlusu kutusu (kaçışlı HTML) |
| `modules.<anahtar>` | açık isteğe bağlı modüller |
| `links.<belge>` | açık belgenin o dildeki adresi (kapalıysa yok: `{{#links.shopTerms}}…{{/links.shopTerms}}`) |
| `retention.security`, `retention.briefs` | Güvenlik ve Talepler modüllerinin `legal()` kancası |
| `commerce.currency`, `.shippingFee`, `.freeOver`, `.bank`, `.cod`, `.codFee`, `.payments` | Mağaza'nın `legal()` kancası |

Yeni modül metne değer vermek için `Core\Module::legal(string $locale): array` kancasını yazar; dönen dizi veriye
birleştirilir (`['retention' => ['orders' => 24]]` → `{{retention.orders}}`).

## Sunucu ve panel

- Derleme `km-legal.json` yazar (belgeler, dillerdeki başlık ve açıklama, şablonlar). `Modules\Site\Legal`:
  `LegalDocs` (liste, açık olanlar), `LegalData` (veri), `LegalPages` (çizim, özelleştirme, yedek), `LegalSsr` (sayfanın
  `<!--km:legal-…-->` işaretleri: gövde, içindekiler, yan menü, tarih, özet).
- Panel › Ayarlar › **Yasal** (`/panel/yasal-belgeler`, yetki `km_manage_legal`): belge ve dil başına "Şablon"
  ya da "Özelleştirildi"; Düzenle ile metin o dilde sabitlenir (`legal_documents`), "Şablona dön" siler. Özelleştirilmiş
  metne sonradan açılan modülün bölümü eklenmez.
- Formların onay bağlantıları belgeye gider: iletişim ve brif formu `contactNotice`, Bülten bloğu `newsletterConsent`,
  ödeme adımı `shopTerms` ve `preInformation`.

Şablonlar hukuki görüş değildir; projeyi yayına almadan önce gerçek veri işleme durumuna göre bir hukukçuya kontrol
ettirin.
