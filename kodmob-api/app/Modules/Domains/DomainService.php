<?php

namespace App\Modules\Domains;

use App\Core\Exceptions\ValidationException;
use App\Modules\Domains\Check\CheckResult;
use App\Modules\Domains\Check\RdapBootstrap;
use App\Modules\Domains\Generators\GeneratorFactory;
use App\Modules\Domains\Generators\ShortGenerator;
use App\Modules\Domains\Generators\Wordlists;
use App\Modules\Domains\Jobs\Job;
use App\Modules\Domains\Jobs\JobRunner;

/**
 * Domain arama becerisinin dış yüzü: panel uçları ve Jarvis araçları bunu
 * kullanır. Anlık kontrol (en çok 100 ad, beklemeden yanıt), tarama işi
 * başlatma, önizleme, seçenekler ve sonuç özetleri.
 */
final class DomainService
{
    public const MAX_INSTANT = 100;

    /** Panelde hazır gelen uzantılar (sıra = önem). */
    public const POPULAR_TLDS = ['si', 'com', 'net', 'org', 'io', 'ai', 'co', 'app', 'dev', 'me', 'xyz', 'tr', 'com.tr', 'de', 'eu'];

    public function __construct(
        private readonly DomainChecker $checker = new DomainChecker,
        private readonly DomainRepository $results = new DomainRepository,
        private readonly JobRunner $runner = new JobRunner,
    ) {}

    /**
     * Anlık kontrol. Uzantısız ad her uzantıyla denenir.
     *
     * @param  list<string>  $names
     * @param  list<string>  $tlds
     * @return array{items:list<array>,counts:array<string,int>}
     */
    public function check(array $names, array $tlds = ['si']): array
    {
        $tlds = array_values(array_unique(array_map(static fn ($t) => DomainName::tld((string) $t), $tlds ?: ['si'])));
        $domains = [];
        foreach ($names as $name) {
            foreach (DomainName::parse((string) $name, $tlds) as $domain) {
                $domains[$domain->fqdn()] = $domain;
            }
        }
        if (! $domains) {
            throw ValidationException::field('names', 'Denenecek en az bir ad yazın.');
        }
        if (count($domains) > self::MAX_INSTANT) {
            throw ValidationException::field('names', sprintf('Anlık kontrolde en çok %d ad olabilir (%d çıktı). Daha fazlası için tarama başlatın.', self::MAX_INSTANT, count($domains)));
        }
        @set_time_limit(60);
        $results = $this->checker->check(array_values($domains));
        // Hız sınırına takılanlar bir kez daha denenir (kısa bekleme).
        $retry = array_values(array_filter($results, static fn (CheckResult $r) => $r->status === CheckResult::RETRY && ! $r->throttled));
        if ($retry) {
            usleep(800000);
            $again = [];
            foreach ($this->checker->check(array_map(static fn (CheckResult $r) => $r->domain, $retry), false) as $r) {
                $again[$r->domain->fqdn()] = $r;
            }
            $results = array_map(static fn (CheckResult $r) => $again[$r->domain->fqdn()] ?? $r, $results);
        }
        // Hâlâ sonuçlanmayan (hız sınırı, ulaşılamadı) ad "belirsiz" olarak gösterilir.
        $results = array_map(static fn (CheckResult $r) => $r->status === CheckResult::RETRY
            ? new CheckResult($r->domain, CheckResult::UNKNOWN, $r->source, note: 'Şu an doğrulanamadı: '.$r->note.'. Biraz sonra yeniden deneyin.')
            : $r, $results);
        $this->results->upsert(0, array_values(array_filter($results, static fn (CheckResult $r) => $r->status !== CheckResult::UNKNOWN || $r->source === 'dns')));

        $counts = [];
        foreach ($results as $r) {
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
        }

        return ['items' => array_map(static fn (CheckResult $r) => $r->toArray(), $results), 'counts' => $counts];
    }

    public function scan(array $spec): Job
    {
        $job = $this->runner->create(DomainScanHandler::KIND, $spec);
        // İlk parçayı hemen işle: kullanıcı sonuçları beklemeden görmeye başlasın.
        $this->runner->tick((int) $job->id(), 6.0);

        return $this->runner->get((int) $job->id());
    }

    /** @return array{title:string,total:int,sample:list<string>,estimate_minutes:float} */
    public function preview(array $spec): array
    {
        [$generator] = GeneratorFactory::make($spec);
        $total = $generator->total();

        return [
            'title' => $generator->describe(),
            'total' => $total,
            'sample' => $generator->preview(16),
            // Kaba tahmin: DNS ön elemesiyle saniyede ~15 ad (çoğu kısa ad zaten kayıtlı).
            'estimate_minutes' => round($total / 15 / 60, 1),
        ];
    }

    /** Sonuç özeti (Jarvis aracı ve bildirim için). */
    public function jobSummary(Job $job, int $limit = 30): array
    {
        $stats = $this->results->stats((int) $job->id());

        return $job->toArray() + [
            'counts' => $stats['status'],
            'by_length' => $stats['length'],
            'found_names' => array_map(static fn ($r) => array_intersect_key($r, array_flip(['domain', 'status', 'expires_at', 'note'])), $this->results->found((int) $job->id(), $limit)),
        ];
    }

    public function options(): array
    {
        $map = RdapBootstrap::map();
        $popular = array_map(static fn ($tld) => ['tld' => $tld, 'rdap' => isset($map[$tld])], self::POPULAR_TLDS);

        return [
            'tlds' => $popular,
            'rdap_tlds' => count($map),
            'charsets' => array_map(static fn ($k) => ['key' => $k, 'chars' => ShortGenerator::CHARSETS[$k]], array_keys(ShortGenerator::CHARSETS)),
            'wordsets' => Wordlists::all(),
            'prefixes' => Wordlists::PREFIXES,
            'suffixes' => Wordlists::SUFFIXES,
            'patterns' => [
                ['pattern' => 'CVCV', 'example' => 'bana, kodi, lume'],
                ['pattern' => 'CVCC', 'example' => 'bank, kord, lift'],
                ['pattern' => 'CVVC', 'example' => 'boat, kean, loom'],
                ['pattern' => 'VCVC', 'example' => 'amet, oken, ulas'],
                ['pattern' => 'CVCVC', 'example' => 'kodas, lumin, nexar'],
                ['pattern' => 'CVCCV', 'example' => 'banko, lirva, tempo'],
                ['pattern' => 'CCVCV', 'example' => 'brava, klima, trina'],
            ],
            'limits' => ['instant' => self::MAX_INSTANT, 'total' => GeneratorFactory::MAX_TOTAL, 'tlds' => GeneratorFactory::MAX_TLDS, 'list' => GeneratorFactory::MAX_LIST],
        ];
    }
}
