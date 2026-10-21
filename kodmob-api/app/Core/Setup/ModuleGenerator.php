<?php

namespace App\Core\Setup;

use App\Core\Content\Types;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Text;
use Illuminate\Support\Str;

/**
 * Yeni içerik türü iskeleti (`php artisan kodmob:module`). Üretilen tür panelde listesi, formu,
 * dil sekmeleri, sıralaması, Silinenler'i, işlem kaydı, canlı şeridi, yetkisi ve yedeğiyle hemen
 * çalışır; hepsi tür kaydından (Types) türetilir. Yalnızca projenin dosyalarına yazar (çatı
 * güncellemesi bunlara dokunmaz):
 *
 *   app/Project/<Ad>/<Tür>Type.php             alanlar (shared, localized) ve ziyaretçi çıktısı
 *   config/project.php                         content_types listesine kayıt
 *   tests/Project/<Ad>ContentTest.php          ekle, listele, güncelle, sil, yetki
 *   ../tv/src/profiles/<ad>/panel.js           panel menüsü ve adresi (tv yanındaysa; etkin profil)
 *   ../tv/src/profiles/<ad>/site.config.js     açık içerik türleri (`profile.contentTypes`)
 *
 * Sitede gösterilmesi (sayfa şablonu, rota) projeye özgüdür; elle eklenir.
 */
final class ModuleGenerator
{
    private const MARKER = '// kodmob:module';

    /**
     * Rezervasyonlu türün birimine göre ilk özellikler ve sayfa bölümleri (Modules\Commerce\Booking\BookingProfile;
     * üretilen sınıfta açıkça yazılır, geliştirici değiştirir).
     */
    private const BOOKABLE = [
        'person' => [['packages', 'groups', 'seasons', 'early', 'capacity', 'dates'], ['highlights', 'itinerary', 'includes', 'excludes', 'faq', 'meeting', 'languages']],
        'day' => [['packages', 'seasons', 'early', 'capacity'], ['highlights', 'includes', 'excludes', 'faq']],
        'fixed' => [['packages', 'seasons', 'early', 'capacity', 'dates'], ['highlights', 'includes', 'excludes', 'faq', 'meeting']],
        'night' => [['seasons', 'early', 'capacity'], ['highlights', 'amenities', 'includes', 'faq']],
    ];

