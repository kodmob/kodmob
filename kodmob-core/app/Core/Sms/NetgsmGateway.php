<?php

namespace App\Core\Sms;

use App\Core\Exceptions\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * NetGSM REST v2 (https://api.netgsm.com.tr, Basic auth: abone numarası / kullanıcı kodu + API
 * şifresi). Uçlar NetGSM'in resmi SDK'sıyla aynı:
 *
 *   POST /sms/rest/v2/send       { msgheader, messages: [{ msg, no }], encoding?, iysfilter?, appname? }
 *                                → { code: "00", jobid, description }
 *   GET  /sms/rest/v2/msgheader  → { code: "00", msgheaders: [...] }
 *
 * Hata durumunda NetGSM çoğunlukla HTTP 406 ve gövdede `code` döner; kodlar Türkçe açıklamayla
 * yöneticiye gösterilir (ERRORS).
 */
final class NetgsmGateway implements SmsGateway
{
    public const URL = 'https://api.netgsm.com.tr';

    public const ERRORS = [
        '20' => 'Mesaj metninde sorun var ya da metin çok uzun.',
        '30' => 'Kullanıcı adı ya da şifre hatalı veya API erişim izni yok. NetGSM panelinde IP kısıtlaması açıksa bu sunucunun IP adresi izinli olmalı.',
        '40' => 'Gönderici başlığı NetGSM hesabında tanımlı değil.',
        '50' => 'Abonelik İYS kontrollü gönderim yapamıyor; İYS filtresini "Bilgilendirme" yapın.',
        '51' => 'Aboneliğe tanımlı İYS marka bilgisi bulunamadı.',
        '60' => 'İş bulunamadı.',
        '70' => 'Parametrelerden biri hatalı ya da eksik.',
        '80' => 'Gönderim sınırı aşıldı.',
        '85' => 'Aynı numaraya bir dakikada 20\'den fazla gönderim yapılamaz.',
        '100' => 'NetGSM sistem hatası; biraz sonra yeniden deneyin.',
    ];

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $sender,
        private readonly string $iysFilter = '0',
        private readonly string $appName = '',
    ) {}

    public function send(array $numbers, string $text, string $encoding): string
    {
        $body = [
            'msgheader' => $this->sender,
            'messages' => array_map(static fn (string $no) => ['msg' => $text, 'no' => $no], array_values($numbers)),
        ];
        if ($encoding !== '') {
            $body['encoding'] = $encoding;
        }
        if ($this->iysFilter !== '') {
            $body['iysfilter'] = $this->iysFilter;
        }
        if ($this->appName !== '') {
            $body['appname'] = $this->appName;
        }

        return (string) ($this->call('post', '/sms/rest/v2/send', $body)['jobid'] ?? '');
    }

    public function senders(): array
    {
        $json = $this->call('get', '/sms/rest/v2/msgheader', $this->appName !== '' ? ['appname' => $this->appName] : []);

        return array_values(array_filter(array_map('strval', (array) ($json['msgheaders'] ?? [])), static fn ($h) => $h !== ''));
    }

    private function call(string $method, string $path, array $data): array
    {
        try {
            $request = Http::withBasicAuth($this->username, $this->password)->acceptJson()->timeout(30);
            /** @var Response $response */
            $response = $method === 'get' ? $request->get(self::URL.$path, $data) : $request->post(self::URL.$path, $data);
        } catch (ConnectionException $e) {
            throw new DomainException('km_sms_failed', 'NetGSM\'e bağlanılamadı: '.mb_substr($e->getMessage(), 0, 200), 502);
        }
        $json = $response->json();
        $code = is_array($json) ? (string) ($json['code'] ?? '') : '';
        if ($code === '00' || ($code === '' && $response->successful() && is_array($json) && isset($json['msgheaders']))) {
            return $json;
        }
        if ($code === '') {
            throw new DomainException('km_sms_failed', 'NetGSM beklenmeyen bir yanıt verdi (HTTP '.$response->status().').', 502);
        }

        throw new DomainException('km_sms_failed', 'NetGSM: '.(self::ERRORS[$code] ?? 'tanımsız hata.').' (kod '.$code.')', 422, ['provider_code' => $code]);
    }
}
