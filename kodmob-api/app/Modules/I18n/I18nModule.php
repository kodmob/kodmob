<?php

namespace App\Modules\I18n;

use App\Core\Content\Locales;
use App\Core\Events\EventRecorded;
use App\Core\Health\Health;
use App\Core\Module;
use Illuminate\Support\Facades\Event;

/**
 * Çoklu dil (done-coklu-dil-raporu.md): dil kaydı (`languages`, Core\Content\Languages),
 * yönetimi (`km_manage_languages`; /v1/languages, panelde Çoklu Dil sayfası), ayarları
 * (I18nSettings: ana anahtar, öneri bandı), içerik çevirisi (ContentTranslator) ve render.php'nin
 * okuduğu derlenmiş kopya (I18nConfig).
 */
final class I18nModule extends Module
{
    public function settings(): array
    {
        return I18nSettings::definitions();
    }

    /** Sistem Sağlığı: Çoklu Dil > Sağlık'ın son denetimi (bağlantılar yeniden taranmaz; `kodmob:links` tarar). */
    public function health(): array
    {
        $items = (new LanguageReport)->health();
        $levels = ['error' => 'warn', 'warning' => 'warn', 'info' => 'info'];
        $flags = array_map(static fn ($i) => Health::flag($levels[$i['level']] ?? 'info', $i['title'].($i['count'] > 1 ? " ({$i['count']})" : '').($i['detail'] !== '' ? ': '.$i['detail'] : '')), array_slice($items, 0, 8));
        if (count($items) > 8) {
            $flags[] = Health::flag('info', (count($items) - 8).' sorun daha: Çoklu Dil › Sağlık.');
        }
        $labels = Locales::labels();
        $by = array_count_values(array_column($items, 'level'));

        return [Health::section('languages', 'Çoklu dil', 'globe', [
            ['Yayındaki diller', implode(', ', array_map(static fn ($c) => $labels[$c] ?? $c, Locales::active()))],
            ['Varsayılan dil', $labels[Locales::default()] ?? Locales::default()],
            ['Sorun', ($by['error'] ?? 0).' hata · '.($by['warning'] ?? 0).' uyarı · '.($by['info'] ?? 0).' bilgi'],
        ], $flags, ['link' => ['route' => 'languages', 'tab' => 'health', 'label' => 'Çoklu Dil'], 'order' => 75])];
    }

    public function boot(): void
    {
        Event::listen(EventRecorded::class, static function (EventRecorded $recorded) {
            $type = $recorded->event->type;
            if (str_starts_with($type, 'languages.') || str_starts_with($type, 'settings.') || $type === 'backup.restored') {
                I18nConfig::compile();
            }
        });
    }
}
