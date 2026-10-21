# Yapay zekâ istem kalıpları (Kodmob'a uyarlanmış)

Kaynak: genel bir sohbetteki dört kalıp. Burada genel "sen niyet odaklı mimarsın"
yerine projenin kendi kuralları kullanılır: araç önce `CLAUDE.md`'yi okur. Köşeli parantezli yerler doldurulur.

## 1. Modül analizi (girdi / çıktı / kural / bağımlılık haritası)

```
CLAUDE.md'deki kurallara göre [modül adı, ör. Modules/Briefs] modülünü incele, kod yazma.
Çıkar:
1. Uçlar: yöntem, adres, yetki (cap:), girdi alanları ve doğrulama kuralları, yanıt biçimi.
2. Kayıtlar: yetkiler, ayarlar, işlem geçmişi olayları, zamanlanmış görevler, panel sayfaları, arama sağlayıcısı.
3. Tablolar ve dış servisler (e-posta, SMS, yapay zekâ, S3).
4. Gizli iş kuralları ve kısıtlar: güvenlik, kişisel veri, sınırlamalar (throttle), çok site kapsamı.
5. Tek paragraf niyet özeti.
6. Risk listesi: yetkisiz uç, sürüme özgü SQL, Sites::forgetMemory'de olmayan statik önbellek, testsiz yol.
```

## 2. Yeni özellik (önce etki analizi, sonra kod)

```
Niyet: [ör. randevu: müşteri saat seçer, çakışmada öneri, SMS bildirimi]
Kapsam: [her site / yalnızca ana site], modül: [çekirdek / isteğe bağlı]
Önce docs/sablonlar/etki-analizi.md'yi doldur ve kararları öneriyle sor; onaydan sonra:
- İçerik türüyse `php artisan kodmob:module` ile başla, gerisini üzerine kur.
- Kayıtları kullan (Capability, SettingDefinition, ActivityCatalog, Module::schedule); yeni uçlar cap: ile.
- Testler: backend (npm run test:db ile üç veritabanı), gerekiyorsa uçtan uca senaryo.
- docs/dikkat.md ve ilgili raporu güncelle.
```

## 2b. Yeni içerik türü: niyet dosyası

```
CLAUDE.md ve docs/gelistirici/03-modul.md'ye göre şu içerik türü için niyet dosyası yaz (JSON, kod değil):
[ör. Kurslar: seviye, toplam saat, ücret, dönem başlangıcı, çevrim içi mi; dil başına ad, kısa tanıtım, program]
Biçim docs/sablonlar/modul-niyeti.json gibi; yalnızca desteklenen alan türleri ve anahtarlar. `purpose`'a amacı ve
sitede nasıl görüneceğini yaz. Sonra `php artisan kodmob:module --intent=<dosya> --dry-run` çıktısını göster;
onaydan sonra --dry-run'sız çalıştır, üretilen testi (`tests/Project/<Ad>ContentTest.php`) koştur.
```

## 3. Mimari denetim

```
[dosya ya da klasör] için CLAUDE.md kurallarına göre denetim yap, kod değiştirme:
1. Katman ihlalleri (çekirdekten modüle bağımlılık), yan etkiler, gizli bağımlılıklar.
2. Performans: N+1 sorgu, döngüde sorgu, gereksiz tam tablo okuma, önbelleksiz pahalı iş.
3. Çok site: sabit yol, ortamdan gelen bağlantı adı, sıfırlanmayan statik önbellek.
4. Veritabanı taşınabilirliği ve güvenlik (yetki, kişisel veri, gizli değer).
Her bulgu: dosya:satır, sorun, somut düzeltme. Önem sırasıyla. Puan verme; ölçülebilir olanı söyle.
Düzeltmeyi davranışı ve uç sözleşmesini değiştirmeden öner; testi olmayan davranış için önce test.
```

## 4. Site bölümü / blok tasarımı

```
[bölüm, ör. "fiyat tablosu"] için site bölümü tasarla (tv/src). Kurallar:
- Nunjucks şablonu; metinler tx() (panelden değişir) ya da t(); veri gerekiyorsa routes.config.js `needs`.
- Stil yalnızca Tailwind sınıfları ve tasarım belirteçleri (CSP: satır içi stil yok), mobil öncelikli.
- Etkileşim Alpine bileşeniyle, ayrı dosyada; erişilebilirlik (etiketler, odak, klavye).
- Ayarlanabilir alanları şema olarak listele: ad, tür, varsayılan, hangi dilde.
Çıktı: şablon, bileşen (varsa), sözlük anahtarları (tr/en/de), şema.
```
