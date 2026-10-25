<?php

namespace App\Modules\Media;

use App\Core\Content\Locales;
use App\Core\Database\Sql;
use App\Core\Exceptions\DomainException;
use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Görsel kitaplığı (`media` tablosu + <site kökü>/media/YYYY/MM/ dosyaları).
 *
 * Her görselin alt metni her dilde ayrıdır (`alts` = { tr, en, de }); Türkçe
 * zorunludur, boş dil Türkçeye düşer. Görsel kayıtları istek boyunca bellekte
 * tutulur (sayfa verisi aynı görseli birkaç kez ister).
 */
final class MediaLibrary
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MIMES = ['image/jpeg' => 'JPG', 'image/png' => 'PNG', 'image/webp' => 'WebP'];

    /** @var array<int,array|null> */
    private static array $rows = [];

    public function __construct(private readonly ImageProcessor $images = new ImageProcessor) {}

    // ------------------------------------------------------------ okuma

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        if (! array_key_exists($id, self::$rows)) {
            $row = DB::table('media')->where('id', $id)->first();
            self::$rows[$id] = $row ? self::decode((array) $row) : null;
        }

        return self::$rows[$id];
    }

    /** Birden çok görseli tek sorguda belleğe alır. @param iterable<int> $ids */
    public static function prime(iterable $ids): void
    {
        $want = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! array_key_exists($id, self::$rows)) {
                $want[] = $id;
            }
        }
        if (! $want) {
            return;
        }
        foreach (array_chunk(array_unique($want), 500) as $chunk) {
            foreach (DB::table('media')->whereIn('id', $chunk)->get() as $row) {
                self::$rows[(int) $row->id] = self::decode((array) $row);
            }
            foreach ($chunk as $id) {
                self::$rows[$id] ??= null;
            }
        }
    }

    public static function forget(?int $id = null): void
    {
        if ($id === null) {
            self::$rows = [];
        } else {
            unset(self::$rows[$id]);
        }
    }

    /** @return array{items:list<array>,total:int,pages:int} */
    public function page(string $search, int $page, int $perPage): array
    {
        $query = DB::table('media')->orderByDesc('created_at')->orderByDesc('id');
        if ($search !== '') {
            $like = Sql::pattern($search);
            $query->where(function ($q) use ($like) {
                $q->whereRaw(Sql::like('file'), [$like])->orWhereRaw(Sql::like('alts'), [$like]);
            });
        }
        $total = (clone $query)->count();
        $rows = $query->forPage(max(1, $page), $perPage)->get();
        foreach ($rows as $row) {
            self::$rows[(int) $row->id] = self::decode((array) $row);
        }

        return [
            'items' => $rows->map(fn ($row) => (int) $row->id)->all(),
            'total' => $total,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    /** Görselin bir boyutunun (yoksa aslının) adresi ve ölçüsü. @return array{0:string,1:int,2:int} */
    public static function source(array $row, string $size): array
    {
        $variant = $size !== 'full' ? ($row['sizes'][$size] ?? null) : null;
        if ($variant) {
            return [self::url($row, $variant['file']), (int) $variant['width'], (int) $variant['height']];
        }

        return [self::url($row, $row['file']), (int) $row['width'], (int) $row['height']];
    }

    public static function url(array $row, string $file): string
    {
        return Paths::mediaUrl(trim($row['path'].'/'.$file, '/'));
    }

    public static function filePath(array $row, ?string $file = null): string
    {
        return Paths::mediaRoot().'/'.trim($row['path'].'/'.($file ?? $row['file']), '/');
    }

    // ------------------------------------------------------------ yazma

    /** Panelden yükleme (multipart `file`). */
    public function storeUpload(?UploadedFile $file, string $origin = 'panel'): int
    {
        if (! $file || ! $file->isValid()) {
            throw new DomainException('km_upload_failed', 'Dosya yüklenemedi. Tekrar deneyin.', 422, ['field' => 'file']);
        }

        return $this->storeFile($file->getRealPath(), $file->getClientOriginalName(), $origin);
    }

    /**
     * Diskteki bir görseli kitaplığa ekler (yükleme, adresten indirme, yedekten
     * taşıma). Kaynak dosya yerinde kalır.
     */
    public function storeFile(string $path, string $name, string $origin = 'panel', ?string $createdAt = null): int
    {
        if (! is_file($path) || filesize($path) === 0) {
            throw new DomainException('km_upload_failed', 'Dosya yüklenemedi. Tekrar deneyin.', 422, ['field' => 'file']);
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new DomainException('km_upload_too_large', 'Görsel en fazla 10 MB olabilir.', 422, ['field' => 'file']);
        }
        $info = @getimagesize($path);
        $mime = (string) ($info['mime'] ?? '');
        if (! isset(self::MIMES[$mime])) {
            throw new DomainException('km_upload_type', 'Yalnızca şu türler yüklenebilir: JPG, PNG, WEBP.', 422, ['field' => 'file']);
        }

        $when = $createdAt ? Clock::carbon($createdAt) : now();
        $relative = $when->format('Y/m');
        $dir = Paths::mediaRoot().'/'.$relative;
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new DomainException('km_upload_failed', 'Görsel klasörü oluşturulamadı: '.$relative, 500);
        }
        $base = $this->uniqueBase($dir, pathinfo(Text::fileName($name), PATHINFO_FILENAME), ImageProcessor::extension($mime));

        $meta = $this->images->process($path, $mime, $dir, $base, SettingsService::instance()->bool(MediaModule::WEBP));
        $now = Clock::now();
        $id = (int) DB::table('media')->insertGetId([
            'path' => $relative,
            'file' => $meta['file'],
            'mime' => $meta['mime'],
            'width' => $meta['width'],
            'height' => $meta['height'],
            'size' => $meta['size'],
            'sizes' => Text::json((object) $meta['sizes']),
            'alts' => Text::json(array_fill_keys(Locales::all(), '')),
            'origin' => $origin,
            'user_id' => Auth::id(),
            'created_at' => $createdAt ?? $now,
            'updated_at' => $now,
        ]);
        self::forget($id);

        return $id;
    }

    /**
     * Adresten indirip ekler. İç ağ adresleri (localhost, 10.x, 192.168.x…)
     * reddedilir; yönlendirmeler de aynı denetimden geçer.
     */
    public function storeUrl(string $url, string $origin = 'url'): int
    {
        $this->assertPublicUrl($url);
        $tmp = tempnam(sys_get_temp_dir(), 'kmimg');
        try {
            (new Client(['timeout' => 30, 'connect_timeout' => 10]))->get($url, [
                RequestOptions::SINK => $tmp,
                RequestOptions::HEADERS => ['User-Agent' => 'Kodmob/1.0 (+'.config('app.url').')'],
                RequestOptions::ALLOW_REDIRECTS => ['max' => 3, 'on_redirect' => fn ($request, $response, $uri) => $this->assertPublicUrl((string) $uri)],
                RequestOptions::PROGRESS => static function ($total, $downloaded) {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new DomainException('km_upload_too_large', 'Görsel en fazla 10 MB olabilir.', 422, ['field' => 'url']);
                    }
                },
            ]);
            $name = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'gorsel';

            return $this->storeFile($tmp, $name.'.img', $origin);
        } catch (DomainException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DomainException('km_upload_failed', 'Görsel indirilemedi: '.mb_substr($e->getMessage(), 0, 160), 422, ['field' => 'url']);
        } finally {
            @unlink($tmp);
        }
    }

    /** @param array<string,mixed> $alts @return array<string,string> */
    public static function saveAlts(int $id, array $alts): array
    {
        $clean = [];
        foreach (Locales::all() as $locale) {
            $clean[$locale] = mb_substr(Text::field((string) ($alts[$locale] ?? '')), 0, 200);
        }
        DB::table('media')->where('id', $id)->update(['alts' => Text::json($clean), 'updated_at' => Clock::now()]);
        self::forget($id);

        return $clean;
    }

    // ------------------------------------------------------------ yardımcılar

    private static function decode(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['width'] = (int) $row['width'];
        $row['height'] = (int) $row['height'];
        $row['sizes'] = json_decode((string) $row['sizes'], true) ?: [];
        $alts = json_decode((string) $row['alts'], true) ?: [];
        $row['alts'] = [];
        foreach (Locales::all() as $locale) {
            $row['alts'][$locale] = trim((string) ($alts[$locale] ?? ''));
        }

        return $row;
    }

    private function uniqueBase(string $dir, string $base, string $ext): string
    {
        $base = $base !== '' ? $base : 'gorsel';
        $candidate = $base;
        for ($n = 2; is_file("{$dir}/{$candidate}.{$ext}") || glob("{$dir}/{$candidate}-*x*.*"); $n++) {
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }

    private function assertPublicUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new DomainException('km_upload_failed', 'Geçerli bir görsel adresi yazın (https://…).', 422, ['field' => 'url']);
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new DomainException('km_upload_failed', 'Adres çözümlenemedi: '.$host, 422, ['field' => 'url']);
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new DomainException('km_upload_failed', 'İç ağ adreslerinden görsel indirilemez.', 422, ['field' => 'url']);
            }
        }
    }
}
