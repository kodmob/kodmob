<?php

namespace App\Core\Extensions;

/**
 * Topluluk araçlarının kataloğu (`resources/marketplace/catalog.json`; çatıyla gelir, `kodmob:upgrade`
 * yeniler). Araç kataloğa açık depoda çekme isteğiyle (PR) eklenir; kontrol listesi docs/arac-paketi.md.
 * Panel kataloğu dışarıya istek atmadan bu dosyadan okur; `kodmob:add <anahtar>` paketi `download`
 * adresinden indirir ve `sha256` özetini denetler.
 */
final class Marketplace
{
    /**
     * @return list<array{key:string,label:string,description:string,author:string,license:string,version:string,kodmob:string,repository:string,download:string,sha256:string,verified:bool}>
     */
    public static function tools(?string $file = null): array
    {
        $file ??= resource_path('marketplace/catalog.json');
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
}
