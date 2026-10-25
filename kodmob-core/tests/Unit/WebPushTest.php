<?php

namespace Tests\Unit;

use App\Core\Push\WebPush;
use PHPUnit\Framework\TestCase;

/** Web Push şifresi RFC 8291 Ek A'nın örneğiyle; VAPID imzası açık anahtarla doğrulanır. */
class WebPushTest extends TestCase
{
    public function test_encryption_matches_rfc_8291_example(): void
    {
        $d = [WebPush::class, 'decode'];
        $body = WebPush::encrypt(
            $d('V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24'),
            $d('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            $d('BTBZMqHH6r4Tts7J_aSIgg'),
            $d('DGv6ra1nlYgDCS1FRnbzlw'),
            [
                'private' => $d('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
                'public' => $d('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
            ],
        );

        $header = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
        $cipher = '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ';
        $this->assertSame($header, WebPush::encode(substr($body, 0, 86)));
        $this->assertSame($cipher, WebPush::encode(substr($body, 86)));
    }

    public function test_vapid_token_is_signed_with_the_site_key(): void
    {
        $keys = WebPush::generateKeys();
        $this->assertSame(32, strlen($keys['private']));
        $this->assertSame(65, strlen($keys['public']));
        $vapid = ['public' => WebPush::encode($keys['public']), 'private' => WebPush::encode($keys['private']), 'subject' => 'mailto:info@ornek.test'];

        $header = WebPush::authorization('https://fcm.googleapis.com/fcm/send/abc', $vapid, 1_700_000_000);
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header, $m);
        $this->assertSame($vapid['public'], $m[4]);
        $claims = json_decode(WebPush::decode($m[2]), true);
        $this->assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 1_700_000_000 + 43200, 'sub' => 'mailto:info@ornek.test'], $claims);

        // r || s → DER, açık anahtarla doğrula.
        $raw = WebPush::decode($m[3]);
        $this->assertSame(64, strlen($raw));
        $int = static function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || ord($v[0]) & 0x80) {
                $v = "\0".$v;
            }

            return "\x02".chr(strlen($v)).$v;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
        $der = "\x30".chr(strlen($seq)).$seq;
        $spki = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$keys['public'];
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify($m[1].'.'.$m[2], $der, $pem, OPENSSL_ALGO_SHA256));
    }

    public function test_invalid_subscription_key_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        WebPush::encrypt('x', str_repeat("\x04", 65), random_bytes(16));
    }
}
