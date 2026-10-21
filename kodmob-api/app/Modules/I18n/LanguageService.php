<?php

namespace App\Modules\I18n;

use App\Core\Content\Languages;
use App\Core\Content\Locales;
use App\Core\Content\PageCache;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Modules\Content\PageTexts;
use Illuminate\Support\Facades\DB;

/**
 * Dil kaydının yönetimi (done-coklu-dil-raporu.md §4.2, §4.8). Kurallar:
 *
 * - Kod (`tr`, `en`, `pt-br`) benzersizdir ve değişmez; içerik bu anahtarla saklanır.
 * - Adres öneki ve varsayılan dil sitenin derlemesinden gelir (karar A; tv/src/profiles/<ad>/locales.js →
 *   km-manifest.json): panelden değişmez, `syncWithBuild()` kaydı derlemeyle eşitler. Derlemede
 *   olup kayıtta olmayan dil pasif eklenir. Derlemede olmayan (planlanan) dilin öneki kodudur.
 * - Tek varsayılan dil vardır ve yayında olmak zorundadır.
 * - Yeni dil pasif eklenir: önce panelde çevrilir. Yayına almak için dilin sitenin
 *   derlemesinde (km-manifest.json `locales`) olması gerekir; yoksa sayfaları yoktur (karar A:
 *   yeni dil build ile siteye girer).
 * - İçeriği olan dil silinmez, pasif bırakılır. Yedek dil zinciri döngü kuramaz.
 *
 * Her değişiklik sayfa önbelleğini temizler; render.php'nin okuduğu kopya I18nModule'de
 * (olay dinleyicisi) yeniden derlenir.
 */
final class LanguageService
{
    public const CODE = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

    /** Hiçbir dilin öneki olamaz: sitenin kendi klasörleri ve dosyaları. */
    public const RESERVED = ['panel', 'api', 'media', 'assets', 'ui', 'sitemap', 'robots', 'llms', 'feed', 'index', 'static'];

    /** Panelden değişebilen alanlar (önek ve varsayılan dil derlemeden gelir). */
    private const FIELDS = ['name', 'native', 'short', 'hreflang', 'og', 'intl', 'dir', 'fallback', 'flag', 'active'];

    /** @return list<array> */
    public function list(): array
    {
        $built = self::builtLocales();

        return array_map(static fn (array $l) => $l + ['built' => $built === null || in_array($l['code'], $built, true)], Languages::all());
    }

    public function create(array $input): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if (! preg_match(self::CODE, $code)) {
            throw ValidationException::field('code', 'Dil kodu iki ya da üç küçük harf olmalı; bölge eki isteğe bağlı (ör. fr, pt-br).');
        }
        if (Languages::find($code)) {
            throw ValidationException::field('code', 'Bu dil zaten kayıtlı.');
        }
        $built = (array) (self::manifest()['locales'][$code] ?? []);
        $language = $this->clean(['prefix' => $built['prefix'] ?? $code] + $input + [
            'name' => '', 'native' => '', 'short' => strtoupper(explode('-', $code)[0]),
            'hreflang' => $code, 'og' => '', 'intl' => $code, 'dir' => 'ltr', 'fallback' => Locales::default(),
            'flag' => '', 'active' => false,
        ], $code);
        $language['code'] = $code;
        $language['default'] = false;
        $language['og'] = $language['og'] !== '' ? $language['og'] : self::ogFor($code);
        $language['position'] = count(Languages::all());
        $this->validate($language, [...Languages::all(), $language]);

        $now = Clock::now();
        DB::table('languages')->insert(Languages::toRow($language) + ['created_at' => $now, 'updated_at' => $now]);

