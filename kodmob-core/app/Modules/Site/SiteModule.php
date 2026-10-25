<?php

namespace App\Modules\Site;

use App\Core\Content\Types;
use App\Core\Module;
use App\Core\Security\Capability;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Modules\Briefs\BriefsModule;
use App\Modules\Site\Legal\LegalData;
use App\Modules\Site\Pages\PageType;
use Illuminate\Support\Facades\DB;

/**
 * Site geneli: iletişim bilgileri, WhatsApp, çalışma saatleri, sosyal medya ve
 * yasal metinlerdeki veri sorumlusu bilgileri.
 *
 * Değerler /panel/ > Ayarlar'dan düzenlenir; herkese açık olanlar `GET /site`
 * ve sayfa verisiyle (Content\PageData) sayfalara gider.
 */
final class SiteModule extends Module
{
    public const EMAIL = 'site.email';

    public const PHONE = 'site.phone';

    public const ADDRESS = 'site.address';

    public const WHATSAPP = 'site.whatsapp';

    public const HOURS_START = 'site.hours_start';

    public const HOURS_END = 'site.hours_end';

    public const LINKEDIN = 'site.linkedin';

    public const INSTAGRAM = 'site.instagram';

    public const YOUTUBE = 'site.youtube';

    public const X = 'site.x';

    public const LEGAL_NAME = 'legal.name';

    public const LEGAL_ADDRESS = 'legal.address';

    public const LEGAL_EMAIL = 'legal.email';

    public const LEGAL_TAX = 'legal.tax';

    public const LEGAL_MERSIS = 'legal.mersis';

    public const LEGAL_KEP = 'legal.kep';

    public const LEGAL_COURT = 'legal.court';

    /** Sıradaki adımlar: kimlik, iletişim ve yasal bilgiler, ana sayfa, ilk içerik. */
    /** Genel bakış kartları (Core\Dashboard\Widgets): içerik sayıları ve son değişen kayıtlar. */
    public function widgets(): array
    {
        $statuses = ['publish' => ['Yayında', 'success'], 'draft' => ['Taslak', 'neutral'], 'future' => ['Zamanlandı', 'info'], 'private' => ['Gizli', 'warning']];

        return [
            ['key' => 'content', 'label' => 'İçerik', 'icon' => 'layers', 'type' => 'stats', 'size' => 'lg', 'order' => 10, 'can' => Types::capabilities(),
                'description' => 'Her içerik türünde kayıt sayısı; yayında ve taslak.',
                'data' => static function () {
                    $items = [];
                    foreach (Types::editable() as $name => $type) {
                        $rows = $type->adminList();
                        $published = count(array_filter($rows, static fn ($r) => $r['status'] === 'publish'));
                        $draft = count(array_filter($rows, static fn ($r) => $r['status'] === 'draft'));
                        $items[] = ['label' => $type->labels()[0], 'value' => count($rows), 'sub' => $published.' yayında'.($draft ? " · {$draft} taslak" : ''), 'route' => 'list', 'params' => ['type' => $name]];
                    }

                    return ['items' => $items];
                }],
            ['key' => 'recent', 'label' => 'Son değişenler', 'icon' => 'clock', 'type' => 'list', 'size' => 'md', 'order' => 20, 'can' => Types::capabilities(),
                'description' => 'En son düzenlenen kayıtlar.',
                'data' => static function () use ($statuses) {
                    $rows = [];
                    foreach (Types::editable() as $name => $type) {
                        foreach ($type->adminList() as $row) {
                            $rows[] = ['type' => $name, 'singular' => $type->labels()[1]] + $row;
                        }
                    }
                    usort($rows, static fn ($a, $b) => strcmp((string) $b['modified'], (string) $a['modified']));

                    return ['empty' => 'Henüz kayıt yok.', 'items' => array_map(static fn ($r) => [
                        'title' => (string) ($r['title'] ?: 'Adsız'), 'meta' => $r['singular'], 'time' => Clock::local((string) $r['modified']),
                        'badge' => $statuses[$r['status']] ?? null, 'route' => 'editor', 'params' => ['type' => $r['type'], 'id' => $r['id']],
                    ], array_slice($rows, 0, 6))];
                }],
        ];
    }