    /**
     * @param  array{name:string,label:string,singular:string,slug?:string,record?:string,icon?:string,orderable?:bool,tv?:?string,bookable?:string,fields?:array{shared:array,localized:array},purpose?:string}  $o
     * @return list<array{path:string,action:string,content:string}> yazılacaklar (dosya, işlem, içerik)
     */
    public function plan(array $o): array
    {
        $name = strtolower(trim($o['name']));
        if (! preg_match('/^[a-z][a-z0-9_]{1,30}$/', $name)) {
            throw ValidationException::field('name', 'Ad küçük harf İngilizce çoğul olmalı (ör. products).');
        }
        $record = strtolower(trim((string) ($o['record'] ?? '') ?: (str_ends_with($name, 'ies') ? substr($name, 0, -3).'y' : rtrim($name, 's'))));
        $label = trim($o['label']);
        $singular = trim($o['singular']);
        $slug = Text::slug((string) ($o['slug'] ?? '') ?: $label);
        $icon = preg_match('/^[a-z0-9-]+$/', (string) ($o['icon'] ?? '')) ? $o['icon'] : 'layers';
        $orderable = (bool) ($o['orderable'] ?? true);
        $module = Str::studly($name);
        $class = Str::studly($record).'Type';
        $tv = $o['tv'] ?? null;
        // Rezervasyonlu tür (Rezervasyon modülünün profil katmanı; docs/done-onyuz-raporu.md §2): birim.
        $bookable = strtolower(trim((string) ($o['bookable'] ?? '')));
        if (! in_array($bookable, ['', ...array_keys(self::BOOKABLE)], true)) {
            throw ValidationException::field('bookable', 'Rezervasyon birimi person (kişi başı), day (gün başı), fixed (sabit fiyat) ya da night (gece başı oda) olmalı.');
        }

        if ($label === '' || $singular === '') {
            throw ValidationException::field('label', 'Çoğul ve tekil etiket gerekli (ör. --label=Ürünler --singular=Ürün).');
        }
        // Çatının modüllerinin türleri (blog, blok sayfaları, Mağaza'nın ürünleri) modül kapalıyken de ayrılmıştır.
        if (in_array($name, ['posts', 'pages', 'products', 'tours', 'hotels'], true) || in_array($record, ['post', 'page', 'product', 'tour', 'hotel'], true)) {
            throw ValidationException::field('name', "'{$name}' çatının türü (Blog / Blok sayfaları / Mağaza / Rezervasyon / Oteller); başka bir ad seçin.");
        }
        if (isset(Types::editable()[$name]) || Types::byKind($record)) {
            throw ValidationException::field('name', "'{$name}' türü zaten var.");
        }
        $typeFile = base_path("app/Project/{$module}/{$class}.php");
        if (is_file($typeFile)) {
            throw ValidationException::field('name', "Dosya zaten var: app/Project/{$module}/{$class}.php");
        }
        $registry = config_path('project.php');
        $registryText = is_file($registry) ? (string) file_get_contents($registry) : '';

        $files = [
            ['path' => $typeFile, 'action' => 'yeni', 'content' => $bookable !== '' ? $this->bookableClass($module, $class, $name, $record, $label, $singular, $bookable) : $this->typeClass($module, $class, $name, $record, $label, $singular, $o['fields'] ?? null, (string) ($o['purpose'] ?? ''))],
            ['path' => base_path("tests/Project/{$module}ContentTest.php"), 'action' => 'yeni', 'content' => $this->testClass($module, $name, $record, $label, $bookable)],
            $this->insert($registry, [
                // İlk `use` satırının önüne; dosyada hiç yoksa açılış etiketinin arkasına.
                preg_match('/^use [^;]+;$/m', $registryText)
                    ? ['/^use [^;]+;$/m', "use App\\Project\\{$module}\\{$class};\n", 'before', true]
                    : ["<?php\n", "\nuse App\\Project\\{$module}\\{$class};\n", 'after'],
                [self::MARKER, "{$class}::class,\n        ", 'before'],
            ]),
        ];

        if ($tv !== null) {
            // Panel kaydı ve açık türler etkin profilde (tv/profile.js); çatının panel dosyaları değişmez.
            $dir = $this->profileDir($tv);
            $panel = $dir.'/panel.js';
            if (! is_file($panel)) {
                throw ValidationException::field('tv', "Profilin panel dosyası yok: {$panel}");
            }
            $taken = (string) file_get_contents($panel).(string) @file_get_contents(rtrim($tv, '/\\').'/src/panel/framework-types.js');
            if (preg_match("/slug: '".preg_quote($slug, '/')."'/", $taken) || in_array($slug, $this->panelSegments($tv), true)) {
                throw ValidationException::field('slug', "Panel adresi '{$slug}' kullanımda; --slug ile başka bir ad verin.");
            }
            $newLabel = 'Yeni '.mb_strtolower($singular, 'UTF-8');
            $entry = sprintf("%s: { label: '%s', singular: '%s', icon: '%s', orderable: %s, newLabel: '%s', record: '%s', can: 'km_manage_%s', slug: '%s' },\n    ",
                $name, $this->js($label), $this->js($singular), $icon, $orderable ? 'true' : 'false', $this->js($newLabel), $record, $name, $slug);
            $files[] = $this->insert($panel, [[self::MARKER, $entry, 'before']]);
            // Profil açık türleri listeliyorsa yeni tür de açık olsun.
            $profile = $dir.'/site.config.js';
            if (is_file($profile) && str_contains((string) file_get_contents($profile), self::MARKER)) {
                $files[] = $this->insert($profile, [[self::MARKER, "'{$name}',\n            ", 'before']]);
            }
        }

        return $files;
    }