        return $this->saved($code);
    }

    /** @return array{0:array,1:array} [yeni hali, değişiklikler] */
    public function update(string $code, array $input): array
    {
        $current = $this->mustFind($code);
        $language = $this->clean(array_intersect_key($input, array_flip(self::FIELDS)) + $current, $code);
        $language['prefix'] = $current['prefix'];
        $language['code'] = $code;
        $language['default'] = $current['default'];
        $all = array_map(static fn ($l) => $l['code'] === $code ? $language : $l, Languages::all());
        $this->validate($language, $all, $current);

        $changes = [];
        foreach (self::FIELDS as $field) {
            if ($language[$field] !== $current[$field]) {
                $changes[$field] = ['from' => $current[$field], 'to' => $language[$field]];
            }
        }
        if ($changes) {
            DB::table('languages')->where('code', $code)->update(Languages::toRow($language) + ['updated_at' => Clock::now()]);
        }

        return [$this->saved($code), $changes];
    }

    /**
     * Kaydı sitenin derlemesiyle eşitler: derlemedeki dillerin öneki, varsayılan dil (öneksiz
     * olan, yayında), kayıtta olmayan derleme dili (pasif eklenir; adları derlemeden). Derleme
     * yoksa dokunulmaz. Kurulumda ve her derlenmiş kopya üretiminde çalışır (I18nConfig).
     *
     * @return bool kayıt değişti mi
     */
    public static function syncWithBuild(): bool
    {
        $manifest = self::manifest();
        $locales = (array) ($manifest['locales'] ?? []);
        if (! $locales) {
            return false;
        }
        $default = (string) ($manifest['default'] ?? '');
        if (! isset($locales[$default])) {
            $default = (string) (array_search('', array_map(static fn ($l) => (string) ($l['prefix'] ?? ''), $locales), true) ?: array_key_first($locales));
        }
        $changed = false;
        $now = Clock::now();
        $position = count(Languages::all());
        foreach ($locales as $code => $info) {
            $code = (string) $code;
            $prefix = (string) ($info['prefix'] ?? $code);
            $dir = ($info['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
            $current = Languages::find($code);
            if ($current === null) {
                $label = (string) ($info['label'] ?? $code);
                DB::table('languages')->insert(Languages::toRow([
                    'code' => $code, 'name' => $label, 'native' => $label, 'short' => (string) ($info['short'] ?? strtoupper($code)),
                    'prefix' => $prefix, 'hreflang' => $code, 'og' => (string) ($info['og'] ?? self::ogFor($code)), 'intl' => (string) ($info['intl'] ?? $code),
                    'dir' => $dir, 'fallback' => null, 'flag' => (string) ($info['flag'] ?? ''), 'active' => $code === $default, 'default' => false, 'position' => $position++,
                ]) + ['created_at' => $now, 'updated_at' => $now]);
                $changed = true;
            } elseif ($current['prefix'] !== $prefix || $current['dir'] !== $dir) {
                // Önek ve yazı yönü derlemeye ait (sayfa kabukları bunlarla üretilir).
                DB::table('languages')->where('code', $code)->update(['prefix' => $prefix, 'dir' => $dir, 'updated_at' => $now]);
                $changed = true;
            }
        }
        // Derlemede olmayan dilin öneki boş kalamaz (yalnızca varsayılan öneksiz).
        foreach (Languages::all() as $language) {
            if (! isset($locales[$language['code']]) && $language['prefix'] === '') {
                DB::table('languages')->where('code', $language['code'])->update(['prefix' => $language['code']]);
                $changed = true;
            }
        }
        Languages::forget();
        $target = Languages::find($default);
        if ($target !== null && (! $target['default'] || ! $target['active'])) {
            DB::transaction(static function () use ($default, $now) {
                DB::table('languages')->update(['is_default' => false]);
                DB::table('languages')->where('code', $default)->update(['is_default' => true, 'active' => true, 'updated_at' => $now]);
            });
            $changed = true;
        }
        if ($changed) {
            Languages::forget();
            PageCache::flush();
        }

        return $changed;
    }

    /** @param list<string> $codes yeni sıra (bütün diller) */
    public function reorder(array $codes): array
    {
        $codes = array_values(array_map('strval', $codes));
        $known = array_column(Languages::all(), 'code');
        if (count($codes) !== count($known) || array_diff($known, $codes) || count(array_unique($codes)) !== count($codes)) {
            throw ValidationException::field('codes', 'Sıralama bütün dilleri bir kez içermeli.');
        }
        foreach ($codes as $i => $code) {
            DB::table('languages')->where('code', $code)->update(['position' => $i]);
        }
        $this->changed();

        return Languages::all();
    }

    public function delete(string $code): array
    {
        $language = $this->mustFind($code);
        if ($language['default']) {
            throw new ConflictException('km_language_default', 'Varsayılan dil silinemez.');
        }
        if ($language['active']) {
            throw new ConflictException('km_language_active', $language['name'].' yayında; silmeden önce pasife alın.');
        }
        $usage = self::usage($code);
        if ($usage > 0) {
            throw new ConflictException('km_language_used', sprintf('%s dilinde %d içerik var; silmek yerine pasif bırakın (içerik korunur).', $language['name'], $usage), ['count' => $usage]);
        }
        foreach (Languages::all() as $other) {
            if ($other['fallback'] === $code) {
                DB::table('languages')->where('code', $other['code'])->update(['fallback' => null]);
            }
        }
        DB::table('languages')->where('code', $code)->delete();
        $this->changed();

        return $language;
    }

    /**
     * Yedekten geri yükleme: kayıtlı diller yedektekiyle değişir. Geçersiz satır atlanır;
     * sitenin derlemesinde olmayan dil pasif gelir; varsayılan yoksa ilk dil varsayılan olur.
     *
     * @return int geri yüklenen dil sayısı
     */
    public static function restore(array $list): int
    {
        $built = self::builtLocales();
        $rows = [];
        foreach (array_values($list) as $i => $item) {
            if (! is_array($item) || ! preg_match(self::CODE, (string) ($item['code'] ?? '')) || isset($rows[$item['code']])) {
                continue;
            }
            $known = array_search($item['code'], array_column(Languages::FALLBACK, 'code'), true);
            $fallback = $known === false ? null : Languages::FALLBACK[$known];
            $language = Languages::fromRow($item + ($fallback ?? []) + ['name' => $item['code'], 'native' => $item['code'], 'short' => strtoupper($item['code']), 'prefix' => $item['code'], 'hreflang' => $item['code'], 'og' => self::ogFor($item['code']), 'intl' => $item['code'], 'dir' => 'ltr', 'flag' => '', 'active' => false]);
            if ($built !== null && ! in_array($language['code'], $built, true)) {
                $language['active'] = false;
            }
            $language['position'] = $i;
            $rows[$language['code']] = $language;
        }
        if (! $rows) {
            return 0;
        }
        $defaults = array_keys(array_filter($rows, static fn ($l) => $l['default'] && $l['active']));
        $default = $defaults[0] ?? array_key_first(array_filter($rows, static fn ($l) => $l['active'])) ?? array_key_first($rows);
        foreach ($rows as $code => &$row) {
            $row['default'] = $code === $default;
            $row['active'] = $row['active'] || $row['default'];
            if (! $row['default'] && $row['prefix'] === '') {
                $row['prefix'] = $code;
            }
            if ($row['fallback'] !== null && ! isset($rows[$row['fallback']])) {
                $row['fallback'] = null;
            }
        }
        unset($row);

        $now = Clock::now();
        DB::table('languages')->delete();
        foreach ($rows as $row) {
            DB::table('languages')->insert(Languages::toRow($row) + ['created_at' => $now, 'updated_at' => $now]);
        }
        Languages::forget();

        return count($rows);
    }

    /** Dilde saklanan içerik sayısı: blog yazıları, dolu içerik alanları, sayfa metni düzeltmeleri. */
    public static function usage(string $code): int
    {
        $count = DB::table('posts')->where('locale', $code)->whereNull('deleted_at')->count();
        foreach (DB::table('contents')->whereNull('deleted_at')->pluck('data') as $data) {
            $i18n = (array) (json_decode((string) $data, true)['i18n'][$code] ?? []);
            if (array_filter($i18n, static fn ($v) => $v !== '' && $v !== null && $v !== [])) {
                $count++;
            }
        }
        $texts = PageTexts::overrides()[$code] ?? [];

        return $count + (is_array($texts) ? count($texts) : 0);
    }

    /** Sitenin derlemesindeki diller (km-manifest.json `locales`); derleme yoksa null. */
    public static function builtLocales(): ?array
    {
        $locales = self::manifest()['locales'] ?? null;

        return is_array($locales) ? array_keys($locales) : null;
    }

    private static function manifest(): array
    {
        return SiteManifest::data();
    }

    /** Ayrılmış adlar + varsayılan dilin bölüm adları (projeler, blog…): bir dil öneki bunlarla çakışırsa adresler karışır. */
    public static function reserved(): array
    {
        $words = self::RESERVED;
        $manifest = self::manifest();
        $default = Locales::default();
        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (($route['locale'] ?? '') !== $default) {
                continue;
            }
            $path = (string) ($route['path'] ?? $route['pattern'] ?? '');
            $segments = array_values(array_filter(explode('/', $path), 'strlen'));
            // Bugünkü derlemede varsayılan dil de önekli olabilir (/tr/projeler/): önek atlanır.
            if (($segments[0] ?? '') === $default) {
                array_shift($segments);
            }
            $first = $segments[0] ?? '';
            if ($first !== '' && ! str_contains($first, '.') && ! str_starts_with($first, ':')) {
                $words[] = $first;
            }
        }

        return array_values(array_unique($words));
    }

    // ------------------------------------------------------------ iç

    private function clean(array $in, string $code): array
    {
        $text = static fn ($v, int $max) => mb_substr(Text::field((string) $v), 0, $max);
        $fallback = $in['fallback'] ?? null;

        return [
            'name' => $text($in['name'] ?? '', 60),
            'native' => $text($in['native'] ?? '', 60),
            'short' => mb_strtoupper($text($in['short'] ?? '', 8)),
            'prefix' => strtolower(trim((string) ($in['prefix'] ?? ''), " /\t")),
            'hreflang' => trim((string) ($in['hreflang'] ?? $code)),
            'og' => trim((string) ($in['og'] ?? '')),
            'intl' => trim((string) ($in['intl'] ?? $code)),
            'dir' => ($in['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
            'fallback' => $fallback === null || $fallback === '' ? null : (string) $fallback,
            'flag' => $text($in['flag'] ?? '', 190),
            'active' => filter_var($in['active'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /** @param list<array> $all değişiklik uygulanmış bütün diller */
    private function validate(array $language, array $all, ?array $current = null): void
    {
        $errors = [];
        if ($language['name'] === '') {
            $errors['name'] = 'Dilin paneldeki adını yazın (ör. Fransızca).';
        }
        if ($language['native'] === '') {
            $errors['native'] = 'Dilin kendi dilindeki adını yazın (ör. Français).';
        }
        if ($language['short'] === '') {
            $errors['short'] = 'Kısa ad boş olamaz (ör. FR).';
        }
        $prefix = $language['prefix'];
        if ($prefix === '') {
            if (! $language['default']) {
                $errors['prefix'] = 'Yalnızca varsayılan dilin adres öneki boş olabilir.';
            }
        } elseif (! preg_match(self::CODE, $prefix)) {
            $errors['prefix'] = 'Önek iki ya da üç küçük harf olmalı; bölge eki isteğe bağlı (ör. fr, pt-br).';
        } elseif (in_array($prefix, self::reserved(), true)) {
            $errors['prefix'] = sprintf('"%s" sitenin kendi adreslerinden biri; başka bir önek seçin.', $prefix);
        } else {
            foreach ($all as $other) {
                if ($other['code'] !== $language['code'] && $other['prefix'] === $prefix) {
                    $errors['prefix'] = sprintf('%s aynı öneki kullanıyor.', $other['name']);
                }
            }
        }
        if (! preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $language['hreflang'])) {
            $errors['hreflang'] = 'hreflang biçimi: en, en-GB, pt-BR.';
        }
        if (! preg_match('/^[a-z]{2,3}_[A-Z]{2}$/', $language['og'])) {
            $errors['og'] = 'Open Graph biçimi: en_GB, tr_TR.';
        }
        if (! preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $language['intl'])) {
            $errors['intl'] = 'Tarih ve sayı biçimi için dil etiketi: en-GB, tr-TR.';
        }
        if ($language['fallback'] !== null) {
            $codes = array_column($all, 'code');
            if ($language['fallback'] === $language['code'] || ! in_array($language['fallback'], $codes, true)) {
                $errors['fallback'] = 'Yedek dil kayıtlı başka bir dil olmalı.';
            } elseif (self::cycles($all)) {
                $errors['fallback'] = 'Yedek diller döngü kuruyor (ör. Almanca → İngilizce → Almanca).';
            }
        }
        if ($language['default'] && ! $language['active']) {
            $errors['active'] = 'Varsayılan dil pasife alınamaz.';
        }
        if ($language['active'] && ! ($current['active'] ?? false)) {
            $built = self::builtLocales();
            if ($built === null) {
                $errors['active'] = 'Sitenin derlemesi bulunamadı; dili yayına almadan önce build alın (build.md).';
            } elseif (! in_array($language['code'], $built, true)) {
                $errors['active'] = sprintf('%s sitenin derlemesinde yok; sayfaları olmadan yayına alınamaz. Dili src/i18n\'e ekleyip build alın, sonra yayına alın.', $language['name']);
            }
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }
    }

    private static function cycles(array $all): bool
    {
        $map = array_column($all, 'fallback', 'code');
        foreach (array_keys($map) as $start) {
            $seen = [];
            for ($code = $start; $code !== null; $code = $map[$code] ?? null) {
                if (isset($seen[$code])) {
                    return true;
                }
                $seen[$code] = true;
            }
        }

        return false;
    }

    private static function ogFor(string $code): string
    {
        [$lang, $region] = array_pad(explode('-', $code), 2, '');

        return $lang.'_'.strtoupper($region !== '' ? substr($region, 0, 2) : $lang);
    }

    private function mustFind(string $code): array
    {
        return Languages::find($code) ?? throw NotFoundException::for('language', $code, 'Dil bulunamadı.');
    }

    private function saved(string $code): array
    {
        $this->changed();

        return Languages::find($code) ?? [];
    }

    private function changed(): void
    {
        Languages::forget();
        PageCache::flush();
    }
}
