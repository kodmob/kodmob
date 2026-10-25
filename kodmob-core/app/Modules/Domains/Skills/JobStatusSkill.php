<?php

namespace App\Modules\Domains\Skills;

use App\Core\Http\ListQuery;
use App\Modules\Domains\DomainScanHandler;
use App\Modules\Domains\DomainService;
use App\Modules\Domains\Jobs\JobRunner;
use App\Modules\Jarvis\Skills\Skill;

/** İşlerin durumu: tek iş (ayrıntılı) ya da etkin/son işler listesi. */
final class JobStatusSkill implements Skill
{
    public function name(): string
    {
        return 'jarvis_job';
    }

    public function title(): string
    {
        return 'İş durumu';
    }

    public function description(): string
    {
        return 'Arka plan işlerinin durumunu verir. job_id verilirse o işin ilerlemesi, durum sayıları ve (domain taramasında) bulunan boş adların ilk 30\'u; '
            .'verilmezse son 10 iş. İş sürüyorsa kullanıcıya yüzdeyi söyle, bitmeden "bulunamadı" deme.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['job_id' => ['type' => 'integer']],
            'additionalProperties' => false,
        ];
    }

    public function run(array $input): array
    {
        $runner = new JobRunner;
        if (! empty($input['job_id'])) {
            $job = $runner->get((int) $input['job_id']);

            return $job->kind() === DomainScanHandler::KIND ? (new DomainService)->jobSummary($job) : $job->toArray();
        }
        $page = $runner->repository()->paginate(new ListQuery(1, 10));

        return ['jobs' => $page->items];
    }
}
