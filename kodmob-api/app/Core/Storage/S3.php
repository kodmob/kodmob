<?php

namespace App\Core\Storage;

use App\Core\Exceptions\DomainException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;

/**
 * S3 uyumlu depoya yükleme (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO…), kitaplıksız:
 * AWS İmza Sürüm 4 (SigV4). Yalnızca gerekenler: dosya yükle, sil, bağlantıyı dene.
 *
 *   $s3 = new S3(['endpoint' => 'https://<hesap>.r2.cloudflarestorage.com', 'region' => 'auto',
 *                 'bucket' => 'yedekler', 'key' => '…', 'secret' => '…', 'path_style' => true]);
 *   $s3->putFile('kodmob/20261016-030000.zip', $yol);
 */
final class S3
{
    public function __construct(private readonly array $config) {}

    public function putFile(string $key, string $path, string $type = 'application/zip'): void
    {
        $hash = hash_file('sha256', $path);
        $handle = fopen($path, 'rb');
        try {
            $response = $this->send('PUT', $key, Utils::streamFor($handle), $hash, ['content-type' => $type, 'content-length' => (string) filesize($path)]);
        } finally {
            // Açık kalırsa Windows'ta dosya kilitli kalır (sonraki yedek aynı adı yazamaz).
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->assert($response, 'yükleme');
    }

    public function delete(string $key): void
    {
        $this->assert($this->send('DELETE', $key, '', hash('sha256', '')), 'silme', [204, 200]);
    }

    /** Bağlantı denemesi: küçük bir dosya yazılıp silinir. */
    public function test(): void
    {
        $key = trim((string) ($this->config['prefix'] ?? ''), '/').'/.kodmob-baglanti-denemesi';
        $body = 'kodmob '.date(DATE_ATOM);
        $this->assert($this->send('PUT', ltrim($key, '/'), $body, hash('sha256', $body), ['content-type' => 'text/plain']), 'deneme yüklemesi');
        $this->delete(ltrim($key, '/'));
    }

    /**
     * İmzalı başlıklar (SigV4). `amz_date` testte sabitlenir.
     *
     * @param  array<string,string>  $headers  küçük harfli ek başlıklar
     * @return array{url:string,headers:array<string,string>}
     */
    public function sign(string $method, string $key, string $payloadHash, array $headers = [], ?string $amzDate = null): array
    {
        $endpoint = rtrim((string) $this->config['endpoint'], '/');
        $host = (string) parse_url($endpoint, PHP_URL_HOST).(parse_url($endpoint, PHP_URL_PORT) ? ':'.parse_url($endpoint, PHP_URL_PORT) : '');
        $bucket = (string) $this->config['bucket'];
        $pathStyle = (bool) ($this->config['path_style'] ?? true);
        $path = '/'.($pathStyle ? rawurlencode($bucket).'/' : '').implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        if (! $pathStyle) {
            $host = $bucket.'.'.$host;
        }
        $scheme = (string) (parse_url($endpoint, PHP_URL_SCHEME) ?: 'https');
        $amzDate ??= gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $region = (string) ($this->config['region'] ?? '') ?: 'us-east-1';

        $headers = array_change_key_case($headers) + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        $signed = array_filter($headers, static fn ($name) => $name !== 'content-length', ARRAY_FILTER_USE_KEY);
        ksort($signed);
        $canonicalHeaders = implode('', array_map(static fn ($k, $v) => $k.':'.trim((string) $v)."\n", array_keys($signed), $signed));
        $signedNames = implode(';', array_keys($signed));
        $canonical = implode("\n", [$method, $path, '', $canonicalHeaders, $signedNames, $payloadHash]);
        $scope = "{$date}/{$region}/s3/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$this->config['secret'], true), true), true), true);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential='.$this->config['key'].'/'.$scope.', SignedHeaders='.$signedNames.', Signature='.hash_hmac('sha256', $toSign, $key);

        return ['url' => $scheme.'://'.$host.$path, 'headers' => $headers];
    }

    private function send(string $method, string $key, mixed $body, string $payloadHash, array $headers = [])
    {
        foreach (['endpoint', 'bucket', 'key', 'secret'] as $required) {
            if (trim((string) ($this->config[$required] ?? '')) === '') {
                throw new DomainException('km_s3_config', 'Uzak depo ayarları eksik: '.$required.'.', 422);
            }
        }
        $request = $this->sign($method, $key, $payloadHash, $headers);
        // Host'u istemci, içerik türünü withBody yazar (iki kez giderse imza tutmaz).
        $type = $request['headers']['content-type'] ?? 'application/octet-stream';
        unset($request['headers']['host'], $request['headers']['content-type']);

        return Http::withHeaders($request['headers'])->timeout(600)->withBody($body, $type)->send($method, $request['url']);
    }

    private function assert($response, string $action, array $ok = [200]): void
    {
        if (! in_array($response->status(), $ok, true)) {
            $message = preg_match('#<Message>([^<]+)</Message>#', (string) $response->body(), $m) ? $m[1] : 'HTTP '.$response->status();
            throw new DomainException('km_s3_failed', "Uzak depo {$action} başarısız: {$message}", 502);
        }
    }
}