    /** Etkin site profilinin klasörü: KM_PROFILE ya da tv/profile.js'teki varsayılan. */
    private function profileDir(string $tv): string
    {
        $tv = rtrim($tv, '/\\');
        $name = (string) getenv('KM_PROFILE');
        if ($name === '' && preg_match("/KM_PROFILE \\|\\| '([a-z0-9_-]+)'/", (string) @file_get_contents($tv.'/profile.js'), $m)) {
            $name = $m[1];
        }

        return $tv.'/src/profiles/'.($name !== '' ? $name : 'starter');
    }

    /** @param list<array{path:string,action:string,content:string}> $files */
    public function write(array $files): void
    {
        foreach ($files as $file) {
            @mkdir(dirname($file['path']), 0755, true);
            file_put_contents($file['path'], $file['content']);
        }
    }

    /**
     * Var olan dosyaya ekleme (işaretin önüne ya da arkasına; satır sonları korunur).
     *
     * @param  list<array{0:string,1:string,2:string,3?:bool}>  $edits  [işaret ya da desen, eklenen, before|after, desen mi]
     */
    private function insert(string $path, array $edits): array
    {
        if (! is_file($path)) {
            throw ValidationException::field('file', "Dosya yok: {$path}");
        }
        $raw = (string) file_get_contents($path);
        $crlf = str_contains($raw, "\r\n");
        $text = str_replace("\r\n", "\n", $raw);
        foreach ($edits as $edit) {
            [$needle, $add, $where] = $edit;
            if ($edit[3] ?? false) {
                preg_match($needle, $text, $m, PREG_OFFSET_CAPTURE);
                $at = $m ? $m[0][1] : false;
                $needleLength = $m ? strlen($m[0][0]) : 0;
            } else {
                $at = strpos($text, $needle);
                $needleLength = strlen($needle);
            }
            if ($at === false) {
                throw ValidationException::field('file', "{$path}: '{$needle}' işareti bulunamadı.");
            }
            $offset = $where === 'after' ? $at + $needleLength : $at;
            $text = substr($text, 0, $offset).$add.substr($text, $offset);
        }

        return ['path' => $path, 'action' => 'güncellenir', 'content' => $crlf ? str_replace("\n", "\r\n", $text) : $text];
    }

    /** Panel rota tablosunun sabit ilk parçaları (talepler, ayarlar…): tür adresi bunlarla çakışmasın. */
    private function panelSegments(string $tv): array
    {
        $routes = (string) @file_get_contents(rtrim($tv, '/\\').'/src/panel/routes.js');
        preg_match_all("/path: '([a-z0-9-]+)/", $routes, $m);

        return array_values(array_unique($m[1]));
    }

