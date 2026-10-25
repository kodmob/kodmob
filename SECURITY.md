# Güvenlik

## Açık bildirmek

Bir güvenlik açığı bulduysanız **herkese açık bir konu (issue) açmayın.** GitHub'da deponun **Security › Report a
vulnerability** düğmesiyle özel olarak bildirin. Bildirimde şunlar olsun:

- etkilenen sürüm (`kodmob.json` `version`) ve dosya / uç,
- açığı yeniden üretmenin adımları (mümkünse küçük bir örnek),
- etkisi (ör. yetkisiz okuma, oturum ele geçirme, kod çalıştırma).

İlk yanıtı 7 gün içinde vermeye çalışırız. Düzeltme yayımlanana kadar ayrıntıları paylaşmamanızı rica ederiz;
düzeltme sürümünün notlarında (CHANGELOG) isteğinize göre adınızla teşekkür ederiz.

## Desteklenen sürümler

Güvenlik düzeltmeleri son ana sürüme (3.x) gelir. Projenize almak için `php artisan kodmob:upgrade`
([docs/gelistirici/07-guncelleme.md](docs/gelistirici/07-guncelleme.md)).

## Kapsam

Kapsamda: bu depodaki kod (`kodmob-core/`, `kodmob-frontend/`), kurulum ve güncelleme komutları, varsayılan yapılandırma.
Kapsam dışı: barındırmanın kendi ayarları, projenin kendi kodu (`app/Project`, profiller), üçüncü taraf araç paketleri
(kendi sahiplerine bildirin), sosyal mühendislik ve hizmet engelleme denemeleri.

---

**English:** please do not open public issues for security problems. Report privately via **Security › Report a
vulnerability** on GitHub with the affected version, steps to reproduce and impact. We aim to respond within 7 days.
Fixes land in the latest major version (3.x).
