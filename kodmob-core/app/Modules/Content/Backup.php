<?php

namespace App\Modules\Content;

use App\Core\Content\ContentRepository;
use App\Core\Content\ContentType;
use App\Core\Content\Languages;
use App\Core\Content\PageCache;
use App\Core\Content\SlugHistory;
use App\Core\Content\Types;
use App\Core\Database\BackupPart;
use App\Core\Database\TableDump;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\ValidationException;
use App\Core\Modules;
use App\Core\Settings\SettingsRegistry;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Blog\BlogPosts;
use App\Modules\I18n\LanguageService;
use App\Modules\Live\PulseFile;
use App\Modules\Media\MediaLibrary;
use App\Modules\Seo\Services\SeoBackup;
use App\Modules\Seo\Services\SeoMeta;
use App\Modules\Site\Blocks\Layouts;
use App\Modules\Site\Legal\LegalPages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tam yedek: bütün içerik (projeler, hizmetler, referanslar, blog), ayarlar,
 * sayfa metinleri, teklif hesaplayıcı fiyatları ve içeriğin kullandığı
 * görsellerin kaydı (adres + alt metinler) tek JSON. Biçim WordPress
 * sürümüyle (kodmob-core eklentisi) aynıdır: oradan alınan yedek buraya
 * yüklenebilir. Görsel dosyaları yedeğe girmez.
 *
 * Modüllerin tabloları (`Module::backup()`: siparişler, talepler, aboneler…) `records` altında, kimlikleriyle.
 * Kişisel veri taşıyanlar indirilen yedeğe yalnızca istenince girer (otomatik ve anlık yedekte hep var); geri
 * yüklemede ayrıca seçilir, seçilmezse mevcut kayıtlar kalır (docs/done-commerce-raporu.md K5).
 *
 * Geri yükleme:
 * 1. Önce mevcut durumun anlık yedeği alınır (storage/app/backups/, son 10
 *    tutulur) — geri yükleme de geri alınabilir.
 * 2. Görseller eşlenir: aynı kimlik ve dosya → aynı; aynı dosya adı → o kayıt;
 *    yoksa storage/app/backups/media/ klasöründen (taşımada elle ya da
 *    paketle yüklenir), o da yoksa adresinden indirilir.
 * 3. Mevcut içerik çöpe taşınır, yedektekiler yazılır (tek transaction),
 *    ayarlar yazılır, sayfa önbelleği temizlenir.
 */
final class Backup
{
    public const FORMAT = 'kodmob-backup';

    public const VERSION = 1;

    private const KEEP = 10;

    public static function dir(): string
    {
        return Paths::backups();
    }

    /** Taşımada görsel asıllarının aranacağı klasör. */
    public static function mediaDir(): string
    {
        return self::dir().'/media';
    }

    // ------------------------------------------------------------ dışa aktarma

    /** @param  bool  $personal  kişisel veri taşıyan modül tabloları da (siparişler, talepler, aboneler) */
    public function export(bool $personal = false): array
    {
        $content = [];
        $mediaIds = [];
        $paged = Types::paged();
        foreach (Types::all() as $name => $type) {
            $content[$name] = [];
            $kind = $type->recordKind();
            foreach ($type->records(false) as $record) {
                $item = $type->export($record);
                $mediaIds = array_merge($mediaIds, $this->imageIds($type, $item['data']));
                // Kendi sayfası olan türün kaydı (ör. proje) kayıt SEO'sunu da taşır.
                if (isset($paged[$kind]) && ($seo = SeoMeta::export($kind, $record->id))) {
                    $item['seo'] = $seo;
                    $mediaIds[] = $seo['image_id'];
                }
                $content[$name][] = $item;
            }
        }
        $blog = new BlogPosts;
        $content['posts'] = [];
        foreach ($blog->all() as $post) {
            $item = $blog->export($post);
            if ($item['cover']) {
                $mediaIds[] = $item['cover'];
            }
            if ($seo = SeoMeta::export('post', (int) $post['id'])) {
                $item['seo'] = $seo;
                $mediaIds[] = $seo['image_id'];
            }
            $content['posts'][] = $item;
        }

        // SEO: sayfa düzenlemeleri, robots kuralları, yönlendirmeler (görselleri de pakete).
        $seo = SeoBackup::export();
        $mediaIds = array_merge($mediaIds, $seo['media'], [(int) SettingsService::instance()->get('seo.default_image'), (int) SettingsService::instance()->get('seo.org_logo')]);
        unset($seo['media']);

        // Sayfa düzenleyicinin bölgeleri (yayındaki ve taslak bloklar) ve bloklardaki görseller.
        $layouts = Layouts::export();
        $mediaIds = array_merge($mediaIds, $layouts['media']);

        $media = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
        MediaLibrary::prime($ids);
        foreach ($ids as $id) {
            $row = MediaLibrary::find($id);
            if ($row) {
                $media[(string) $id] = ['url' => url(MediaLibrary::url($row, $row['file'])), 'file' => $row['file'], 'alts' => $row['alts']];
            }
        }

        $settings = [];
        foreach (SettingsRegistry::all() as $key => $definition) {
            // Gizli ayarlar (API anahtarları) yedeğe girmez; geri yüklenen kurulumda yeniden girilir.
            if ($definition->format === 'secret') {
                continue;
            }
            $settings[$key] = SettingsService::instance()->get($key);
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created' => now()->utc()->format(DATE_ATOM),
            'site' => url('/'),
            'counts' => array_map('count', $content),
            'settings' => $settings,
            'languages' => Languages::all(),
            'texts' => (object) PageTexts::overrides(),
            'estimate' => EstimateConfig::get(),
            'seo' => $seo,
            'layouts' => (object) $layouts['zones'],
            // Panelden özelleştirilmiş yasal belgeler (belge|dil → metin).
            'legal' => (object) LegalPages::export(),
            'media' => (object) $media,
            'content' => $content,
            'slug_history' => SlugHistory::export(),
            'records' => (object) $this->exportRecords($personal),
        ];
    }

