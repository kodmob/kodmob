<?php

namespace App\Modules\Domains;

use App\Core\Module;
use App\Core\Security\Capability;
use App\Core\Settings\SettingDefinition;
use App\Modules\Domains\Jobs\JobRunner;
use App\Modules\Domains\Skills\DomainCheckSkill;
use App\Modules\Domains\Skills\DomainResultsSkill;
use App\Modules\Domains\Skills\DomainScanSkill;
use App\Modules\Domains\Skills\JobStatusSkill;
use App\Modules\Jarvis\Skills\SkillRegistry;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Domain arama (isteğe bağlı modül `domains`; kodmob.com'un aracı, Jarvis sohbetinden bağımsız):
 * anlık kontrol, uzun taramalar (2–3 karakter tam, 4–5 karakter kalıp, jenerik kelime, liste, domain
 * hilesi), sonuçlar. Boşluk DNS (DoH) ön elemesi + kayıt kuruluşunun RDAP servisiyle belirlenir; ikisi
 * de ücretsiz ve anahtarsız. Uzun işler Jobs/ (zamanlanmış görev + açık panel, kaldığı yerden).
 *
 * Adresler (`/jarvis/domains…`, `/jarvis/jobs…`) ve kayıtlı adlar (ayar `jarvis.rdap_concurrency`,
 * olay `jarvis.job_finished`) Jarvis'in altındayken nasılsa öyle kalır: panel ve eski kayıtlar bozulmaz.
 */
final class DomainsModule extends Module
{
    public const RDAP_CONCURRENCY = 'jarvis.rdap_concurrency';

    /** Genel bakış: süren domain taramaları (panelde özel kart; veriyi kart kendisi ister). */
    public function widgets(): array
    {
        return [
            ['key' => 'domains', 'label' => 'Domain taramaları', 'icon' => 'globe', 'type' => 'custom', 'size' => 'md', 'order' => 60, 'can' => Capability::USE_DOMAINS,
                'link' => ['route' => 'domains', 'label' => 'Domain arama']],
        ];
    }

    public function settings(): array
    {
        return [
            new SettingDefinition(self::RDAP_CONCURRENCY, 'int', 2, 'RDAP eşzamanlılığı', 'Domain arama',
                'Kayıt kuruluşuna aynı anda gönderilen sorgu sayısı. Yüksek değer hızlandırır ama hız sınırına takılma riski artar.', 1, 6),
        ];
    }

    public function events(): array
    {
        return [
            'jarvis.job_finished' => ['Domain taraması bitti', 'success', static fn ($p) => sprintf('%s · %s boş / %s', $p['title'] ?? '', $p['found'] ?? 0, $p['processed'] ?? 0)],
        ];
    }

    public function boot(): void
    {
        SkillRegistry::requires(Capability::USE_DOMAINS, new DomainCheckSkill, new DomainScanSkill, new JobStatusSkill, new DomainResultsSkill);
        SkillRegistry::guide('domains', <<<'TXT'
Domain arama kuralları:
- Bir adın boş olduğunu yalnızca araç sonucuna dayanarak söyle; asla tahmin etme. "available" = kayıt kuruluşunda kaydı yok (yine de kayıt anında premium/ayrılmış olabilir, bunu belirt). "expiring" = kayıtlı ama silinme sürecinde ya da süresi geçmiş; düşebilir, takip edilmeli. "unknown" = doğrulanamadı.
- Tek karakterli adlar ve kayıt kuruluşunun ayırdığı adlar (.si'de rs, si, 112, 113) alınamaz; önermeyin.
- Büyük taramadan önce kaç aday çıkacağını ve yaklaşık süreyi söyle; 1.000.000 adayı aşan isteklerde daraltmayı öner (ör. yalnızca harf, örneklem sınırı).
- Tarama başlatınca iş numarasını ve panel bağlantısını ver; sonuçlar Jarvis → Domain arama ekranında canlı görünür, bitince bildirim gelir.
- Ad önerirken kısa, okunuşu kolay, Türkçe ve İngilizce'de olumsuz anlamı olmayan adları seç; önerdiğin adları domain_check ile doğrulamadan "boş" diye sunma.
TXT);
        JobRunner::register(new DomainScanHandler);
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(static fn () => JobRunner::cron())->name('domains:tick')->everyMinute()->withoutOverlapping(5);
    }
}
