<?php

use App\Core\Content\Languages;
use App\Core\Contracts\Contracts;
use App\Core\Database\DatabaseMover;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Extensions\Extensions;
use App\Core\Extensions\ToolInstaller;
use App\Core\Extensions\ToolManifest;
use App\Core\Extensions\ToolScaffold;
use App\Core\Health\Health;
use App\Core\ModuleState;
use App\Core\Setup\FrameworkFiles;
use App\Core\Setup\FrameworkPackage;
use App\Core\Setup\Installer;
use App\Core\Setup\ModuleGenerator;
use App\Core\Setup\ModuleIntent;
use App\Core\Setup\SetupController;
use App\Core\Setup\Upgrader;
use App\Core\Sites\SiteExporter;
use App\Core\Sites\SiteRegistry;
use App\Core\Support\Clock;
use App\Modules\Auth\Models\User;
use App\Modules\Content\Backup;
use App\Modules\I18n\I18nConfig;
use App\Modules\I18n\LanguageReport;
use App\Modules\I18n\LanguageService;
use App\Modules\Media\MediaLibrary;
use App\Modules\Seo\Services\LinkChecker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * Komutlar (SSH varsa; yoksa aynı işler /api/v1/setup ve panelden yapılır).
 * Zamanlanmış görevler modüllerdedir (Core\Module::schedule); cron:
 *   * * * * * php /home/kullanici/kodmob-core/artisan schedule:run >> /dev/null 2>&1
 */

Artisan::command('kodmob:admin {username} {email} {--password=} {--name=}', function (string $username, string $email) {
    $password = (string) $this->option('password');
    if ($password === '') {
        $password = (string) $this->secret('Şifre (en az 10 karakter)');
        // Windows'ta gizli giriş (hiddeninput.exe) konsol kod sayfasıyla okur: ş, ı, ğ gibi
        // harfler tarayıcının gönderdiğinden farklı kaydolur ve giriş "şifre hatalı" döner.
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/[^\x20-\x7E]/', $password)) {
            $this->error('Windows konsolunda Türkçe ya da özel harfler bozuluyor. Şifreyi yalnızca İngilizce harf, rakam ve işaretlerle yazın; isterseniz sonra panelde Profilim\'den değiştirin.');

            return 1;
        }
        if ($password !== (string) $this->secret('Şifre (tekrar)')) {
            $this->error('İki şifre aynı değil; hiçbir şey değişmedi.');

            return 1;
        }
    }
    if (mb_strlen($password) < 10) {
        $this->error('Şifre en az 10 karakter olmalı.');

        return 1;
    }
    $user = User::query()->where('username', $username)->first() ?? new User(['username' => $username]);
    $user->fill([
        'email' => mb_strtolower($email),
        'display_name' => (string) ($this->option('name') ?: $user->display_name ?: $username),
        'password' => $password,
        'role' => User::ADMIN,
        'status' => User::ACTIVE,
        'password_changed_at' => Clock::now(),
    ])->save();
    $this->info(($user->wasRecentlyCreated ? 'Yönetici oluşturuldu: ' : 'Yönetici güncellendi: ').$user->username);

    return 0;
})->purpose('Yönetici hesabı oluşturur ya da şifresini/e-postasını günceller');

/*
 * Yeni içerik türü iskeleti (App\Core\Setup\ModuleGenerator): tür sınıfı, yapılandırma kaydı,
 * panel menüsü/adresi (tv yanındaysa), test. Panel listesi, formu, yetkisi ve yedeği hemen çalışır.
 *   php artisan kodmob:module courses --label=Kurslar --singular=Kurs --icon=book
 *   php artisan kodmob:module courses --label=Kurslar --singular=Kurs --dry-run
 *   php artisan kodmob:module cars --label=Araçlar --singular=Araç --bookable=day   (rezervasyonlu: person | day | fixed | night)
 *   php artisan kodmob:module --intent=kurslar.intent.json   niyet dosyasından: amaç ve alanlar (docs/sablonlar/modul-niyeti.json)
 */
