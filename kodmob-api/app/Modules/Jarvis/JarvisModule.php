<?php

namespace App\Modules\Jarvis;

use App\Core\Module;
use App\Core\Security\Capability;
use App\Modules\Jarvis\Assistant\ConversationSearch;
use App\Modules\Jarvis\Skills\ContractTools;
use App\Modules\Jarvis\Skills\SiteOverviewSkill;
use App\Modules\Jarvis\Skills\SkillRegistry;

/**
 * Jarvis: yönetim panelinin yapay zekâ asistanı (etkin sağlayıcıyla: Core\Ai). İki katman:
 *
 * - Beceriler (Skills/): tek işi yapan, şemalı birimler. Panelden doğrudan ya da modelin aracı
 *   olarak çağrılır. Modüller kendi becerilerini yetkisi ve sohbet kurallarıyla kaydeder:
 *   `SkillRegistry::requires(Capability::…, new MySkill)`, `SkillRegistry::guide('modül', '…')`.
 * - Asistan (Assistant/): sohbet; açık modüllerin becerilerini araç olarak kullanır.
 *
 * Domain arama ayrı modüldür (Modules\Domains): açıksa araçlarını buraya ekler.
 */
final class JarvisModule extends Module
{
    public function search(): array
    {
        return [new ConversationSearch];
    }

    public function boot(): void
    {
        SkillRegistry::requires(Capability::VIEW_OVERVIEW, new SiteOverviewSkill);
        // Sözleşmedeki panel uçlarından araçlar (okuma hemen, yazma onaylı: Operatör); ilk kullanımda üretilir.
        SkillRegistry::provide(static fn () => ContractTools::all());
        SkillRegistry::guide('operator', <<<'TXT'
            Operatör kuralları: Yazma araçları (redirect_create, post_translate) hemen çalışmaz; çağırdığında kullanıcıya
            panelde onay kartı çıkar, onaylarsa uygulanır ve İşlem Geçmişi'ne onun adıyla yazılır. Bir turda en çok bir
            yazma aracı çağır; çağırmadan önce ne yapacağını ve nedenini bir cümleyle söyle. Okuma araçlarıyla (seo_not_found,
            seo_broken_links, content_list…) önce durumu gör, sonra öner. Kişisel veri (talepler, siparişler, aboneler)
            araçlarda yoktur; isteme.
            TXT);
    }

    /** @return array<string,array{0:string,1?:string,2?:callable}> */
    public function events(): array
    {
        return [
            'jarvis.action_approved' => ['Jarvis önerisi onaylandı', 'primary', static fn ($p) => (string) ($p['summary'] ?? '')],
            'jarvis.action_rejected' => ['Jarvis önerisi reddedildi', 'neutral', static fn ($p) => (string) ($p['summary'] ?? '')],
        ];
    }
}