    public function steps(): array
    {
        $s = SettingsService::instance();
        $filled = static fn (string ...$keys) => array_filter($keys, static fn ($k) => trim($s->string($k)) !== '') !== [];
        // Blok sayfası (kampanya…) ilk içerik sayılmaz: içerik türlerinin kayıtları ya da blog.
        $published = static fn (string $table) => DB::table($table)->where('status', 'publish')->whereNull('deleted_at')
            ->when($table === 'contents', static fn ($q) => $q->where('type', '!=', PageType::POST_TYPE))->exists();

        return [
            ['key' => 'identity', 'order' => 10, 'label' => 'Kimlik ve logo', 'hint' => 'Site adı, logo ve ana renk: sitede ve e-postalarda görünür.',
                'done' => $filled(SiteProfile::NAME, SiteProfile::LOGO) || $s->int(SiteProfile::LOGO_IMAGE) > 0, 'route' => 'projectSettings', 'anchor' => SiteProfile::NAME, 'can' => Capability::MANAGE_SETTINGS],
            ['key' => 'contact', 'order' => 20, 'label' => 'İletişim ve yasal bilgiler', 'hint' => 'E-posta, telefon, adres; yasal belgelerdeki unvan ve yetkili mahkeme.',
                'done' => $filled(self::EMAIL) && $filled(self::PHONE) && LegalData::missing() === [], 'route' => 'projectSettings', 'anchor' => self::EMAIL, 'can' => Capability::MANAGE_SETTINGS],
            ['key' => 'home', 'order' => 30, 'label' => 'Ana sayfayı düzenleyin', 'hint' => 'Sayfa düzenleyicide bölümleri kendi metninizle yazıp Yayınla.',
                'done' => DB::table('page_layouts')->where('zone', 'like', 'home.%')->whereNotNull('published_at')->exists(), 'route' => 'builder', 'can' => Capability::EDIT_LAYOUT],
            ['key' => 'content', 'order' => 40, 'label' => 'İlk içerikleri ekleyin', 'hint' => 'Blog yazısı ya da projenizin kayıtları; taslak sitede görünmez.',
                'done' => $published('contents') || $published('posts'), 'route' => 'list', 'params' => ['type' => 'posts'], 'can' => Types::capabilities()],
        ];
    }

    public function settings(): array
    {
        return [
            // Kimlik (SiteProfile): boşsa derlemedeki ad ve logo; aynı temayı kullanan alt site kendi adını buradan verir.
            new SettingDefinition(SiteProfile::NAME, 'string', '', 'Site adı', 'Kimlik', 'Sayfa başlıklarının eki, paylaşım kartları, alt bilgi, yapısal veri ve e-postalarda geçer. Boşsa derlemedeki ad.'),
            new SettingDefinition(SiteProfile::LOGO, 'string', '', 'Logo yazısı', 'Kimlik', 'Üst bar ve alt bilgideki logo yazısı. Boşsa derlemedeki.'),
            new SettingDefinition(SiteProfile::LOGO_SUB, 'string', '', 'Logo alt yazısı', 'Kimlik', 'Logonun yanındaki küçük yazı (bütün dillerde aynı). Boşsa derlemedeki, dile göre.'),
            new SettingDefinition(SiteProfile::LOGO_IMAGE, 'int', 0, 'Logo görseli', 'Kimlik', 'Seçilirse logo yazısının yerine üst bar ve alt bilgide görünür. Yüksekliği 36 piksel gösterilir; yatay, şeffaf zeminli (SVG ya da PNG) olmalı.', 0, null, '', [], 'image'),
            new SettingDefinition(SiteProfile::ICON, 'int', 0, 'Proje ikonu', 'Kimlik', 'Tarayıcı sekmesinde, telefona eklenen uygulamada ve anlık bildirimlerde görünür. Kare, en az 512 × 512 piksel PNG ya da JPG; boşsa derlemedeki simge.', 0, null, '', [], 'image'),
            new SettingDefinition(SiteProfile::COLOR, 'string', '', 'Ana renk', 'Kimlik', 'Düğmeler, bağlantılar ve vurgular; açık ve koyu tonları kendiliğinden türetilir. Boşsa derlemedeki renk. Beyaz yazıyla okunacak kadar koyu seçin.', null, null, '', [], 'color'),
            new SettingDefinition(SiteProfile::COLOR_DARK, 'string', '', 'Koyu bant rengi', 'Kimlik', 'Öne çıkan bölümler, çağrı bandı ve alt bilgi vurgularındaki koyu zemin. Boşsa derlemedeki.', null, null, '', [], 'color'),
            new SettingDefinition(self::EMAIL, 'string', '', 'E-posta', 'İletişim', 'Sitede gösterilen iletişim adresi.'),
            new SettingDefinition(self::PHONE, 'string', '', 'Telefon', 'İletişim', 'Sitede gösterilen numara, ör. +90 535 000 00 00. Boşsa gösterilmez.'),
            new SettingDefinition(self::ADDRESS, 'string', '', 'Adres', 'İletişim', 'İletişim, Hakkımızda ve yasal metinlerde gösterilen açık adres. Boşsa yalnızca şehir (footer) gösterilir.'),
            new SettingDefinition(self::WHATSAPP, 'string', '', 'WhatsApp numarası', 'İletişim', 'Ülke koduyla, yalnızca rakam: 905320000000. Boşsa WhatsApp düğmesi gösterilmez.'),
            new SettingDefinition(self::HOURS_START, 'int', 9, 'Çalışma başlangıcı', 'İletişim', 'Hafta içi, Türkiye saati. WhatsApp düğmesi bu saatlerde "çevrimiçi" görünür.', 0, 23, 'saat'),
            new SettingDefinition(self::HOURS_END, 'int', 18, 'Çalışma bitişi', 'İletişim', 'Hafta içi, Türkiye saati.', 1, 24, 'saat'),
            new SettingDefinition(self::LINKEDIN, 'string', '', 'LinkedIn', 'Sosyal medya', 'Profil adresi. Boşsa simge gösterilmez.'),
            new SettingDefinition(self::INSTAGRAM, 'string', '', 'Instagram', 'Sosyal medya', 'Profil adresi.'),
            new SettingDefinition(self::YOUTUBE, 'string', '', 'YouTube', 'Sosyal medya', 'Kanal adresi.'),
            new SettingDefinition(self::X, 'string', '', 'X (Twitter)', 'Sosyal medya', 'Profil adresi.'),
            new SettingDefinition(self::LEGAL_NAME, 'string', '', 'Veri sorumlusu', 'Yasal', 'KVKK, gizlilik ve çerez metinlerinde geçer. Şahıs şirketinde ad soyad ve ticari ad, ör. "Ad Soyad – Kodmob Yazılım". Boşsa metinlerde köşeli parantezle uyarı görünür; yayından önce doldurun.'),
            new SettingDefinition(self::LEGAL_ADDRESS, 'string', '', 'Adres', 'Yasal', 'Veri sorumlusunun tebligat adresi. Boşsa iletişim adresi kullanılır.'),
            new SettingDefinition(self::LEGAL_EMAIL, 'string', '', 'KVKK başvuru e-postası', 'Yasal', 'İlgili kişi başvuruları bu adrese yapılır. Boşsa iletişim e-postası kullanılır.'),
            new SettingDefinition(self::LEGAL_TAX, 'string', '', 'Vergi dairesi ve no', 'Yasal', 'İsteğe bağlı, ör. "Kozan V.D. 1234567890". Doluysa metinlerde gösterilir.'),
            new SettingDefinition(self::LEGAL_MERSIS, 'string', '', 'MERSİS numarası', 'Yasal', 'Şirketlerde (şahıs işletmesinde yok). Doluysa yasal belgelerde gösterilir.'),
            new SettingDefinition(self::LEGAL_KEP, 'string', '', 'KEP adresi', 'Yasal', 'Kayıtlı elektronik posta; doluysa KVKK başvuru yolu olarak yazılır.'),
            new SettingDefinition(self::LEGAL_COURT, 'string', '', 'Yetkili mahkeme', 'Yasal', 'Kullanım koşullarındaki yetkili mahkeme ve icra daireleri, ör. "Adana". Tüketicilerin hakem heyeti ve tüketici mahkemesi hakkı ayrıca yazılır.'),
        ];
    }

