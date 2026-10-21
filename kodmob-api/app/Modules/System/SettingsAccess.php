<?php

namespace App\Modules\System;

use App\Core\Security\Capability;
use App\Core\Settings\SettingDefinition;
use App\Modules\Auth\Models\User;
use App\Modules\I18n\I18nSettings;
use App\Modules\Security\SecurityModule;
use App\Modules\Seo\SeoModule;

/**
 * Hangi ayar grubunu kim değiştirir: Güvenlik ayarları `km_manage_security`,
 * Jarvis (Claude) ayarları `km_manage_ai`, SEO `km_manage_seo`, çoklu dil `km_manage_languages`,
 * diğerleri `km_manage_settings`.
 */
final class SettingsAccess
{
    private const GROUP_CAPS = [
        SecurityModule::GROUP => Capability::MANAGE_SECURITY,
        'Jarvis' => Capability::MANAGE_AI,
        SeoModule::GROUP => Capability::MANAGE_SEO,
        I18nSettings::GROUP => Capability::MANAGE_LANGUAGES,
    ];

    public static function capFor(SettingDefinition|string $group): string
    {
        $group = $group instanceof SettingDefinition ? $group->group : $group;

        return self::GROUP_CAPS[$group] ?? Capability::MANAGE_SETTINGS;
    }

    public static function allows(User $user, SettingDefinition $definition): bool
    {
        return $user->can(self::capFor($definition));
    }
}
