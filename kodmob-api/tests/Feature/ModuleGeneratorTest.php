<?php

namespace Tests\Feature;

use App\Core\Content\Types;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Capability;
use App\Core\Setup\ModuleGenerator;
use App\Core\Setup\ModuleIntent;
use App\Project\Courses\CourseType;
use App\Project\Lessons\LessonType;
use Tests\TestCase;

/**
 * kodmob:module: üretilen tür kendini tanımlar ve kayda eklenince yetki listesi, şema ve
 * Silinenler gibi her şey ondan türer. Yalnızca projenin dosyalarına yazar (app/Project,
 * config/project.php, tests/Project, profilin panel.js'i ve site.config.js'i); çatının dosyaları
 * değişmez. Test dosya yazmaz (plan() içerikleri döner).
 */
class ModuleGeneratorTest extends TestCase
{
    public function test_plan_scaffolds_a_self_describing_content_type(): void
    {
        $files = collect((new ModuleGenerator)->plan(['name' => 'courses', 'label' => 'Kurslar', 'singular' => 'Kurs', 'icon' => 'book', 'tv' => null]))
            ->keyBy(fn ($f) => str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $f['path']));

        $this->assertSame(['app/Project/Courses/CourseType.php', 'tests/Project/CoursesContentTest.php', 'config/project.php'], $files->keys()->all());
        $config = str_replace("\r\n", "\n", $files['config/project.php']['content']);
        $this->assertStringContainsString('use App\\Project\\Courses\\CourseType;', $config);
        $this->assertStringContainsString("CourseType::class,\n        // kodmob:module", $config);
        $this->assertStringContainsString('namespace Tests\\Project;', $files['tests/Project/CoursesContentTest.php']['content']);

        // Üretilen sınıf yüklenir ve kendini tanımlar.
        $file = sys_get_temp_dir().'/km-course-type-'.uniqid().'.php';
        file_put_contents($file, $files['app/Project/Courses/CourseType.php']['content']);
        require_once $file;
        @unlink($file);
        $type = new CourseType;
        $this->assertSame(['courses', 'course', 'km_manage_courses', ['Kurslar', 'Kurs']], [$type->name(), $type->recordKind(), $type->capability(), $type->labels()]);
        $this->assertArrayHasKey('title', $type->localized());

