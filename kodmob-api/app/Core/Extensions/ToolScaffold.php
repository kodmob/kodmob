<?php

namespace App\Core\Extensions;

use App\Core\Exceptions\ValidationException;
use App\Core\Modules;
use Illuminate\Support\Str;

/**
 * Yeni araç paketinin iskeleti (`php artisan kodmob:tool new <anahtar>`; docs/arac-paketi.md): tanım, modül
 * (yetki + ayar), bir uç, panel sayfası, test, README ve MIT lisansı. Üretilen paket hemen çalışır: klasör
 * kodmob-api/extensions altındaysa kuruludur (tablolar yok; panel sayfası için tv'de derleme).
 */
final class ToolScaffold
{
    /**
     * @return list<string> yazılan dosyalar (paket klasörüne göre)
     */
    public static function create(string $root, string $key, string $label = '', string $author = ''): array
    {
        if (! preg_match(ToolManifest::KEY, $key)) {
            throw ValidationException::field('key', 'Anahtar küçük harf, rakam ve tire olmalı (2–31 karakter), ör. "stok-takibi".');
        }
        if (isset(Modules::OPTIONAL[$key])) {
            throw ValidationException::field('key', "\"{$key}\" çatının bir modülü; başka bir anahtar seçin.");
        }
        $dir = rtrim($root, '/\\').'/'.$key;
        if (file_exists($dir)) {
            throw ValidationException::field('key', "Klasör zaten var: {$dir}");
        }
        $files = self::files($key, $label !== '' ? $label : Str::headline($key), $author !== '' ? $author : 'Yazarın adı');
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir.'/'.$path), 0755, true);
            file_put_contents($dir.'/'.$path, $content);
        }

        return array_keys($files);
    }

    /** @return array<string,string> göreli yol → içerik */
    public static function files(string $key, string $label, string $author): array
    {
        $studly = Str::studly($key);
        $snake = str_replace('-', '_', $key);
        [$major, $minor] = array_map('intval', explode('.', (string) config('kodmob.version').'.0'));
        $vars = [
            '{{key}}' => $key, '{{label}}' => $label, '{{studly}}' => $studly, '{{snake}}' => $snake, '{{author}}' => $author,
            '{{year}}' => date('Y'), '{{kodmob}}' => ">={$major}.{$minor} <".($major + 1), '{{namespace}}' => 'KodmobTools\\'.$studly,
            '{{namespace_json}}' => 'KodmobTools\\\\'.$studly,
        ];
        $fill = static fn (string $text) => strtr($text, $vars);

        return array_map($fill, [
            ToolManifest::FILE => self::MANIFEST,
            "php/{$studly}Module.php" => self::MODULE,
            "php/{$studly}Controller.php" => self::CONTROLLER,
            'php/routes.php' => self::ROUTES,
            'panel/index.js' => self::PANEL_INDEX,
            "panel/{$key}.js" => self::PANEL_SCRIPT,
            "panel/_{$key}.njk" => self::PANEL_TEMPLATE,
            "tests/{$studly}Test.php" => self::TEST,
            'README.md' => self::README,
            'LICENSE' => self::LICENSE,
        ]);
    }

    private const MANIFEST = <<<'TXT'
{
    "key": "{{key}}",
    "label": "{{label}}",
    "description": "{{label}} aracı: ne işe yaradığını bir cümleyle yazın.",
    "version": "0.1.0",
    "kodmob": "{{kodmob}}",
    "author": "{{author}}",
    "license": "MIT",
    "homepage": "",
    "namespace": "{{namespace_json}}",
    "module": "{{studly}}Module"
}

TXT;

    private const MODULE = <<<'TXT'
<?php

namespace {{namespace}};

use App\Core\Module;
use App\Core\Settings\SettingDefinition;

/**
 * {{label}} aracının modülü: yetkileri ve ayarları (Core\Module kancaları; events(), boot(), schedule(),
 * health() de kullanılabilir). Araç Pazarı'nda kapatılınca hiçbiri yüklenmez, uçları 404 döner.
 */
final class {{studly}}Module extends Module
{
    public const USE = 'km_use_{{snake}}';

    public function capabilities(): array
    {
        return [self::USE => ['label' => '{{label}} aracını kullanma', 'group' => 'Araçlar']];
    }

    public function settings(): array
    {
        return [
            // Proje Ayarları'nda kendiliğinden görünür (kapsam: proje).
            new SettingDefinition('{{snake}}.greeting', 'string', 'Merhaba!', 'Karşılama metni', '{{label}}', 'Aracın sayfasında görünen metin.', scope: 'project'),
        ];
    }
}

TXT;

    private const CONTROLLER = <<<'TXT'
<?php

namespace {{namespace}};

use App\Core\Http\Controller;
use App\Core\Settings\SettingsService;

