<?php

namespace App\Core\Sms;

/**
 * SMS metni ve numara kuralları. Panel aynı hesabı kodmob-frontend/src/domain/sms.js ile yapar; ikisi
 * tests/fixtures/sms.json vektörleriyle sınanır.
 *
 * - Metin: tipografik tırnak, uzun tire, üç nokta, şapkalı harf gibi karakterler SMS'e uygun
 *   karşılıklarına çevrilir (clean). GSM alfabesi + Türkçe harfler dışında kalan (emoji vb.)
 *   gönderilmez: hangileri olduğu söylenir.
 * - Kodlama: ş ğ ı İ ç içeren metin Türkçe kodlamayla gider (`tr`, NetGSM `encoding`); boy
 *   160 yerine 155, birleşik mesajda parça başına 153 yerine 150 karakter. { } [ ] ~ ^ | \ €
 *   iki karakter sayılır. Boy sayısı operatöre göre değişebilir; panel "yaklaşık" der.
 * - Numara: Türkiye cep numarası 5XXXXXXXXX'e indirgenir (0, 90, +90 önekleri atılır); başka
 *   ülke `+` ya da `00` ile yazılır ve 00… olarak gider (NetGSM hesabında yurt dışı izni gerekir).
 *   Sabit hat ve eksik numara geçersizdir.
 */
final class SmsText
{
    /** En uzun mesaj (boy): yanlışlıkla uzun metin göndermeye karşı. */
    public const MAX_PARTS = 6;

    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = '^{}\\[~]|€';

    private const TURKISH = 'ŞşĞğİıç';

    private const REPLACE = [
        "\r\n" => "\n", "\t" => ' ', "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ',
        '“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"', '″' => '"',
        '‘' => "'", '’' => "'", '‚' => "'", '′' => "'", '`' => "'", '´' => "'",
        '–' => '-', '—' => '-', '―' => '-', '−' => '-', '•' => '-', '…' => '...',
        'â' => 'a', 'Â' => 'A', 'î' => 'i', 'Î' => 'I', 'û' => 'u', 'Û' => 'U',
    ];

    public static function clean(string $text): string
    {
        return trim(strtr($text, self::REPLACE));
    }

    /**
     * @return array{text:string,encoding:string,length:int,parts:int,per_part:int,unsupported:list<string>}
     */
    public static function analyze(string $text): array
    {
        $text = self::clean($text);
        $length = 0;
        $turkish = false;
        $unsupported = [];
        foreach (mb_str_split($text) as $char) {
            if (str_contains(self::GSM_EXTENDED, $char)) {
                $length += 2;
            } elseif (str_contains(self::GSM, $char)) {
                $length += 1;
            } elseif (str_contains(self::TURKISH, $char)) {
                $length += 1;
                $turkish = true;
            } else {
                $unsupported[$char] = true;
            }
        }
        [$single, $multi] = $turkish ? [155, 150] : [160, 153];
        $parts = $length === 0 ? 0 : ($length <= $single ? 1 : (int) ceil($length / $multi));

        return [
            'text' => $text,
            'encoding' => $turkish ? 'tr' : '',
            'length' => $length,
            'parts' => $parts,
            'per_part' => $parts > 1 ? $multi : $single,
            'unsupported' => array_map('strval', array_keys($unsupported)),
        ];
    }

    /** Gönderilecek numara ya da geçersizse null. */
    public static function number(string $raw): ?string
    {
        $raw = trim($raw);
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (str_starts_with($raw, '+')) {
            $digits = '00'.$digits;
        }
        if (str_starts_with($digits, '0090')) {
            $digits = substr($digits, 4);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (preg_match('/^5\d{9}$/', $digits) || preg_match('/^00[1-9]\d{6,14}$/', $digits)) {
            return $digits;
        }

        return null;
    }

    /** Gönderim kaydında numaranın tamamı tutulmaz: 5321234567 → 532*****67. */
    public static function mask(string $number): string
    {
        $n = strlen($number);

        return $n <= 5 ? $number : substr($number, 0, 3).str_repeat('*', $n - 5).substr($number, -2);
    }
}
