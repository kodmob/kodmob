<?php

namespace App\Modules\Domains\Jobs;

use App\Core\Exceptions\ValidationException;

/**
 * Bir iş türünün işleyicisi. `step()` kısa parçalar hâlinde çalışır ve verilen
 * süre dolmadan döner; kaldığı yeri `$job->setState()` ile bırakır. İş bitince
 * `$job->complete()` çağırır. Paylaşımlı hostingde tek PHP isteği uzun süremez:
 * iş, cron ve açık panel tarafından tekrar tekrar ilerletilir (JobRunner).
 */
interface JobHandler
{
    public function kind(): string;

    /**
     * Girdiyi doğrular ve işi kurar (başlık, toplam, başlangıç durumu).
     *
     * @throws ValidationException
     */
    public function create(array $input): Job;

    /** `$deadline` (microtime) dolmadan döner. */
    public function step(Job $job, float $deadline): void;

    /** İş bitince bildirimde kullanılacak özet: [başlık, metin, ton]. */
    public function summary(Job $job): array;

    /**
     * Öğeleri yeniden kuyruğa alır (iş bitmiş olsa da) ve kaç öğe alındığını
     * döner; 0 ise yapılacak bir şey yoktur. `$scope`: `unknown` sonuçlanamayanlar,
     * `found` bulunanlar (sonuç eskidiyse yeniden doğrulamak için).
     */
    public function retry(Job $job, string $scope = 'unknown'): int;

    /** İş silinirken ürettiği kayıtları da siler. */
    public function purge(Job $job): void;
}
