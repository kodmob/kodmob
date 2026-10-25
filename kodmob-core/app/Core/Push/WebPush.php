<?php

namespace App\Core\Push;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push (tarayıcı bildirimi), ek kütüphanesiz: yalnızca PHP'nin openssl'i.
 *
 * - İleti şifresi RFC 8291 (aes128gcm; ECDH P-256 + HKDF + AES-128-GCM): yalnızca aboneliğin tarayıcısı açar,
 *   aradaki itme servisi (Google, Mozilla, Apple) içeriği göremez.
 * - Gönderen kimliği RFC 8292 (VAPID): ES256 imzalı kısa ömürlü jeton; anahtar çifti sitenindir (VapidKeys).
 *
 *   WebPush::send($subscription, ['title' => 'Yeni talep', 'body' => '…', 'url' => '/panel/talepler/12'], $keys)
 *
 * Yanıt kodu döner: 201 gönderildi; 404 / 410 abonelik artık yok (silinmeli); diğerleri geçici hata.
 */
final class WebPush
{
    private const RECORD_SIZE = 4096;

    /** Bir ileti en çok bu kadar bayt (itme servisleri 4 KB'a kadar kabul eder; şifre ve başlık payı düşülmüş). */
    public const MAX_PAYLOAD = 3000;

    /**
     * @param  array{endpoint:string,p256dh:string,auth:string}  $subscription  tarayıcının verdiği (base64url)
     * @param  array{public:string,private:string,subject:string}  $vapid  ham anahtarlar (base64url) ve iletişim
     */
    public static function send(array $subscription, array $payload, array $vapid, int $ttl = 86400): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        if (strlen($json) > self::MAX_PAYLOAD) {
            throw new RuntimeException('Bildirim çok uzun.');
        }
        $body = self::encrypt($json, self::decode($subscription['p256dh']), self::decode($subscription['auth']));
        $response = Http::timeout(10)
            ->withHeaders([
                'Authorization' => self::authorization($subscription['endpoint'], $vapid),
                'TTL' => (string) $ttl,
                'Content-Encoding' => 'aes128gcm',
                'Urgency' => 'normal',
            ])
            ->withBody($body, 'application/octet-stream')
            ->post($subscription['endpoint']);

        return $response->status();
    }

    /**
     * RFC 8291: şifreli gövde (başlık + tek kayıt). Testte tuz ve sunucunun geçici anahtarı verilir (RFC'nin örneği).
     *
     * @param  string  $uaPublic  tarayıcının açık anahtarı (65 bayt, 0x04…)
     * @param  string  $authSecret  16 bayt
     * @param  array{private:string,public:string}|null  $ephemeral  ham geçici anahtar çifti
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $salt = null, ?array $ephemeral = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('Geçersiz abonelik anahtarı.');
        }
        $salt ??= random_bytes(16);
        $ephemeral ??= self::generateKeys();
        $secret = openssl_pkey_derive(self::publicKey($uaPublic), self::privateKey($ephemeral['private'], $ephemeral['public']), 32);
        if ($secret === false) {
            throw new RuntimeException('Anahtar anlaşması yapılamadı (geçersiz abonelik anahtarı).');
        }
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$uaPublic.$ephemeral['public'], $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        // Tek (son) kayıt: düz metin + 0x02 sınırlayıcı, dolgu yok.
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('İleti şifrelenemedi.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$ephemeral['public'].$cipher.$tag;
    }

    /** VAPID başlığı: `vapid t=<ES256 jeton>, k=<açık anahtar>`; jeton 12 saat geçerli, kitlesi itme servisinin kökeni. */
    public static function authorization(string $endpoint, array $vapid, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $header = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::encode((string) json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $vapid['subject']], JSON_UNESCAPED_SLASHES));
        $signed = $header.'.'.$claims;
        if (! openssl_sign($signed, $der, self::privateKey(self::decode($vapid['private']), self::decode($vapid['public'])), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID imzası atılamadı.');
        }

        return 'vapid t='.$signed.'.'.self::encode(self::rawSignature($der)).', k='.$vapid['public'];
    }

    /** @return array{private:string,public:string} ham P-256 anahtar çifti (32 + 65 bayt) */
    public static function generateKeys(): array
    {
        // Önce eğri parametresiyle (openssl.cnf gerekmez; Windows PHP'si onu çoğu zaman bulamaz), olmazsa klasik biçim.
        $key = @openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1']])
            ?: @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = $key ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
        if (! $ec) {
            throw new RuntimeException('Sunucunun openssl\'i P-256 anahtarı üretemiyor.');
        }
        $pad = static fn (string $v) => str_pad($v, 32, "\0", STR_PAD_LEFT);

        return ['private' => $pad($ec['d']), 'public' => "\x04".$pad($ec['x']).$pad($ec['y'])];
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr(preg_replace('/\s+/', '', $text) ?? '', '-_', '+/'), true);
    }

    /** Ham özel anahtar (+ açık nokta) → openssl anahtarı (SEC1 ECPrivateKey, DER → PEM). */
    private static function privateKey(string $private, string $public): \OpenSSLAsymmetricKey
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".$private
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$public;
        $key = openssl_pkey_get_private(self::pem('EC PRIVATE KEY', $der));
        if ($key === false) {
            throw new RuntimeException('Özel anahtar okunamadı.');
        }

        return $key;
    }

    /** Ham açık nokta (65 bayt) → openssl anahtarı (SubjectPublicKeyInfo). */
    private static function publicKey(string $public): \OpenSSLAsymmetricKey
    {
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$public;
        $key = openssl_pkey_get_public(self::pem('PUBLIC KEY', $der));
        if ($key === false) {
            throw new RuntimeException('Açık anahtar P-256 eğrisinde değil.');
        }

        return $key;
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    /** openssl'in DER ECDSA imzası (SEQUENCE { r, s }) → JWS'in istediği 64 baytlık r || s. */
    private static function rawSignature(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $length);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }
}
