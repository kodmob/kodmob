<?php

namespace App\Modules\Domains\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use App\Core\Http\ListQuery;
use App\Modules\Domains\DomainRepository;
use App\Modules\Domains\DomainScanHandler;
use App\Modules\Domains\DomainService;
use App\Modules\Domains\Jobs\JobRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Domain arama uçları (km_use_domains). Adresler Jarvis altında kalır (panel ve eski bağlantılar);
 * modül Jarvis sohbetinden bağımsız açılıp kapanır.
 *
 *   GET    /jarvis/jobs                   işler (sayfalı; ?status=active|done…)
 *   GET    /jarvis/jobs/{id}              iş (+ domain taramasında özet)
 *   POST   /jarvis/jobs/{id}/{action}     pause | resume (hatayla duran da) | cancel | retry (belirsizleri yeniden dene) | verify (boşları yeniden doğrula)
 *   DELETE /jarvis/jobs/{id}              iş ve sonuçları
 *   POST   /jarvis/tick                   { job_id? } işleri birkaç saniye ilerlet (panel açıkken)
 *
 *   GET    /jarvis/domains/options        uzantılar, kelime listeleri, kalıp örnekleri, sınırlar
 *   POST   /jarvis/domains/preview        tarif → { title, total, sample, estimate_minutes }
 *   POST   /jarvis/domains/scan           tarif → iş (201)
 *   POST   /jarvis/domains/check          { names, tlds } anlık kontrol (en çok 100)
 *   GET    /jarvis/domains                sonuçlar (kmGrid: job_id, status, length, tld, q, sort)
 *   GET    /jarvis/domains/export         aynı filtrelerle CSV
 */
final class DomainsController extends Controller
{
    public function jobs(Request $request): mixed
    {
        return (new JobRunner)->repository()->paginate(ListQuery::fromRequest($request, 20));
    }

    public function job(int $id): array
    {
        $job = (new JobRunner)->get($id);

        return $job->kind() === DomainScanHandler::KIND ? (new DomainService)->jobSummary($job, 10) : $job->toArray();
    }

    public function control(int $id, string $action): array
    {
        $job = (new JobRunner)->control($id, $action);
        Activity::subject('jarvis_job', $id, (string) ($job->toArray()['title'] ?? ''));
        Activity::with(['action' => $action]);

        return $job->toArray();
    }

    public function deleteJob(int $id): Response
    {
        (new JobRunner)->delete($id);

        return $this->noContent();
    }

    /** Panel açıkken iş ilerletme: kısa süre (istek zaman aşımına düşmesin). */
    public function tick(Request $request): array
    {
        $body = $this->input($request);
        $runner = new JobRunner;
        @set_time_limit(40);
        $runner->tick(! empty($body['job_id']) ? (int) $body['job_id'] : null, min(15.0, max(3.0, (float) ($body['budget'] ?? 8))));

        return ['jobs' => array_map(static fn ($j) => $j->toArray(), $runner->repository()->active())];
    }

    public function options(): array
    {
        return (new DomainService)->options();
    }

    public function preview(Request $request): array
    {
        return (new DomainService)->preview($this->input($request));
    }

    public function scan(Request $request): JsonResponse
    {
        $job = (new DomainService)->scan($this->input($request))->toArray();
        Activity::subject('jarvis_job', (int) $job['id'], (string) ($job['title'] ?? ''));
        Activity::with(['title' => (string) ($job['title'] ?? ''), 'fields' => []]);

        return $this->created($job);
    }

    public function check(Request $request): array
    {
        $body = $this->input($request);
        $names = $body['names'] ?? [];
        if (is_string($names)) {
            $names = preg_split('/[\r\n,;\s]+/', $names) ?: [];
        }

        return (new DomainService)->check(array_values(array_filter(array_map('strval', (array) $names), 'strlen')), (array) ($body['tlds'] ?? ['si']));
    }

    public function domains(Request $request): mixed
    {
        return (new DomainRepository)->paginate(ListQuery::fromRequest($request, 50));
    }

    public function export(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, 500);
        $labels = ['available' => 'Boş', 'expiring' => 'Düşüyor', 'taken' => 'Kayıtlı', 'unknown' => 'Belirsiz'];
        $rows = (static function () use ($query, $labels) {
            foreach ((new DomainRepository)->export($query, 200000) as $row) {
                $row['status'] = $labels[$row['status']] ?? $row['status'];
                yield $row;
            }
        })();

        return $this->csv('domainler-'.date('Y-m-d').'.csv', [
            'domain' => 'Alan adı',
            'status' => 'Durum',
            'length' => 'Uzunluk',
            'tld' => 'Uzantı',
            'registered_at' => 'Kayıt tarihi',
            'expires_at' => 'Bitiş tarihi',
            'registrar' => 'Kayıt firması',
            'rdap_status' => 'RDAP durumu',
            'note' => 'Not',
            'checked_at' => 'Kontrol',
        ], $rows);
    }
}
