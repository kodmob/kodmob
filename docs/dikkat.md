# Dikkat Edilecekler

"Yapıldı, **ama**…" listesi. Her madde: durum → risk → ne yapmalı.
Çatının bilinen sınırları; projenin kendi "yapıldı, ama…" notları da buraya yazılır.

## Canlıya çıkış

- **Her sürüm geçişinde yeni tablolar gelebilir, ama sunucuda kendiliğinden oluşmaz.**
  → Zip'leri açtıktan sonra kurulum adresini (`/api/v1/setup?token=…`) bir kez açın. Bitince `.env`'deki `SETUP_TOKEN` satırını boşaltın.
- **Arama motorlarına açıklık tek anahtara bağlı: `kodmob-frontend/.env.production` `SITE_INDEXABLE` (şu an `true`).** Sayfaların noindex başlığını ve robots.txt'yi belirler. Bir deneme alanına paket yüklenecekse önce `false` yapıp yeniden build alın. Panel her zaman noindex.
- **Güncellemede zip'ler üzerine açılıyor, ama bazı klasörler silinirse veri gider.** `kodmob-core/.env`, `kodmob-core/storage/` ve `public_html/media/` korunmalı.
- **Zamanlanmış işler ve e-posta kuyruğu var, ama cron kurulmazsa eksik çalışırlar.** Cron yokken e-postalar yanıttan sonra aynı istekte gider (kaybolmaz ama başarısız olursa yeniden denenmez); Jarvis taramaları yalnızca panel açıkken ilerler; eski kayıtların silinmesi ve 404 kayıtlarının içeri alınması hiç çalışmaz. Durum: Ayarlar > Arka plan (son cron, bekleyen ve başarısız işler).
  → Hostinger > Cron Jobs: her dakika `schedule:run` (OKUBENI 9. adım). Kurduktan bir dakika sonra Ayarlar > Arka plan "Çalışıyor" demeli.
- **E-posta "sendmail" ile gidiyor, ama Hostinger'da çalışacağı denenmedi.** Panelde Ayarlar > E-posta > "Deneme gönder" sonucu ve hatası gösterir; gelmezse aynı sayfada SMTP girilir (smtp.hostinger.com, 465, SSL). Yerelde yalnızca "log" ve ulaşılamayan sunucuyla denendi.
- **Yapay zekâ OpenAI ve Gemini ile gerçek anahtarla hiç denenmedi; Claude yolu önceki koddan aynen taşındı.** İstek biçimleri sağlayıcıların resmî belgelerine göre (OpenAI Responses API, Gemini generateContent) ve testlerde sahte yanıtlarla sınandı. Önerilen model adları 18 Ekim 2026 tarihli; eskirse Ayarlar > Yapay zekâ'da "Modelleri getir" güncel listeyi çeker ya da model elle yazılır. Eski Jarvis Claude anahtarı yeni yapıya kendiliğinden taşınır.
- **SMS (NetGSM) gerçek hesapla hiç denenmedi.** İstek biçimi NetGSM'in resmi SDK'sıyla aynı (`/sms/rest/v2/send`, `/msgheader`, Basic auth) ve testlerde sahte yanıtla sınandı. Canlıda: Ayarlar > SMS'te kullanıcı adı, API şifresi, onaylı başlık → "Bağlantıyı dene" (kredi harcamaz) → "Deneme gönder". NetGSM panelinde IP kısıtlaması açıksa Hostinger sunucusunun IP'si izinli olmalı (yoksa hata kodu 30). SMS kapalıyken Kullanıcılar'da SMS düğmesi görünmez. Gönderim yetkisi yeni: **"Kullanıcılara SMS ve e-posta gönderme"** (`km_message_users`); Yönetici'de var, diğer rollere Roller ve İzinler'den verilir.
- **SMS metninde emoji ve GSM dışı karakter gönderilmez** (panel yazarken uyarır, sunucu reddeder); Türkçe harfli mesajda boy 155, birleşik mesajda 150 karakter. Boy sayısı operatöre göre değişebilir; panel "yaklaşık" gösterir. Mesaj metni ve numaranın tamamı hiçbir yerde saklanmaz (kayıtta `532*****67`).
  → Canlıda ilk iş deneme e-postası; ardından sitede bir deneme talebi gönderip Son gönderimler'de iki satır (bildirim, yanıt) görün.

## Çok site (ana site + alt siteler)

