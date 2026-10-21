---
layout: home

hero:
  name: Kodmob
  text: Kurumsal site ve yönetim paneli çatısı
  tagline: Kimlik, roller, çok dil, SEO, yedek, panel ve sistem sağlığı hazır; siz yalnızca projenize özgü kodu yazın. Paylaşımlı hostinge sunucuda derleme gerekmeden kurulur.
  actions:
    - theme: brand
      text: Başlayın
      link: /gelistirici/01-kurulum
    - theme: alt
      text: Mimari
      link: /gelistirici/02-mimari
    - theme: alt
      text: GitHub
      link: https://github.com/kodmob/kodmob

features:
  - title: Hazır altyapı
    details: Kullanıcılar, roller ve yetkiler, iki adımlı doğrulama, işlem geçmişi, otomatik ve şifreli uzak yedek, e-posta ve SMS, arka plan işleri, Sistem Sağlığı.
  - title: Çok dilli site ve SEO
    details: Dile göre adresler ve çeviriler, 301 adres geçmişi, site haritası, yapısal veri, llms.txt, yapay zekâ botları için görünürlük raporu.
  - title: Kayıt tabanlı mimari
    details: Yeni özellik çoğunlukla bir kayda bir satırdır (modül kancası, yetki, ayar, içerik türü). Çatı projenin koduna bağlanmaz; güncelleme projenin dosyalarına dokunmaz.
  - title: Sayfa düzenleyici ve bloklar
    details: Panelden sürükle-bırak bloklar, yerinde yazma, taslak ve yayın geçmişi. Blok yazmak bir block.json ve bir şablondur.
  - title: İsteğe bağlı araçlar
    details: Mağaza, Stripe ve PayTR, Rezervasyon, Oteller, Bülten, Jarvis asistanı, çok proje. Araç Pazarı'ndan proje başına açılıp kapanır; topluluk araç paketleri.
  - title: Yapay zekâ ile geliştirmeye hazır
    details: Asistan rehberi (CLAUDE.md / AGENTS.md), koddan üretilen sözleşme ve OpenAPI, niyet dosyasından içerik türü. Asistanınız yalnızca işinize özgü kodu yazar.
---

## Hızlı başlangıç

Node.js 20+, PHP 8.4 (pdo_sqlite) ve Composer yeterli; yerelde veritabanı SQLite dosyasıdır.

```bash
git clone https://github.com/kodmob/kodmob.git benim-projem && cd benim-projem
cd kodmob-api && composer install
php artisan kodmob:install --profile=benim-sitem      # .env, anahtarlar, tablolar, ilk yönetici, site profili
cd ../tv && npm ci && npm run deploy:local && npm run serve:local
# site: http://127.0.0.1:8090/   panel: http://127.0.0.1:8090/panel/
```

Sonra [01 Kurulum](gelistirici/01-kurulum.md) ve [02 Mimari](gelistirici/02-mimari.md). Laravel 12 API, Vite 7,
Alpine 3, Tailwind 4 ve Nunjucks; SQLite, MySQL / MariaDB ve PostgreSQL; tek kurulumdan çok proje.

::: info In English
Kodmob is an MIT-licensed starter framework for company websites with a multi-user admin panel (Laravel 12 API,
Vite + Alpine.js + Tailwind front end). It installs on shared hosting without a server-side build and ships users,
roles, two-factor sign-in, an activity log, content types, multilingual URLs, SEO, backups, mail and SMS, background
jobs, a page builder and optional tools (shop, bookings, newsletter, AI assistant). The panel and these docs are in
Turkish for now; English issues and pull requests are welcome.
:::