    /** @return array<string,BackupPart> açık modüllerin yedek parçaları (anahtarla) */
    public static function parts(): array
    {
        $parts = [];
        foreach (Modules::classes() as $class) {
            foreach ((new $class)->backup() as $part) {
                $parts[$part->key] = $part;
            }
        }

        return $parts;
    }

    /** @return list<string> kişisel veri taşıyan parçaların adları (panel: "siparişler de eklensin mi") */
    public static function personalLabels(): array
    {
        return array_values(array_map(static fn (BackupPart $p) => $p->label, array_filter(self::parts(), static fn (BackupPart $p) => $p->personal)));
    }

    private function exportRecords(bool $personal): array
    {
        $records = [];
        foreach (self::parts() as $key => $part) {
            if ($part->personal && ! $personal) {
                continue;
            }
            $tables = TableDump::export($part->tables);
            $records[$key] = ['label' => $part->label, 'personal' => $part->personal, 'count' => array_sum(array_map('count', $tables)), 'tables' => $tables];
        }

        return $records;
    }

    // ------------------------------------------------------------ anlık yedekler

    /** Mevcut durumu dosyaya yazar; dosya adını döner. */
    public function snapshot(string $reason): string
    {
        $name = now()->format('Ymd-His').'-'.Text::slug($reason).'.json';
        // Kişisel kayıtlar da: geri yükleme geri alınabilsin (dosya sunucuda kalır).
        file_put_contents(self::dir().'/'.$name, Text::json($this->export(true)));
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            @unlink(self::dir().'/'.$old['name']);
        }

