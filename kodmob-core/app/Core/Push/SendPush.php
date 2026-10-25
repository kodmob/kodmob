<?php

namespace App\Core\Push;

use App\Core\Jobs\Background;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bir sahibin (panel kullanıcısı ya da üye) bütün cihazlarına anlık bildirim, arka planda (Background). Tek deneme:
 * bildirim zamana bağlıdır, geç gelen bildirim yerine zildeki kayıt yeter. Biten abonelik kendiliğinden silinir.
 *
 *   SendPush::to('user', 12, ['title' => 'Yeni talep', 'body' => 'Ayşe Yılmaz', 'url' => '/panel/talepler/5'])
 */
final class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param array{title:string,body?:string,url?:string,tag?:string} $payload */
    public function __construct(public string $ownerType, public int $ownerId, public array $payload) {}

    /** Sahibin aboneliği varsa kuyruğa alır (yoksa hiçbir şey yapmaz; çağırmak ucuzdur). */
    public static function to(string $ownerType, int $ownerId, array $payload): void
    {
        if (PushSubscriptions::has($ownerType, $ownerId)) {
            Background::dispatch(new self($ownerType, $ownerId, $payload));
        }
    }

    public static function label(): string
    {
        return 'Anlık bildirim';
    }

    /** @return array{sent:int,failed:int} */
    public function handle(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $keys = VapidKeys::get();
        if ($keys === null) {
            return $result;
        }
        foreach (PushSubscriptions::forOwner($this->ownerType, $this->ownerId) as $subscription) {
            try {
                $status = WebPush::send($subscription, $this->payload, $keys);
            } catch (Throwable $e) {
                Log::warning('[Kodmob push] '.$e->getMessage());
                $status = 0;
            }
            PushSubscriptions::result($subscription['id'], $status);
            $result[$status >= 200 && $status < 300 ? 'sent' : 'failed']++;
        }

        return $result;
    }
}