- **Alt siteler panelden (Yönetim > Siteler) ya da `php artisan kodmob:site create …` ile kuruluyor, ama alan adı yönlendirmesi elle.** Kurulumdan sonra alan adı sunucuda ana siteyle aynı klasöre bakmalı (Hostinger: alt alan adı → `public_html`; başka alan adı nameserver'ı Hostinger'a çevrilip ana siteye takma ad olarak eklenir). Yönlendirilmeden önce alan adı açılmaz; yönlendirildikten sonra kayıtta yoksa ana siteyi açar. Ayrıntı: [06 Yayın](gelistirici/06-yayin.md).
- **Yeni sürüm yüklenince alt sitelerin tabloları da güncellenmeli.** Ana sitenin kurulum adresi (`/api/v1/setup?token=…`) bunu kendisi yapar ve sonucu site site gösterir; panelde Siteler'de "Tümünü güncelle" ya da `php artisan kodmob:site migrate`.
- **"Kayıttan çıkar" veriyi silmez.** Alt sitenin veritabanı `kodmob-core/storage/sites/<anahtar>/` (SQLite) ya da kendi veritabanında, görselleri `public_html/media/<anahtar>/`'da kalır; tamamen silmek elle. Aynı anahtarla yeniden kurulunca site verisiyle geri gelir (ilk yöneticinin şifresi formdakiyle değişir).
- **Alt sitenin MySQL/PostgreSQL şifresi `APP_KEY` ile şifreli saklanıyor** (`storage/sites.json`). `APP_KEY` değişirse alt sitelerin veritabanı bağlantısı kopar; SQLite alt siteler etkilenmez.
- **Site Sihirbazı (Siteler > Yeni site) yapay zekâyla hiç denenmedi; testler sahte motorla.** Ayarlar > Yapay zekâ'da anahtar girilince ilk önerinin metinleri gözden geçirilmeli. Sihirbaz rakam, yıl, müşteri adı uydurmamak üzere yönlendiriliyor, ama yapay zekâ yine de hata yapabilir: kurulumdan sonra yeni sitenin panelinde Çoklu Dil > Metinler'den kontrol edin (özellikle ana sayfadaki istatistik kutuları). Hizmetler taslak olarak eklenir; yayınlanmaları gerekir.
- **Sihirbazın yapay zekâ maliyeti:** öneri başına bir büyük çağrı (148 metin) + seçilen her ek dil için bir çeviri çağrısı. Anahtar ana sitenin (alt projeler de ana projenin yapay zekâ ayarını kullanır).
- **Alt site varsayılan olarak ana sitenin derlemesini kullanıyor (ortak tema): adresleri kendisinin; site adı, logo (yazı ya da görsel), ana renk ve koyu bant rengi alt sitenin panelinde Ayarlar > Kimlik'ten verilmeli, verilmezse ana sitenin adı ve rengi görünür.** Sayfa metinleri (ana sayfa başlıkları vb.) alt sitenin panelinde Çoklu Dil > Metinler'den değişir. Şablon ve yerleşim farkı için alt sitenin kendi derlemesi: `kodmob-frontend/sites/<anahtar>/` (bkz. `kodmob-frontend/sites/README.md`), sonra build + yükleme.
- **Renkler `theme.css` ile geliyor (`/api/v1/theme.css?v=…`), ama yalnızca belirteçlere bağlı yerlerde.** Ana renkten açık/koyu tonlar tarayıcıda türetilir (color-mix: 2023 sonrası tarayıcılar). WhatsApp düğmesi, cihaz çizimleri ve panelin kendi renkleri değişmez. Çok açık bir ana renk seçilirse düğmedeki beyaz yazı okunmaz; panel uyarmıyor.
- **Kimlik ayarı derlemedeki adı kabukta metin olarak arayıp değiştiriyor.** Sayfa metinlerinde markanın adı geçiyorsa (ör. "Kodmob Yazılım'ın …") o da değişir. Ad kısa ve yaygın bir kelimeyse (ör. "Site") beklenmedik yerler de değişebilir; derlemedeki ad markaya özgü olmalı.
- **Alt projeler e-posta, SMS ve yapay zekâ ayarını ana projeden alıyor, ama kendi ayrı ayarları olamıyor** (Sistem Ayarları'nda bu sekmeler yalnızca ana projede). Alt projenin veritabanında bu özellikten önce girilmiş değer varsa yok sayılır, silinmez. Gönderen adı ve adresi de ortak: alt projenin e-postaları ana projenin göndereniyle çıkar. `kodmob:site export` ile ayrı kuruluma taşınan proje bu ayarları götürmez; yeni kurulumda yeniden girilmeli. Arka plan ve Yedekleme proje başına.
- **Kayıtlı olmayan alan adı ana siteyi açar** (bugünkü davranış). Bir alt sitenin alan adı `sites.json`'da yanlış yazılırsa o adreste ana site görünür; veri karışmaz.
- **`php artisan config:cache` çok siteyle kullanılmamalı.** Önbelleklenmiş yapılandırmada günlük, görünüm ve önbellek yolları ana sitenin depolamasında kalır (veritabanı ve site kökü yine doğru seçilir). Paket bunu zaten çalıştırmıyor.
- **Tek cron bütün siteleri çalıştırıyor, ama sırayla ve aynı süreçte.** Ana sitenin `schedule:run`'ı her dakika alt siteleri dolaşır (`sites:schedule`): her birinin nabzı, kuyruğu (e-postalar), otomatik yedeği ve günlük temizlikleri kendi veritabanında. Çok sayıda alt site ya da uzun kuyruklarda bir tur bir dakikayı geçebilir (üst üste binmez, bir sonraki dakikaya kalır). Cron kurulmadıysa hiçbir sitede çalışmaz (alt sitelerde de Ayarlar > Arka plan "Hiç çalışmadı" der).
- **Alt site ayrı kuruluma taşınabiliyor: `php artisan kodmob:site export <anahtar> --to=<klasör>`** (SQLite dosyası, depolama, görseller ve adımlar; MySQL/PostgreSQL alt site için `--db="…"` hedefi). Yalnızca komut satırından; panelde düğmesi yok. Yeni kurulumda `MEDIA_URL=/media/<anahtar>` yazılmazsa içerikteki görseller kırılır (OKUBENI.txt'te).

## Yapay zekâ görünürlüğü (SEO > Yapay zekâ)

- **Yapay zekâ botlarının ziyaretleri sayılıyor, ama yalnızca kimlik bilgisine (User-Agent) göre.** Kendini "GPTBot" diye tanıtan sahte bir tarayıcı da sayılır; botların IP aralıklarıyla doğrulama yapılmıyor. Sayılar yön gösterir, kesin değildir.
- **Google'ın Gemini'si ve yapay zekâ özetleri ayrı görünmüyor.** Bunlar Googlebot'la gelir; tabloda "Arama motoru" satırında. Apple Intelligence da Applebot'la.
- **Kayıt 2.4 ile başladı; geçmiş yok.** Ham kayıt `kodmob-core/storage/framework/seo/ai.log` (5 MB'ta durur), saatlik cron ya da panel açılınca özet tabloya alınır; 180 günden eskisi silinir. Cron yoksa ve panel uzun süre açılmazsa dosya 5 MB'ı doldurup yeni ziyaretleri kaçırabilir.
- **Kayıt için SEO açık ve "Yapay zekâ görünürlüğü" alt modülü açık olmalı** (SEO > Genel bakış; varsayılan açık). Güncellemeden sonra kayıt, ilk sayfa üretiminde (ya da kurulum adresiyle) kendiliğinden başlar.
- **İçerik hazırlık puanı kural tabanlı** (özet, içerik uzunluğu, ara başlıklar, güncellik, görsel açıklamaları, noindex); yapay zekâya sorulmuyor, ücretsiz. "Yapay zekâ bu sayfayı kaynak gösterir" garantisi değildir.

## Kod kalitesi

- **Mimari kurallar otomatik denetleniyor (`tests/Feature/ArchitectureTest.php`), ama izin listeleriyle.** Yetkisiz uç, tanımsız yetki, işlem geçmişinde başlığı olmayan rota, çekirdekten modüle yeni bağımlılık, site değişiminde sıfırlanmayan statik önbellek, `Core\Database` dışında sürüme özgü SQL testi düşürür. Bugünkü istisnalar listede gerekçesiyle duruyor; liste büyümemeli.
- **Statik analiz (Larastan seviye 5) CI'da, ama 36 eski uyarı `phpstan-baseline.neon`'da donduruldu** (çoğu tür açıklaması eksikliği). Yeni kod uyarısız olmalı; eskiler düzeltildikçe baseline yeniden üretilir.
- **Yapay zekâ araçları için kurallar kökte `CLAUDE.md`'de** (diğer araçlar `AGENTS.md` üzerinden). Mimari değişince bu dosya da güncellenmeli; yoksa yapay zekâ eski kurala göre çalışır.

## Build ve sürüm

- **Sürüm üç yerde (kodmob-frontend/package.json, kodmob-core/config/kodmob.php, kökteki kodmob.json), ama `node build.mjs --bump` üçünü birlikte yükseltir.** Elle değiştirilirse paket "sürümler farklı" diye durur.
- **Çatı güncellemesi (`kodmob:upgrade`) var, ama yalnızca kilidi olan projede.** Kilit (`kodmob.lock.json`) kurulu sürümü ve dosya özetlerini tutar; yoksa güncelleme durur. Bu depo çatının kendisidir, kilidi yoktur; `kodmob:pack` paketi buradan üretir. Güncelleme çatı dosyalarının tamamını değil, projede değişmemiş olanları yeniler; değişmiş olanın yenisi `*.kodmob-yeni` olarak yanına gelir.
  → Güncellemeden önce commit; sonra `composer install`, `php artisan migrate`, `npm ci`, `npm run build`, testler.
- **`--bump` sürümü yükseltiyor, ama bunu testlerden önce yapıyor.** Testler geçmezse sürüm yükseltilmiş kalır.
  → Testleri geçemeyen build'den sonra sürümü elle geri alın ya da tekrar build alın.
- **Paket yerel içeriği de alıyor, ama yerel MySQL kapalıysa yalnızca uyarı verip içeriksiz paket üretiyor.**
  → İlk kurulum paketi alırken çıktıda `ilk içerik: …` satırını kontrol edin.
- **Sürüm iki dosyada tutuluyor (`kodmob-frontend/package.json` ve `kodmob-core/config/kodmob.php`).** Paketleme betiği ikisini karşılaştırıyor, ama elle düzenlenirse ikisi birlikte değiştirilmeli.
- **Panel görünümleri ilk açıldıklarında iniyor (ilk yük gzip ile 37 KB), ama yeni görünüm `src/panel/views.js`'e eklenmezse açılmaz.** Kabuk yalnızca giriş, menü, bildirimler ve medya penceresini taşır; zengin metin düzenleyici (TipTap, ~390 KB) yalnızca düzenleyicide iner, boşta önceden indirilir. Yeni sürüm yüklendiğinde eski dosya bulunamazsa panel sayfayı bir kez yeniler.
  → Yeni ekran: `views.js`'e yükleyici (şablondaki x-data adlarıyla) ve `index.njk`'ye x-if.
- **İkon seti ayrı dosyada (`assets/img/icons-<hash>.svg`, sayfalar ~16 KB küçüldü), ama JS'te üretilen ikon başvurusu `iconHref()` ile yazılmalı.** Şablonlardaki `#i-…` yazımlarını build kendisi çevirir; JS'te elle `'#i-ad'` yazılırsa canlıda ikon görünmez (geliştirmede görünür, çünkü orada set sayfaya gömülü). İlk ziyarette ikonlar dosya inene kadar bir an boş kalabilir.

## Yedekleme

- **Otomatik yedek var (Ayarlar > Yedekleme), ama kapalı gelir ve cron ister.** Açılınca her gün ya da haftada bir içerik + görsellerin asılları tek zip'te `kodmob-core/storage/app/backups/auto/`'da tutulur (son N); zip panelden indirilir ya da geri yüklenir (görseller de gelir). Sunucu kaybolursa sunucudaki yedek de gider.
  → Açın ve "Uzak depoya da gönder" ile S3 uyumlu bir depo bağlayın (Cloudflare R2 ücretsiz katmanı yeterli: uç adresi, kova, erişim ve gizli anahtar; "Bağlantıyı dene"). Gizli anahtar APP_KEY ile şifreli; APP_KEY değişirse yeniden girilmeli. Gerçek bir depoyla henüz denenmedi (imza AWS'nin resmi örneğiyle doğrulandı).
- **Uzak depoya giden yedek yedek şifresiyle şifreli; şifre kaybolursa uzak yedek açılamaz.** Şifre sunucuda mühürlü
  (APP_KEY ile) durur ve panele geri gelmez: bir parola yöneticisinde saklayın. Sunucudaki zip şifresiz (sunucu
  veritabanıyla aynı güven düzeyinde). Şifreli kopya panelden yüklenir; barındırmanın yükleme sınırını aşan büyük yedek
  (görsellerle) dosya yöneticisiyle `kodmob-core/storage/app/backups/auto/`'ya konup listeden geri yüklenir.
- **Yedekte kişisel kayıtlar (siparişler, talepler, aboneler) var.** Otomatik ve anlık yedekte hep; elle indirilen
  yedekte yalnızca "Kişisel kayıtları da ekle" işaretliyse. Geri yüklemede kutu işaretsiz gelir: işaretlenirse
  sitedeki siparişler yedektekilerle **değişir** (yedekten sonra gelen siparişler gider; öncesi anlık yedekte).
  Talep ekleri ve sipariş e-postaları yedekte yok.
- **Elle "Yedeği indir" JSON'u görselleri içermiyor.** Yalnızca adresleri ve alt metinleri tutar; başka siteye taşırken görseller adreslerinden indirilir. Görselli yedek için otomatik yedeğin zip'ini kullanın.

## Panel ve hesaplar

- **Menü proje odaklı yeniden düzenlendi (Temel / Proje modülleri / Proje Ayarları / altta Sistem), ama panelden kendi menüsü kaydedilmiş roller eski gruplarını görür.** Kaydedilmiş rol menüsü değişmez (bilinçli); eski "Ayarlar" öğesi Proje Ayarları'nı açar. Yeni düzen için Roller ve İzinler › Yan Menü'de "Varsayılana dön".
- **Ayarlar ikiye ayrıldı; alt projede E-posta, SMS ve Yapay zekâ ana projeden geliyor, ama Arka plan ve Yedekleme proje başına.** Ayrıntı: Çok site bölümü.
- **Proje seçiciden alt projeye yeniden giriş yapmadan geçiliyor, ama yalnızca orada aynı e-postalı etkin bir yönetici varsa.** Her projenin kullanıcıları ayrı; eşleşme e-postayla ve yöneticiler arasında. Eşleşme yoksa uyarı çıkar ve o projenin giriş ekranı açılır. Geçiş anahtarı tek kullanımlık ve 60 sn; hedefin iki adımlı doğrulaması sorulmaz (ana projenin projeleri yöneten kişisi alt projeyi zaten yönetebiliyor). Yeni tablo `handoff_tokens` (yeni göç): canlıda kurulum adresi (`/api/v1/setup`) alt projelerin tablolarını da günceller.
- **Araç Pazarı'nda çatının araçları panelden ekleniyor ve kapatılıyor (çalışma anında, proje başına), ama bilgi projenin depolamasında, yedekte değil.** Keşfet › Projeye ekle derleme gerektirmez; eklenen ve kapatılan araçlar `storage/framework/modules.json`'da (`added`, `disabled`). Yedekten yeni kuruluma dönülünce panelden eklenen araçlar yeniden eklenir (içerik türlerinin kayıtları, ör. ürünler, yedekte; siparişler, rezervasyonlar ve bülten aboneleri yedeğe girmez). Profilde (`profile.modules`) yazılı araç panelden çıkarılmaz, yalnızca kapatılır. Topluluk paketleri hâlâ komutla kurulur (aşağıda).
- **Araçların site adresleri her projede ayrılmış:** `/magaza/`, `/sepet/`, `/odeme/`, `/siparis/`, `/satis-sozlesmesi/`, `/turlar/`, `/oteller/`, `/bulten/` (ve dillerdeki karşılıkları) araç kapalıyken 404 döner; aynı adresle blok sayfası açılamaz (sitenin sayfası önce gelir). Profil bu rotaları kendisi yazarsa onunkiler geçer; hiç istemeyen profil `site.config.js`'te `toolPages: false` (o zaman panelden eklenen aracın site sayfası olmaz).
- **Topluluk araçları paket olarak kuruluyor (`kodmob:add`, `kodmob-core/extensions/<anahtar>/`), ama yalnızca geliştirme bilgisayarında, komutla.** Paket sunucuda çatıyla aynı yetkiyle çalışır (veritabanı, dosyalar): yalnızca güvendiğiniz paketi kurun; "Doğrulanmış" yalnızca incelenen sürüm içindir. Kurulan paket bütün projelerde açık başlar (her projede Araç Pazarı'ndan kapatılır). Panel sayfası olan paket için `tv`'de yeniden derleme, tablosu olan için `migrate` (canlıda kurulum adresi) gerekir. Ayrıntı: [arac-paketi.md](arac-paketi.md).
- **Paket kaldırmak elle:** Araç Pazarı'nda kapatıp klasörü silin, derleyin. Tabloları ve verisi kalır. Çatı sürümü yükselince paketin uyumu (`kodmob` alanı) tutmazsa paket yüklenmez; Araç Pazarı nedenini yazar.
- **Topluluk kataloğu (`kodmob-core/resources/marketplace/catalog.json`) şimdilik boş.** Açık depo yayına girince araçlar çekme isteğiyle eklenecek. Paketten içerik türü, site sayfası ve Composer bağımlılığı henüz desteklenmiyor. Paketin panel şablonundaki Tailwind sınıfları yalnızca `kodmob-core/extensions` taranarak üretilir (`KM_EXTENSIONS` ile başka klasör kullanılırsa çatıda olmayan sınıf çıkmaz).

- **Çok kullanıcılı rol sistemi var, ama Yönetici rolü kilitli ve son yönetici pasife alınamıyor.** Bu bilinçli bir tercih: tek yönetici hesabının şifresini kaybederseniz yalnızca SSH ya da veritabanı ile girebilirsiniz.
  → Canlıda en az iki yönetici hesabı açın.
- **`kodmob:admin` komutuyla şifre sıfırlanabiliyor, ama Windows konsolunda Türkçe harfli şifre bozuluyordu.** Komut artık bu durumda uyarıp duruyor. Türkçe harfli şifreyi panelde Profilim'den belirleyin.
- **Hatalı girişler korumalı, ama kendi kendinizi de kilitleyebilirsiniz.** 5 hatalı denemede kullanıcı adı ve IP 15 dakika kilitleniyor.
  → Kilit Güvenlik sayfasından açılabilir.
- **Panel adresleri yol tabanlı (`/panel/ayarlar/yedekleme`), ama sunucu kuralına bağlı.** `public_html/.htaccess`'teki panel kuralı yüklenmezse derin bir adreste sayfa yenilemek 404 verir (paketle gelir; elle düzenlenirken silinmemeli). Rota tablosu `kodmob-frontend/src/panel/routes.js`; yeni sayfa eklenirken `kodmob-core/tests/fixtures/panel-urls.json`'a örnek eklenmeli (`npm test` denetler).
- **Eski `/panel/#/…` bağlantılar çalışıyor, ama yalnızca uyumluluk katmanı sayesinde.** Gönderilmiş talep e-postaları ve eski Jarvis mesajları bunlara dayanıyor; `routes.js` `fromLegacyHash()` ve `PanelUrl::fromLegacyHash()` silinmemeli.
- **Tablolarda canlı şerit var ("1 yeni kayıt · 1 güncelleme"), ama yalnızca nabız dosyası okunabiliyorsa ve olay defterinde kayıt varsa.** Değişiklikler İşlem Geçmişi kayıtlarından okunur; eski kayıtlar saklama süresiyle silinince uzun süre açık kalan tablo "Liste uzun süredir güncellenmedi" der ve tıklayınca yenilenir.
- **Projeler, Hizmetler ve Referanslar sürükle-bırakla sıralanıyor, ama yalnızca arama/süzgeç yokken, "Sıra"ya göre sıralıyken ve bütün kayıtlar tek sayfadayken.** Değilse tablonun üstünde ipucu ve "Sıraya göre göster" çıkar. Liste satırları artık bağlantı değil; kayıt "Düzenle" ile açılır (orta tıkla yeni sekme yok).
- **Tablo gruplaması (blogda dil grupları) sayfadaki satırları bir araya alıyor, ama sunucu kaynaklı tablolarda grup sayfa sınırında bölünebilir.** Bellek içi kaynakta (`localSource({ group })`) bölünmez.
- **Süzgeçler adreste duruyor, ama yalnızca tek tablolu sayfalarda** (içerik listeleri, Talepler, İşlem Geçmişi, Kullanıcılar). SEO ve Güvenlik sekmelerindeki tablolarda henüz yok.
- **Oturum jetonları kalıcı depoda değil, ama CSP'de Alpine için `'unsafe-eval'` hâlâ açık.** Yenileme jetonu HttpOnly `km_refresh` çerezinde (JavaScript okuyamaz), erişim jetonu (≤1 sa) sekmenin sessionStorage'ında; sekmeler arası yenileme kilitli, döndürmede 60 sn ödünç süresi var. `@alpinejs/csp` denendi: ifade ayrıştırıcısı ok fonksiyonlarını ve `?.`'yı desteklemiyor, panelde yüzlerce ifade yeniden yazılmadan geçilemez. Koruyanlar: betik yalnızca kendi alan adından, içerikteki `x-*`/`@` öznitelikleri HtmlSanitizer'da atılır.
  → Açık bir panel sekmesi güncellemeden sonra bir kez çıkış-giriş isteyebilir (eski sürümün depodaki jetonu bir kez kabul edilip silinir).
- **İki adımlı doğrulama var (Profilim), ama zorunlu değil.** Güvenlik denetimi kapalı olan yöneticileri uyarır. Telefonunu kaybeden kullanıcı yedek koduyla girer ya da yönetici Kullanıcılar'dan sıfırlar (oturumları da kapanır). Yedek kodla giriş Güvenlik kayıtlarına düşer.
  → Canlıda bütün yöneticilerde açın.
- **Kullanım Kılavuzu var (Diğer İşlemler ve hesap menüsü › Kullanım Kılavuzu; tur + rehberler), ama rehber metinleri elle yazıldı.** Tur kişinin menüsünden kendiliğinden kurulur; menüye yeni sayfa eklenince tur metni gerekir (`npm test` yakalar). Rehberler (`kodmob-frontend/src/panel/guides/*.html`: başlangıç, site, talepler, yönetim) ekranlar değişince kendiliğinden güncellenmez; rehberdeki bağlantıların geçerliliği test edilir, metnin doğruluğu edilmez. Tur sayfa her açıldığında başlar (paylaşılan `?rehber=` bağlantısıyla gelince başlamaz).
  → Ekranı değişen bir işten sonra ilgili rehberi de güncelleyin.
- **Sistem Sağlığı var (Yönetim › Sistem Sağlığı), ama yalnızca anlık okur: geçmiş tutmaz, kendiliğinden uyarmaz.** Aylık rapor kapalı gelir; rapor alıcısı yazılmadıkça Bildir çalışmaz. Paylaşımlı hostingde "Disk boş / toplam" sunucunun diskidir, hesabın kotası değil. Hata kodu (`DB-…`, `ERR-…`) yalnızca beklenmeyen hatada (500) üretilir; son 50 kayıt sitenin depolamasında dosyada tutulur, SQL parametreleri yazılmaz. Gizli bir aylık e-posta kanalı bilinçli olarak yok: alıcı ve aylık gönderim Ayarlar › Sistem sağlığı'nda görünür ve kapatılabilir.
  → Canlıda rapor alıcısını yazın; müşteri kurulumunda `.env` `HEALTH_REPORT_TO` ile önceden verilebilir.
- **Jarvis sohbeti çalışıyor, ama bilinen son durumda (11 Ekim) yalnızca sahte bağlantıyla denendi.**
  → Gerçek API anahtarıyla bir kez uçtan uca deneyin. Talep sahiplerinin kişisel verileri yapay zekâ sağlayıcısına gönderilmez; bu kural korunmalı.

## Modüller ve profil (20 Ekim, açık kaynak 1. aşama)

- **Açık modüller ve içerik türleri artık sitenin profilinde (`kodmob-frontend/src/profiles/<ad>/site.config.js` `profile`), ama profil derlemeyle gelir.** Canlıda `.env` değişikliği gerekmez; kodmob.com'un profili bütün modülleri açar. Profil yoksa (eski derleme) hepsi açık kalır: yeni çekirdek, yeni derlemeden önce yüklense de bir şey kaybolmaz. `KODMOB_MODULES` yazılırsa profilin üstüne geçer.
  → Paketi yüklerken `public_html` ve `kodmob-core`'u birlikte yükleyin.
- **SMS ayrı yetki aldı (`km_manage_sms`), ama panelden değiştirilmiş özel roller bunu kendiliğinden almaz.** Yönetici etkilenmez. Önceden "Site ayarlarını değiştirme" yetkisiyle SMS sekmesini gören özel rol artık görmez.
  → Gerekirse Roller ve İzinler'den "SMS ayarları" yetkisini verin.
- **Domain arama ayrı modül (`domains`), ama adresleri ve kayıtlı adları (ayar `jarvis.rdap_concurrency`, olay `jarvis.job_finished`) Jarvis'in altındaki gibi.** Zamanlanmış işin adı `domains:tick` oldu (eski `jarvis:tick` kilidi kendiliğinden düşer).
- **Varsayılan yan menü sadeleşti, ama kaydedilmiş rol menüleri değişmez.** Seyrek sayfalar (Domain arama, Teklif hesaplayıcı, İşlem Geçmişi, Roller, Güvenlik, Siteler) Diğer İşlemler'de; Asistan üst bardaki Jarvis düğmesinde.
- **Profilde kapalı içerik türüne bağlı çekirdek yerleri türü atlıyor, ama kodmob.com'un site sayfaları (ana sayfa projeleri, proje sayfası) türü varsayar.** Türü kapatmak sayfaları da kaldırmayı gerektirir; türsüz sade site için `profiles/starter`.
- **Başlangıç profili (`profiles/starter`) derleniyor ve sınanıyor, ama yasal metinleri (gizlilik, çerezler, KVKK) şablon.** Başlarındaki ŞABLON notu yeni projede kaldırılmadan önce metinler işletmeye göre doldurulmalı ve hukuken gözden geçirilmeli.
  → Deneme: `cd kodmob-frontend && npm run e2e -- --starter` (yalnızca başlangıç senaryosu; yereldeki `.local` kodmob profiliyle kalır, ama `kodmob-frontend/dist` başlangıç derlemesiyle kalır: ardından `--no-build` kullanmadan derleyin).
- **İletişim formu talep olarak düşüyor ve hizmet seçimi istemiyor (`source: contact`), ama Talepler'de proje brifleriyle aynı listede.** Kaynak süzgeci "İletişim" ile ayrılır.
- **Her sayfada gelen hizmet listesi (alt bilgi) artık profilin `needs`'inden, ama yalnızca yeni derlemeyle.** Profilsiz eski derlemede hizmetler eskisi gibi her sayfada gelir; profil olup `needs` yazılmamışsa gelmez.

## Mağaza (commerce)

- **Mağaza isteğe bağlı ve kapalı başlar; açmak için Araç Pazarı › Keşfet › Projeye ekle** (ya da profilin `profile.modules` listesine `'commerce'`). Site sayfaları her derlemede hazır (araç kapalıyken 404); profil adreslerini değiştirmek isterse rotalarına `...commerceRoutes()` yazar (başlangıç profilinde böyle). kodmob.com'un profilinde Mağaza yok; Keşfet'te ilk kart olarak görünür.
- **Stripe ve PayTR yazıldı ve testlerde sahte yanıtla doğrulandı, ama gerçek hesapla hiç denenmedi.** Canlıya almadan önce sağlayıcının deneme kipiyle (Stripe: sk_test_…, PayTR: Deneme kipi açık) baştan sona bir sipariş verin. Bildirim adresleri sağlayıcının panelinde elle tanımlanır: Stripe `https://<site>/api/v1/shop/payments/stripe` (olay `checkout.session.completed`, imza anahtarı whsec_… Proje Ayarları'na), PayTR `https://<site>/api/v1/shop/payments/paytr`. Bildirim gelmezse sipariş "Ödeme bekliyor"da kalır; panelden "Ödendi" yapılabilir.
- **Ödeme bildirimi tutar denetimli:** gelen tutar siparişten azsa sipariş ödendi sayılmaz (Sistem Sağlığı'nda hata kaydı). PayTR'de taksit farkı tutarı büyütebilir; büyük tutar kabul edilir.
- **API anahtarları (Stripe, PayTR) "gizli ayar":** mühürlü saklanır, panele, işlem geçmişine ve yedeğe girmez. Yedekten geri yüklenen ya da `APP_KEY`'i değişen kurulumda yeniden girilir. Panelden silinemez (yenisi yazılır; sağlayıcıyı kapatmak için Araç Pazarı).
- **Stok yarışı:** MySQL ve PostgreSQL'de sipariş verilirken ürün satırı kilitlenir; SQLite'ta kilit yok (yazma zaten tek sıra), çok yoğun eşzamanlı siparişte stok eksiye düşebilir.
- **Kargo tek ücret** (ücretsiz kargo alt sınırıyla); bölgeye / ağırlığa göre kargo, kargo firması tümleştirmesi, fatura PDF'i, e-fatura ve pazaryerleri yok (raporda sonraki aşamalar). Para birimi satış başladıktan sonra değiştirilmemeli (siparişler kendi para birimini saklar, ürün fiyatları dönüştürülmez).
- **Genel bakış'ta satış özeti yok** (siparişler Mağaza › Siparişler'de). Mesafeli satış sözleşmesi şablon metindir: yayından önce hukukçuya kontrol ettirin (Yasal belgeler).

## Sayfa düzenleyici ve bloklar (20 Ekim)

- **Canlıya yüklerken site kökündeki `.htaccess` de güncellenmeli.** Önizleme isteği (`?km-preview=`) güvenlik başlıklarını render.php'den alır (panelin çerçevesine izin); `km-blocks.json` dışarıya kapalıdır. Eski `.htaccess` kalırsa düzenleyicinin önizlemesi "çerçeveye izin yok" ile boş görünür.
- **Başlangıç profilinin ve kodmob.com'un ana sayfası artık bloklardan.** Ana sayfa metinleri Sayfalar ve Diller > Metinler'de görünmez; Sayfa düzenleyici'den değişir. kodmob.com'un bölümleri: Görselli karşılama, Referans logoları, Öne çıkan projeler, Hizmet kartları, Neden biz (profilin blokları) ve Süreç adımları, Son yazılar, Çağrı bandı.
- **Metinler'de daha önce değiştirilmiş ana sayfa metinleri kaybolmaz**: bölge hiç düzenlenmemişse sitede ve düzenleyicide o metinler görünür (bloğun ilk halinde metnin sözlük anahtarı var). Bölge düzenleyicide bir kez yayınlanınca metinler bloklardadır; Metinler'deki eski kayıt artık kullanılmaz.
- **Görselli karşılama ve Neden biz'de görsel seçilmezse sitenin kendi fotoğrafları** (proje ekranı, ofis) görünür; düzenleyicide seçilen görsel onların yerine geçer. Karşılamadaki yüzen kartlar ve kart yığını öne çıkan projelerden (Projeler'de "Öne çıkan"; yoksa bütün projeler).
- **Kayıt sayfası şablonu (ör. blog yazısı) o dilde yayında bir kayıt varken düzenlenir**; yoksa düzenleyici bunu söyler. Şablonun bölgeleri bütün kayıtlarda ortaktır.
- **Önizleme anahtarı 2 saat geçerli**; düzenleyici yenisini kendisi alır, uzun süre açık kalan sekmede sayfa değiştirmek yeterli.
- **Yapay zekâ (Jarvis):** yalnızca bloğun metinleri, sitenin adı / tanımı ve isteğiniz gider. Sonuç taslaktır; Ctrl+Z geri alır. Anahtar yoksa düğmeler görünmez.
- **Yerel test notu:** testler artık uygulama açılırken geçici depolamada (`LARAVEL_STORAGE_PATH`); yerel sitede Araç Pazarı'ndan kapatılan araç (storage/framework/modules.json) testleri etkilemez.
- **Blok sayfaları kökte (`/kampanya/`), sitenin sayfalarından sonra eşlenir.** Derlemeye sonradan aynı adla bir sayfa (rota) eklenirse blok sayfası açılmaz olur (sitenin sayfası kazanır); panel adres verilirken çakışmayı denetler, sonradan eklenen rotayı göremez. Dil önekleri ve sitenin klasörleri (panel, api, media…) adres olamaz.
- **Kökteki bilinmeyen tek parçalı adres (`/x/`) artık sayfa üretiminden 404 alıyor** (önce "blok sayfası var mı" bakılır). Büyük harfli adres (`/Kampanya/`) küçük harflisine 301 ile gider, kayıt yoksa orada 404 (kayıt sayfalarındaki gibi).
- **Taslak blok sayfası yalnızca düzenleyicinin önizlemesinde açılır.** Yayınla taslak sayfayı da yayına alır; içerik yayınlama yetkisi olmayan rolde bölümler yayınlanır, sayfa taslakta kalır (düzenleyici bunu söyler). Silme ve diller Blok sayfaları listesinde (Tüm İşlemler) ya da düzenleyicinin "Sayfa ayarları" düğmesinde.
- **Blok sayfasına bağlantı (`page:<kimlik>`) sayfa o dilde yayında değilse boş adres verir**; sayfa silinirse bağlantı da boşalır (bloğu güncelleyin). Kapalı aracın sayfasına bağlantı da boştur (ör. Teklif hesaplayıcı kapalıyken Çağrı bandının ikinci düğmesi görünmez).

## Rezervasyon profilleri (20 Ekim)

- **Turların verisi değişmedi** (aynı alan adları); yeni alanlar: paketler, program, dahil olmayanlar, öne çıkanlar, SSS, rehberlik dilleri. Tur sayfası artık bunları ve yeni rezervasyon kutusunu gösterir (telefonda altta fiyat çubuğu, dokununca alttan açılan sayfa).
- **Paket adları dil başına ayrı listede (`Paket adları`), fiyatlı paket listesiyle aynı sırada.** Paket silinir ya da yeri değişirse adlar listesini de aynı sıraya getirin; adı olmayan paket kısa adıyla görünür.
- **Gün biriminde (kiralama) tarih listesi yok**: ziyaretçi alış ve teslim günü seçer; teslim günü kapasiteden düşmez.
- **Projenin rezervasyonlu türü** (`kodmob:module … --bookable=person|day|fixed`) Mağaza ve Rezervasyon modülleri profilde açık değilse panelde düzenlenir ama sitede rezerve edilemez (fiyat adresi 404). Sayfa şablonu ve rota elle eklenir.
- **`commerce_bookings.tour_id`** artık rezerve edilen kaydın kimliği (tür ne olursa olsun); ad geriye uyum için kaldı. `persons` kapasiteden düşen miktar (kişi, adet, oda ya da 1 rezervasyon).

## Yerelde alt site (21 Ekim)

- **Yerelde alt site `anahtar.localhost` ile açılır** (Chrome, Edge, Firefox ek ayar istemez; hosts dosyası gerekmez). Panel bu bilgisayardayken yeni site formunda "Yerel deneme adresi" kutusu işaretli gelir. Safari ve bazı eski tarayıcılar `*.localhost`'u çözmeyebilir.
- Canlıya taşınacak sitenin gerçek alan adını da yazın; `.localhost` adresi canlıda zararsızdır ama işe yaramaz (panelden silinebilir).

## Tur ve otel sayfasının bölümleri bloklardan (21 Ekim)

- **Tur ve otel sayfasındaki bölümler (bilgiler, öne çıkanlar, olanaklar, program, dahil olanlar, SSS) artık Sayfa düzenleyicinin bölgesinde** (`tour.sections`, `hotel.sections`): sıralanır, gizlenir, başlıkları değişir, araya başka blok eklenir. Düzenleme **bütün turlara (otellere)** uygulanır; içerik yine her kaydın kendisinden.
- **"Kayıt sayfası" blokları yalnızca kayıt sayfası şablonunda eklenir**; başka sayfaya kopyalanırsa (Ctrl+C / V) orada kayıt olmadığından hiçbir şey göstermez.
- Projenin kendi rezervasyonlu türünün sayfası `pages/shop/_booking-sections.njk`'yi kullanmaya devam edebilir; bloklu hali için şablona `zone('<rota>.sections', …, [ilk bloklar])` yazılır.

## Oteller ve gece birimi (21 Ekim)

- **Oteller ayrı bir araç** (Araç Pazarı'nda "Oteller", Rezervasyon'a takılı): Keşfet › Projeye ekle Mağaza ve Rezervasyon'u da ekler; ya da profilin `modules`'ına `'hotels'` (başlangıç profilinde ekli; kodmob.com'da Mağaza kapalı).
- **Fiyat oda başı ve gecelik**: kişi sayısı fiyatı değiştirmez, yalnızca oda kapasitesine göre denetlenir (yetişkin + çocuk; bebek sorulmaz). Kişi başı fiyatlı konaklama (ör. kişi başı her şey dahil) için bugün kişi birimli tür (`--bookable=person`) ve paketler kullanılır.
- **Oda tipi başına adet (`Oda adedi`) ve toplam oda sayısı ayrı sınırlardır**; ikisinden dar olanı geçerli. Çıkış günü boş sayılır. Oda tipinin kısa adı değişirse eski rezervasyonlar o oda tipinin adedinden düşmez (kısa adı yayından sonra değiştirmeyin). Kısa adı boş oda tipi sırasıyla tutulur (`r1`, `r2`): satırların yeri değişirse sayım kayar; kısa adı her zaman yazın.
- **Oda adları dil başına ayrı listede (`Oda adları`), oda tipleri listesiyle aynı sırada** (paket adları gibi).
- **Sezon farkı giriş gününe göre** uygulanır (gece gece değil); sezona taşan konaklamada ilk gecenin sezonu bütün geceler için geçer.
- **Panelde satırlardaki para sütunu (paket ve oda fiyatı) önceden kuruş olarak görünüyordu**; artık "1500,00". Yeni sürümden önce panelden paket fiyatı düzenlenip kaydedildiyse fiyat 100 katına çıkmış olabilir: tur paketlerini kontrol edin.

## Jarvis Operatör (21 Ekim)

- **Jarvis artık panel verisini okuyor ve iş önerebiliyor, ama gerçek bir yapay zekâ anahtarıyla hiç denenmedi** (testler sahte sağlayıcıyla). Canlıda önce sohbette küçük bir öneriyle (bir yönlendirme) deneyin.
- **Okuma araçlarının sonucu yapay zekâ sağlayıcısına gider:** SEO özeti, 404 adresleri, içerik başlıkları, sistem ve çeviri sağlığı, yasal belge durumu. Talepler, siparişler, aboneler ve kullanıcılar araç değildir; 404 kaydındaki yönlendiren adresin yalnızca alan adı gider.
- **Yazma araçları yalnızca onayla çalışır** ve kullanıcının yetkisiyle sınırlıdır; onay bekleyen öneri varken sohbete yeni mesaj yazılamaz (önce onay / ret). Onaylanmamış öneriler kendiliğinden silinmez (sohbet açık kalır).
- **Araç listesi şimdilik iki yazma işi:** yönlendirme ekleme ve yazıyı çevirip taslak ekleme. Yayına alma, silme gibi işler bilinçli olarak yok.

## Yasal belgeler (21 Ekim)

- **Şablonlar hukuki görüş değildir.** Gizlilik, KVKK, çerez, kullanım koşulları ve satış belgeleri genel şablondur; yayından önce işletmenin gerçek veri işleme durumuna ve faaliyetine göre bir hukukçuya kontrol ettirin, gerekirse panelde Yasal belgeler'den özelleştirin.
- **Proje Ayarları › Yasal doldurulmadan metinlerde köşeli parantezli uyarı görünür** (unvan, adres, e-posta, yetkili mahkeme). Panelde Yasal belgeler sayfası eksikleri yazar.
- **Özelleştirilmiş metin sabittir:** sonradan açılan modülün bölümü (ör. Mağaza açılınca gizlilik politikasındaki alışveriş bölümü) ya da değişen firma bilgisi o metne eklenmez. Panel uyarır; "Şablona dön" ile güncel şablona bağlanır.
- **Çerez onay bandı yok:** çatı yalnızca zorunlu kayıtları kullanır (dil, tema, sepet, panel oturumu). Projeye analitik, reklam ya da gömülü içerik (YouTube, harita) eklenirse çerez politikasını özelleştirin ve onay bandı ekleyin.
- **Rezervasyon koşullarındaki iptal kuralı varsayılandır** (hizmet tarihinden 7 gün önceye kadar tam iade): işletmenizin kuralına göre özelleştirin; paket turlarda Paket Tur Sözleşmeleri Yönetmeliği'ni gözetin.
- **Mağaza'nın panelde Metinler'den değiştirilen satış sözleşmesi (`shop.terms.body`) taşınmadı:** kendi metninizi yazdıysanız Yasal belgeler › Mesafeli satış sözleşmesi'nde özelleştirin.
- **İYS, VERBİS ve çevrim içi veri sahibi başvuru formu yok;** başvurular e-posta, KEP ya da dilekçeyle (KVKK metninde yazar).

## Bülten (21 Ekim)

- **Bülten gönderimi cron ister.** Gönderim her dakika zamanlanmış görevle parça parça sürer; cron yoksa yalnızca panelde Bülten sayfası açıkken ilerler (sayfa uyarır). Hosting › Cron Jobs: her dakika `schedule:run`.
- **Saatlik sınır proje başına** (Proje Ayarları › Bülten, varsayılan 100). E-posta hesabı bütün projelerde ortak olduğundan, birden çok projeden aynı anda bülten gidiyorsa sınırların toplamı barındırma firmasının sınırını aşmasın. Onay e-postaları sayılmaz.
- **Teslim edilebilirlik alan adının ayarına bağlı:** Gmail ve Yahoo toplu gönderende SPF, DKIM ve DMARC ister; bunlar alan adının DNS'inde (Hostinger › E-posta) yapılır. Bülten e-postaları List-Unsubscribe ve tek tık çıkma başlığını taşır.
- **Türkiye'de ticari elektronik ileti:** onay kutusu ve çift onay ispatı tutulur (onay tarihi, IP özeti), ama İYS'ye (İleti Yönetim Sistemi) kayıt ya da aktarım yapılmaz. Yükümlülüğünüz varsa onayları İYS'ye siz aktarırsınız. Onay metni bloğun "Onay metni" alanından değişir.
- **Aboneler ve bültenler yedekte kişisel kayıt** (talepler ve siparişler gibi; geri yüklemede ayrıca seçilir). Abone içe aktarma yok (onay ispatı olmayan liste yasal risk); panelden eklenen kişiye de onay e-postası gider.
- **Onay ve çıkma bağlantısı sitenin Bülten sayfasına gider** (`/bulten/`). Profil `toolPages: false` ile araç sayfalarını almıyorsa bağlantılar ana sayfaya düşer ve işlemez: o profil `newsletterRoutes()`'u kendi rotalarına ekler.
- **E-posta sunucusu art arda 5 kez hata verirse bülten durur** (bildirim gelir, Sistem Sağlığı uyarır); ayar düzeltilip "Devam et" denince o aboneler yeniden denenir. Tek tük başarısız adresler atlanır ve "başarısız" sayılır; yeniden denenmez.
- **Onaylanmayan abonelik 7 gün sonra silinir.** Aynı adrese onay e-postası 10 dakikada bir gider; zaten abone olan adres formda aynı yanıtı alır (listede kim olduğu dışarıdan anlaşılmasın).

## Yeni içerik türü (iskelet)

- **`php artisan kodmob:module courses --label=Kurslar --singular=Kurs` yeni türü panelde hemen çalıştırıyor, ama sitede göstermiyor.** Tür sınıfı (`app/Project/<Ad>/`), `config/project.php` `content_types` kaydı, profilin tür listesi (`kodmob-frontend/src/profiles/<ad>/site.config.js`), panel menüsü ve adresi (`kodmob-frontend/src/panel/types.js`), test manifestindeki adres ve bir test dosyası yazılır. Liste, form, dil sekmeleri, sıralama, Silinenler, işlem kaydı, canlı şerit, yetki (Editör'e de gelir) ve yedek tür kaydından (`App\Core\Content\Types`) türer; başka dosyaya dokunulmaz. `--dry-run` yazmadan gösterir.
  → Sonra: kodmob-frontend'de build; sitede sayfa isteniyorsa profilin `routes.config.js`'i + şablon ve `PageData` ihtiyacı elle eklenir. Panelden değiştirilmiş özel roller yeni yetkiyi kendiliğinden almaz.
- **Türler arası bağ (`type: post` alanı, `postType: km_project`) yedekte yeni kimliklere eşleniyor, ama yalnızca bağlanılan tür listede daha önce geliyorsa.** `content_types` sırası önemlidir.

## Site ve SEO

- **Siteye özgü olan her şey derlemede, ama sunucu onu yalnızca manifestten bilir.** `kodmob-frontend/src/profiles/<ad>/site.config.js` (canlı adres, kurum bilgisi, sözlük eşlemesi, arama sayfaları) ve profilin `i18n/<dil>.json`'u (proje/blog kategorileri, brif hizmetleri, brif yanıt e-postası, içindekiler başlığı) build ile `km-manifest.json`'a girer; kodmob-core bunları `SiteManifest`'ten okur, kodda Kodmob'a özgü liste yok. Yeni kategori: sözlüğe ekle, build al. Sözlüğü taşımayan eski bir derlemeyle çalışılırsa seçim alanları değeri korur (silmez) ama panelde seçenek çıkmaz.
  → Yeni projede ilk düzenlenecekler profil klasöründe (`kodmob-frontend/src/profiles/<ad>/`): `site.config.js`, sözlükler, `locales.js`, `routes.config.js`.
- **Türkçe adresler öneksiz (`/`, `/projeler/x/`), ama adres yapısı build'e ait.** Önek ve varsayılan dil profilin `locales.js`'i, bölüm adları `routes.config.js`; değiştirmek için build gerekir. Panel bunları değiştiremez; dil kaydı kurulumda (ya da `php artisan kodmob:i18n`) build ile eşitlenir.
- **Eski adresler 301 ile yönleniyor, ama yalnızca `kodmob-frontend/src/profiles/<ad>/i18n/url-history.json` sayesinde.** Bu dosyayı build yazar; silinmemeli, elle düzenlenmemeli, git'e girmeli. Silinirse önceki adreslerin yönlendirmesi kaybolur.
- **Sayfa kabukları `public_html/_pages/` altında, ama canlıdaki eski `tr/`, `en/`, `de/` klasörleri kendiliğinden silinmez.** Yeni `.htaccess` onları sunmaz (zararsız); güncellemeden sonra elle silinebilir.
- **Yeni bir dil API'den eklenebiliyor, ama pasif gelir ve sitenin derlemesinde yoksa yayına alınamaz.** Bu bilinçli bir kilit: sayfası olmayan dil yayına çıkmasın.
- **Özelleştirilmiş roller yeni yetkiyi kendiliğinden almaz.** `km_manage_languages` varsayılan Editör ve Yönetici'de var; yetkileri panelden değiştirilmiş bir rolde elle verilmeli.
- **Çoklu Dil sayfası (/panel/diller) dilleri, metinleri ve sağlığı yönetiyor, ama önek ve varsayılan dil yine derlemeye ait.** Yeni dil panelden pasif eklenir; yayına almak için dil derlemeye eklenmeli (build). "Sayfa metinleri" artık Çoklu Dil > Metinler.
- **Tek dilli mod var (Çoklu Dil > Genel bakış), ama açıkken bütün diğer dillerin adresleri 302 ile varsayılan dile gider.** Çeviriler silinmez; anahtar geri açılınca diller kaldığı yerden yayına döner. Yerelde denendikten sonra açık bırakıldı.
- **Paket almadan önce bağlantılar denetleniyor (`php artisan kodmob:links`; Sistem Sağlığı raporu `kodmob:health`), ama yalnızca içerikli pakette ve yerel veritabanı açıksa.** Hata (kırık bağlantı gibi) varsa paket durur; `--ignore-links` ile geçilir.
- **Bir dil pasife alınınca sayfaları 302 ile varsayılan dile gider, ama dilin içeriği silinmez.** Site haritası, hreflang, dil seçici ve öneri bandı pasif dili göstermez.
- **Blog yazılarının çevirileri ayrı kayıtlar, ama artık yönetilen bir çeviri grubuyla bağlı** (düzenleyicide "Diller" kutusu: oluştur, bağla, gruptan ayır). Eski "Çeviri anahtarı" göçle gruba çevrildi; aynı grupta bir dilde tek yazı olabilir.
- **Blog düzenleyicisi tabloları koruyor ve hücre metni düzenleniyor, ama tablo ekleme, satır ya da sütun ekleme yok.** 2.2.1 öncesi düzenleyici tabloyu tek paragrafa düzleştiriyordu; o sürümle kaydedilmiş bir yazı varsa tablosu bozulmuş olabilir (yerelde yoktu).
- **Proje, hizmet ve referansta dil kaldırılabiliyor, ama metni silinmiyor.** Kaldırılan dil sitede görünmez; metni veritabanında ve yedekte durur, dil yeniden eklenince geri gelir. Yeni eklenen dil **taslak** gelir; sağdaki "Diller" anahtarından yayına alınmalı.
- **Çoklu dil tabloları ana göçlerde kurulu (ayrı göç yok), ama bu yalnızca canlıda henüz Laravel verisi olmadığı için doğru.** `posts.translation_group`, `source_hash`, `slug_history` ve 404 sütunları ilk kurulumda gelir. Canlıya çıktıktan sonraki her şema değişikliği yeni bir göçle yapılmalı. Dil durumu olmayan eski kayıt (eski yedekten) okunurken dolu dilleri "yayında" sayılır.
- **Adresi değişen proje ya da yazı 301 ile yeni adresine gidiyor, ama yalnızca adres geçmişi (`slug_history` tablosu) sayesinde.** Bu yönlendirmeler SEO > Yönlendirmeler listesinde görünmez. Eski adres başka bir kayda verilirse yönlendirme kalkar ve adres yeni kayda ait olur. Geçmiş yedeğe girer ve geri yüklemede yeni kayıtlara bağlanır; denetimden (16 Ekim) önce alınmış yedeklerde yoktur.
- **Silinen proje ya da yazının adresi 410 veriyor, ama yalnızca kayıt Silinenler'deyken.** Geri alınınca sayfa açılır; kayıt kalıcı silinirse adres 404'e döner.
- **"Güncellenmeli" rozeti var, ama yalnızca metne bakıyor.** Kaynak dil (varsayılan dil; eklenmemişse ilk eklenen) metni değişince diğer diller işaretlenir; görsel, kategori ya da adres değişikliği işaretlemez. "Güncel say" rozeti çeviriye dokunmadan kaldırır.
- **Jarvis ile çeviri hazır, ama Claude API anahtarı girilmeden düğmeler görünmüyor** (Jarvis > Ayarlar). Çeviri kaydetmez; forma dolar, gözden geçirip Kaydet'e basmak gerekir. Gönderilen yalnızca içerik metni; talep bilgileri gönderilmez. Yerelde gerçek çeviri denenmedi (testler sahte motorla).
- **404 sayfası artık yol gösteriyor, ama önerileri tarayıcı istiyor** (`/api/v1/suggest`, dakikada 30 istek). Önbellekteki 404 HTML'i böylece herkese aynı kalır. Kayıt başka dilde varsa "şu dillerde mevcut" kutusu sunucudan gelir. "Sık ziyaret edilenler" şimdilik derlemedeki ana bölümler; panelden seçimi Faz 8'de.
- **Yazım farkı (büyük harf, çift `/`) ve yanlış dildeki bölüm adı (`/en/projeler/x/`) 301 ile düzeltiliyor, ama yalnızca hedef gerçekten bir sayfaysa.** Küçük harflisi de yoksa adres 404 kalır.
- **Kırık bağlantı denetleyicisi her gece çalışıyor, ama yalnızca yayındaki yazı ve kayıt metinlerine bakıyor** (SEO > 404 üstündeki kart). Menüler ve sayfa metinleri denetlenmiyor. "Yönleniyor" satırları çalışan ama eski adrese giden bağlantılardır; "Yönlenenleri güncelle" onları içerikte düzeltir. Kırık bağlantıyı kendiliğinden düzeltmez (hedefi belirsiz); bildirim yalnızca kırık ya da silinmiş içerik için gider.
- **404 kaydında "iç bağlantı" işareti var, ama yönlendiren adres sitenin alan adına göre belirleniyor** (`APP_URL` ve manifest `siteUrl`, www'li ve www'siz). Alan adı değişirse eski kayıtlar "dış" görünür.
- **Kayıt sayfaları ve listeler (projeler, blog, ana sayfa kartları) sunucuda HTML'e yazılıyor, ama yalnızca basit alanlar.** JS çalıştırmayan botlar başlığı, özeti ve bağlantıları görür; görseller ve etiketler Alpine'la gelir. Alpine açılırken sunucu kopyaları kaldırılır (çift içerik yok). Şablon işaretleri silinmemeli: kayıtta `data-km-ssr` ve `<!--km:e:…-->` (`EntrySsr.php`), listede `x-for` şablonundaki `data-km-ssr-list` (`ListSsr.php`).
- **`.htaccess` artık sıkıştırma (Brotli/gzip) ve panel için noindex veriyor, ama Hostinger'da (LiteSpeed) denenmedi.** Yerel sunucu .htaccess okumaz. Yüklemeden sonra bir sayfanın yanıt başlıklarında `content-encoding` olduğuna ve sitede değil yalnızca `/panel/`'de `x-robots-tag: noindex` olduğuna bakın.
- **Görsel alt metni yalnızca varsayılan dilde zorunlu, alanlar dil kaydından geliyor.** Yeni dil eklenince medya penceresinde alanı kendiliğinden çıkar; boş bırakılırsa varsayılan dildeki alt metin kullanılır.
- **SEO modülü tamamen kapatılabiliyor ve kapatınca veriler silinmiyor.** Ama kapalıyken yönlendirmeler ve 404 kaydı da durur.
- **IndexNow modülü hazır, ama varsayılan olarak kapalı.** Site indekslenebilir değilken açılsa da gönderim yapmıyor.
- **Yönlendirmeler ve 404 kaydı Laravel açılmadan çalışıyor (render.php), ama sayaçlar önce dosyaya yazılıyor** (`storage/framework/seo/hits.log`, `404.log`). Panele günlük zamanlanmış görevle geliyor; cron yoksa gecikir.
- **Sayfalar arası yumuşak geçiş (View Transitions, `effects.css`) tanımlı, ama Chrome 155'te çoğu gezinmede yarıda kalıyor** (20 Ekim'de bulundu; önceden de vardı). Sayfa normal açılır, yalnızca geçiş animasyonu oynamaz ve konsola "Transition was aborted because of invalid state. ViewTransition opt-in disabled" düşer. Görünmez (headless) Chrome'da hep, görünür Chrome'da ara sıra. Aynı kuralla iki sade sayfada geçiş çalışıyor; sitede iki ayrı tetikleyici var: prerender (speculation rules) ve sayfa içeriği (tek bir öğe değil; betikler, dış ikon dosyası, `html` öznitelikleri, yanıt başlıkları denendi, neden değil). Uçtan uca testler bu iletiyi saymıyor (`e2e/helpers.js`).
  → Ayrı bir işte incelenmeli (Chrome sürümü, `pagereveal` ile geçişin durumu); kalıcı çözüm bulunamazsa geçiş kaldırılabilir.
- **robots.txt artık panelden yönetiliyor, ama sunucuda eski bir `robots.txt` dosyası kalabilir.** Geçerli olan yine render.php'nin yanıtı; kafa karışmasın diye silinebilir.

## Yerel geliştirme


- **`.local/` canlının birebir kopyası, ama `api/index.php` ve `api/render.php` oraya kopyalanıyor, bağlanmıyor.**
  → `kodmob-core/public/` altında değişiklik yaptıktan sonra `npm run deploy:local` çalıştırın. `kodmob-core` ise bağlantı (junction), orada değişiklik anında geçer.
- **`.local/` silinip yeniden kurulabilir, ama `public_html/media/` silinirse yerelde yüklenen görseller gider.**
- **Yerel sunucu (`serve:local`) artık önde Node vekil, arkada 4 `php -S` süreciyle çalışıyor, ama Windows'ta kapatılınca PHP süreçleri kalabiliyor.** PHP'nin yerleşik sunucusu tek iş parçacıklı ve Chrome'un boş bağlantılarında 15–20 sn takılıyordu (panelde "Sunucu yanıt vermedi"); vekil istekleri tam okuyup boştaki sürece veriyor (`kodmob-frontend/scripts/php-pool.mjs`, `KM_WORKERS=1` eskisi gibi). Terminalde Ctrl+C süreçleri kapatır; pencere sertçe kapatılırsa 8090 dolu kalabilir.
  → "Port 8090 kullanımda" derse: `netstat -ano | findstr :8090` ile PID'i bulup `taskkill /PID <pid> /F`.
- **Uçtan uca senaryoların bir kısmı başlangıç profilinde atlanıyor** (portföy türü, SMS, Jarvis ya da çok site isteyenler; raporda "bu derlemede yok: …"). Senaryolar profilin verdiğini sınar; bütün modüller açık bir derlemede hepsi koşar.
- **Backend SQLite, MySQL ve PostgreSQL'de test ediliyor (`cd kodmob-frontend && npm run test:db`), ama Hostinger paylaşımlı hostingde PostgreSQL yok.** Orada MySQL ya da SQLite; PostgreSQL VPS içindir. `test:db` MySQL için MAMP'ın açık olmasını ister (ayrı `kodmob_test` veritabanını kendi açar, geliştirme veritabanına dokunmaz); PostgreSQL'i kendisi başlatıp durdurur (`<proje>/.pg`, port 54329). MAMP'ın `php.ini`'sinde `pdo_pgsql` kapalı; betik eklentiyi komut satırında açar. Elle PostgreSQL'e bağlanan bir artisan komutu da `php -d extension=pdo_pgsql artisan …` ister.
- **`kodmob:db-move` siteyi başka veritabanına taşıyor, ama yazma sırasında site açıksa son birkaç kayıt kaçabilir.** Kaynak yalnız okunur; hedef dolularsa `--force` olmadan yazmaz. Taşıma sonrası `.env`'deki `DB_*` satırları elle yeni veritabanına çevrilir.
  → Canlıda taşımadan önce bakım modu (`php artisan down`) ve yedek.
  → Depo GitHub'a itilince CI kendiliğinden çalışır (kökte `.github/workflows/ci.yml`; tek depo olduğu için ek ayar gerekmez). Yeni ekran ya da akış eklenince `e2e/specs/`'e senaryo eklenmeli.
