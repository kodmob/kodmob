# Değişiklikler

Kodmob'un sürümleri. Biçim: her sürümde **Eklendi**, **Değişti**, **Düzeltildi**, **Kaldırıldı**; canlıya geçişte
yapılacaklar **Yükseltme** başlığında. Sürüm numarası `tv/package.json`, `kodmob-api/config/kodmob.php` ve
`kodmob.json`'da aynıdır (`node build.mjs --bump minor` üçünü birlikte yükseltir).

## 3.0.0 — 2026-10-22

İlk açık kaynak sürüm (MIT): [github.com/kodmob/kodmob](https://github.com/kodmob/kodmob), geliştirici belgeleri
[kodmob.github.io/kodmob](https://kodmob.github.io/kodmob/). 2.5'ten bu yana Sayfa düzenleyici ve bloklar, Mağaza,
Rezervasyon ve Oteller, Bülten, yasal belgeler, Jarvis Operatör, sözleşme ve OpenAPI eklendi.

**Eklendi**
- Açık kaynak: `LICENSE` (MIT; "Kodmob" adı ve logosu lisansa dahil değil), `SECURITY.md` (açık GitHub'da özel
  bildirimle), `CONTRIBUTING.md`, geliştirici belgeleri sitesi (`docs/`, VitePress; GitHub Pages iş akışı
  `.github/workflows/docs.yml`), README baştan (Türkçe + İngilizce özet).
- Profilin kendi testleri: `tv/src/profiles/<ad>/tests/*.test.mjs` (`npm test` çalıştırır); başlangıç profilinde
  rota ve sözlük denetimi.
- Uçtan uca senaryolarda `requires(request, { types, modules })`: istediği içerik türü ya da modül derlemede yoksa
  senaryo nedeniyle atlanır (başlangıç profilinde portföy türü, SMS, Jarvis yok).
- Sayfa düzenleyici (`/panel/sayfa-duzenleyici`): sitenin gerçek sayfası önizlemede; blokları sürükle-bırak, yazıya
  çift tıklayıp yerinde yazma, cihaz genişlikleri, dil, gizle / çoğalt / taşı, sayfalar arası kopyala-yapıştır, geri al,
  taslak (kendiliğinden kaydedilir) ve Yayınla, bölge başına yayın geçmişi. Jarvis: bloğu isteğe göre yaz, diğer
  dillere çevir, sayfaya bölüm öner. Yetki `km_edit_layout` (Editör rolünde).
- Bloklar ve bölgeler: `tv/src/blocks/<ad>/`, şablonda `zone()`, profilin `site.config.js` `zones`'u; derleme
  `km-blocks.json`. Bloklar: Karşılama, Özellik kartları, Süreç adımları, Son yazılar, Çağrı bandı, Metin, Rakamlar,
  Logo şeridi, Müşteri yorumları, Sık sorulanlar, Güven rozetleri, Görsel ve metin; Mağaza: Öne çıkan ürünler / turlar;
  Animasyon: Kayan etiketler, Harf animasyonlu başlık. Belge: [docs/gelistirici/08-bloklar.md](docs/gelistirici/08-bloklar.md).
- Araç Pazarı › Keşfet'te bloklar (kategorileriyle) ve "Kitaplığa ekle".
- Tur ve otel sayfasının bölümleri bloklardan: "Kayıt sayfası" blokları (Bilgiler, Öne çıkanlar, Olanaklar, Program,
  Dahil olanlar, Sık sorulanlar; içerik kayıttan) Sayfa düzenleyicide sıralanır, gizlenir, başlıkları değişir.
  Blok tanımında `scope: entry`; şablon bölgeye ilk blokları verebilir (`zone(anahtar, etiket, [bloklar])`).
- Oteller (araç `hotels`, Rezervasyon'a takılı) ve gece birimi (`night`): giriş – çıkış, oda sayısı, yetişkin /
  çocuk; oda tipi × pansiyon fiyat matrisi (sadece oda, oda kahvaltı, yarım / tam pansiyon, her şey dahil), oda tipi
  başına adet ve toplam oda sayısı, en az gece, giriş / çıkış saati, olanaklar; sitede otel listesi ve sayfası
  (`hotelRoutes()`), yapısal veri `Hotel`. `kodmob:module … --bookable=night`.
- kodmob.com ana sayfası bloklardan: Görselli karşılama, Referans logoları, Öne çıkan projeler, Hizmet kartları,
  Neden biz (profilin blokları; `tv/src/profiles/kodmob/blocks/`) ve çatının Süreç adımları, Son yazılar, Çağrı
  bandı. Metinler'de değiştirilmiş ana sayfa metinleri bloklara taşınır (bloğun ilk halinde sözlük anahtarı).
  Çağrı bandına isteğe bağlı ikinci düğme.
- Blok sayfaları: Sayfa düzenleyicide **Yeni sayfa** ile yalnızca bloklardan oluşan sayfa (kampanya, etkinlik…);
  adres kökte (`/kampanya/`), taslak açılır, Yayınla ile yayına girer. Bağlantı alanlarında seçilebilir; site
  haritası, arama ve yedekte. İçerik türü `pages` (her projede).
- Rezervasyon profilleri: rezerve edilebilen her tür (`Bookable`) birimini seçer (kişi / gün / sabit fiyat) ve
  özelliklerini (paketler, grup, sezon, erken rezervasyon, kapasite, tarihler) ve sayfa bölümlerini (program, dahil /
  hariç, SSS…). Yeni rezervasyon kutusu: paket kartları, sayaçlar, grup kademeleri, özet; telefonda alttan açılan
  sayfa. `kodmob:module … --bookable=person|day|fixed`.
- Mağaza (commerce), Stripe, PayTR ve Rezervasyon modülleri.
- Yedekte kişisel kayıtlar: siparişler, ödemeler, kuponlar, rezervasyonlar, talepler ve bülten aboneleri (modül kancası
  `Module::backup()`). Otomatik yedekte ve geri yükleme öncesi anlık yedekte hep var; elle indirilen yedeğe "Kişisel
  kayıtları da ekle" ile girer; geri yüklemede ayrıca seçilir (seçilmezse sitedeki kayıtlar kalır). Kimlikler korunur
  (sipariş numarası aynı), siparişteki ürün geri yüklenen ürüne bağlanır.
- Uzak depoya giden yedek şifreli: yedek şifresiyle AES-256-GCM (`.zip.kmbak`; anahtar kurulumdan bağımsız). Yeni
  sunucuda dosya Yedekleme'den yüklenip (ya da otomatik yedek klasörüne konup) şifreyle geri yüklenir; otomatik yedeğin
  zip'i de Dosyadan geri yükle ile yüklenebilir.
- Niyetten içerik türü: `php artisan kodmob:module --intent=kurslar.intent.json` (alanlar ve amaç tek JSON
  dosyasında; yapay zekâ yazabilir). Örnek `docs/sablonlar/modul-niyeti.json`.
- Jarvis Operatör: Jarvis panel uçlarından üretilen araçlarla SEO durumunu, 404 ve kırık bağlantıları, içerik
  listesini, sistem ve çeviri sağlığını, yasal belgeleri okur; yönlendirme ekleme ve yazı çevirisi gibi işleri önerir,
  sohbette çıkan onay kartıyla onaylarsanız sizin adınıza ve yetkinizle yapar (İşlem Geçmişi'nde sizin adınızla).
  Kişisel veri (talepler, siparişler, aboneler) Jarvis'e gitmez.
- Çatının sözleşmesi ve OpenAPI: `php artisan kodmob:contracts` modülleri, uçları, yetkileri, ayarları, olayları ve
  zamanlanmış görevleri koddan `docs/contracts/`'a yazar; test depodakiyle karşılaştırır. OpenAPI 3.1 herkese açık
  değil: panelde Araç Pazarı › "API belgesi" (yalnızca ayar yetkisi olan). Bildirimsel istek şemaları
  (`Core\Http\Schema`): kullanıcı ve SEO yönlendirmesi uçları; aynı tanım doğrular ve belgeler.
- Yasal belgeler: gizlilik politikası, KVKK aydınlatma
  metni, çerez politikası ve kullanım koşulları her projede; modül açılınca kendi belgeleri gelir (Talepler: iletişim
  aydınlatma metni; Bülten: ticari ileti onayı; Mağaza: mesafeli satış sözleşmesi, ön bilgilendirme formu, teslimat,
  iade ve müşteri aydınlatma metni; Rezervasyon: rezervasyon koşulları) ve genel belgelerdeki bölümleri görünür.
  Türkçe, İngilizce, Almanca şablonlar; firma bilgileri ve modül değerleri (kargo ücreti, saklama süreleri, ödeme
  yöntemleri) ayarlardan. Adresler `/yasal/` altında (özet sayfası, yan menü, içindekiler); panelde Temel ›
  **Yasal belgeler**: dil başına özelleştir, şablona dön. Yetki `km_manage_legal`; Proje Ayarları › Yasal'a MERSİS,
  KEP, yetkili mahkeme. Modüller metne `Module::legal()` kancasıyla değer verir.
- Bülten (araç `newsletter`): sitede "Bülten aboneliği" bloğu ve
  Bülten sayfası (`/bulten/`); abonelik her zaman çift onaylı (onay e-postası, onayın tarihi ve IP özeti), her
  e-postada abonelikten çıkma bağlantısı ve tek tık çıkma (List-Unsubscribe). Panelde `/panel/bulten`: bülten yaz,
  kendine deneme gönder, onaylı abonelere (dile göre) gönder; saatlik sınırla parça parça, duraklat / devam et,
  e-posta hatasında kendiliğinden durur. Aboneler tablosu (CSV, ekle, kalıcı sil). Yetki `km_manage_newsletter`.
- Araç Pazarı › Keşfet'te **Projeye ekle**: çatının araçları (Mağaza, Rezervasyon, Oteller, Jarvis…) derlemeden ve
  profili değiştirmeden bu projeye eklenir; takıldığı araçlar birlikte gelir (Oteller → Mağaza, Rezervasyon).
  Kurulu sekmesinde **Projeden çıkar** (verisi silinmez; önce ona takılı olan çıkarılır). Kayıt projenin
  `storage/framework/modules.json`'ında (`added`), işlem geçmişinde `tools.installed`.

**Değişti**
- Çatının testleri projenin koduna bağlı değil: backend testleri kendi içerik türleriyle (`tests/Support/Portfolio`;
  `TestCase::CONTENT_TYPES`), ön yüz birim testleri test profiliyle (`tv/tests/fixtures/profile`; `KM_PROFILE_DIR`)
  çalışır. Projenin `app/Project`'i ve sitenin profili silinse de değişse de çatının testleri geçer.
- Yerel bilgisayara ve projeye özgü yapay zekâ notları `CLAUDE.local.md`'de (git dışı); `CLAUDE.md` genel.
- Başlangıç profilinin ana sayfası bloklardan (Sayfa düzenleyici'den değişir).
- Yasal sayfalar profilden çatıya geçti: `/gizlilik/`, `/cerezler/`, `/kvkk/` ve Mağaza'nın `/satis-sozlesmesi/`'i
  301 ile `/yasal/…` adreslerine gider. kodmob.com'un elle yazılmış metinleri yerine genel şablonlar (eski metinler
  git geçmişinde); panelde Metinler'deki `shop.terms.*` kalktı. Ödeme adımında sözleşme ve ön bilgilendirme formu ayrı
  bağlantı; iletişim ve brif formunun onay bağlantısı iletişim aydınlatma metnine.
- Çatının araçlarının site sayfaları (Mağaza, Sepet, Ödeme, Turlar, Oteller…) her profilin derlemesinde; araç
  kapalıyken 404 ve site haritası dışı. Profil aynı adlı rotayı kendisi yazdıysa onunki geçer; istemeyen profil
  `site.config.js`'te `toolPages: false`.
- Kökteki tek parçalı bilinmeyen adres (`/x/`) artık blok sayfası adayı: sitenin sayfaları ve eski adres
  yönlendirmeleri önce eşlenir, kayıt yoksa yine 404. Önizleme çerçevesinde özel imleç efekti kapalı.
- Yedek sayfa düzenini (bölgeler) de taşır.

**Düzeltildi**
- Sayfa düzenleyici panele dönüp yeniden açılınca "Sayfa açılıyor…"da kalıyordu (yenileyince düzeliyordu): kapanan
  görünümün bayrağı panelin kök bileşenine yazılıyordu. Aynı kalıp başka panel sayfalarında da (olay dinleyicileri)
  düzeltildi; birim testi bileşenlerde tanımsız özelliğe atamayı yakalar.
- Siteler: panel bu bilgisayardaysa yeni siteye "Yerel deneme adresi" (`anahtar.localhost`) eklenir, "Panele git"
  yerelde onu açar; açılmayacak alan adı (`deneme.127.0.0.1`) açıklamalı hatayla reddedilir.
- Sayfalar HTML'de `no-store` taşıyordu: geri / ileri önbelleği çalışmıyordu ve değişmeyen sayfa her seferinde
  baştan iniyordu. Artık `no-cache` + `Last-Modified` / `ETag` (304); panel saklanmaz. Açılışta sayan rakamlar
  ve kaydırınca belirme daha az yerleşim yaptırır.
- Paneldeki satırlarda (tur paketleri) para sütunu kuruş olarak görünüyordu; düzenleyip kaydetmek fiyatı 100 ile
  çarpıyordu. Satırların sütun adları artık her hücrenin üstünde.
- Sayfa düzenleyicinin sayfa seçicisi ve Yayınla menüsü sahnenin altında kalıyordu.
- Sayfa düzenleyici: seçim kalkarken konsolda "Cannot read properties of null (reading 'zone')" hatası.
- Bloktaki bağlantı projede kapalı aracın sayfasına (rotanın modülü) gitmiyor.
- Testler uygulama açılırken geçici depolamada: yerel sitenin araç ayarı (Araç Pazarı) testleri etkilemiyor.
- `kodmob:db-move` MySQL'de sunucudaki bütün veritabanlarını taramıyor (yalnızca kendi şeması); büyük MySQL
  sunucularında dakikalarca sürüyordu.
- Mağaza testleri sipariş numarasını kimlik 1 varsaymıyor (MySQL ve PostgreSQL).

**Yükseltme**
- Uzak depoya yedek gönderiliyorsa Sistem Ayarları › Yedekleme'de **yedek şifresi** girin (en az 12 karakter); girilene
  kadar uzak depoya yedek gönderilmez (Sistem Sağlığı uyarır). Şifreyi başka yerde saklayın.
- Site kökündeki `.htaccess` yenisiyle değiştirilmeli (önizleme başlıkları, `km-blocks.json` erişimi, HTML önbellek
  başlığı); kurulum
  adresi yeni tabloları (`page_layouts`, `page_layout_revisions`) kurar.

## 2.5.0 — 2026-10-20

Açık kaynak başlangıç çatısına geçiş: proje ile çatı ayrıldı, panel proje odaklı sadeleşti, araçlar paket oldu.

**Eklendi**
- Panel: üstte proje seçici; projeler arası tek oturumla geçiş (aynı e-postalı yönetici, 60 sn'lik tek kullanımlık anahtar).
- Panel: Proje Ayarları ve Sistem Ayarları (ayar tanımında `scope`); Tüm İşlemler üst barda.
- Araç Pazarı (`/panel/araclar`): araçlar her projede ayrı açılıp kapanır; Keşfet'te eklenebilecek araçlar ve topluluk kataloğu.
- Araç paketleri: `kodmob-api/extensions/<anahtar>/`, `kodmob:tool new | check | list`, `kodmob:add` (klasör, zip, katalog;
  sha256 denetimi). Belge: [docs/arac-paketi.md](docs/arac-paketi.md).
- Alt projeler e-posta, SMS ve yapay zekâ ayarını ana projeden kullanır.
- Proje klasörleri: `app/Project`, `config/project.php`, `routes/project.php`, `tests/Project`, profilin `panel.js`'i
  (projenin panel sayfaları ve içerik türleri). Çatı projenin sınıfını bilmez.
- Site profili klasörü (`tv/src/profiles/<ad>/`) ve sade başlangıç profili (`starter`: ana sayfa, blog, iletişim formu,
  yasal metinler, 404; tr + en).
- Kurulum ve güncelleme: `kodmob:install` (yeni projenin yerel kurulumu, SQLite), `kodmob.json`, `kodmob:pack`,
  `kodmob:lock`, `kodmob:upgrade` (yalnızca projede değişmemiş çatı dosyalarını yeniler).
- SEO > Yapay zekâ: yapay zekâ botlarının ziyaretleri, site denetimi, içerik hazırlık puanı.
- Site Sihirbazı: işletme bilgisinden yapay zekâ önerisiyle yeni alt site.
- Kullanım Kılavuzu (tur ve rehberler) ve Sistem Sağlığı (kartlar, hata kodları, Bildir); `kodmob:health`.
- Geliştirici belgeleri ([docs/gelistirici/](docs/gelistirici/README.md)) ve bu dosya.

**Değişti**
- Yan menü: Genel bakış, Temel (Blog, Talepler, Sayfalar ve Diller, SEO), Proje modülleri, Proje Ayarları, altta Sistem.
  Eski `/panel/ayarlar/…` adresleri yenisine açılır; kaydedilmiş rol menüleri değişmez.
- İsteğe bağlı modüller sitenin profilinden seçilir (`profile.modules`); domain arama Jarvis'ten ayrı modül; SMS ve
  çok proje isteğe bağlı.
- Yerel geliştirme MAMP'sız: SQLite ve PHP'nin kendi sunucusu.
- İstek sınırları yol başına sayılır.

**Kaldırıldı**
- WordPress REST hata kodları (ön yüz `errors.js`).

**Yükseltme**
- İki zip'i her zamanki yerlerine açın; kurulum adresini bir kez açın: yeni tablolar (`seo_ai_hits`, `handoff_tokens`)
  ana ve alt projelerde oluşur.

## 2.4.0 — 2026-10-18

**Eklendi**
- Ayarlar > Kimlik: site adı, logo (yazı ya da görsel), ana renk, koyu bant rengi (`/api/v1/theme.css`).
- Ayarlar > Yapay zekâ: Claude, OpenAI, Gemini (eski Jarvis anahtarı kendiliğinden taşınır).
- Diğer İşlemler sayfası ve kişiye özel Hızlı İşlemler; üst barda panel araması (Ctrl+K).
- Çok site: tek kurulumdan alt siteler (alan adıyla seçilir, ayrı veritabanı ve depolama), `kodmob:site`, site başına
  zamanlanmış görevler, `kodmob:site export`.
- SQLite, MySQL / MariaDB ve PostgreSQL desteği; `kodmob:db-move`.
- Mimari kural testleri (ArchitectureTest), statik analiz (Larastan), CLAUDE.md / AGENTS.md.

**Yükseltme**
- `public_html/.htaccess` yeni haliyle yüklenmeli (`_sites` kuralı); kurulum adresi yeni sütunları kurar.

## 2.3.0 — 2026-10-16

**Eklendi**
- Arka plan işleri ve SMTP (Ayarlar > E-posta, Arka plan), iki adımlı doğrulama, HttpOnly çerezli oturum yenileme.
- Otomatik ve uzak (S3) yedek, SMS (NetGSM), kullanıcılara SMS ve e-posta.
- İçerik türü kaydı ve `kodmob:module` üreticisi; uçtan uca testler (Playwright) ve CI.

## 2.2.1 — 2026-10-16

**Düzeltildi**
- Panel düzenleyicisi tabloları düzleştiriyordu.

## 2.2.0 — 2026-10-16

**Eklendi**
- Çoklu dil: Türkçe öneksiz adresler (eski `/tr/…` 301), içerik çevirileri (dil başına yayın, blog çeviri grupları,
  Jarvis ile çeviri), adresi değişen kayıt 301, silinen 410, yol gösteren 404, kırık bağlantı denetimi, Çoklu Dil sayfası.

## 2.1.0 — 2026-10-15

**Değişti**
- Panel adreslerinde `#` yok (`/panel/projeler/12`); eski bağlantılar kendiliğinden çevrilir. Dil kaydı tablosu.

## 2.0.0 — 2026-10-15

İlk Laravel sürümü (WordPress'ten geçiş): roller ve yetkiler, işlem geçmişi, oturumlar, güvenlik, SEO modülü
(robots.txt panelden), Jarvis ve domain arama, dfGrid tabloları, canlı bildirimler.
