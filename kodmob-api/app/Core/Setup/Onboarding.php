<?php

namespace App\Core\Setup;

use App\Core\Modules;

/**
 * "Sıradaki adımlar" (panelde Genel bakış): siteyi yayına hazırlarken sırayla yapılacaklar. Adımları modüller verir
 * (`Module::steps()`: kimlik, iletişim ve yasal bilgiler, ana sayfa, ilk içerik, e-posta denemesi, otomatik yedek;
 * Mağaza açıksa ödeme yöntemi…); her adım gerçek veriden "yapıldı" sayılır (ayar dolu, sayfa yayında, deneme gitti),
 * işaretlemek gerekmez. Kişi yalnızca yetkisi olan adımları görür (`$can`: yetki → bool; çekirdek kullanıcı modelini bilmez).
 */
final class Onboarding
{
    /**
     * @return list<array{key:string,label:string,hint:string,done:bool,route:string,params:array<string,mixed>,tab:?string}>
     */
    public static function for(callable $can): array
    {
        $steps = [];
        foreach (Modules::classes() as $class) {
            foreach ((new $class)->steps() as $step) {
                if (! empty($step['can']) && ! $can($step['can'])) {
                    continue;
                }
                $steps[] = $step;
            }
        }
        usort($steps, static fn ($a, $b) => ($a['order'] ?? 50) <=> ($b['order'] ?? 50));

        return array_map(static fn ($s) => [
            'key' => $s['key'], 'label' => $s['label'], 'hint' => $s['hint'] ?? '', 'done' => (bool) $s['done'],
            'route' => $s['route'], 'params' => $s['params'] ?? [], 'tab' => $s['tab'] ?? null,
        ], $steps);
    }
}
