<?php

namespace App\Modules\Newsletter;

use App\Core\Settings\SettingsService;
use App\Core\Support\Clock;
use App\Core\Support\Options;

/**
 * Bülten gönderiminin saatlik sınırı (paylaşımlı sunucuların e-posta sınırı aşılmasın): son 60 dakikada gönderilen
 * e-postalar dakika başına sayılır (`options` › newsletter_quota). Onay e-postaları sayılmaz (tek tük gider).
 */
final class SendQuota
{
    private const OPTION = 'newsletter_quota';

    public static function limit(): int
    {
        return max(1, SettingsService::instance()->int(NewsletterModule::HOURLY_LIMIT));
    }

    /** Şu an gönderilebilecek e-posta sayısı. */
    public static function available(): int
    {
        return max(0, self::limit() - array_sum(self::window()));
    }

    public static function used(int $count = 1): void
    {
        $window = self::window();
        $minute = Clock::carbon(Clock::now())->format('YmdHi');
        $window[$minute] = ($window[$minute] ?? 0) + $count;
        Options::set(self::OPTION, $window);
    }

    /** @return array<string,int> son 60 dakika: dakika → sayı */
    private static function window(): array
    {
        Options::flush();
        $since = Clock::carbon(Clock::now())->subMinutes(59)->format('YmdHi');

        return array_filter(array_map('intval', (array) Options::get(self::OPTION, [])), static fn ($n, $minute) => (string) $minute >= $since, ARRAY_FILTER_USE_BOTH);
    }
}