Artisan::command('kodmob:module {name? : API adı, İngilizce çoğul (products); niyet dosyası verildiyse ondan} {--label= : Çoğul etiket (Ürünler)} {--singular= : Tekil etiket (Ürün)} {--slug= : Panel adresi (urunler); boşsa etiketten} {--record= : Kayıt adı (product); boşsa addan} {--icon=layers : Panel ikonu} {--no-order : Listede sürükle-bırak sıralama olmasın} {--bookable= : Rezervasyonlu tür: person (kişi başı) | day (gün başı) | fixed (sabit fiyat) | night (gece başı oda)} {--tv= : tv klasörü; boşsa ../tv} {--intent= : Niyet dosyası (JSON): amaç, etiketler, alanlar} {--dry-run : Yazmadan göster}', function (?string $name = null) {
    $tv = (string) $this->option('tv') ?: dirname(base_path()).DIRECTORY_SEPARATOR.'tv';
    $generator = new ModuleGenerator;
    try {
        // Niyet dosyası temeldir; komut satırında verilen seçenek onu ezer.
        $intent = $this->option('intent') ? ModuleIntent::read((string) $this->option('intent')) : [];
        $cli = array_filter([
            'name' => (string) $name, 'label' => (string) $this->option('label'), 'singular' => (string) $this->option('singular'),
            'slug' => (string) $this->option('slug'), 'record' => (string) $this->option('record'), 'bookable' => (string) $this->option('bookable'),
        ], static fn ($v) => $v !== '');
        $options = $cli + $intent + ['name' => '', 'label' => '', 'singular' => '', 'icon' => (string) $this->option('icon'), 'orderable' => ! $this->option('no-order')];
        $name = (string) $options['name'];
        $files = $generator->plan($options + ['tv' => is_dir($tv) ? $tv : null]);
    } catch (ValidationException $e) {
        $this->error($e->getMessage());
        foreach ((array) ($e->details()['fields'] ?? []) as $message) {
            $this->line('  '.$message);
        }

        return 1;
    }
    $root = dirname(base_path()).DIRECTORY_SEPARATOR;
    foreach ($files as $file) {
        $this->line(sprintf('  %-12s %s', $file['action'], str_replace($root, '', $file['path'])));
    }
    if (! is_dir($tv)) {
        $this->warn("tv klasörü yok ({$tv}): panel kaydını profilin panel.js'ine (tv/src/profiles/<ad>/panel.js) elle ekleyin.");
    }
    if ($this->option('dry-run')) {
        $this->info('Deneme: hiçbir dosya yazılmadı.');

        return 0;
    }
    $generator->write($files);
    // Kod biçimi (CI'daki `pint --test` geçsin).
    $php = array_values(array_filter(array_column($files, 'path'), static fn ($p) => str_ends_with($p, '.php')));
    if (is_file(base_path('vendor/bin/pint'))) {
        Process::path(base_path())->run([PHP_BINARY, base_path('vendor/bin/pint'), ...$php]);
    }
    $this->info('Tür eklendi. Sıradakiler: tv\'de build (panel menüsü), `php artisan test --filter='.Str::studly($name).'ContentTest`; sitede göstermek için profilde sayfa şablonu ve rota (routes.config.js, `dynamic`: kayıt adı).');
    if ((string) $this->option('bookable') !== '') {
        $this->warn('Rezervasyonlu tür: profilin `profile.modules` listesinde \'commerce\' ve \'booking\' olmalı. Sayfa şablonunda rezervasyon kutusu ve bölümler: {% include "pages/shop/_booking-box.njk" %} ve _booking-sections.njk (örnek: tv/src/pages/shop/tour.njk).');
    }

    return 0;
})->purpose('Yeni içerik türü iskeleti: tür sınıfı, kayıt, panel menüsü, test');

/*
 * Araç paketleri (kodmob-api/extensions/<anahtar>/; App\Core\Extensions, docs/arac-paketi.md):
 *   php artisan kodmob:tool new stok-takibi --label="Stok takibi"   iskelet (modül, uç, panel sayfası, test)
 *   php artisan kodmob:tool check stok-takibi                        tanım ve uyum denetimi (klasör yolu da olur)
 *   php artisan kodmob:tool list                                     kurulu ve yüklenemeyen paketler
 *   php artisan kodmob:add <klasör | zip | katalogdaki anahtar>      paketi kurar (--update: üstüne yazar)
 */
