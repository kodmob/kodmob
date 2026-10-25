<?php

namespace App\Modules\Domains;

use App\Core\Exceptions\ValidationException;

/**
 * Alan adı = etiket + uzantı (`kodmob` + `si`, `kodmob` + `com.tr`).
 *
 * Etiket kuralları (RDAP geçersiz adı da "bulunamadı" diye döndürdüğü için
 * burada doğrulanır):
 * - yalnızca a–z, 0–9 ve tire; tireyle başlamaz/bitmez
 * - en az 2, en çok 63 karakter (tek karakterliler kayıt kuruluşlarınca
 *   ayrılmıştır; .si'de hepsi kayıtsız görünür ama alınamaz)
 * - 3–4. karakterde `--` yok (IDN `xn--` önekleri kapsam dışı)
 */
final class DomainName
{
    public const MIN_LABEL = 2;

    public const MAX_LABEL = 63;

    /** İki düzeyli yaygın uzantılar (com.tr gibi); ayrıştırmada etiketi doğru ayırmak için. */
    private const SECOND_LEVEL = [
        'com.tr', 'net.tr', 'org.tr', 'gen.tr', 'biz.tr', 'info.tr', 'web.tr', 'av.tr', 'dr.tr', 'bel.tr', 'tv.tr', 'name.tr',
        'co.uk', 'org.uk', 'me.uk', 'com.au', 'net.au', 'com.br', 'com.mx', 'co.za', 'co.jp', 'co.nz', 'com.cy', 'co.at', 'or.at',
    ];

    public function __construct(public readonly string $label, public readonly string $tld) {}

    public function fqdn(): string
    {
        return $this->label.'.'.$this->tld;
    }

    public function isValid(): bool
    {
        return self::labelError($this->label) === null;
    }

    /** Etiket geçersizse Türkçe neden; geçerliyse null. */
    public static function labelError(string $label): ?string
    {
        $length = strlen($label);
        if ($length < self::MIN_LABEL) {
            return 'Tek karakterli adlar alınamaz.';
        }
        if ($length > self::MAX_LABEL) {
            return 'Ad en çok 63 karakter olabilir.';
        }
        if (! preg_match('/^[a-z0-9-]+$/', $label)) {
            return 'Yalnızca harf, rakam ve tire kullanılabilir.';
        }
        if ($label[0] === '-' || $label[$length - 1] === '-') {
            return 'Ad tireyle başlayamaz ya da bitemez.';
        }
        if ($length >= 4 && substr($label, 2, 2) === '--') {
            return '3. ve 4. karakterde çift tire olamaz.';
        }

        return null;
    }

    /** Uzantıyı normalize eder (`.SI` → `si`). Geçersizse ValidationException. */
    public static function tld(string $tld, string $field = 'tlds'): string
    {
        $tld = strtolower(trim($tld, " .\t\n\r"));
        if (! preg_match('/^[a-z]{2,24}(\.[a-z]{2,24})?$/', $tld)) {
            throw ValidationException::field($field, sprintf('Geçersiz uzantı: "%s".', $tld));
        }

        return $tld;
    }

    /**
     * Kullanıcı girdisi → alan adları. `ab` → her varsayılan uzantıyla;
     * `ab.si`, `https://www.ab.si/yol` → yalnızca kendisi.
     *
     * @param  list<string>  $defaultTlds
     * @return list<self>
     */
    public static function parse(string $value, array $defaultTlds): array
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^[a-z]+://#', '', $value);
        $value = preg_replace('#[/?\#].*$#', '', $value);
        $value = preg_replace('/^www\./', '', $value);
        $value = self::ascii($value);
        if ($value === '') {
            return [];
        }
        if (! str_contains($value, '.')) {
            return array_map(static fn ($tld) => new self($value, $tld), $defaultTlds);
        }
        foreach (self::SECOND_LEVEL as $suffix) {
            if (str_ends_with($value, '.'.$suffix)) {
                return [new self(substr($value, 0, -strlen($suffix) - 1), $suffix)];
            }
        }
        $dot = strrpos($value, '.');
        $label = substr($value, 0, $dot);
        // Alt alan adı verildiyse (a.b.si) kayıt edilebilir kısım son etikettir.
        if (str_contains($label, '.')) {
            $label = substr($label, strrpos($label, '.') + 1);
        }

        return [new self($label, substr($value, $dot + 1))];
    }

    /** Türkçe harfleri ASCII karşılığına çevirir, izin verilmeyenleri atar. */
    public static function ascii(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u', 'é' => 'e', 'ä' => 'a', 'ß' => 'ss', 'č' => 'c', 'š' => 's', 'ž' => 'z']);

        return preg_replace('/[^a-z0-9.\-]/', '', $value);
    }
}
