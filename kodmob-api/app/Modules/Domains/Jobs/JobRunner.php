<?php

namespace App\Modules\Domains\Jobs;

use App\Core\Events\Event;
use App\Core\Events\EventBus;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\Capability;
use App\Core\Support\PanelUrl;
use App\Modules\Live\PulseFile;
use App\Modules\Notifications\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * İş kuyruğu: işleyici kaydı, iş oluşturma ve ilerletme.
 *
 * İlerletme (`tick`) üç yerden gelir: zamanlanmış görev (dakikada bir), panel
 * (iş çalışırken birkaç saniyede bir) ve iş oluşturulduğu anki ilk adım. Aynı
 * anda yalnızca bir tick çalışır (önbellek kilidi); diğerleri beklemeden döner.
 *
 * Her tick sonunda nabız (`jarvis_job`) dokunulur: açık paneller ilerlemeyi
 * görür. Biten iş için olay yazılır ve yöneticilere bildirim bırakılır.
 */
final class JobRunner
{
    private const LOCK = 'km_jarvis_runner';

    private const MAX_DB_ERRORS = 5;

    /** @var array<string,JobHandler> */
    private static array $handlers = [];

    public function __construct(private readonly JobRepository $jobs = new JobRepository) {}

    public static function register(JobHandler ...$handlers): void
    {
        foreach ($handlers as $handler) {
            self::$handlers[$handler->kind()] = $handler;
        }
    }

    public static function handler(string $kind): JobHandler
    {
        if (! isset(self::$handlers[$kind])) {
            throw ValidationException::field('kind', sprintf('Bilinmeyen iş türü: %s', $kind));
        }

        return self::$handlers[$kind];
    }

    public function create(string $kind, array $input): Job
    {
        $job = $this->jobs->save(self::handler($kind)->create($input));
        PulseFile::touch('jarvis_job');

        return $job;
    }

    /**
     * İşleri `$budget` saniye boyunca ilerletir. `$jobId` verilirse önce o iş.
     * Kilit alınamazsa (başka tick çalışıyor) hiçbir şey yapmaz.
     *
     * @return list<Job> bu tickte dokunulan işler
     */
    public function tick(?int $jobId = null, float $budget = 20.0): array
    {
        $lock = Cache::lock(self::LOCK, (int) ceil($budget) + 60);
        if (! $lock->get()) {
            return [];
        }
        $touched = [];
        try {
            $deadline = microtime(true) + max(1.0, $budget);
            while (microtime(true) < $deadline - 0.5) {
                $job = $jobId ? $this->jobs->find($jobId) : null;
                if (! $job || ! $job->isRunnable()) {
                    $job = $this->jobs->nextRunnable();
                }
                if (! $job) {
                    break;
                }
                $jobId = null;
                $this->advance($job, $deadline);
                $touched[$job->id()] = $job;
                // Geri çekilmede (hız sınırı) bu tickte başka bir şey yapma.
                if (($job->state()['backoff_until'] ?? 0) > time()) {
                    break;
                }
            }
        } finally {
            $lock->release();
        }
        if ($touched) {
            PulseFile::touch('jarvis_job');
        }

        return array_values($touched);
    }

    private function advance(Job $job, float $deadline): void
    {
        $handler = self::handler($job->kind());
        $job->begin();
        try {
            $handler->step($job, $deadline);
            $state = $job->state();
            if (isset($state['db_errors'])) {
                unset($state['db_errors']);
                $job->setState($state);
            }
        } catch (QueryException|\PDOException $e) {
            // Geçici olabilir (MySQL yeniden başladı, bağlantı koptu): iş sürer, sonraki
            // tick son kaydedilen yerden yeniden dener. Art arda MAX_DB_ERRORS'ta durur.
            Log::warning('[Kodmob Jarvis] iş #'.$job->id().' veritabanı: '.$e->getMessage());
            $state = $job->state();
            $state['db_errors'] = (int) ($state['db_errors'] ?? 0) + 1;
            $state['backoff_until'] = time() + 30;
            if ($state['db_errors'] >= self::MAX_DB_ERRORS) {
                $job->fail('Veritabanına art arda ulaşılamadı; iş durduruldu. "Sürdür" ile kaldığı yerden devam eder.');
            }
            $job->setState($state);
            try {
                $this->jobs->save($job);
            } catch (\Throwable) {
                // Veritabanı hâlâ yok: durum kaydedilemedi, sonraki tick yeniden dener.
                return;
            }
            if ($job->isFinished()) {
                $this->finished($job, $handler);
            }

            return;
        } catch (\Throwable $e) {
            Log::error('[Kodmob Jarvis] iş #'.$job->id().': '.$e->getMessage(), ['exception' => $e]);
            $job->fail($e instanceof DomainException ? $e->getMessage() : 'Beklenmeyen bir hata oluştu; ayrıntı sunucu günlüğünde.');
        }
        $this->jobs->save($job);
        if ($job->isFinished()) {
            $this->finished($job, $handler);
        }
    }

    private function finished(Job $job, JobHandler $handler): void
    {
        [$title, $message, $tone] = $handler->summary($job);
        (new EventBus)->publish(new Event('jarvis.job_finished', 'jarvis_job', (int) $job->id(), [
            'kind' => $job->kind(),
            'title' => $job->title(),
            'status' => $job->status(),
            'found' => $job->found(),
            'processed' => $job->processed(),
        ]));
        NotificationService::notifyCapable(Capability::USE_DOMAINS, 'jarvis.job_finished', $title, $message, PanelUrl::to('domains', ['job' => $job->id()]), $tone, ['job_id' => $job->id()]);
    }

    public function get(int $id): Job
    {
        $job = $this->jobs->find($id);
        if (! $job) {
            throw NotFoundException::for('jarvis_job', $id, 'İş bulunamadı.');
        }

        return $job;
    }

    public function control(int $id, string $action): Job
    {
        $job = $this->get($id);
        match ($action) {
            'pause' => $job->pause(),
            'resume' => $job->resume(),
            'cancel' => $job->cancel(),
            'retry' => self::handler($job->kind())->retry($job) ?: throw new ConflictException('km_job_nothing_to_retry', 'Yeniden denenecek belirsiz sonuç yok.'),
            'verify' => $job->isFinished()
                ? (self::handler($job->kind())->retry($job, 'found') ?: throw new ConflictException('km_job_nothing_to_verify', 'Yeniden doğrulanacak sonuç yok.'))
                : throw new ConflictException('km_job_not_finished', 'Yeniden doğrulama için işin bitmiş olması gerekir.'),
            default => throw ValidationException::field('action', 'Geçersiz işlem.'),
        };
        $this->jobs->save($job);
        PulseFile::touch('jarvis_job');

        return $job;
    }

    public function delete(int $id): void
    {
        $job = $this->get($id);
        self::handler($job->kind())->purge($job);
        $this->jobs->delete($id);
        PulseFile::touch('jarvis_job');
    }

    public function repository(): JobRepository
    {
        return $this->jobs;
    }

    /** Zamanlanmış görev: her dakika. Bekleyen iş yoksa hemen döner. */
    public static function cron(): void
    {
        $runner = new self;
        if (! $runner->jobs->hasRunnable()) {
            return;
        }
        @set_time_limit(90);
        $runner->tick(null, 45.0);
    }
}