    /** Blok sayfaları (Sayfa düzenleyiciden açılan sayfalar; her projede). */
    public function contentTypes(): array
    {
        return [PageType::class];
    }

    /**
     * `GET /site` ve sayfa verisi: herkese açık site bilgileri (iletişim, WhatsApp,
     * çalışma saatleri, sosyal medya, yasal metinlerdeki veri sorumlusu, talep
     * saklama süresi). Gizli ayar dönmez.
     */
    public static function payload(): array
    {
        $s = SettingsService::instance();
        $social = array_filter([
            'linkedin' => $s->string(self::LINKEDIN),
            'instagram' => $s->string(self::INSTAGRAM),
            'youtube' => $s->string(self::YOUTUBE),
            'x' => $s->string(self::X),
        ]);

        return [
            'email' => $s->string(self::EMAIL),
            'phone' => $s->string(self::PHONE),
            'address' => $s->string(self::ADDRESS),
            'whatsapp' => preg_replace('/\D+/', '', $s->string(self::WHATSAPP)),
            'hours' => [
                'start' => $s->int(self::HOURS_START),
                'end' => $s->int(self::HOURS_END),
                'days' => [1, 2, 3, 4, 5],
                'timezone' => 'Europe/Istanbul',
            ],
            'social' => (object) $social,
            'legal' => [
                'name' => $s->string(self::LEGAL_NAME),
                'address' => $s->string(self::LEGAL_ADDRESS) ?: $s->string(self::ADDRESS),
                'email' => $s->string(self::LEGAL_EMAIL) ?: $s->string(self::EMAIL),
                'tax' => $s->string(self::LEGAL_TAX),
            ],
            'retentionMonths' => $s->int(BriefsModule::RETENTION_MONTHS),
        ];
    }
}
