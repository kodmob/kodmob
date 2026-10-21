<?php

namespace App\Modules\Seo\Services;

use App\Core\Content\Types;
use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Settings\SettingsService;
use App\Core\Support\Options;
use App\Core\Support\Paths;
use App\Core\Support\SiteManifest;
use App\Modules\Seo\Seo;
use App\Modules\Site\Render\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IndexNow (Bing, Yandex, Naver, Seznam… — Google desteklemez): yayınlanan,
 * güncellenen ya da silinen sayfaların adresleri istek bitince tek seferde bildirilir.
 *
 * Anahtar `seo.indexnow_key`'dir; doğrulama dosyası `/<anahtar>.txt` render.php'den
 * sunulur. Site arama motorlarına kapalıyken (yayın öncesi) gönderilmez.
 */
final class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    private const LOG = 'seo_indexnow_log';

    /** @var list<string> */
    private static array $queue = [];

    private static bool $flushing = false;

    public static function key(): string
    {
        $key = (string) Seo::setting('seo.indexnow_key');
        if ($key === '') {
            $key = bin2hex(random_bytes(16));
            SettingsService::instance()->set('seo.indexnow_key', $key);
        }

        return $key;
    }

    /** Kaydın (bütün dillerdeki) adreslerini kuyruğa ekler. */
    public static function queueSubject(string $type, int $id): void
    {
        if (! Seo::on('indexnow') || $id <= 0 || ! in_array($type, SeoMeta::types(), true)) {
            return;
        }
        $router = Router::load(Paths::siteRoot());
        if (! $router) {
            return;
        }
        $site = rtrim((string) $router->manifest()['siteUrl'], '/');
        $urls = [];
        if ($type !== 'post') {
            // Kendi sayfası olan türün kaydı (ör. proje).
            $page = Types::page($type);
            $record = $page?->find($id);
            $route = SiteManifest::pageRoutes()[$type] ?? $type;
            foreach ($record ? $page->slugs($record->data) : [] as $code => $slug) {
                // Yalnızca yayındaki diller.
                if ($router->isActive((string) $code) && ($url = $router->url($route, (string) $code, (string) $slug)) !== null) {
                    $urls[] = $site.$url;
                }
            }
        } else {
            $post = DB::table('posts')->where('id', $id)->first();
            if ($post && $router->isActive((string) $post->locale) && ($url = $router->url('post', (string) $post->locale, (string) $post->slug)) !== null) {
                $urls[] = $site.$url;
            }
        }
        self::queue($urls);
    }

    /** @param list<string> $urls */
    public static function queue(array $urls): void
    {
        $urls = array_values(array_filter($urls, static fn ($u) => is_string($u) && str_starts_with($u, 'http')));
        if (! $urls) {
            return;
        }
        if (! self::$queue) {
            app()->terminating(static fn () => self::flush());
        }
        self::$queue = array_values(array_unique(array_merge(self::$queue, $urls)));
    }

    /** @return array{status:string,count:int,code:?int} */
    public static function flush(): array
    {
        $urls = self::$queue;
        self::$queue = [];

        return $urls ? self::submit($urls) : ['status' => 'empty', 'count' => 0, 'code' => null];
    }

    /**
     * @param  list<string>  $urls
     * @return array{status:string,count:int,code:?int}
     */
    public static function submit(array $urls): array
    {
        if (self::$flushing) {
            return ['status' => 'busy', 'count' => 0, 'code' => null];
        }
        $router = Router::load(Paths::siteRoot());
        $site = rtrim((string) ($router?->manifest()['siteUrl'] ?? ''), '/');
        $indexable = (bool) ($router?->manifest()['indexable'] ?? false);
        $urls = array_slice(array_values(array_unique($urls)), 0, 10000);
        if (! $indexable) {
            return self::log(['status' => 'skipped', 'count' => count($urls), 'code' => null, 'message' => 'Site arama motorlarına kapalı (yayın öncesi); gönderilmedi.']);
        }
        self::$flushing = true;
        try {
            $key = self::key();
            $response = Http::timeout(10)->acceptJson()->post(self::ENDPOINT, [
                'host' => parse_url($site, PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => $site.'/'.$key.'.txt',
                'urlList' => $urls,
            ]);
            $ok = in_array($response->status(), [200, 202], true);
            $result = self::log(['status' => $ok ? 'sent' : 'failed', 'count' => count($urls), 'code' => $response->status(), 'message' => $ok ? 'Kabul edildi.' : mb_substr((string) $response->body(), 0, 200)]);
            (new EventBus)->publish(new Event('seo.indexnow_sent', 'seo', 0, ['count' => count($urls), 'status' => $ok ? 'kabul edildi' : 'hata '.$response->status()]));

            return $result;
        } catch (\Throwable $e) {
            Log::warning('[Kodmob SEO] IndexNow: '.$e->getMessage());

            return self::log(['status' => 'failed', 'count' => count($urls), 'code' => null, 'message' => $e->getMessage()]);
        } finally {
            self::$flushing = false;
        }
    }

    /** @return list<array> son 30 gönderim */
    public static function history(): array
    {
        $log = Options::get(self::LOG, []);

        return is_array($log) ? $log : [];
    }

    private static function log(array $entry): array
    {
        $entry['at'] = now()->format('Y-m-d\TH:i:s');
        Options::set(self::LOG, array_slice(array_merge([$entry], self::history()), 0, 30));

        return $entry;
    }
}
