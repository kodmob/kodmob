<?php

namespace App\Modules\Briefs\Service;

use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Settings\SettingsService;
use App\Modules\Briefs\BriefsModule;
use App\Modules\Briefs\Repository\BriefRepository;
use Illuminate\Support\Facades\DB;

/**
 * Kişisel veri saklama süresi (KVKK md. 7, aydınlatma metnindeki süre):
 * saklama süresini dolduran talepler her gün kalıcı olarak silinir.
 * Tekil silme (panelde "Kalıcı olarak sil") ilgili kişinin silme talebi içindir.
 *
 * Silme olayları kişisel veri taşımaz: yalnızca sayı ya da talep numarası.
 */
final class PurgeBriefs
{
    private const BATCH = 500;

    public function __construct(
        private readonly BriefRepository $briefs = new BriefRepository,
        private readonly EventBus $events = new EventBus
    ) {}

    /** Saklama süresi dolanları siler; silinen talep sayısını döndürür. */
    public function expired(): int
    {
        $months = SettingsService::instance()->int(BriefsModule::RETENTION_MONTHS);
        $before = now()->subMonths($months)->format('Y-m-d H:i:s');
        $total = 0;

        do {
            $ids = $this->briefs->idsCreatedBefore($before, self::BATCH);
            $deleted = $ids ? DB::transaction(fn () => $this->briefs->deleteWithEvents($ids)) : 0;
            $total += $deleted;
        } while (count($ids) === self::BATCH && $deleted > 0);

        if ($total > 0) {
            $this->events->publish(new Event('brief.purged', 'brief', 0, ['count' => $total, 'months' => $months]));
        }

        return $total;
    }

    /** İlgili kişinin talebiyle tek bir talebi siler. */
    public function one(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            if ($this->briefs->deleteWithEvents([$id]) === 0) {
                return false;
            }
            $this->events->publish(new Event('brief.deleted', 'brief', 0, ['id' => $id]));

            return true;
        });
    }
}
