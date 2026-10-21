<?php

namespace App\Core\Extensions;

use App\Core\Exceptions\ValidationException;

/**
 * Araç paketinin tanımı (`kodmob-tool.json`; docs/arac-paketi.md). Paket tek klasördür:
 *
 *   kodmob-tool.json   key, label, description, version, kodmob (uyumlu çatı), author, license, namespace, module
 *   php/               ad alanının kökü (PSR-4): Module sınıfı, controller'lar; php/routes.php (isteğe bağlı)
 *   database/migrations/   (isteğe bağlı) tablolar
 *   panel/index.js     (isteğe bağlı) panel sayfaları (`PAGES`, biçim tv/src/panel/extension.js)
 *   tests/             PHP testleri · README.md · LICENSE
 *
 * Anahtar klasörün adıdır; çatının modül anahtarlarıyla çakışamaz. Uyum: `"kodmob": ">=3.0 <4"` (boşlukla
 * ayrılmış koşullar: >=, >, <=, <, =, ^). İsteğe bağlı `"requires": ["commerce"]`: paket o modüle takılır,
 * o açık değilken kapalıdır.
 */
final class ToolManifest
{
    public const FILE = 'kodmob-tool.json';

    public const KEY = '/^[a-z][a-z0-9-]{1,30}$/';

    private const NAMESPACE_PATTERN = '/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)+$/';

    /** Paketin kullanamayacağı ad alanları (çatı, Laravel, testler). */
    private const RESERVED = ['App', 'Tests', 'Database', 'Illuminate', 'Laravel', 'Symfony'];

    /**
     * @param  array{key:string,label:string,description:string,version:string,kodmob:string,author:string,license:string,namespace:string,module:string,homepage:string,icon:string}  $data
     * @param  list<string>  $requires  takıldığı modüller (`"requires": ["commerce"]`; çatının ya da başka paketin anahtarı)
     */
    public function __construct(public readonly string $dir, public readonly array $data, public readonly array $requires = []) {}

    /**
     * Klasördeki tanımı okur ve denetler; hata: alan → Türkçe ileti. `$folder`: klasörün adı anahtarla aynı
     * olmalı (kurulu paket; kurulmadan önce açılan zip'te değil).
     */
    public static function read(string $dir, bool $folder = true): self
    {
        $dir = rtrim($dir, '/\\');
        $file = $dir.'/'.self::FILE;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($data)) {
            throw ValidationException::field('manifest', 'Araç tanımı yok ya da bozuk: '.self::FILE);
        }
        $requires = $data['requires'] ?? [];
        $data = array_map(static fn ($v) => is_scalar($v) ? trim((string) $v) : '', array_intersect_key($data, array_flip(['key', 'label', 'description', 'version', 'kodmob', 'author', 'license', 'namespace', 'module', 'homepage', 'icon'])))
            + ['key' => '', 'label' => '', 'description' => '', 'version' => '', 'kodmob' => '', 'author' => '', 'license' => '', 'namespace' => '', 'module' => '', 'homepage' => '', 'icon' => ''];
        $errors = [];
        if (! is_array($requires) || ! array_is_list($requires) || array_filter($requires, static fn ($r) => ! is_string($r) || ! preg_match(self::KEY, $r) || $r === $data['key'])) {
            $errors['requires'] = 'requires, takıldığı modüllerin anahtar listesi olmalı, ör. ["commerce"].';
            $requires = [];
        }
        if (! preg_match(self::KEY, $data['key'])) {
            $errors['key'] = 'Anahtar küçük harf, rakam ve tire olmalı (2–31 karakter), ör. "stok-takibi".';
        }
        foreach (['label' => 'Ad', 'description' => 'Açıklama', 'author' => 'Yazar', 'license' => 'Lisans'] as $field => $name) {
            if ($data[$field] === '') {
                $errors[$field] = "{$name} boş olamaz.";
            }
        }
        if (! preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/', $data['version'])) {
            $errors['version'] = 'Sürüm x.y.z biçiminde olmalı (ör. 0.1.0).';
        }
        if (self::constraint($data['kodmob']) === null) {
            $errors['kodmob'] = 'Uyumlu çatı sürümü yazılmalı, ör. ">=3.0 <4".';
        }
        if (! preg_match(self::NAMESPACE_PATTERN, $data['namespace']) || in_array(explode('\\', $data['namespace'])[0], self::RESERVED, true)) {
            $errors['namespace'] = 'Ad alanı kendi adınızla başlamalı, ör. "KodmobTools\\StokTakibi" (App, Tests, Illuminate… olamaz).';
        }
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $data['module'])) {
            $errors['module'] = 'Modül sınıfının adı yazılmalı, ör. "StokTakibiModule".';
        } elseif (! is_file($dir.'/php/'.$data['module'].'.php')) {
            $errors['module'] = "Modül sınıfı yok: php/{$data['module']}.php";
        }
        if ($folder && $data['key'] !== '' && basename($dir) !== $data['key']) {
            $errors['key'] = "Klasörün adı anahtarla aynı olmalı: {$data['key']}";
        }
        if ($errors) {
            throw ValidationException::fields($errors, 'Araç tanımı geçersiz ('.self::FILE.'): '.implode(' ', $errors));
        }

        return new self($dir, $data, array_values(array_unique($requires)));
    }

    public function key(): string
    {
        return $this->data['key'];
    }

    /** Modül sınıfının tam adı. */
    public function moduleClass(): string
    {
        return $this->data['namespace'].'\\'.$this->data['module'];
    }

    public function compatible(string $version): bool
    {
        return self::satisfies($this->data['kodmob'], $version);
    }

    /** `php/routes.php` (varsa). */
    public function routes(): ?string
    {
        return is_file($file = $this->dir.'/php/routes.php') ? $file : null;
    }

    public function migrations(): ?string
    {
        return is_dir($path = $this->dir.'/database/migrations') ? $path : null;
    }

    /** Sürüm koşulu sağlanıyor mu ("<sürüm>" koşulun bütün parçalarına uyar). */
    public static function satisfies(string $constraint, string $version): bool
    {
        $parts = self::constraint($constraint);
        if ($parts === null) {
            return false;
        }
        $version = self::normalize(preg_replace('/[-+].*$/', '', $version) ?? $version);
        foreach ($parts as [$op, $target]) {
            if (! version_compare($version, $target, $op)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Koşulun parçaları: [[işleç, x.y.z], …]; geçersizse null. `^2.5` → >=2.5.0 <3.0.0.
     *
     * @return list<array{0:string,1:string}>|null
     */
    private static function constraint(string $constraint): ?array
    {
        $tokens = preg_split('/[\s,]+/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $tokens) {
            return null;
        }
        $out = [];
        foreach ($tokens as $token) {
            if (! preg_match('/^(>=|<=|>|<|==|=|\^)?v?(\d+(?:\.\d+){0,2})$/', $token, $m)) {
                return null;
            }
            $version = self::normalize($m[2]);
            if ($m[1] === '^') {
                $out[] = ['>=', $version];
                $out[] = ['<', ((int) explode('.', $version)[0] + 1).'.0.0'];

                continue;
            }
            $out[] = [in_array($m[1], ['', '='], true) ? '==' : $m[1], $version];
        }

        return $out;
    }

    private static function normalize(string $version): string
    {
        return implode('.', array_slice([...explode('.', $version), '0', '0'], 0, 3));
    }
}
