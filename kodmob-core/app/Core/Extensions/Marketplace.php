<?php

namespace App\Core\Extensions;

use App\Core\Support\Clock;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Topluluk araçlarının kataloğu (Araç Pazarı › Keşfet; `kodmob:add <anahtar>`). Kaynak Keşfet deposu
 * (github.com/kodmob/kodmob-explore, `config('kodmob.marketplace_url')`): depoya araç eklenince CI kataloğu üretir,
 * kurulumlar en çok 6 saatte bir okur ve son kopyayı saklar (`storage/framework/marketplace.json`). Okunamazsa son
 * kopya, o da yoksa çatıyla gelen `resources/marketplace/catalog.json` kullanılır; adres boşsa dışarı hiç istek atılmaz.
 * İndirilen paketin `sha256` özeti katalogdakiyle aynı olmalı (ToolInstaller).
 */
final class Marketplace
{
    /** Uzak kataloğun tazelik süresi (saniye). */
    private const TTL = 6 * 3600;

    /**
     * @return list<array{key:string,label:string,description:string,author:string,license:string,version:string,kodmob:string,repository:string,download:string,sha256:string,verified:bool}>
     */
    public static function tools(?string $file = null): array
    {
        $file ??= is_file(self::cacheFile()) ? self::cacheFile() : self::bundledFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $out = [];
        foreach ((array) ($data['tools'] ?? []) as $tool) {
            if (! is_array($tool) || ! preg_match(ToolManifest::KEY, (string) ($tool['key'] ?? ''))) {
                continue;
            }
            $text = static fn (string $field) => trim((string) ($tool[$field] ?? ''));
            $out[] = [
                'key' => $text('key'), 'label' => $text('label') ?: $text('key'), 'description' => $text('description'),
                'author' => $text('author'), 'license' => $text('license'), 'version' => $text('version'), 'kodmob' => $text('kodmob'),
                'repository' => $text('repository'), 'download' => $text('download'), 'sha256' => strtolower($text('sha256')),
                'verified' => (bool) ($tool['verified'] ?? false),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $key, ?string $file = null): ?array
    {
        foreach (self::tools($file) as $tool) {
            if ($tool['key'] === $key) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Uzak kataloğu okur (`$force` değilse yalnızca son kopya eskiyse). Hata atmaz: okunamazsa eldeki kalır.
     *
     * @return array{source:string,url:string,fetched_at:?string,error:?string}
     */
    public static function refresh(bool $force = false): array
    {
        $url = trim((string) config('kodmob.marketplace_url'));
        $cache = self::cacheFile();
        $meta = self::meta();
        $fresh = is_file($cache) && time() - (int) filemtime($cache) < self::TTL;
        $recentError = ! empty($meta['error_at']) && time() - (int) $meta['error_at'] < 600;
        if ($url !== '' && str_starts_with($url, 'https://') && ($force || (! $fresh && ! $recentError))) {
            try {
                $response = Http::timeout(5)->acceptJson()->get($url);
                $data = $response->json();
                if (! $response->successful() || ! is_array($data) || ! is_array($data['tools'] ?? null)) {
                    throw new \RuntimeException('katalog okunamadı (HTTP '.$response->status().')');
                }
                @mkdir(dirname($cache), 0755, true);
                file_put_contents($cache, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $meta = ['fetched_at' => Clock::now(), 'error' => null, 'error_at' => null];
            } catch (Throwable $e) {
                $meta = ['fetched_at' => $meta['fetched_at'] ?? null, 'error' => mb_substr($e->getMessage(), 0, 200), 'error_at' => time()];
            }
            @file_put_contents(self::metaFile(), (string) json_encode($meta));
        }

        return [
            'source' => is_file($cache) ? 'remote' : 'bundled',
            'url' => $url,
            'fetched_at' => isset($meta['fetched_at']) ? Clock::local((string) $meta['fetched_at']) : null,
            'error' => $meta['error'] ?? null,
        ];
    }

    private static function meta(): array
    {
        $meta = is_file(self::metaFile()) ? json_decode((string) file_get_contents(self::metaFile()), true) : null;

        return is_array($meta) ? $meta : [];
    }

    private static function cacheFile(): string
    {
        return storage_path('framework/marketplace.json');
    }

    private static function metaFile(): string
    {
        return storage_path('framework/marketplace.meta.json');
    }

    private static function bundledFile(): string
    {
        return resource_path('marketplace/catalog.json');
    }
}
