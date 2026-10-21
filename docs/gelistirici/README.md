# Geliştirici belgeleri

Kodmob ile yeni bir proje (kurumsal site, ürün sitesi, küçük bir SaaS'ın paneli) başlatan geliştiriciler için.
Sırayla okumanız önerilir; her belge kısa ve koddaki gerçek dosyalara bağlanır.

| Belge | Konu |
|---|---|
| [01 Kurulum](01-kurulum.md) | Gerekenler, yeni proje, yerel site, testler |
| [02 Mimari](02-mimari.md) | Çatı / proje / paket, istek akışı, modüller, çok proje, veritabanları |
| [03 Modül ve içerik türü](03-modul.md) | Modül kancaları, içerik türü üretici, isteğe bağlı modüller |
| [04 Sayfalar](04-sayfalar.md) | Site sayfası (profil, rota, şablon, metin) ve panel sayfası |
| [05 Ayar, yetki, uç](05-ayar-yetki.md) | Ayar tanımı, yetkiler ve roller, uç kuralları, işlem geçmişi |
| [06 Yayın](06-yayin.md) | Paket, paylaşımlı hostinge kurulum, cron, alt projeler |
| [07 Güncelleme](07-guncelleme.md) | Çatı sürümünü projeye almak (`kodmob:upgrade`) |
| [08 Bloklar](08-bloklar.md) | Sayfa düzenleyici: bölgeler, blok yazmak, şablon dili |
| [09 Yasal belgeler](09-yasal-belgeler.md) | Gizlilik, KVKK, çerez, sözleşmeler: modüle göre belgeler, şablon verisi, panelden özelleştirme |
| [Araç paketi](../arac-paketi.md) | Topluluk aracı yazmak, kurmak, kataloğa eklemek |

Diğerleri: [CHANGELOG](../../CHANGELOG.md), açık riskler ve bilinen sınırlar [dikkat.md](../dikkat.md), yapay zekâ
araçları için kurallar [CLAUDE.md](../../CLAUDE.md) (proje şablonu: [sablonlar/CLAUDE.proje.md](../sablonlar/CLAUDE.proje.md)),
belge dizini [llms.txt](../llms.txt). Tasarım kararlarının geçmişi `docs/*-raporu.md`'dedir.

**Kodmob ne verir:** kullanıcılar, roller ve yetkiler, iki adımlı doğrulama, işlem geçmişi, içerik türleri ve
editör, medya, blog, talepler (formlar), çoklu dil (adresler, çeviriler, 404), SEO (site haritası, yönlendirmeler,
yapay zekâ görünürlüğü), yedek (otomatik, uzak), e-posta ve SMS, arka plan işleri, Sistem Sağlığı, panel araması,
Kullanım Kılavuzu, tek kurulumdan çok proje, yapay zekâ sağlayıcıları (Claude, OpenAI, Gemini) ve Jarvis asistanı,
araç paketleri. Paylaşımlı hostinge sunucuda derleme gerekmeden kurulur.
