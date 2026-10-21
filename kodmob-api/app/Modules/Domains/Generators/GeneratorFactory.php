<?php

namespace App\Modules\Domains\Generators;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Text;
use App\Modules\Domains\DomainName;

/**
 * Tarama tarifi (panel formu ya da Jarvis aracı) → doğrulanmış tarif + üretici.
 *
 *     ['mode' => 'short',   'tlds' => ['si'], 'lengths' => [2, 3], 'charset' => 'letters']
 *     ['mode' => 'pattern', 'tlds' => ['si'], 'patterns' => ['CVCV', 'CVCVC'], 'limit' => 20000]
 *     ['mode' => 'words',   'tlds' => ['si', 'com'], 'wordsets' => ['en-tech'], 'words' => "kod\nbulut", 'affixes' => true]
 *     ['mode' => 'list',    'tlds' => ['si'], 'names' => "kodmob\nkodmob.com.tr", 'hack' => false]
 *
 * Doğrulanmış tarif işin `input`'una yazılır; her adımda üretici bu tariften
 * yeniden kurulur (aynı tarif → aynı sıra).
 */
final class GeneratorFactory
{
    public const MODES = ['short', 'pattern', 'words', 'list'];

    public const MAX_TOTAL = 1000000;

    public const MAX_TLDS = 10;

    public const MAX_LIST = 5000;

    /** @return array{0:NameGenerator,1:array} üretici, normalize tarif */
    public static function make(array $spec): array
    {
        $mode = (string) ($spec['mode'] ?? 'short');
        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::field('mode', 'Tarama türü short, pattern, words ya da list olmalı.');
        }
        $tlds = self::tlds($spec['tlds'] ?? ['si']);
        $clean = ['mode' => $mode, 'tlds' => $tlds];