/** {{label}} aracının uçları (php/routes.php). */
final class {{studly}}Controller extends Controller
{
    public function show(): array
    {
        return ['message' => (string) SettingsService::instance()->get('{{snake}}.greeting')];
    }
}

TXT;

    private const ROUTES = <<<'TXT'
<?php

/*
 * {{label}} aracının uçları: /api/v1 altında yüklenir; araç bu projede kapalıyken 404 (`module:{{key}}`).
 * Kurallar çatınınkiyle aynı: panel ucu `auth.jwt` + `cap:`; yazma rotasının adı (->name('{{snake}}.x'))
 * modülün events()'inde tanımlı olmalı (İşlem Geçmişi).
 */

use Illuminate\Support\Facades\Route;
use {{namespace}}\{{studly}}Controller;
use {{namespace}}\{{studly}}Module;

Route::middleware(['auth.jwt', 'activity', 'cap:km_access_panel', 'cap:'.{{studly}}Module::USE])->prefix('tools/{{key}}')->group(function () {
    Route::get('/', [{{studly}}Controller::class, 'show']);
});

TXT;

    private const PANEL_INDEX = <<<'TXT'
/**
 * {{label}} aracının panel sayfaları (biçim: tv/src/panel/extension.js). Menüde "Proje modülleri"
 * altında, Tüm İşlemler'in Araçlar kartında, aramada ve turda görünür; yetkisi olmayana (ya da araç
 * kapalıyken) görünmez. Sayfa anahtarı aracın anahtarıyla başlar.
 */
export const PAGES = [
    {
        key: '{{key}}',
        path: '{{key}}',
        label: '{{label}}',
        icon: 'sparkles',
        can: 'km_use_{{snake}}',
        guide: '{{label}}: aracın kısa tanıtımı (Kullanım Kılavuzu turu).',
        load: () => import('./{{key}}.js'),
        template: 'panel/_{{key}}.njk',
    },
];

TXT;

    private const PANEL_SCRIPT = <<<'TXT'
/** {{label}} sayfasının bileşeni (şablon: _{{key}}.njk). Uç: GET /api/v1/tools/{{key}}. */
import { http } from '@/services/http.js';
import { toast } from '@/ui/toast.js';

export function km{{studly}}() {
    return {
        message: '',
        loading: true,

        async init() {
            try {
                this.message = (await http.get('/tools/{{key}}')).message;
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },
    };
}

TXT;

    private const PANEL_TEMPLATE = <<<'TXT'
{# {{label}} aracı (panel/{{key}}.js km{{studly}}). #}
<div class="pn-page" x-data="km{{studly}}" data-testid="tool-{{key}}">
  <div class="pn-bar">
    <div class="min-w-0 flex-1">
      <p class="pn-bar-title">{{label}}</p>
      <p class="pn-bar-meta"><span>Karşılama metni Proje Ayarları'ndan değişir.</span></p>
    </div>
  </div>
  <section class="card">
    <div class="card-body">
      <p class="text-ink" x-text="loading ? 'Yükleniyor…' : message" data-testid="tool-{{key}}-message"></p>
    </div>
  </section>
</div>

TXT;

    private const TEST = <<<'TXT'
<?php

namespace {{namespace}}\Tests;

use Tests\TestCase;

/** {{label}} aracı: uç yetkiye bağlı ve projenin ayarını okur. */
class {{studly}}Test extends TestCase
{
    public function test_greeting_needs_the_capability(): void
    {
        $this->getJson('/v1/tools/{{key}}', $this->admin())->assertOk()->assertJsonPath('message', 'Merhaba!');
        $this->getJson('/v1/tools/{{key}}', $this->as('editor'))->assertForbidden();
    }
}

TXT;

    private const README = <<<'TXT'
# {{label}}

Kodmob aracı. Ne işe yaradığını, kimin için olduğunu buraya yazın.

## Kurulum

```
cd kodmob-api
php artisan kodmob:add <bu paketin klasörü ya da zip'i>
php artisan migrate          # aracın tabloları varsa
cd ../tv && npm run build    # panel sayfası derlemeye girer
```

Kurulunca Araç Pazarı'nda görünür; her projede ayrı açılıp kapanır.

## Geliştirme

- `php/`: modül (`{{studly}}Module`: yetkiler, ayarlar, olaylar), uçlar (`routes.php`), controller'lar
- `database/migrations/`: tablolar (SQLite, MySQL ve PostgreSQL'de çalışacak biçimde)
- `panel/`: sayfalar (`index.js` `PAGES`), bileşenler, şablonlar
- `tests/`: `cd kodmob-api && php artisan test --filter={{studly}}`

Kurallar ve katkı kontrol listesi: Kodmob'un `docs/arac-paketi.md` belgesi.

## Lisans

MIT, bkz. LICENSE.

TXT;

    private const LICENSE = <<<'TXT'
MIT License

Copyright (c) {{year}} {{author}}

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

TXT;
}