        return $name;
    }

    /** @return list<array{name:string,size:int,created:string}> yeniden eskiye */
    public function list(): array
    {
        $files = glob(self::dir().'/*.json') ?: [];
        rsort($files);

        return array_map(static fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f), 'created' => date(DATE_ATOM, (int) filemtime($f))], $files);
    }

    public function read(string $name): array
    {
        $file = self::dir().'/'.basename($name);
        if (! preg_match('/^[\w.-]+\.json$/', basename($name)) || ! is_file($file)) {
            throw new DomainException('km_not_found', 'Yedek dosyası bulunamadı.', 404);
        }

        return (array) json_decode((string) file_get_contents($file), true);
    }

    // ------------------------------------------------------------ geri yükleme

    /** @param  bool  $personal  yedekteki kişisel kayıtlar da yazılsın (mevcutların yerine geçer) */
    public function import(array $backup, bool $personal = false): array
    {
        if (($backup['format'] ?? '') !== self::FORMAT || ! is_array($backup['content'] ?? null)) {
            throw ValidationException::field('file', 'Bu dosya bir Kodmob yedeği değil.');
        }
        @set_time_limit(300);
        $snapshot = $this->snapshot('geri-yukleme-oncesi');

        // Diller önce: içerik alanları ve görsel alt metinleri kayıtlı dillere göre saklanır.
        // Eski yedeklerde yok: mevcut diller kalır.
        $languages = isset($backup['languages']) && is_array($backup['languages']) ? LanguageService::restore($backup['languages']) : null;

        [$mediaMap, $mediaReport, $urlMap] = $this->resolveMedia((array) ($backup['media'] ?? []));
        $counts = $languages !== null ? ['languages' => $languages] : [];
        $records = ['restored' => [], 'skipped' => []];

        DB::transaction(function () use ($backup, $mediaMap, $urlMap, $personal, &$counts, &$records) {
            $now = Clock::now();
            DB::table('contents')->whereNull('deleted_at')->update(['deleted_at' => $now]);
            DB::table('posts')->whereNull('deleted_at')->update(['deleted_at' => $now]);
            ContentRepository::forget();

            // Kayıtlar yeni kimlik alır: kayıt türü (project) → yedekteki kimlik → yeni kimlik. Başka
            // türe bağlanan alanlar (`post`) bu eşlemeyle bağlanır; türler yapılandırma sırasıyla yazılır.
            $idMaps = [];
            foreach (Types::all() as $name => $type) {
                $counts[$name] = 0;
                foreach ((array) ($backup['content'][$name] ?? []) as $record) {
                    $data = $this->mapLinks($type, $this->mapImages($type, (array) ($record['data'] ?? []), $mediaMap), $idMaps);
                    $id = $type->restore($record, $data);
                    $idMaps[$type->recordKind()][(int) ($record['id'] ?? 0)] = $id;
                    if (! empty($record['seo']) && is_array($record['seo'])) {
                        SeoMeta::import($type->recordKind(), $id, $record['seo'], $mediaMap);
                    }
                    ContentRepository::forget();
                    $counts[$name]++;
                }
            }

            $blog = new BlogPosts;
            $postMap = [];
            $counts['posts'] = 0;
            foreach ((array) ($backup['content']['posts'] ?? []) as $record) {
                $record['cover'] = $mediaMap[(int) ($record['cover'] ?? 0)] ?? 0;
                $record['html'] = strtr((string) ($record['html'] ?? ''), $urlMap);
                $postId = $blog->restore($record);
                $postMap[(int) ($record['id'] ?? 0)] = $postId;
                if (! empty($record['seo']) && is_array($record['seo'])) {
                    SeoMeta::import('post', $postId, $record['seo'], $mediaMap);
                }
                $counts['posts']++;
            }

            // Eski adresler (301) yeni kimliklere bağlanır. Eski yedeklerde yok: mevcut geçmiş kalır.
            if (isset($backup['slug_history']) && is_array($backup['slug_history'])) {
                $counts['slugs'] = SlugHistory::restore($backup['slug_history'], $idMaps + ['post' => $postMap]);
            }

            // Eski yedeklerde yok: mevcut metinler ve fiyatlar kalır.
            if (isset($backup['texts']) && is_array($backup['texts'])) {
                $counts['texts'] = PageTexts::replace($backup['texts']);
            }
            if (isset($backup['estimate']) && is_array($backup['estimate'])) {
                EstimateConfig::replace($backup['estimate']);
            }

            // Eski yedeklerde yok: mevcut sayfa düzeni kalır.
            if (isset($backup['layouts']) && is_array($backup['layouts'])) {
                $counts['layouts'] = Layouts::import($backup['layouts'], $mediaMap, $idMaps['page'] ?? []);
            }

            // Eski yedeklerde yok: mevcut özelleştirmeler kalır.
            if (isset($backup['legal']) && is_array($backup['legal'])) {
                $counts['legal'] = LegalPages::import($backup['legal']);
            }

            if (isset($backup['seo']) && is_array($backup['seo'])) {
                $counts['seo'] = SeoBackup::import($backup['seo'], $mediaMap);
            }

            // Modüllerin tabloları: yedekte olmayan parça (eski yedek, indirilirken alınmamış) ve seçilmeyen kişisel
            // kayıtlar dokunulmadan kalır; kapalı modülün parçası yazılmaz.
            $contentMap = [];
            foreach ($idMaps as $map) {
                $contentMap += $map;
            }
            $parts = self::parts();
            foreach ((array) ($backup['records'] ?? []) as $key => $saved) {
                $part = $parts[$key] ?? null;
                $label = (string) ($part->label ?? ($saved['label'] ?? $key));
                if (! $part || ! is_array($saved) || ($part->personal && ! $personal)) {
                    $records['skipped'][] = $label;

                    continue;
                }
                $counts[$key] = TableDump::replace((array) ($saved['tables'] ?? []), $part->tables, $part->contentLinks, $contentMap);
                $records['restored'][] = $label;
            }

            $counts['settings'] = 0;
            foreach ((array) ($backup['settings'] ?? []) as $key => $value) {
                if (in_array($key, ['seo.default_image', 'seo.org_logo'], true)) {
                    $value = $mediaMap[(int) $value] ?? 0;
                }
                if (SettingsRegistry::get((string) $key)) {
                    try {
                        SettingsService::instance()->set((string) $key, $value);
                        $counts['settings']++;
                    } catch (ValidationException) {
                        // Geçersiz değer: mevcut ayar kalır.
                    }
                }
            }
        });
        ContentRepository::forget();
        PageCache::flush();
        PulseFile::touch('content');

        return ['counts' => $counts, 'media' => $mediaReport, 'snapshot' => $snapshot, 'records' => $records];
    }

    // ------------------------------------------------------------ görseller

    /** @return list<int> */
    private function imageIds(ContentType $type, array $data): array
    {
        $ids = [];
        foreach ($type->shared() as $name => $field) {
            if ($field['type'] === 'image' && ! empty($data[$name])) {
                $ids[] = (int) $data[$name];
            }
            if ($field['type'] === 'gallery') {
                $ids = array_merge($ids, array_map('intval', (array) ($data[$name] ?? [])));
            }
        }

        return $ids;
    }

    /**
     * Başka türe bağlanan alanlar (`type: post`, `postType: km_project`): yedekteki kimlik, o türün
     * yeni kimliğine. Bağlanan kayıt yedekte yoksa bağ kalkar (0).
     *
     * @param  array<string,array<int,int>>  $idMaps  kayıt türü → yedekteki kimlik → yeni kimlik
     */
    private function mapLinks(ContentType $type, array $data, array $idMaps): array
    {
        foreach ($type->shared() as $name => $field) {
            if (($field['type'] ?? '') === 'post' && ! empty($data[$name])) {
                $kind = (string) preg_replace('/^km_/', '', (string) ($field['postType'] ?? ''));
                $data[$name] = $idMaps[$kind][(int) $data[$name]] ?? 0;
            }
        }

        return $data;
    }

    private function mapImages(ContentType $type, array $data, array $map): array
    {
        foreach ($type->shared() as $name => $field) {
            if ($field['type'] === 'image') {
                $data[$name] = $map[(int) ($data[$name] ?? 0)] ?? 0;
            }
            if ($field['type'] === 'gallery') {
                $data[$name] = array_values(array_filter(array_map(static fn ($id) => $map[(int) $id] ?? 0, (array) ($data[$name] ?? []))));
            }
        }

        return $data;
    }

    /**
     * Yedekteki görsel → bu sitedeki görsel kimliği; ayrıca eski adres → yeni
     * adres (yazı gövdesindeki görseller için).
     *
     * @return array{0:array<int,int>,1:array{reused:int,downloaded:int,failed:list<string>},2:array<string,string>}
     */
    private function resolveMedia(array $media): array
    {
        $library = new MediaLibrary;
        $map = [];
        $urls = [];
        $report = ['reused' => 0, 'downloaded' => 0, 'failed' => []];
        foreach ($media as $oldId => $item) {
            $oldId = (int) $oldId;
            $url = (string) ($item['url'] ?? '');
            $file = basename((string) ($item['file'] ?? parse_url($url, PHP_URL_PATH)));
            $id = 0;

            $same = $oldId ? MediaLibrary::find($oldId) : null;
            if ($same && $same['file'] === $file) {
                $id = $oldId;
            }
            if (! $id && $file !== '') {
                $id = (int) DB::table('media')->where('file', $file)->orderBy('id')->value('id');
            }
            if ($id) {
                $report['reused']++;
            } else {
                // Taşıma: görsel, sunucuya yüklenen klasörde (backups/media/) ise oradan
                // alınır — yerel siteden canlıya geçişte kaynak adres indirilemez.
                $local = $file !== '' ? self::mediaDir().'/'.$file : '';
                try {
                    if ($local !== '' && is_file($local)) {
                        $id = $library->storeFile($local, $file, 'import');
                    } elseif ($url !== '' && preg_match('#^https?://#', $url)) {
                        $id = $library->storeUrl($url, 'import');
                    }
                    if ($id) {
                        $report['downloaded']++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Kodmob yedek] görsel alınamadı: '.$file.' — '.$e->getMessage());
                    $id = 0;
                }
            }
            if ($id) {
                if (! empty($item['alts']) && is_array($item['alts'])) {
                    MediaLibrary::saveAlts($id, $item['alts']);
                }
                $map[$oldId] = $id;
                $row = MediaLibrary::find($id);
                if ($url !== '' && $row) {
                    $new = MediaLibrary::url($row, $row['file']);
                    $urls[$url] = $new;
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    if ($path !== '') {
                        $urls[$path] = $new;
                    }
                }
            } else {
                $report['failed'][] = $file ?: $url;
            }
        }
        uksort($urls, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return [$map, $report, $urls];
    }
}