        switch ($mode) {
            case 'short':
                $lengths = array_values(array_unique(array_map('intval', self::list($spec['lengths'] ?? [2, 3]))));
                sort($lengths);
                if (! $lengths || min($lengths) < 1 || max($lengths) > 6) {
                    throw ValidationException::field('lengths', 'Uzunluk 1 ile 6 karakter arasında olmalı.');
                }
                $charset = (string) ($spec['charset'] ?? 'letters');
                if (! isset(ShortGenerator::CHARSETS[$charset])) {
                    throw ValidationException::field('charset', 'Karakter kümesi letters, alnum, digits ya da consonants olmalı.');
                }
                $prefix = self::affix($spec['prefix'] ?? '', 'prefix');
                $suffix = self::affix($spec['suffix'] ?? '', 'suffix');
                if ($prefix === '' && $suffix === '' && min($lengths) < DomainName::MIN_LABEL) {
                    throw ValidationException::field('lengths', 'Tek karakterli adlar alınamaz; en az 2 karakter seçin.');
                }
                $clean += compact('lengths', 'charset', 'prefix', 'suffix');
                $generator = new ShortGenerator($tlds, $lengths, $charset, $prefix, $suffix);
                break;

            case 'pattern':
                $patterns = array_values(array_unique(array_filter(array_map(static fn ($p) => preg_replace('/\s+/', '', (string) $p), self::list($spec['patterns'] ?? ['CVCV'])))));
                if (! $patterns || count($patterns) > 10) {
                    throw ValidationException::field('patterns', 'En az 1, en çok 10 kalıp girin (ör. CVCV, CVCVC).');
                }
                foreach ($patterns as $pattern) {
                    if (strlen($pattern) < DomainName::MIN_LABEL || strlen($pattern) > PatternGenerator::MAX_PATTERN) {
                        throw ValidationException::field('patterns', sprintf('Kalıp 2–%d karakter olmalı: %s', PatternGenerator::MAX_PATTERN, $pattern));
                    }
                }
                $limit = max(0, min(self::MAX_TOTAL, (int) ($spec['limit'] ?? 0)));
                $shuffle = filter_var($spec['shuffle'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $seed = (int) ($spec['seed'] ?? random_int(1, 99999));
                $consonants = preg_replace('/[^a-z]/', '', strtolower((string) ($spec['consonants'] ?? '')));
                $vowels = preg_replace('/[^a-z]/', '', strtolower((string) ($spec['vowels'] ?? '')));
                $clean += compact('patterns', 'limit', 'shuffle', 'seed', 'consonants', 'vowels');
                $generator = new PatternGenerator($tlds, $patterns, $consonants, $vowels, $limit, $shuffle, $seed);
                break;

            case 'words':
                $sets = array_values(array_filter(array_map('strval', self::list($spec['wordsets'] ?? [])), [Wordlists::class, 'exists']));
                $custom = array_values(array_filter(array_map('trim', self::list($spec['words'] ?? []))));
                $words = $custom;
                foreach ($sets as $set) {
                    $words = array_merge($words, Wordlists::words($set));
                }
                if (! $words) {
                    throw ValidationException::field('words', 'Bir kelime listesi seçin ya da kendi kelimelerinizi yazın.');
                }
                $affixes = filter_var($spec['affixes'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $prefixes = array_values(array_unique(array_filter(array_map(static fn ($a) => DomainName::ascii((string) $a), self::list($spec['prefixes'] ?? ($affixes ? Wordlists::PREFIXES : []))))));
                $suffixes = array_values(array_unique(array_filter(array_map(static fn ($a) => DomainName::ascii((string) $a), self::list($spec['suffixes'] ?? ($affixes ? Wordlists::SUFFIXES : []))))));
                $maxLength = max(0, min(63, (int) ($spec['max_length'] ?? 0)));
                $clean += ['wordsets' => $sets, 'words' => $custom, 'prefixes' => $prefixes, 'suffixes' => $suffixes, 'max_length' => $maxLength];
                $label = implode(', ', array_map([Wordlists::class, 'title'], $sets)) ?: '';
                if ($sets && array_filter($sets, [Wordlists::class, 'isHack']) === $sets && ! $prefixes && ! $suffixes) {
                    // Yalnızca domain hilesi listeleri: kelimeler uzantıyla bölünür.
                    $generator = new ListGenerator($tlds, $words, true);
                } else {
                    $generator = new WordGenerator($tlds, $words, $prefixes, $suffixes, $maxLength, $label);
                }
                break;

            default: // list
                $names = array_values(array_filter(array_map('trim', self::list($spec['names'] ?? []))));
                if (! $names) {
                    throw ValidationException::field('names', 'Denenecek adları her satıra bir tane gelecek şekilde yazın.');
                }
                if (count($names) > self::MAX_LIST) {
                    throw ValidationException::field('names', sprintf('Bir listede en çok %s ad olabilir.', Text::number(self::MAX_LIST)));
                }
                $hack = filter_var($spec['hack'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $clean += compact('names', 'hack');
                $generator = new ListGenerator($tlds, $names, $hack);
        }

        if ($generator->total() === 0) {
            throw ValidationException::field('mode', 'Bu tarifle denenecek ad çıkmadı.');
        }
        if ($generator->total() > self::MAX_TOTAL) {
            throw ValidationException::field('mode', sprintf(
                'Bu tarif %s aday üretiyor; bir taramada en çok %s olabilir. Uzunluğu, uzantı sayısını azaltın ya da örneklem sınırı koyun.',
                Text::number($generator->total()),
                Text::number(self::MAX_TOTAL)
            ));
        }

        return [$generator, $clean];
    }

    /** @return list<string> */
    private static function tlds(mixed $value): array
    {
        $tlds = array_values(array_unique(array_map(static fn ($t) => DomainName::tld((string) $t), array_filter(self::list($value), 'strlen'))));
        if (! $tlds) {
            throw ValidationException::field('tlds', 'En az bir uzantı seçin (ör. si).');
        }
        if (count($tlds) > self::MAX_TLDS) {
            throw ValidationException::field('tlds', sprintf('Bir taramada en çok %d uzantı olabilir.', self::MAX_TLDS));
        }

        return $tlds;
    }

    private static function affix(mixed $value, string $field): string
    {
        $value = DomainName::ascii((string) $value);
        if ($value !== '' && ! preg_match('/^[a-z0-9-]{1,20}$/', $value)) {
            throw ValidationException::field($field, 'Ön ek / son ek yalnızca harf, rakam ve tire içerebilir (en çok 20).');
        }

        return $value;
    }

    /** Dizi ya da virgül / satır ayrılmış metin → liste. */
    private static function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (is_scalar($value)) {
            return preg_split('/[\r\n,;]+/', (string) $value) ?: [];
        }

        return [];
    }
}