    private function js(string $text): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $text);
    }

    private function php(string $text): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $text);
    }

    /** Alan tanımları PHP dizisi olarak (sınıf gövdesinin girintisiyle). */
    private function phpFields(array $fields): string
    {
        if ($fields === []) {
            return '[]';
        }
        $lines = [];
        foreach ($fields as $name => $field) {
            $lines[] = "                        '{$name}' => ".$this->phpValue($field).',';
        }

        return "[\n".implode("\n", $lines)."\n                    ]";
    }

    private function phpValue(mixed $value): string
    {
        if (is_array($value)) {
            $list = array_is_list($value);
            $parts = array_map(fn ($k, $v) => ($list ? '' : "'".$this->php((string) $k)."' => ").$this->phpValue($v), array_keys($value), $value);

            return '['.implode(', ', $parts).']';
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'null',
            default => "'".$this->php((string) $value)."'",
        };
    }

    /** Varsayılan alanlar (niyet dosyası alan vermezse): görsel, başlık, adres, özet, içerik. */
    private const DEFAULT_SHARED = [
        'cover' => ['type' => 'image', 'label' => 'Görsel', 'help' => 'Listede ve sayfada görünür.', 'section' => 'Genel'],
    ];

    private const DEFAULT_LOCALIZED = [
        'title' => ['type' => 'text', 'label' => 'Başlık', 'help' => 'Boşsa kayıt bu dilde gösterilmez.', 'section' => 'İçerik'],
        'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'section' => 'İçerik', 'width' => 'half'],
        'summary' => ['type' => 'textarea', 'label' => 'Özet', 'max' => 400, 'section' => 'İçerik'],
        'body' => ['type' => 'html', 'label' => 'İçerik', 'section' => 'İçerik'],
    ];

    /** @param array{shared:array,localized:array}|null $fields niyet dosyasının alanları (ModuleIntent) */
    private function typeClass(string $module, string $class, string $name, string $record, string $label, string $singular, ?array $fields = null, string $purpose = ''): string
    {
        $label = $this->php($label);
        $singular = $this->php($singular);
        $shared = $fields === null ? self::DEFAULT_SHARED : $fields['shared'];
        $localized = $fields === null ? self::DEFAULT_LOCALIZED : $fields['localized'];
        // Başlık ve adres her türde (liste, arama, site haritası); niyet dosyasında yoksa başa eklenir.
        $localized = ['title' => $localized['title'] ?? self::DEFAULT_LOCALIZED['title'], 'slug' => $localized['slug'] ?? self::DEFAULT_LOCALIZED['slug']] + $localized;
        $sharedPhp = $this->phpFields($shared);
        $localizedPhp = $this->phpFields($localized);
        $public = implode("\n", array_map(static fn ($key) => "                        '{$key}' => \$item['{$key}'],", ['id', 'order', 'slugs', ...array_keys($shared), ...array_keys($localized)]));
        $about = $purpose !== '' ? ' * '.str_replace("\n", "\n * ", wordwrap(str_replace('*/', '* /', $purpose), 110))."\n *\n" : '';

        return <<<PHP
            <?php

            namespace App\\Project\\{$module};

            use App\\Core\\Content\\ContentType;

            /**
            {$about} * {$label} (php artisan kodmob:module ile üretildi). Alanlar `shared()` (dilden bağımsız) ve
             * `localized()` (dil başına); panel listesi, formu ve dil sekmeleri bu şemadan çizilir.
             * Ziyaretçiye giden biçim `publicItem()`. Sitede gösterilmesi (sayfa, rota) projeye özgüdür.
             */
            final class {$class} extends ContentType
            {
                public const POST_TYPE = 'km_{$record}';

                public function postType(): string
                {
                    return self::POST_TYPE;
                }

                public function name(): string
                {
                    return '{$name}';
                }

                public function labels(): array
                {
                    return ['{$label}', '{$singular}'];
                }

                public function shared(): array
                {
                    return {$sharedPhp};
                }

                public function localized(): array
                {
                    return {$localizedPhp};
                }

                protected function publicItem(array \$item, string \$locale): array
                {
                    return [
            {$public}
                    ];
                }
            }

            PHP;
    }

    /** Rezervasyonlu tür: alanlar, fiyat, sepet satırı, sitedeki kutu ve bölümler profilden (Bookable). */
    private function bookableClass(string $module, string $class, string $name, string $record, string $label, string $singular, string $unit): string
    {
        $label = $this->php($label);
        $singular = $this->php($singular);
        $list = static fn (array $items) => "['".implode("', '", $items)."']";
        [$features, $sections] = self::BOOKABLE[$unit];
        $constant = ['person' => 'PERSON', 'day' => 'DAY', 'fixed' => 'FIXED', 'night' => 'NIGHT'][$unit];
        $features = $list($features);
        $sections = $list($sections);

        return <<<PHP
            <?php

            namespace App\\Project\\{$module};

            use App\\Core\\Content\\ContentType;
            use App\\Modules\\Commerce\\Booking\\Bookable;
            use App\\Modules\\Commerce\\Booking\\BookingFields;
            use App\\Modules\\Commerce\\Booking\\BookingProfile;
            use App\\Modules\\Commerce\\Booking\\BookingView;

            /**
             * {$label} (php artisan kodmob:module --bookable ile üretildi): rezerve edilebilir tür. Rezervasyon profili
             * (birim, özellikler, sayfa bölümleri) fiyat alanlarını, kuralları, sepet satırını ve sitedeki kutuyu belirler;
             * Mağaza ve Rezervasyon modülleri profilde açık olmalı (`profile.modules`: 'commerce', 'booking'). Sitedeki
             * sayfa: profilin rotasında `dynamic: '{$record}'`, şablonda pages/shop/_booking-box.njk ve _booking-sections.njk.
             */
            final class {$class} extends ContentType implements Bookable
            {
                public const POST_TYPE = 'km_{$record}';

                public function postType(): string
                {
                    return self::POST_TYPE;
                }

                public function name(): string
                {
                    return '{$name}';
                }

                public function labels(): array
                {
                    return ['{$label}', '{$singular}'];
                }

                public function bookingProfile(): BookingProfile
                {
                    return new BookingProfile(BookingProfile::{$constant}, {$features}, {$sections});
                }

                public function shared(): array
                {
                    return BookingFields::shared(\$this->bookingProfile()) + [
                        'cover' => ['type' => 'image', 'label' => 'Görsel', 'help' => 'Listede ve sayfada görünür.', 'section' => 'Görseller'],
                        'gallery' => ['type' => 'gallery', 'label' => 'Diğer görseller', 'section' => 'Görseller'],
                    ];
                }

                public function localized(): array
                {
                    return [
                        'title' => ['type' => 'text', 'label' => 'Başlık', 'help' => 'Boşsa kayıt bu dilde gösterilmez.', 'section' => 'İçerik'],
                        'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'section' => 'İçerik', 'width' => 'half'],
                        'summary' => ['type' => 'textarea', 'label' => 'Özet', 'max' => 400, 'section' => 'İçerik'],
                        'body' => ['type' => 'html', 'label' => 'İçerik', 'section' => 'İçerik'],
                    ] + BookingFields::localized(\$this->bookingProfile());
                }

                protected function publicItem(array \$item, string \$locale): array
                {
                    return BookingView::item(\$this->bookingProfile(), \$item, \$locale) + [
                        'id' => \$item['id'],
                        'order' => \$item['order'],
                        'slug' => \$item['slug'],
                        'slugs' => \$item['slugs'],
                        'cover' => \$item['cover'],
                        'gallery' => \$item['gallery'],
                        'title' => \$item['title'],
                        'summary' => \$item['summary'],
                        'body' => \$item['body'],
                    ];
                }
            }

            PHP;
    }

    private function testClass(string $module, string $name, string $record, string $label, string $bookable = ''): string
    {
        $label = $this->php($label);
        $profileTest = $bookable === '' ? '' : <<<PHP

                public function test_type_is_bookable(): void
                {
                    \$this->assertSame('{$bookable}', Types::get('{$name}')->bookingProfile()->unit);
                }

            PHP;

        return <<<PHP
            <?php

            namespace Tests\\Project;

            use App\\Core\\Content\\Types;
            use App\\Core\\Security\\Capability;
            use Tests\\TestCase;

            /** {$label}: kodmob:module ile üretilen tür panelden yönetilir (kayıttan türeyen her şey). */
            class {$module}ContentTest extends TestCase
            {
                public function test_type_is_managed_from_the_panel(): void
                {
                    \$this->assertNotNull(Types::get('{$name}'));
                    \$this->assertTrue(Capability::exists('km_manage_{$name}'));
                    \$h = \$this->admin();

                    \$this->getJson('/v1/admin/schema/{$name}', \$h)->assertOk();
                    \$id = \$this->postJson('/v1/admin/content/{$name}', ['status' => 'publish', 'i18n' => ['tr' => ['title' => 'Deneme']]], \$h)->assertCreated()->json('id');
                    \$this->getJson('/v1/admin/content/{$name}', \$h)->assertOk()->assertJsonPath('items.0.id', \$id);
                    \$item = \$this->getJson("/v1/admin/content/{$name}/{\$id}", \$h)->assertOk()->json();
                    \$item['i18n']['tr']['title'] = 'Deneme 2';
                    \$this->putJson("/v1/admin/content/{$name}/{\$id}", \$item, \$h)->assertOk();
                    \$this->deleteJson("/v1/admin/content/{$name}/{\$id}", [], \$h)->assertNoContent();
                    \$this->getJson('/v1/records/hidden?type={$record}', \$h)->assertOk()->assertJsonPath('items.0.id', \$id);
                }

                public function test_author_without_capability_cannot_edit(): void
                {
                    \$this->getJson('/v1/admin/content/{$name}', \$this->as('sales'))->assertForbidden();
                }
            {$profileTest}}

            PHP;
    }
}