Artisan::command('kodmob:tool {action : new | check | list} {key?} {--label= : Aracın adı} {--author= : Yazar (LICENSE ve tanım)} {--path= : Paketlerin klasörü; boşsa kodmob-api/extensions}', function (string $action, ?string $key = null) {
    $root = rtrim((string) $this->option('path') ?: Extensions::path(), '/\\');
    try {
        if ($action === 'list') {
            foreach (Extensions::loaded() as $name => $tool) {
                $this->line("  {$name}  {$tool->data['version']}  {$tool->data['label']} ({$tool->data['author']}, {$tool->data['license']})");
            }
            foreach (Extensions::problems() as $name => $problem) {
                $this->warn("  {$name}  yüklenmedi: {$problem}");
            }
            if (! Extensions::loaded() && ! Extensions::problems()) {
                $this->info("Kurulu araç paketi yok ({$root}).");
            }

            return 0;
        }
        if ((string) $key === '') {
            $this->error('Aracın anahtarı gerekli, ör. kodmob:tool new stok-takibi');

            return 1;
        }
        if ($action === 'new') {
            $files = ToolScaffold::create($root, $key, (string) $this->option('label'), (string) $this->option('author'));
            if (is_file(base_path('vendor/bin/pint'))) {
                Process::path(base_path())->run([PHP_BINARY, base_path('vendor/bin/pint'), $root.'/'.$key]);
            }
            Extensions::forget();
            $this->info("Araç iskeleti: {$root}/{$key} (".count($files).' dosya).');
            $this->line('Sıradakiler: kodmob-tool.json\'da açıklama ve yazar; `php artisan test --filter='.Str::studly($key).'`; panel sayfası için `cd ../tv && npm run build`.');

            return 0;
        }
        if ($action === 'check') {
            $tool = ToolManifest::read(is_dir($key) ? $key : $root.'/'.$key);
            $version = (string) config('kodmob.version');
            $this->info("{$tool->data['label']} {$tool->data['version']}: tanım geçerli.");
            if (! $tool->compatible($version)) {
                $this->error("Çatının {$version} sürümüyle uyumsuz ({$tool->data['kodmob']}).");

                return 1;
            }
            $this->line('Uyum: '.$tool->data['kodmob'].", çatı {$version}. Uçlar: ".($tool->routes() ? 'var' : 'yok').', tablolar: '.($tool->migrations() ? 'var' : 'yok').', panel: '.(is_file($tool->dir.'/panel/index.js') ? 'var' : 'yok').', testler: '.(is_dir($tool->dir.'/tests') ? 'var' : 'YOK').'.');

            return is_dir($tool->dir.'/tests') ? 0 : 1;
        }
    } catch (ValidationException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->error("Bilinmeyen işlem: {$action} (new | check | list)");

    return 1;
})->purpose('Araç paketi: iskelet, denetim, liste');

Artisan::command('kodmob:add {source : Paketin klasörü, zip\'i ya da katalogdaki anahtarı} {--update : Kurulu paketin üstüne yaz} {--path= : Paketlerin klasörü; boşsa kodmob-api/extensions}', function (string $source) {
    try {
        $result = ToolInstaller::add($source, (string) $this->option('path') ?: Extensions::path(), (bool) $this->option('update'));
    } catch (ValidationException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info(($result['updated'] ? 'Güncellendi' : 'Kuruldu').": {$result['key']} {$result['version']} → {$result['dir']}");
    $this->line('Sıradakiler: `php artisan migrate` (tabloları varsa; canlıda kurulum adresi), `cd ../tv && npm run build` (panel sayfası), paketi yükleyin. Araç Pazarı\'nda her projede açılıp kapanır.');

    return 0;
})->purpose('Araç paketini kurar: klasör, zip ya da katalog (sha256 denetimli)');

/*
 * Çatı paketi ve güncelleme (kökteki kodmob.json; App\Core\Setup\FrameworkFiles, docs/acik-kaynak-raporu.md §5c):
 *   php artisan kodmob:pack                              çatının dosyaları + kilit → release/kodmob-<sürüm>.zip
 *   php artisan kodmob:lock                              projede kurulu sürümü işaretler (kodmob.lock.json)
 *   php artisan kodmob:upgrade --from=kodmob-2.6.0.zip   projede değişmemiş çatı dosyalarını yeniler (--dry-run)
 * `--root`: deponun kökü (boşsa kodmob-api'nin bir üstü).
 */
$frameworkRoot = static fn ($command) => rtrim((string) $command->option('root') ?: dirname(base_path()), '/\\');

Artisan::command('kodmob:pack {--out= : Paketin klasörü; boşsa <kök>/release} {--root= : Deponun kökü}', function () use ($frameworkRoot) {
    $root = $frameworkRoot($this);
    try {
        $result = FrameworkPackage::build(FrameworkFiles::load($root), (string) $this->option('out') ?: $root.'/release');
    } catch (ValidationException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info("Çatı paketi: {$result['file']} ({$result['count']} dosya, sürüm {$result['version']})");

    return 0;
})->purpose('Çatı paketi: kodmob.json\'daki çatı dosyaları ve kilit (kodmob:upgrade bunu okur)');

Artisan::command('kodmob:lock {--force : Var olan kilidin üstüne yaz} {--root= : Deponun kökü}', function () use ($frameworkRoot) {
    try {
        $framework = FrameworkFiles::load($frameworkRoot($this));
    } catch (ValidationException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    if ($framework->lock() !== null && ! $this->option('force')) {
        $this->error('kodmob.lock.json zaten var. Yeniden yazmak için --force (çatı dosyalarının şu anki hali "değişmemiş" sayılır).');

        return 1;
    }
    $files = $framework->scan();
    $framework->writeLock($files);
    $this->info('Kilit yazıldı: '.count($files)." çatı dosyası, sürüm {$framework->manifest['version']}. Değiştirilmemiş bir kurulumda çalıştırın.");

    return 0;
})->purpose('Projede kurulu çatı sürümünü işaretler (kodmob.lock.json)');

Artisan::command('kodmob:upgrade {--from= : Çatı paketi (zip ya da açılmış klasör)} {--dry-run : Yazmadan göster} {--force : Git\'te kaydedilmemiş değişiklik varken de çalış} {--root= : Deponun kökü}', function () use ($frameworkRoot) {
    $root = $frameworkRoot($this);
    $from = (string) $this->option('from');
    if ($from === '') {
        $this->error('Paket gerekli: --from=<kodmob-x.y.z.zip ya da klasör>');

        return 1;
    }
    $temp = storage_path('framework');
    try {
        $project = FrameworkFiles::load($root);
        $package = FrameworkPackage::open($from, $temp);
        if ($package['lock']['name'] !== $project->manifest['name']) {
            throw ValidationException::field('from', "Paket başka bir çatının: {$package['lock']['name']}");
        }
        $upgrader = new Upgrader($project, $package);
        $plan = $upgrader->plan();
    } catch (ValidationException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $labels = [
        'update' => 'güncellenir', 'add' => 'eklenir', 'delete' => 'silinir',
        'conflict' => 'çakışma: projede değişmiş, yenisi yanına (.kodmob-yeni)',
        'keep' => 'projede değişmiş, çatıda aynı (dokunulmaz)',
        'orphan' => 'çatıdan kalktı ama projede değişmiş (elle silin)',
        'removed' => 'projede silinmiş (eklenmez)',
    ];
    $from = $project->lock()['version'] ?? '?';
    $this->line("Çatı {$from} → {$package['lock']['version']}");
    foreach ($plan as $step) {
        $this->line(sprintf('  %-10s %s', $step['action'], $step['path']));
    }
    $counts = array_count_values(array_column($plan, 'action'));
    foreach ($labels as $action => $label) {
        if (! empty($counts[$action])) {
            $this->line("  {$counts[$action]} dosya {$label}");
        }
    }
    $cleanup = static function () use ($package) {
        if ($package['temp']) {
            File::deleteDirectory($package['dir']);
        }
    };
    if ($this->option('dry-run')) {
        $cleanup();
        $this->info('Deneme: hiçbir dosya yazılmadı.');

        return 0;
    }
    // Geri dönüş için: git'te kaydedilmemiş değişiklik varken güncelleme yapılmaz.
    if (is_dir($root.'/.git') && ! $this->option('force')) {
        $dirty = trim(Process::path($root)->run(['git', 'status', '--porcelain'])->output());
        if ($dirty !== '') {
            $cleanup();
            $this->error('Kaydedilmemiş değişiklikler var: önce commit edin (güncellemeyi geri alabilmek için) ya da --force.');

            return 1;
        }
    }
    $upgrader->apply($plan);
    $cleanup();
    $this->info("Çatı {$package['lock']['version']} sürümüne güncellendi.");
    $this->line('Sıradakiler: kodmob-api\'de composer install ve php artisan migrate, tv\'de npm ci ve npm run build; testler.');
    if (! empty($counts['conflict'])) {
        $this->warn('Çakışmalar: *.kodmob-yeni dosyalarını kendi dosyanızla birleştirip silin.');
    }

    return 0;
})->purpose('Çatıyı yeni sürüme günceller; projenin dosyalarına ve projede değişmiş çatı dosyalarına dokunmaz');

/*
 * Yeni projenin yerel kurulumu (App\Core\Setup\Installer): .env, anahtarlar, SQLite, tablolar, ilk yönetici,
 * projenin profili ve kilit. Var olan .env'e ve anahtarlara dokunmaz (--local yerel değerleri yeniden yazar).
 *   php artisan kodmob:install --profile=benim-sitem
 *   php artisan kodmob:install --admin=hakki --email=hakki@ornek.com --password=… --no-interaction
 */
Artisan::command('kodmob:install {--profile= : Projenin profili (başlangıçtan kopyalanır), ör. benim-sitem} {--admin= : İlk yöneticinin kullanıcı adı} {--email= : İlk yöneticinin e-postası} {--password= : İlk yöneticinin şifresi (boşsa rastgele)} {--url=http://127.0.0.1:8090 : Yerel adres} {--local : Var olan .env\'e de yerel değerleri yaz} {--no-database : Tabloları ve yöneticiyi kurma} {--root= : Deponun kökü}', function () use ($frameworkRoot) {
    $root = $frameworkRoot($this);
    $installer = new Installer($root);
    $step = fn (array $lines) => array_map(fn ($line) => $this->line("  ✓ {$line}"), $lines);
    try {
        $this->info('Kodmob kurulumu');
        $step($installer->env((string) $this->option('url'), (bool) $this->option('local')));
        if ($profile = (string) $this->option('profile')) {
            $step($installer->profile($profile));
        }
    } catch (ValidationException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    if (! $this->option('no-database')) {
        // Ayrı süreç: yeni .env (SQLite, anahtarlar) okunsun.
        $artisan = static fn (array $args) => Process::path($installer->api())->timeout(300)->run([PHP_BINARY, 'artisan', ...$args]);
        $migrate = $artisan(['migrate', '--force']);
        if (! $migrate->successful()) {
            $this->error('Tablolar kurulamadı: '.trim($migrate->errorOutput() ?: $migrate->output()));

            return 1;
        }
        $this->line('  ✓ tablolar kuruldu');
        $username = (string) $this->option('admin') ?: (string) $this->ask('İlk yöneticinin kullanıcı adı', 'yonetici');
        $email = (string) $this->option('email') ?: (string) $this->ask('İlk yöneticinin e-postası', $username.'@example.com');
        $password = (string) $this->option('password');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }
        $admin = $artisan(['kodmob:admin', $username, $email, '--password='.$password]);
        if (! $admin->successful()) {
            $this->error('Yönetici oluşturulamadı: '.trim($admin->errorOutput() ?: $admin->output()));

            return 1;
        }
        $this->line("  ✓ yönetici: {$username}");
        if ($generated) {
            $this->warn("    Şifre (bir kez gösterilir, girişten sonra Profilim'den değiştirin): {$password}");
        }
    }
    if ($lock = $installer->lock()) {
        $this->line("  ✓ {$lock}");
    }
    $this->newLine();
    $this->info('Sıradakiler: cd ../tv && npm ci && npm run deploy:local && npm run serve:local');
    $this->line('Panel: '.rtrim((string) $this->option('url'), '/').'/panel/ · Site: '.rtrim((string) $this->option('url'), '/').'/');

    return 0;
})->purpose('Yeni projenin yerel kurulumu: .env, anahtarlar, SQLite, tablolar, ilk yönetici, profil, kilit');

/*
 * Uçtan uca test veritabanını sıfırlar (tv: `npm run e2e`). Yalnızca `.e2e` klasöründeki SQLite
 * dosyasında çalışır: ortam değişkenleri PHP'ye geçmemişse yerel MySQL'e asla dokunmaz. Boş şema,
 * derlemedeki bütün diller yayında, verilen şifreyle `e2e` yöneticisi.
 */
Artisan::command('kodmob:e2e-reset {--password=}', function () {
    $database = (string) config('database.connections.sqlite.database');
    if (app()->environment('production') || config('database.default') !== 'sqlite' || ! preg_match('#[\\\\/]\.e2e[\\\\/][^\\\\/]+\.sqlite$#', $database)) {
        $this->error('Durduruldu: bu komut yalnızca .e2e klasöründeki SQLite test veritabanında çalışır (şu an: '.config('database.default').' '.$database.').');

        return 1;
    }
    $password = (string) $this->option('password');
    if (mb_strlen($password) < 10) {
        $this->error('--password en az 10 karakter olmalı.');

        return 1;
    }
    $this->callSilently('migrate:fresh', ['--force' => true]);
    // Araç Pazarı'nda kapatılmış araçlar (dosyada) da sıfırlanır: her koşu bütün araçlar açık başlar.
    @unlink(storage_path('framework/modules.json'));
    ModuleState::forget();
    I18nConfig::compile();
    foreach (Languages::all() as $language) {
        if (! $language['active']) {
            (new LanguageService)->update($language['code'], ['active' => true]);
        }
    }
    I18nConfig::compile();
    User::query()->create([
        'username' => 'e2e', 'email' => 'e2e@kodmob.test', 'display_name' => 'E2E Test',
        'password' => $password, 'role' => User::ADMIN, 'status' => User::ACTIVE, 'password_changed_at' => Clock::now(),
    ]);
    $this->info('E2E veritabanı hazır: '.implode(', ', array_column(Languages::all(), 'code')));

    return 0;
})->purpose('Uçtan uca test veritabanını sıfırlar (yalnızca .e2e SQLite)');

// Dil kaydını sitenin derlemesiyle eşitler ve render.php'nin kopyasını yazar (deploy:local sonunda çalışır).
Artisan::command('kodmob:i18n', function () {
    if (! I18nConfig::compile()) {
        $this->error('Dil yapılandırması yazılamadı (günlüğe bakın).');

        return 1;
    }
    foreach (Languages::all() as $l) {
        $this->line(sprintf('%s %-6s önek %-4s %s%s', $l['default'] ? '*' : ' ', $l['code'], $l['prefix'] === '' ? '—' : $l['prefix'], $l['active'] ? 'yayında' : 'pasif', $l['default'] ? ' (varsayılan)' : ''));
    }

    return 0;
})->purpose('Dil kaydını sitenin derlemesiyle eşitler (önek, varsayılan dil, yeni diller)');

/*
 * Sistem Sağlığı (paneldeki ekranın aynısı; Core\Health): kartlar ve bulgular. Çıkış: 0 kritik bulgu yok,
 * 1 kritik var. `--json` makine için (izleme, CI).
 */
Artisan::command('kodmob:health {--json : Raporu JSON olarak yazar}', function () {
    $report = Health::report();
    if ($this->option('json')) {
        $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $report['level'] === 'critical' ? 1 : 0;
    }
    $marks = ['critical' => 'KRİTİK', 'warn' => 'dikkat', 'info' => 'bilgi '];
    foreach ($report['sections'] as $s) {
        $this->line(sprintf('<options=bold>%s</> · %s', $s['title'], ['ok' => 'iyi', 'warn' => 'dikkat', 'critical' => 'KRİTİK'][$s['level']] ?? $s['level']));
        foreach ($s['rows'] as $r) {
            $this->line(sprintf('    %s: %s', $r['label'], $r['value']));
        }
        foreach ($s['flags'] as $f) {
            $this->line(sprintf('  %s  %s%s', $marks[$f['level']] ?? $f['level'], $f['text'], $f['fix'] !== '' ? ' → '.$f['fix'] : ''));
        }
    }
    $this->info(['ok' => 'Sistem iyi durumda.', 'warn' => 'Dikkat gerektiren bulgu var.', 'critical' => 'Kritik bulgu var.'][$report['level']] ?? '');

    return $report['level'] === 'critical' ? 1 : 0;
})->purpose('Sistem Sağlığı: sunucu, güvenlik, hız, veritabanı, depolama, arka plan, modüllerin kartları (kritikse 1 döner)');

/*
 * Çoklu dil ve bağlantı denetimi (Çoklu Dil > Sağlık; build.mjs paketten önce çalıştırır). İçerikteki
 * bağlantılar yeniden denetlenir. Çıkış: 0 sorun yok ya da yalnızca uyarı/bilgi, 1 hata var (paket durur),
 * 2 denetlenemedi (veritabanı yok).
 */
Artisan::command('kodmob:links', function () {
    try {
        LinkChecker::run(false);
        $items = (new LanguageReport)->health();
    } catch (Throwable $e) {
        $this->warn('Sağlık denetlenemedi: '.$e->getMessage());

        return 2;
    }
    $marks = ['error' => 'HATA ', 'warning' => 'uyarı', 'info' => 'bilgi'];
    foreach ($items as $item) {
        $this->line(sprintf('  %s  %s%s — %s', $marks[$item['level']], $item['title'], $item['count'] > 1 ? ' ('.$item['count'].')' : '', $item['detail']));
    }
    $errors = count(array_filter($items, static fn ($i) => $i['level'] === 'error'));
    $this->info($items ? sprintf('%d sorun (%d hata).', count($items), $errors) : 'Sorun yok.');

    return $errors > 0 ? 1 : 0;
})->purpose('Çoklu dil ve bağlantı denetimi (hata varsa 1 döner; paketten önce)');

Artisan::command('kodmob:export {file} {--media=}', function (string $file) {
    $backup = (new Backup)->export();
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $copied = 0;
    if ($media = $this->option('media')) {
        // İçeriğin kullandığı görsellerin asılları (geri yükleme backups/media/ klasöründen alır).
        @mkdir($media, 0755, true);
        foreach ((array) $backup['media'] as $id => $item) {
            $row = MediaLibrary::find((int) $id);
            if ($row && is_file($source = MediaLibrary::filePath($row))) {
                copy($source, rtrim($media, '/\\').'/'.$row['file']);
                $copied++;
            }
        }
    }
    $this->info('Yedek: '.json_encode($backup['counts'], JSON_UNESCAPED_UNICODE).' · '.$copied.' görsel');

    return 0;
})->purpose('İçeriği Kodmob yedeği olarak dışa aktarır (isteğe bağlı görsel asıllarıyla)');

Artisan::command('kodmob:import {file?} {--media=} {--as=}', function (?string $file = null) {
    $file ??= Backup::dir().'/'.SetupController::SEED;
    if (! is_file($file)) {
        $this->error('Yedek dosyası yok: '.$file);

        return 1;
    }
    if ($media = $this->option('media')) {
        // Görsel asılları backups/media/ klasörüne kopyalanır (geri yükleme oradan alır).
        @mkdir(Backup::mediaDir(), 0755, true);
        foreach (glob(rtrim($media, '/\\').'/*') ?: [] as $source) {
            if (is_file($source)) {
                copy($source, Backup::mediaDir().'/'.basename($source));
            }
        }
    }
    $user = $this->option('as') ? User::query()->where('username', $this->option('as'))->first() : User::query()->where('role', User::ADMIN)->orderBy('id')->first();
    if ($user) {
        Auth::setUser($user);
    }
    $result = (new Backup)->import((array) json_decode((string) file_get_contents($file), true));
    $this->info('İçerik: '.json_encode($result['counts'], JSON_UNESCAPED_UNICODE));
    $this->info(sprintf('Görseller: %d alındı, %d mevcut, %d bulunamadı', $result['media']['downloaded'], $result['media']['reused'], count($result['media']['failed'])));
    foreach ($result['media']['failed'] as $name) {
        $this->warn('  bulunamadı: '.$name);
    }
    $this->line('Önceki durumun yedeği: '.$result['snapshot']);

    return 0;
})->purpose('Kodmob yedeğini (JSON) içe aktarır; mevcut içerik çöpe taşınır');

/*
 * Siteyi başka veritabanına taşır (SQLite, MySQL/MariaDB, PostgreSQL arasında):
 *   php artisan kodmob:db-move --to="pgsql://kullanici:sifre@127.0.0.1:5432/kodmob"
 *   php artisan kodmob:db-move --to="sqlite:C:/yol/site.sqlite"
 * Şifre komut geçmişinde kalmasın diye hedef KODMOB_DB_MOVE_TO ortam değişkeniyle de verilebilir.
 * Bitince kodmob-api/.env'de DB_* hedefe çevrilir (komut değiştirmez).
 */
Artisan::command('kodmob:db-move {--to= : Hedef veritabanı adresi} {--force : Hedefteki tabloları silip baştan kur}', function () {
    $url = (string) ($this->option('to') ?: getenv('KODMOB_DB_MOVE_TO') ?: '');
    if ($url === '') {
        $this->error('Hedef gerekli: --to="pgsql://kullanici:sifre@sunucu:5432/veritabani" (ya da KODMOB_DB_MOVE_TO).');

        return 1;
    }
    try {
        $target = DatabaseMover::connectionFromUrl($url);
        $this->info(sprintf('Kaynak: %s (%s) → hedef: %s (%s)', config('database.default'), DB::connection()->getDatabaseName(), $target['driver'], $target['database']));
        $counts = (new DatabaseMover)->move((string) config('database.default'), $target, (bool) $this->option('force'), fn (string $table, int $rows) => $this->line(sprintf('  %-28s %8d satır', $table, $rows)));
    } catch (DomainException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info(sprintf('Bitti: %d tablo, %d satır. Şimdi kodmob-api/.env\'de DB_CONNECTION ve DB_* değerlerini hedefe çevirin.', count($counts), array_sum($counts)));

    return 0;
})->purpose('Siteyi başka veritabanına taşır (SQLite, MySQL/MariaDB, PostgreSQL)');

/*
 * Alt siteler (çok site; App\Core\Sites\SiteRegistry). Panelde: Yönetim > Siteler (ana site).
 *   php artisan kodmob:site list
 *   php artisan kodmob:site create demo --name=Demo --domain=demo.kodmob.com --admin=yonetici --email=a@b.com
 *       [--domain=… (birden çok)] [--url=https://…] [--modules=blog,briefs] [--db="pgsql://k:s@sunucu:5432/vt"] [--schema=demo]
 *       (şifre sorulur; --password ile de verilir. --db yoksa SQLite: storage/sites/<anahtar>/database.sqlite)
 *   php artisan kodmob:site migrate [demo]     sürüm güncellemesinden sonra (anahtarsız: hepsi)
 *   php artisan kodmob:site remove demo        kayıttan çıkarır, veriler kalır
 *   php artisan kodmob:site export demo --to=C:/paket [--db="mysql://…"]   ayrı kuruluma dışa alma (OKUBENI.txt)
 * Bir alt sitede komut: KODMOB_SITE=demo php artisan …
 */
Artisan::command('kodmob:site {action : list | create | migrate | remove | export} {key?} {--to= : export: hedef klasör} {--name=} {--domain=*} {--url=} {--modules=} {--db=} {--schema=} {--admin=} {--email=} {--password=}', function (string $action, ?string $key = null) {
    try {
        if ($action === 'list') {
            $sites = SiteRegistry::list();
            if (! $sites) {
                $this->line('Alt site yok. Kayıt: '.SiteRegistry::file());

                return 0;
            }
            $this->table(['Anahtar', 'Ad', 'Alan adları', 'Veritabanı', 'Durum'], array_map(static fn ($s) => [
                $s['key'], $s['name'], implode(', ', $s['domains']), $s['db']['driver'].(isset($s['db']['database']) ? ' · '.$s['db']['database'] : ''),
                $s['status']['error'] ?: ($s['status']['pending'] ? $s['status']['pending'].' migration bekliyor' : ($s['status']['admins'] ? 'hazır' : 'yönetici yok')),
            ], $sites));

            return 0;
        }
        if ($action === 'migrate') {
            $failed = 0;
            foreach (SiteRegistry::migrate($key) as $result) {
                $result['ok'] ? $this->info("{$result['key']}: tamam") : $this->error("{$result['key']}: {$result['output']}");
                $failed += $result['ok'] ? 0 : 1;
            }

            return $failed ? 1 : 0;
        }
        if ($action === 'export') {
            $r = SiteExporter::export((string) $key, (string) $this->option('to'), $this->option('db') ?: null);
            $this->info("{$key} dışa alındı: {$r['dir']} (veritabanı: {$r['database']}, {$r['media']} görsel, {$r['storage']} depolama dosyası). Adımlar: OKUBENI.txt");

            return 0;
        }
        if ($action === 'remove') {
            $site = SiteRegistry::remove((string) $key);
            $this->info("{$site['key']} kayıttan çıkarıldı. Veriler yerinde: storage/sites/{$site['key']} (yeniden create ile geri gelir).");

            return 0;
        }
        if ($action !== 'create') {
            $this->error('Bilinmeyen işlem: list, create, migrate, remove ya da export.');

            return 1;
        }
        $db = ['driver' => 'sqlite'];
        if ($url = (string) $this->option('db')) {
            $c = DatabaseMover::connectionFromUrl($url);
            $db = array_filter(['driver' => $c['driver'], 'host' => $c['host'] ?? null, 'port' => $c['port'] ?? null, 'database' => $c['database'], 'username' => $c['username'] ?? null, 'password' => $c['password'] ?? null]);
        }
        if ($schema = (string) $this->option('schema')) {
            $db['schema'] = $schema;
        }
        $password = (string) $this->option('password') ?: (string) $this->secret('İlk yöneticinin şifresi (en az 10 karakter)');
        $site = SiteRegistry::create([
            'key' => (string) $key,
            'name' => (string) ($this->option('name') ?: $key),
            'domains' => (array) $this->option('domain'),
            'url' => (string) $this->option('url'),
            'modules' => $this->option('modules') !== null ? array_filter(array_map('trim', explode(',', (string) $this->option('modules')))) : null,
            'db' => $db,
            'admin' => ['username' => (string) $this->option('admin'), 'email' => (string) $this->option('email'), 'password' => $password],
        ]);
        $this->info("{$site['key']} kuruldu: ".implode(', ', $site['domains']).' ('.$site['db']['driver'].').');
        $this->line('Alan adını sunucuda ana siteyle aynı klasöre yönlendirin (Hostinger: alt alan adı ya da takma alan adı → public_html).');

        return 0;
    } catch (DomainException $e) {
        $this->error($e->getMessage());
        foreach ((array) ($e->details()['fields'] ?? []) as $field => $message) {
            $this->line("  {$field}: {$message}");
        }

        return 1;
    }
})->purpose('Alt siteler: list, create, migrate, remove, export');

/*
 * Çatının sözleşmesi (App\Core\Contracts; docs/done-sozlesme-raporu.md §4.1): modüller, uçlar, yetkiler, ayarlar, olaylar,
 * zamanlanmış görevler ve OpenAPI → docs/contracts/. Uç ya da yetki değişince yeniden üretilip commit edilir;
 * ContractsTest depodakiyle karşılaştırır. `--check`: farklıysa hata (CI).
 */
Artisan::command('kodmob:contracts {--check : Depodakiyle aynı değilse hata} {--root= : Deponun kökü}', function () use ($frameworkRoot) {
    $dir = $frameworkRoot($this).'/docs/contracts';
    $stale = [];
    foreach (Contracts::files() as $name => $content) {
        $file = $dir.'/'.$name;
        $same = is_file($file) && str_replace("\r\n", "\n", (string) file_get_contents($file)) === $content;
        if ($this->option('check')) {
            $same || $stale[] = $name;

            continue;
        }
        File::ensureDirectoryExists($dir);
        file_put_contents($file, $content);
        $this->line(($same ? 'Aynı' : 'Yazıldı').": docs/contracts/{$name}");
    }
    if ($stale) {
        $this->error('Sözleşme güncel değil: '.implode(', ', $stale).'. `php artisan kodmob:contracts` ile üretip commit edin.');

        return 1;
    }

    return 0;
})->purpose('Çatının sözleşmesi ve OpenAPI belgesi (docs/contracts)');
