<?php

namespace App\Core\Settings;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * `settings (setting_key, scope, value)` okuma/yazma.
 *
 * Çözümleme sırası: kayıtlı değer → tanımdaki varsayılan. `scope` sütunu
 * şimdilik hep `global`. Tablo küçüktür; istek başına bir kez okunur.
 */
final class SettingsService
{
    public const GLOBAL_SCOPE = 'global';

    /** @var array<string,array<string,string>>|null scope => key => değer */
    private ?array $cache = null;

    public static function instance(): self
    {
        return app(self::class);
    }

    public function get(string $key): int|float|bool|string
    {
        $definition = $this->definition($key);
        $stored = $this->load()[self::GLOBAL_SCOPE][$key] ?? null;

        return $stored !== null ? $definition->decode($stored) : $definition->default;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    /**
     * @throws ValidationException
     */
    public function set(string $key, mixed $raw): int|float|bool|string
    {
        $definition = $this->definition($key);
        $value = $definition->cast($raw);

        DB::table('settings')->updateOrInsert(
            ['setting_key' => $key, 'scope' => self::GLOBAL_SCOPE],
            ['value' => $definition->encode($value), 'updated_by' => Auth::id(), 'updated_at' => Clock::now()]
        );
        $this->cache = null;

        return $value;
    }

    /** Kayıtlı değeri siler; ayar varsayılana döner. */
    public function reset(string $key): void
    {
        $this->definition($key);
        DB::table('settings')->where('setting_key', $key)->where('scope', self::GLOBAL_SCOPE)->delete();
        $this->cache = null;
    }

    /** Bütün ayar değerlerini siler; her ayar varsayılana döner. */
    public function resetAll(): int
    {
        $deleted = DB::table('settings')->delete();
        $this->cache = null;

        return $deleted;
    }

    /** Bellekteki değerleri bırakır (tablo dışarıdan değiştiyse). */
    public function flush(): void
    {
        $this->cache = null;
    }

    /** Bütün tanımlar ve geçerli değerleri. */
    public function describe(): array
    {
        $out = [];
        foreach (SettingsRegistry::all() as $key => $definition) {
            // Gizli değer panele gitmez: yalnızca girilip girilmediği.
            $out[] = $definition->toArray() + ($definition->format === 'secret'
                ? ['value' => '', 'set' => $this->get($key) !== '']
                : ['value' => $this->get($key)]);
        }

        return $out;
    }

    private function definition(string $key): SettingDefinition
    {
        return SettingsRegistry::get($key) ?? throw new \InvalidArgumentException('Tanımsız ayar: '.$key);
    }

    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $this->cache = [];
        foreach (DB::table('settings')->get(['setting_key', 'scope', 'value']) as $row) {
            $this->cache[$row->scope][$row->setting_key] = (string) $row->value;
        }

        return $this->cache;
    }
}
