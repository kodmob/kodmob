<?php

namespace App\Core\Http;

use App\Core\Activity\Activity;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Panel ucunu sunucunun içinden, oturumdaki kullanıcı adına çağırır (Jarvis'in sözleşme araçları): istek aynı
 * ara katmanlardan geçer (oturum, `cap:` yetkisi, `module:` kapısı, hız sınırı, İşlem Geçmişi), yani araç
 * kullanıcının panelde yapabildiğinden fazlasını yapamaz ve yaptığı kullanıcının adıyla kaydedilir. Dıştaki isteğin
 * işlem kaydı bağlamı ve istek nesnesi sonra geri konur.
 *
 *   InternalRequest::send('POST', '/v1/seo/redirects', ['source' => '/eski/', 'target' => '/yeni/'])
 */
final class InternalRequest
{
    /** @return array{status:int,body:mixed} */
    public static function send(string $method, string $path, array $data = []): array
    {
        $outer = app('request');
        $method = strtoupper($method);
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $outer->ip() ?? '127.0.0.1',
            'HTTP_USER_AGENT' => 'Kodmob Jarvis',
        ];
        if ($auth = $outer->header('Authorization')) {
            $server['HTTP_AUTHORIZATION'] = $auth;
        }
        $inner = Request::create($path, $method, $method === 'GET' ? $data : [], [], [], $server, $method === 'GET' ? null : (string) json_encode($data));
        $state = Activity::state();
        try {
            $response = app(Kernel::class)->handle($inner);
        } finally {
            app()->instance('request', $outer);
            Facade::clearResolvedInstance('request');
            Activity::restore($state);
        }
        $content = (string) $response->getContent();

        return ['status' => $response->getStatusCode(), 'body' => json_decode($content, true) ?? $content];
    }
}
