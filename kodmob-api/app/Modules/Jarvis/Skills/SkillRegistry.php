<?php

namespace App\Modules\Jarvis\Skills;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Security\Capability;
use App\Modules\Auth\Models\User;

/**
 * Kayıtlı Jarvis becerileri. Sıra kayıt sırasıdır ve sabittir: Claude'a giden
 * araç listesi her istekte aynı kalır (istem önbelleği bozulmasın).
 */
final class SkillRegistry
{
    /** @var array<string,Skill> */
    private static array $skills = [];

    /**
     * Becerinin gerektirdiği yetki (sohbette Claude'a yalnızca kullanıcının yetkili olduğu araçlar
     * verilir; doğrudan çağrıda da denetlenir). Yoksa Jarvis yetkisi.
     *
     * @var array<string,string>
     */
    private static array $caps = [];

    /** @var array<string,string> modül → sistem istemine eklenen kurallar (DomainsModule gibi) */
    private static array $guidance = [];

    /** @var list<\Closure(): list<Skill>> ilk kullanımda kaydedilen beceriler (açılışta veritabanına dokunmasın) */
    private static array $providers = [];

    /** Kullanıcı yoksa (konsol, zamanlanmış iş) serbest. Yetki `a|b` ise biri yeter. */
    public static function allowed(string $name, ?User $user): bool
    {
        if ($user === null) {
            return true;
        }
        foreach (explode('|', self::capability($name)) as $cap) {
            if ($user->can($cap)) {
                return true;
            }
        }

        return false;
    }

    /** Becerinin yetkisi: kayıttaki, yoksa becerinin kendi bildirdiği (RouteSkill: rotanın `cap:`'ı), yoksa Jarvis. */
    public static function capability(string $name): string
    {
        self::resolve();
        if (isset(self::$caps[$name])) {
            return self::$caps[$name];
        }
        $skill = self::$skills[$name] ?? null;

        return $skill instanceof RouteSkill ? $skill->capability() : Capability::USE_JARVIS;
    }

    /** Beceriler ilk kullanımda üretilsin (ör. ContractTools: dil ve tür listesi veritabanından). */
    public static function provide(\Closure $provider): void
    {
        self::$providers[] = $provider;
    }

    private static function resolve(): void
    {
        while ($provider = array_shift(self::$providers)) {
            self::register(...$provider());
        }
    }

    public static function authorize(string $name, ?User $user): void
    {
        if (! self::allowed($name, $user)) {
            throw new DomainException('km_forbidden', 'Bu Jarvis becerisini kullanma yetkiniz yok.', 403, ['capability' => self::capability($name)]);
        }
    }

    /** Beceriler ayrı bir yetkiyle: `SkillRegistry::requires(Capability::USE_DOMAINS, new DomainCheckSkill, …)`. */
    public static function requires(string $capability, Skill ...$skills): void
    {
        foreach ($skills as $skill) {
            self::$caps[$skill->name()] = $capability;
        }
        self::register(...$skills);
    }

    /** Modülün sohbet kuralları (araçları ne zaman, nasıl kullanmalı); sistem isteminde kayıt sırasıyla. */
    public static function guide(string $key, string $text): void
    {
        self::$guidance[$key] = trim($text);
    }

    public static function guidance(): string
    {
        return implode("\n\n", self::$guidance);
    }

    /** @return array<string,Skill> kullanıcının kullanabildikleri */
    private static function visible(?User $user): array
    {
        self::resolve();

        return array_filter(self::$skills, static fn (Skill $s) => self::allowed($s->name(), $user));
    }

    public static function register(Skill ...$skills): void
    {
        foreach ($skills as $skill) {
            self::$skills[$skill->name()] = $skill;
        }
    }

    public static function get(string $name): Skill
    {
        self::resolve();
        if (! isset(self::$skills[$name])) {
            throw NotFoundException::for('skill', $name, sprintf('Jarvis becerisi bulunamadı: %s', $name));
        }

        return self::$skills[$name];
    }

    public static function has(string $name): bool
    {
        self::resolve();

        return isset(self::$skills[$name]);
    }

    /** @return array<string,Skill> */
    public static function all(): array
    {
        self::resolve();

        return self::$skills;
    }

    /** Claude Messages API araç tanımları (SDK'nın camelCase anahtarlarıyla). */
    public static function tools(?User $user = null): array
    {
        return array_values(array_map(static fn (Skill $s) => [
            'name' => $s->name(),
            'description' => $s->description(),
            'inputSchema' => $s->inputSchema(),
        ], self::visible($user)));
    }

    /** Panel için özet. */
    public static function describe(?User $user = null): array
    {
        return array_values(array_map(static fn (Skill $s) => [
            'name' => $s->name(),
            'title' => $s->title(),
            'description' => $s->description(),
            'schema' => $s->inputSchema(),
        ], self::visible($user)));
    }
}
