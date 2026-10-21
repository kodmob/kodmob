<?php

namespace App\Core\Setup;

use App\Core\Exceptions\ValidationException;

/**
 * Çatı güncellemesi (`php artisan kodmob:upgrade --from=<paket>`). Üç özet karşılaştırılır: projenin
 * kilidi (kurulu sürümde dosyanın özeti), projedeki dosya, yeni sürümdeki dosya.
 *
 *   projede değişmemiş            → yeni sürümle değişir (yoksa eklenir)
 *   projede değişmiş, çatıda da   → dokunulmaz; yenisi yanına `<dosya>.kodmob-yeni` (elle birleştirilir)
 *   projede değişmiş, çatıda değil → dokunulmaz
 *   yeni sürümden kalkmış         → projede değişmemişse silinir, değişmişse bırakılır (uyarı)
 *   projenin dosyası (kodmob.json `project`) → hiç dokunulmaz
 *
 * Sonunda kilit ve kodmob.json yeni sürüme yazılır (projenin eklediği `project` desenleri kalır; çatının
 * bir dosyasını bilerek değiştiren proje onu oraya ekleyebilir). `plan()` yazmadan gösterir (--dry-run).
 */
final class Upgrader
{
    public const NEW_SUFFIX = '.kodmob-yeni';

    /** @param array{dir:string,lock:array{name:string,version:string,files:array<string,string>}} $package */
    public function __construct(private readonly FrameworkFiles $project, private readonly array $package) {}

    /**
     * @return list<array{path:string,action:string}> action: add | update | conflict | keep | delete | orphan | removed
     */
    public function plan(): array
    {
        $old = $this->project->lock();
        if ($old === null) {
            throw ValidationException::field('lock', 'Projede kodmob.lock.json yok: kurulu çatı sürümü bilinmiyor. Değiştirilmemiş bir kurulumda `php artisan kodmob:lock` ile oluşturun.');
        }
        $oldFiles = $old['files'];
        $newFiles = $this->package['lock']['files'];
        $newManifest = $this->packageManifest();
        $projectPatterns = array_merge($this->project->manifest['project'], $newManifest['project'] ?? []);

        $plan = [];
        foreach ($newFiles as $path => $newHash) {
            if (FrameworkFiles::matches($path, $projectPatterns)) {
                continue;
            }
            $local = $this->localHash($path);
            $before = $oldFiles[$path] ?? null;
            $action = match (true) {
                $local === $newHash => null,
                $local === null && $before === null => 'add',
                $local === null => $before === $newHash ? 'removed' : 'conflict',
                $local === $before => 'update',
                $before === $newHash => 'keep',
                default => 'conflict',
            };
            if ($action !== null) {
                $plan[] = ['path' => $path, 'action' => $action];
            }
        }
        foreach ($oldFiles as $path => $before) {
            if (isset($newFiles[$path]) || FrameworkFiles::matches($path, $projectPatterns)) {
                continue;
            }
            $local = $this->localHash($path);
            if ($local === null) {
                continue;
            }
            $plan[] = ['path' => $path, 'action' => $local === $before ? 'delete' : 'orphan'];
        }
        usort($plan, static fn ($a, $b) => strcmp($a['path'], $b['path']));

        return $plan;
    }

    /**
     * Planı uygular ve kilidi yeni sürüme yazar.
     *
     * @param  list<array{path:string,action:string}>  $plan
     */
    public function apply(array $plan): void
    {
        $root = $this->project->root;
        foreach ($plan as $step) {
            $target = $root.'/'.$step['path'];
            $source = $this->package['dir'].'/'.$step['path'];
            switch ($step['action']) {
                case 'add':
                case 'update':
                    @mkdir(dirname($target), 0755, true);
                    copy($source, $target);
                    break;
                case 'conflict':
                    @mkdir(dirname($target), 0755, true);
                    copy($source, $target.self::NEW_SUFFIX);
                    break;
                case 'delete':
                    @unlink($target);
                    break;
            }
        }
        // Çatının tanımı yeni sürümünkidir; projenin eklediği proje desenleri korunur.
        $manifest = $this->packageManifest();
        if ($manifest) {
            $manifest['project'] = array_values(array_unique(array_merge((array) ($manifest['project'] ?? []), $this->project->manifest['project'])));
            file_put_contents($root.'/'.FrameworkFiles::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }
        $this->project->writeLock($this->package['lock']['files'], $this->package['lock']['version']);
    }

    private function localHash(string $path): ?string
    {
        $file = $this->project->root.'/'.$path;

        return is_file($file) ? FrameworkFiles::hash($file) : null;
    }

    /** @return array<string,mixed> */
    private function packageManifest(): array
    {
        $file = $this->package['dir'].'/'.FrameworkFiles::MANIFEST;
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : [];
    }
}
