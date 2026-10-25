<?php

namespace App\Core\Activity;

use App\Core\Content\Types;
use App\Core\Security\Capability;

/**
 * İşlem Geçmişi grupları: olay türünün önekinden. Sıra önemli, ilk eşleşen
 * kazanır. Grup olay yazılırken `events.group_key` sütununa yazılır (sekmeler
 * ve süzme SQL'de hızlı).
 *
 * `DEPARTMENTS`: "Tüm İşlemler"de bütün işlemleri görme yetkisi olmayan kullanıcı,
 * yetkilerinin ilgili olduğu grupların işlemlerini ve kendi işlemlerini görür.
 */
final class EventGroups
{
    public const GROUPS = [
        // İçerik türlerinin olayları (project., service.… kayıt adıyla) ayrıca kayıttan: of().
        'content' => ['label' => 'İçerik', 'prefixes' => ['post.', 'content.', 'media.', 'records.']],
        'site' => ['label' => 'Site', 'prefixes' => ['texts.', 'estimate.', 'settings.', 'seo.', 'languages.']],
        'crm' => ['label' => 'Talepler', 'prefixes' => ['brief.', 'briefs.']],
        'jarvis' => ['label' => 'Jarvis', 'prefixes' => ['jarvis.']],
        'users' => ['label' => 'Kullanıcılar', 'prefixes' => ['users.', 'user.', 'roles.', 'role.']],
        'security' => ['label' => 'Güvenlik', 'prefixes' => ['auth.', 'security.', 'sessions.', 'profile.']],
        'system' => ['label' => 'Sistem', 'prefixes' => ['backup.', 'system.', 'setup.']],
    ];

    public const OTHER = 'other';

    public const OTHER_LABEL = 'Diğer';

    /** Yetki → görebildiği gruplar (içerik türlerinin yetkileri ayrıca kayıttan: forCaps()). */
    public const DEPARTMENTS = [
        Capability::MANAGE_POSTS => ['content'],
        Capability::MANAGE_MEDIA => ['content'],
        Capability::MANAGE_TEXTS => ['site'],
        Capability::MANAGE_SEO => ['site'],
        Capability::MANAGE_LANGUAGES => ['site'],
        Capability::MANAGE_ESTIMATE => ['site'],
        Capability::MANAGE_SETTINGS => ['site'],
        Capability::VIEW_BRIEFS => ['crm'],
        Capability::MANAGE_BRIEFS => ['crm'],
        Capability::USE_DOMAINS => ['jarvis'],
        Capability::MANAGE_AI => ['jarvis'],
        Capability::VIEW_USERS => ['users'],
        Capability::MANAGE_USERS => ['users'],
        Capability::MANAGE_SECURITY => ['security'],
        Capability::MANAGE_BACKUPS => ['system'],
    ];

    public static function of(string $type): string
    {
        // İçerik türünün olayı: kayıt adı önekte (project.created).
        if (str_contains($type, '.') && Types::byKind(strstr($type, '.', true)) !== null) {
            return 'content';
        }
        foreach (self::GROUPS as $key => $group) {
            foreach ($group['prefixes'] as $prefix) {
                if (str_starts_with($type, $prefix)) {
                    return $key;
                }
            }
        }

        return self::OTHER;
    }

    public static function label(string $key): string
    {
        return self::GROUPS[$key]['label'] ?? self::OTHER_LABEL;
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::GROUPS as $key => $group) {
            $out[] = ['value' => $key, 'label' => $group['label']];
        }
        $out[] = ['value' => self::OTHER, 'label' => self::OTHER_LABEL];

        return $out;
    }

    /** @param list<string> $caps @return list<string> */
    public static function forCaps(array $caps): array
    {
        $groups = [];
        $departments = self::DEPARTMENTS;
        foreach (Types::editable() as $type) {
            $departments[$type->capability()] = ['content'];
        }
        foreach ($departments as $cap => $list) {
            if (in_array($cap, $caps, true)) {
                array_push($groups, ...$list);
            }
        }

        return array_values(array_unique($groups));
    }
}
