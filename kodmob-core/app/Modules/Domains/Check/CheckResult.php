<?php

namespace App\Modules\Domains\Check;

use App\Modules\Domains\DomainName;

/**
 * Bir alan adının kontrol sonucu.
 *
 * Durumlar:
 * - `available` kayıtlı değil (RDAP 404)
 * - `taken`     kayıtlı (DNS'te yetkilendirilmiş ya da RDAP 200)
 * - `expiring`  kayıtlı ama silinme sürecinde (redemption / pending delete) ya da süresi geçmiş
 * - `unknown`   kesinleşmedi (uzantının RDAP'ı yok ya da denemeler tükendi)
 * - `invalid`   ad kurala uymuyor
 * - `retry`     geçici hata / hız sınırı; sonra yeniden denenecek (kalıcı yazılmaz)
 *
 * Kaynak: `rule` · `cache` · `dns` · `rdap` · `queue` (RDAP'a henüz sorulmadı, sırada).
 */
final class CheckResult
{
    public const AVAILABLE = 'available';

    public const TAKEN = 'taken';

    public const EXPIRING = 'expiring';

    public const UNKNOWN = 'unknown';

    public const INVALID = 'invalid';

    public const RETRY = 'retry';

    public const FINAL = [self::AVAILABLE, self::TAKEN, self::EXPIRING, self::UNKNOWN];

    public function __construct(
        public readonly DomainName $domain,
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $registeredAt = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $registrar = null,
        public readonly ?string $rdapStatus = null,
        public readonly ?string $note = null,
        public readonly int $retryAfter = 0,
        /** Hız sınırı (429): adın suçu değil; deneme hakkı harcanmaz. */
        public readonly bool $throttled = false,
    ) {}

    public function isFound(): bool
    {
        return in_array($this->status, [self::AVAILABLE, self::EXPIRING], true);
    }

    public function toArray(): array
    {
        return [
            'domain' => $this->domain->fqdn(),
            'label' => $this->domain->label,
            'tld' => $this->domain->tld,
            'length' => strlen($this->domain->label),
            'status' => $this->status,
            'source' => $this->source,
            'registered_at' => $this->registeredAt,
            'expires_at' => $this->expiresAt,
            'registrar' => $this->registrar,
            'rdap_status' => $this->rdapStatus,
            'note' => $this->note,
        ];
    }
}
