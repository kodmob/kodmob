<?php

namespace App\Core\Support;

/**
 * Panel adresleri (`/panel/talepler/12`). Rota tablosunun kaynağı ön yüzdür
 * (tv/src/panel/routes.js); build tabloyu km-manifest.json'a (`panel`) yazar, burada
 * okunur. Bildirim, e-posta, işlem kaydı ve Jarvis bağlantıları buradan üretilir;
 * adres elle yazılmaz.
 *
 *   PanelUrl::to('briefs', ['id' => 12])                      → /panel/talepler/12
 *   PanelUrl::to('editor', ['type' => 'projects', 'id' => 3]) → /panel/projeler/3
 *   PanelUrl::to('settings', ['tab' => 'backup'])              → /panel/ayarlar/yedekleme
 *   PanelUrl::absolute('briefs', ['id' => 12])                 → https://kodmob.com/panel/talepler/12
 *
 * Ön yüzle aynı sonucu ürettiği tests/fixtures/panel-urls.json vektörleriyle sınanır.
 * Manifest yoksa (derleme yapılmamış) her adres panelin köküdür.
 */
final class PanelUrl
{
    /** @var array<string, array> site kökü → panel tablosu */
    private static array $config = [];

    public static function to(string $name, array $params = [], array $query = []): string
    {
        $config = self::config();
        $base = (string) ($config['base'] ?? '/panel');
        $pattern = $config['routes'][$name] ?? null;
        if (! is_string($pattern)) {
            return $base.'/';
        }
        $parts = [];
        foreach (array_filter(explode('/', $pattern), 'strlen') as $part) {
            $optional = str_ends_with($part, '?');
            $token = rtrim($part, '?');
            if (! str_starts_with($token, ':')) {
                $parts[] = $token;

                continue;
            }
            $value = self::segment($token, $params, $name, $config);
            if ($value !== null) {
                $parts[] = $value;
            } elseif (! $optional) {
                return $base.'/';
            }
        }

        return $base.'/'.implode('/', $parts).self::query($query);
    }

    public static function absolute(string $name, array $params = [], array $query = []): string
    {
        return rtrim((string) config('app.url'), '/').self::to($name, $params, $query);
    }

    /**
     * Eski hash adresi (`#/briefs/12`, `#/settings?tab=backup`) → yeni adres. Eski bildirim
     * kayıtlarının göçü için; ön yüzdeki fromLegacyHash() ile aynı eşleme.
     */
    public static function fromLegacyHash(string $hash): string
    {
        $value = (string) preg_replace('#^\#?/?#', '', $hash);
        [$path, $search] = array_pad(explode('?', $value, 2), 2, '');
        parse_str($search, $q);
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        $head = $segments[0] ?? '';
        $id = $segments[1] ?? null;
        $num = static fn ($v): ?int => preg_match('/^[1-9]\d*$/', (string) $v) ? (int) $v : null;
        $types = self::config()['types'] ?? [];

        return match (true) {
            $head === '' => self::to('overview'),
            isset($types[$head]) => $id !== null ? self::to('editor', ['type' => $head, 'id' => $id === 'new' ? 0 : ($num($id) ?? 0)]) : self::to('list', ['type' => $head]),
            $head === 'jarvis' => $id === 'domains' ? self::to('domains', ['job' => $num($q['job'] ?? null)]) : self::to('jarvis', ['id' => $num($id)]),
            $head === 'briefs' => self::to('briefs', ['id' => $num($id)]),
            $head === 'history' => self::to('history', ['tab' => ($q['scope'] ?? '') === 'all' ? 'all' : null, 'id' => $num($q['id'] ?? null)], ['user_id' => $q['user_id'] ?? null]),
            $head === 'users' => self::to('users', ['id' => $num($q['id'] ?? null)]),
            // Ayarlar ikiye ayrıldı: sistem sekmesi Sistem Ayarları'na, gerisi Proje Ayarları'na.
            $head === 'settings' => isset(self::config()['tabs']['settings'][$q['tab'] ?? '']) ? self::to('settings', ['tab' => $q['tab']]) : self::to('projectSettings'),
            in_array($head, ['seo', 'security'], true) => self::to($head, ['tab' => $q['tab'] ?? null]),
            isset(self::config()['routes'][$head]) => self::to($head),
            default => self::to('overview'),
        };
    }

    /** Saklanmış bağlantı: eski `#/…` ise yenisi, değilse olduğu gibi. */
    public static function normalize(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        return str_starts_with($url, '#') ? self::fromLegacyHash($url) : $url;
    }

    /** Rota tablosu okunabiliyor mu (manifest var ve `panel` bölümü dolu)? */
    public static function available(): bool
    {
        return ! empty(self::config()['routes']);
    }

    /** Testler ve site kökü değişince. */
    public static function flush(): void
    {
        self::$config = [];
    }

    private static function segment(string $token, array $params, string $name, array $config): ?string
    {
        $key = $token === ':idOrNew' ? 'id' : substr($token, 1);
        $value = $params[$key] ?? null;
        $isId = static fn ($v): bool => is_numeric($v) && (int) $v > 0 && (string) (int) $v === (string) $v;

        return match ($token) {
            ':type' => isset($config['types'][$value]) ? (string) $config['types'][$value] : null,
            ':tab' => $value !== null && isset($config['tabs'][$name][(string) $value]) ? (string) $config['tabs'][$name][(string) $value] : null,
            ':idOrNew' => $value === 0 || $value === '0' || $value === 'new' ? 'yeni' : ($isId($value) ? (string) (int) $value : null),
            default => $isId($value) ? (string) (int) $value : null,
        };
    }

    private static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = urlencode(is_array($value) ? $key.'[]' : (string) $key).'='.urlencode((string) $item);
            }
        }

        return $pairs ? '?'.implode('&', $pairs) : '';
    }

    private static function config(): array
    {
        $file = Paths::manifest();
        if (! isset(self::$config[$file])) {
            $manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$config[$file] = is_array($manifest['panel'] ?? null) ? $manifest['panel'] : [];
        }

        return self::$config[$file];
    }
}
