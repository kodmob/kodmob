<?php

namespace App\Modules\Domains;

use App\Core\Support\Text;
use App\Modules\Domains\Check\CheckResult;
use App\Modules\Domains\Generators\GeneratorFactory;
use App\Modules\Domains\Jobs\Job;
use App\Modules\Domains\Jobs\JobHandler;

/**
 * Domain taraması (`domain_scan`). Tarif → üretici; her adımda imleçten
 * BATCH aday alınır, kontrol edilir, sonuçlar yazılır.
 *
 * Durum (`state`):
 *   cursor         sıradaki aday numarası
 *   retry          [fqdn => deneme] RDAP'a (yeniden) sorulacaklar
 *   backoff_until  hız sınırında bu ana kadar RDAP'a gidilmez (unix)
 *   rate_hits      art arda hız sınırı sayısı (bekleme katlanır)
 *
 * Hız sınırı (kayıt kuruluşu 429 döndü; .si'de saatte ~1.000 sorgu civarı):
 * iş durmaz. Bekleme süresince yeni adaylar yalnızca DNS ile elenir (çoğu kısa
 * ad zaten kayıtlıdır ve DNS'te görünür); RDAP gerekenler kuyruğa (`retry`)
 * yazılır, bekleme bitince sorulur. Kuyruk MAX_QUEUE'yu aşarsa imleç ilerlemez.
 * Hız sınırı adın suçu olmadığından deneme hakkı harcamaz; başka geçici
 * hatalarda bir ad en çok MAX_ATTEMPTS kez denenir, sonra `unknown` yazılır.
 */
final class DomainScanHandler implements JobHandler
{
    public const KIND = 'domain_scan';

    private const BATCH = 60;

    private const RETRY_BATCH = 20;

    private const MAX_ATTEMPTS = 4;

    private const MAX_QUEUE = 3000;

    private const MAX_BACKOFF = 900;

