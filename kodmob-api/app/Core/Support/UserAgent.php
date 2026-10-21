<?php

namespace App\Core\Support;

/**
 * Tarayıcı kimliğinden okunur cihaz adı: "Chrome · Windows", "Safari · iPhone".
 * Oturumlar listesinde ve giriş kayıtlarında kullanılır; kesin değildir.
 */
final class UserAgent
{
    public static function describe(string $ua): string
    {
        if (trim($ua) === '') {
            return 'Bilinmeyen cihaz';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            str_contains($ua, 'HeadlessChrome') => 'Chrome (otomatik)',
            default => 'Tarayıcı',
        };
        if (str_contains($ua, 'HeadlessChrome')) {
            $browser = 'Chrome (otomatik)';
        }
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return $os !== '' ? "{$browser} · {$os}" : $browser;
    }
}
