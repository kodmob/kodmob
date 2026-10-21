<?php

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Core\Storage\EncryptedFile;
use PHPUnit\Framework\TestCase;

/** Parolayla şifreli dosya (uzak depoya giden yedek): parça parça AES-256-GCM, kesilme ve değişiklik yakalanır. */
class EncryptedFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/km-enc-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private function roundTrip(string $data): string
    {
        file_put_contents($this->dir.'/a', $data);
        // Testte düşük yineleme ve küçük parça: birden çok parça ve sınır durumları denenir.
        EncryptedFile::encrypt($this->dir.'/a', $this->dir.'/a.kmbak', 'uzun bir yedek şifresi', 1000, 1024);
        $this->assertTrue(EncryptedFile::is($this->dir.'/a.kmbak'));
        $this->assertStringNotContainsString('gizli müşteri', (string) file_get_contents($this->dir.'/a.kmbak'));
        EncryptedFile::decrypt($this->dir.'/a.kmbak', $this->dir.'/b', 'uzun bir yedek şifresi');

        return (string) file_get_contents($this->dir.'/b');
    }

    public function test_round_trip_for_empty_exact_and_multi_chunk_files(): void
    {
        $this->assertSame('', $this->roundTrip(''));
        $this->assertSame(str_repeat('x', 2048), $this->roundTrip(str_repeat('x', 2048)), 'parça boyunun tam katı');
        $data = 'gizli müşteri '.random_bytes(5000);
        $this->assertSame($data, $this->roundTrip($data));
        $this->assertFalse(EncryptedFile::is($this->dir.'/b'));
    }

    public function test_wrong_passphrase_truncation_and_tampering_are_rejected(): void
    {
        $this->roundTrip(random_bytes(3000));
        $sealed = (string) file_get_contents($this->dir.'/a.kmbak');

        $fails = function (string $content, string $passphrase = 'uzun bir yedek şifresi'): void {
            file_put_contents($this->dir.'/c.kmbak', $content);
            try {
                EncryptedFile::decrypt($this->dir.'/c.kmbak', $this->dir.'/d', $passphrase);
                $this->fail('açılmamalıydı');
            } catch (ValidationException) {
                $this->assertFileDoesNotExist($this->dir.'/d', 'yarım çıktı silinir');
            }
        };
        $fails($sealed, 'başka bir şifre');
        // Parça sınırından kesilmiş (son parça eksik): "son" işareti tutmaz.
        $fails(substr($sealed, 0, 36 + 2 * (1024 + 16)));
        $tampered = $sealed;
        $tampered[100] = chr(ord($tampered[100]) ^ 1);
        $fails($tampered);
        // Başlıktaki aşırı yineleme sayısı sunucuyu yormaz.
        $fails(substr_replace($sealed, pack('N', 999999999), 5, 4));
        $fails('düz bir dosya');
    }
}