        // Kayda eklenince: yetki listesinde, editör rolünde, kayıt adından bulunur.
        config(['project.content_types' => [...config('project.content_types'), CourseType::class]]);
        Types::forget();
        $this->assertTrue(Capability::exists('km_manage_courses'));
        $this->assertSame('Kurslar ekleme ve düzenleme', Capability::all()['km_manage_courses']['label']);
        $this->assertSame($type::class, Types::byKind('course')::class);
        $this->assertStringContainsString('courses', Types::pattern());
    }

    /** --bookable: tür Bookable olur; fiyat alanları, kurallar, sayfa bölümleri ve sitedeki kutu profilden. */
    public function test_bookable_type_gets_a_booking_profile(): void
    {
        $files = collect((new ModuleGenerator)->plan(['name' => 'boats', 'label' => 'Tekneler', 'singular' => 'Tekne', 'bookable' => 'fixed', 'tv' => null]))
            ->keyBy(fn ($f) => str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $f['path']));
        $this->assertStringContainsString('test_type_is_bookable', $files['tests/Project/BoatsContentTest.php']['content']);

        $file = sys_get_temp_dir().'/km-boat-type-'.uniqid().'.php';
        file_put_contents($file, $files['app/Project/Boats/BoatType.php']['content']);
        require_once $file;
        @unlink($file);
        $class = 'App\\Project\\Boats\\BoatType';
        $type = new $class;
        $this->assertSame(['fixed', ['packages', 'seasons', 'early', 'capacity', 'dates']], [$type->bookingProfile()->unit, $type->bookingProfile()->features]);
        $this->assertArrayHasKey('price_fixed', $type->shared());
        $this->assertArrayHasKey('max_persons', $type->shared());
        $this->assertArrayHasKey('faq', $type->localized());
        $this->assertArrayNotHasKey('itinerary', $type->localized());

        // Gece birimi (villa, pansiyon): oda tipleri ve dil dil adları.
        $files = collect((new ModuleGenerator)->plan(['name' => 'villas', 'label' => 'Villalar', 'singular' => 'Villa', 'bookable' => 'night', 'tv' => null]))
            ->keyBy(fn ($f) => str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $f['path']));
        $file = sys_get_temp_dir().'/km-villa-type-'.uniqid().'.php';
        file_put_contents($file, $files['app/Project/Villas/VillaType.php']['content']);
        require_once $file;
        @unlink($file);
        $class = 'App\\Project\\Villas\\VillaType';
        $villa = new $class;
        $this->assertSame(['night', ['seasons', 'early', 'capacity']], [$villa->bookingProfile()->unit, $villa->bookingProfile()->features]);
        $this->assertArrayHasKey('rooms', $villa->shared());
        $this->assertArrayHasKey('amenities', $villa->localized());

        // Oteller çatının türü (Oteller aracı): ad ayrılmış.
        $this->expectException(ValidationException::class);
        (new ModuleGenerator)->plan(['name' => 'hotels', 'label' => 'Oteller', 'singular' => 'Otel', 'bookable' => 'night', 'tv' => null]);
    }

    /** tv yanındaysa panel kaydı etkin profilin panel.js'ine, açık türler site.config.js'ine; çatı dosyası değişmez. */
    public function test_panel_entry_goes_to_the_active_profile(): void
    {
        $tv = $this->storage.'/tv-'.uniqid();
        @mkdir($tv.'/src/profiles/demo', 0755, true);
        @mkdir($tv.'/src/panel', 0755, true);
        file_put_contents($tv.'/profile.js', "export const PROFILE = process.env.KM_PROFILE || 'demo';\n");
        file_put_contents($tv.'/src/profiles/demo/panel.js', "export const TYPES = {\n    // kodmob:module\n};\n");
        file_put_contents($tv.'/src/profiles/demo/site.config.js', "export const SITE = { profile: { contentTypes: [\n            // kodmob:module\n        ] } };\n");
        file_put_contents($tv.'/src/panel/framework-types.js', "export const FRAMEWORK_TYPES = { posts: { slug: 'blog' } };\n");
        file_put_contents($tv.'/src/panel/routes.js', "export const ROUTES = [{ name: 'briefs', path: 'talepler/:id?' }];\n");

        $prefix = str_replace('\\', '/', $tv).'/';
        $files = collect((new ModuleGenerator)->plan(['name' => 'courses', 'label' => 'Kurslar', 'singular' => 'Kurs', 'tv' => $tv]))
            ->keyBy(fn ($f) => str_replace($prefix, '', str_replace('\\', '/', $f['path'])));
        $this->assertTrue($files->has('src/profiles/demo/panel.js'));
        $this->assertStringContainsString("courses: { label: 'Kurslar', singular: 'Kurs', icon: 'layers', orderable: true, newLabel: 'Yeni kurs', record: 'course', can: 'km_manage_courses', slug: 'kurslar' },\n    // kodmob:module", $files['src/profiles/demo/panel.js']['content']);
        $this->assertStringContainsString("'courses',\n            // kodmob:module", $files['src/profiles/demo/site.config.js']['content']);
        $this->assertFalse($files->has('src/panel/types.js'));

        // Çatının (blog) ya da panelin sabit adresiyle çakışan adres reddedilir.
        foreach (['blog', 'talepler'] as $slug) {
            try {
                (new ModuleGenerator)->plan(['name' => 'things', 'label' => 'Şeyler', 'singular' => 'Şey', 'slug' => $slug, 'tv' => $tv]);
                $this->fail("Kabul edilmemeliydi: {$slug}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_or_duplicate_names_are_rejected(): void
    {
        $generator = new ModuleGenerator;
        foreach ([
            ['name' => 'projects', 'label' => 'X', 'singular' => 'Y'],
            // Mağaza'nın türü (modül kapalıyken de ayrılmış).
            ['name' => 'products', 'label' => 'Ürünler', 'singular' => 'Ürün'],
            ['name' => 'Bad-Name', 'label' => 'X', 'singular' => 'Y'],
            ['name' => 'things', 'label' => '', 'singular' => 'Y'],
        ] as $input) {
            try {
                $generator->plan($input + ['tv' => null]);
                $this->fail('Kabul edilmemeliydi: '.$input['name']);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_intent_file_drives_the_fields_and_rejects_invented_ones(): void
    {
        $intent = ModuleIntent::parse([
            'name' => 'lessons', 'label' => 'Dersler', 'singular' => 'Ders', 'icon' => 'book',
            'purpose' => 'Okulun dersleri: seviye ve süre.',
            'fields' => [
                'shared' => ['level' => ['type' => 'select', 'label' => 'Seviye', 'options' => ['a1' => 'Başlangıç', 'b1' => 'Orta']], 'hours' => ['type' => 'int', 'label' => 'Saat', 'min' => 1]],
                'localized' => ['summary' => ['type' => 'textarea', 'label' => "Öğrencinin 'kısa' özeti", 'max' => 300]],
            ],
        ]);
        $files = collect((new ModuleGenerator)->plan($intent + ['tv' => null]))->keyBy(fn ($f) => basename($f['path']));
        $source = $files['LessonType.php']['content'];
        $this->assertStringContainsString('Okulun dersleri: seviye ve süre.', $source);
        $file = sys_get_temp_dir().'/km-lesson-type-'.uniqid().'.php';
        file_put_contents($file, $source);
        require_once $file;
        @unlink($file);
        $type = new LessonType;
        $this->assertSame(['level', 'hours'], array_keys($type->shared()));
        $this->assertSame(['a1' => 'Başlangıç', 'b1' => 'Orta'], $type->shared()['level']['options']);
        // Başlık ve adres her türde; niyet dosyasında yoksa eklenir. Tırnak kaçar.
        $this->assertSame(['title', 'slug', 'summary'], array_keys($type->localized()));
        $this->assertSame("Öğrencinin 'kısa' özeti", $type->localized()['summary']['label']);

        foreach ([
            ['fields' => ['shared' => ['x' => ['type' => 'magic', 'label' => 'X']]]],
            ['fields' => ['shared' => ['x' => ['type' => 'text', 'label' => 'X', 'validate' => 'email']]]],
            ['fields' => ['shared' => ['x' => ['type' => 'select', 'label' => 'X']]]],
            ['fields' => ['shared' => ['title' => ['type' => 'text', 'label' => 'Başlık']]]],
            ['fields' => ['other' => []]],
            ['owner' => 'ben'],
        ] as $bad) {
            try {
                ModuleIntent::parse($bad + ['name' => 'things', 'label' => 'Şeyler', 'singular' => 'Şey']);
                $this->fail('Kabul edilmemeliydi: '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertStringStartsWith('Niyet dosyası:', (string) ($e->details()['fields']['intent'] ?? ''));
            }
        }
        // Örnek niyet dosyası (belgedeki) geçerli.
        $sample = dirname(base_path()).'/docs/sablonlar/modul-niyeti.json';
        if (is_file($sample)) {
            $this->assertSame('courses', ModuleIntent::read($sample)['name']);
        }
    }
}