    public function __construct(
        private readonly DomainChecker $checker = new DomainChecker,
        private readonly DomainRepository $results = new DomainRepository,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function create(array $input): Job
    {
        [$generator, $spec] = GeneratorFactory::make($input);
        $title = trim((string) ($input['title'] ?? '')) ?: $generator->describe();

        return Job::create(self::KIND, $title, $spec, $generator->total(), ['cursor' => 0, 'retry' => []]);
    }

    public function step(Job $job, float $deadline): void
    {
        [$generator] = GeneratorFactory::make($job->input());
        $state = $job->state() + ['cursor' => 0, 'retry' => [], 'rate_hits' => 0];
        $total = $generator->total();

        while (microtime(true) < $deadline - 2.0) {
            $rdapOpen = ($state['backoff_until'] ?? 0) <= time();
            if ($rdapOpen) {
                unset($state['backoff_until']);
            }

            // RDAP açıksa önce kuyruktakiler; sonra imleçten yeni adaylar.
            $batch = [];
            $tries = [];
            if ($rdapOpen) {
                foreach (array_slice($state['retry'], 0, self::RETRY_BATCH, true) as $fqdn => $count) {
                    $parsed = DomainName::parse((string) $fqdn, []);
                    if ($parsed) {
                        $batch[] = $parsed[0];
                        $tries[$fqdn] = (int) $count;
                    }
                    unset($state['retry'][$fqdn]);
                }
            }
            if ($rdapOpen || count($state['retry']) < self::MAX_QUEUE) {
                $fresh = $generator->slice((int) $state['cursor'], self::BATCH - count($batch));
                $state['cursor'] = (int) $state['cursor'] + count($fresh);
                foreach ($fresh as $domain) {
                    $batch[] = $domain;
                    $tries[$domain->fqdn()] = 0;
                }
            }

            if (! $batch) {
                $job->setState($state);
                if ((int) $state['cursor'] >= $total && ! $state['retry']) {
                    $job->complete();
                }

                return; // bitti ya da hız sınırı bekleniyor
            }

            $processed = $found = $failed = 0;
            $final = [];
            $attempts = [];
            $throttledFor = 0;
            $rdapAnswered = false;
            foreach ($this->checker->check($batch, true, $rdapOpen) as $result) {
                $fqdn = $result->domain->fqdn();
                if ($result->status === CheckResult::RETRY) {
                    if ($result->throttled || $result->source === 'queue') {
                        // Hız sınırı / sıra: deneme sayılmaz.
                        $state['retry'][$fqdn] = $tries[$fqdn];
                        $throttledFor = max($throttledFor, $result->throttled ? $result->retryAfter : 0);

                        continue;
                    }
                    if ($tries[$fqdn] + 1 < self::MAX_ATTEMPTS) {
                        $state['retry'][$fqdn] = $tries[$fqdn] + 1;
                        if ($result->retryAfter > 0) {
                            $state['backoff_until'] = max((int) ($state['backoff_until'] ?? 0), time() + $result->retryAfter);
                        }

                        continue;
                    }
                    $result = new CheckResult($result->domain, CheckResult::UNKNOWN, $result->source, note: 'Denemeler tükendi: '.$result->note);
                }
                $rdapAnswered = $rdapAnswered || $result->source === 'rdap';
                $processed++;
                if ($result->status === CheckResult::INVALID) {
                    continue;
                }
                $final[] = $result;
                $attempts[$fqdn] = $tries[$fqdn] + 1;
                if ($result->isFound()) {
                    $found++;
                } elseif ($result->status === CheckResult::UNKNOWN) {
                    $failed++;
                }
            }

            if ($throttledFor > 0) {
                $state['rate_hits'] = (int) $state['rate_hits'] + 1;
                $wait = (int) min(self::MAX_BACKOFF, $throttledFor * (2 ** min(5, $state['rate_hits'] - 1)));
                $state['backoff_until'] = time() + $wait;
            } elseif ($rdapAnswered) {
                $state['rate_hits'] = 0;
            }

            $this->results->upsert((int) $job->id(), $final, $attempts);
            $job->count($processed, $found, $failed);
            $job->setState($state);
        }
    }

    public function summary(Job $job): array
    {
        $stats = sprintf('%s ad tarandı · %s boş', Text::number($job->processed()), Text::number($job->found()));

        return match ($job->status()) {
            Job::DONE => ['Domain taraması bitti: '.Text::number($job->found()).' boş ad', $job->title().' · '.$stats, $job->found() ? 'success' : 'neutral'],
            Job::CANCELLED => ['Domain taraması durduruldu', $job->title().' · '.$stats, 'warning'],
            default => ['Domain taraması yarıda kaldı', $job->title().' · '.$stats, 'danger'],
        };
    }

    /**
     * Adları yeniden kuyruğa alır: `unknown` belirsiz kalanlar (hız sınırı,
     * ulaşılamadı); `found` boş ve düşen adlar — hareketli uzantılarda (.si)
     * "boş" sonucu günler içinde eskir, yeniden doğrulamak gerekir.
     */
    public function retry(Job $job, string $scope = 'unknown'): int
    {
        $found = $scope === 'found';
        $domains = $this->results->byStatus((int) $job->id(), $found ? ['available', 'expiring'] : ['unknown'], self::MAX_QUEUE);
        if (! $domains) {
            return 0;
        }
        $state = $job->state() + ['cursor' => $job->total(), 'retry' => [], 'rate_hits' => 0];
        foreach ($domains as $fqdn) {
            $state['retry'][$fqdn] = 0;
        }
        unset($state['backoff_until'], $state['db_errors']);
        $this->results->deleteDomains((int) $job->id(), $domains);
        $job->reopen(count($domains), $state, $found);

        return count($domains);
    }

    public function purge(Job $job): void
    {
        $this->results->deleteJob((int) $job->id());
    }
}
