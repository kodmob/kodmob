<?php

namespace App\Modules\Content;

use App\Core\Exceptions\DomainException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Storage\EncryptedFile;
use App\Core\Storage\S3;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\Secret;
use App\Core\Support\Text;
use App\Modules\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use ZipArchive;

/**
 * Zamanlanmış tam yedek (Ayarlar > Yedekleme > Otomatik yedek): içerik yedeği (Backup::export) ve
 * görsellerin asılları tek zip'te, sunucuda `storage/app/backups/auto/` (son N tanesi). İsteğe
 * bağlı olarak S3 uyumlu bir depoya da gönderilir (sunucu yanarsa yedek de gitmesin).
 *
 *   arsiv.zip
 *     backup.json        Backup::export(true) (içerik, ayarlar, metinler, SEO, adres geçmişi, kişisel kayıtlar)
 *     media/<dosya>      görsellerin asılları (boyutlar geri yüklemede yeniden üretilir)
 *
 * Uzak depoya giden kopya her zaman yedek şifresiyle şifrelidir (`arsiv.zip.kmbak`, Core\Storage\EncryptedFile):
 * yedekte siparişler, talepler, aboneler var. Şifre sunucuda mühürlü durur; yeni sunucuda dosya bu klasöre konup
 * şifreyle geri yüklenir (ya da panelden yüklenir).
 *
 * Geri yükleme: görseller `backups/media/`'ya açılır, sonra Backup::import (görseller oradan
 * bulunur). Zamanlama saatte bir bakar (cron): günlükte 23, haftalıkta 167 saatten eski son yedek.
 */
final class AutoBackup
{
    private const OPTION = 'auto_backup';

    private const DEFAULTS = [
        'enabled' => false, 'frequency' => 'daily', 'keep' => 7, 'media' => true,
        'remote' => false, 'endpoint' => '', 'region' => 'auto', 'bucket' => '', 'prefix' => 'kodmob', 'key' => '', 'secret' => '', 'path_style' => true, 'passphrase' => '',
        'last_at' => null, 'last_status' => null, 'last_error' => null, 'last_remote' => null, 'last_file' => null,
    ];

    public static function dir(): string
    {
        $dir = Backup::dir().'/auto';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public static function settings(): array
    {
        $stored = Options::get(self::OPTION, []);
        $s = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        $s['secret'] = Secret::open($s['secret']);
        $s['passphrase'] = Secret::open($s['passphrase']);

        return $s;
    }

    /** Panelde gösterilen hal (gizli anahtar yok) + yedek listesi. */
    public static function view(): array
    {
        $s = self::settings();
        $secretSet = $s['secret'] !== '';
        $passphraseSet = $s['passphrase'] !== '';
        unset($s['secret'], $s['passphrase']);

        return $s + ['secret_set' => $secretSet, 'passphrase_set' => $passphraseSet, 'items' => self::list(), 'zip' => class_exists(ZipArchive::class), 'personal' => Backup::personalLabels()];
    }

    public static function save(array $input): array
    {
        $s = self::settings();
        foreach (['enabled', 'media', 'remote', 'path_style'] as $key) {
            if (array_key_exists($key, $input)) {
                $s[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('frequency', $input)) {
            if (! in_array($input['frequency'], ['daily', 'weekly'], true)) {
                throw ValidationException::field('frequency', 'Sıklık günlük ya da haftalık olmalı.');
            }
            $s['frequency'] = $input['frequency'];
        }
        if (array_key_exists('keep', $input)) {
            $s['keep'] = max(1, min(60, (int) $input['keep']));
        }
        foreach (['endpoint', 'region', 'bucket', 'prefix', 'key'] as $key) {
            if (array_key_exists($key, $input)) {
                $s[$key] = trim(Text::field((string) $input[$key]));
            }
        }
        if ($s['endpoint'] !== '' && ! preg_match('#^https?://#', $s['endpoint'])) {
            throw ValidationException::field('endpoint', 'Adres https:// ile başlamalı (ör. https://<hesap>.r2.cloudflarestorage.com).');
        }
        if (! empty($input['clear_secret'])) {
            $s['secret'] = '';
        } elseif (isset($input['secret']) && (string) $input['secret'] !== '') {
            $s['secret'] = mb_substr((string) $input['secret'], 0, 300);
        }
        if (isset($input['passphrase']) && (string) $input['passphrase'] !== '') {
            if (mb_strlen((string) $input['passphrase']) < 12) {
                throw ValidationException::field('passphrase', 'Yedek şifresi en az 12 karakter olmalı.');
            }
            $s['passphrase'] = mb_substr((string) $input['passphrase'], 0, 200);
        }
        if ($s['remote'] && ($s['endpoint'] === '' || $s['bucket'] === '' || $s['key'] === '' || $s['secret'] === '')) {
            throw ValidationException::field('endpoint', 'Uzak depo için adres, kova, erişim anahtarı ve gizli anahtar gerekli.');
        }
        if ($s['remote'] && $s['passphrase'] === '') {
            throw ValidationException::field('passphrase', 'Uzak depoya giden yedek şifrelenir: yedek şifresi girin.');
        }
        self::store($s);

        return self::view();
    }

    /** Zamanlanmış görev (saatte bir): sırası geldiyse yedekler. */
    public static function runIfDue(): void
    {
        $s = self::settings();
        if (! $s['enabled']) {
            return;
        }
        $hours = $s['frequency'] === 'weekly' ? 167 : 23;
        if ($s['last_at'] && Clock::carbon((string) $s['last_at'])->diffInHours(Clock::carbon(Clock::now()), true) < $hours) {
            return;
        }
        self::run('zamanlanmış');
    }

    /** Yedek alır (ve gönderir); hatayı kaydeder, çağırana fırlatır. @return array{file:string,size:int,remote:?string} */
    public static function run(string $reason = 'panel'): array
    {
        @set_time_limit(600);
        $s = self::settings();
        $file = null;
        try {
            if (! class_exists(ZipArchive::class)) {
                throw new DomainException('km_zip_missing', 'Sunucuda PHP zip eklentisi yok.', 500);
            }
            $base = self::dir().'/'.now()->format('Ymd-His').'-'.Text::slug($reason ?: 'yedek');
            $file = $base.'.zip';
            for ($n = 2; is_file($file); $n++) {
                $file = $base.'-'.$n.'.zip';
            }
            $zip = new ZipArchive;
            if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new DomainException('km_zip_failed', 'Yedek dosyası oluşturulamadı.', 500);
            }
            $zip->addFromString('backup.json', Text::json((new Backup)->export(true)));
            if ($s['media']) {
                foreach (DB::table('media')->get(['id', 'path', 'file']) as $row) {
                    $path = MediaLibrary::filePath((array) $row);
                    if (is_file($path)) {
                        $zip->addFile($path, 'media/'.basename((string) $row->file));
                        $zip->setCompressionName('media/'.basename((string) $row->file), ZipArchive::CM_STORE);
                    }
                }
            }
            $zip->close();
            self::prune((int) $s['keep']);

            $remote = null;
            if ($s['remote']) {
                $remote = 'failed';
                // 2.6 öncesinde şifresiz kaydedilmiş uzak depo ayarı: şifre girilene kadar gönderilmez.
                if ($s['passphrase'] === '') {
                    throw ValidationException::field('passphrase', 'Uzak depoya giden yedek şifrelenir: Sistem Ayarları > Yedekleme\'de yedek şifresi girin.');
                }
                $sealed = $file.'.'.EncryptedFile::EXTENSION;
                try {
                    EncryptedFile::encrypt($file, $sealed, $s['passphrase']);
                    (new S3($s))->putFile(trim($s['prefix'], '/').'/'.basename($sealed), $sealed, 'application/octet-stream');
                } finally {
                    @unlink($sealed);
                }
                $remote = 'uploaded';
            }
            self::state(['last_at' => Clock::now(), 'last_status' => 'ok', 'last_error' => null, 'last_remote' => $remote, 'last_file' => basename($file)]);

            return ['file' => basename($file), 'size' => (int) filesize($file), 'remote' => $remote];
        } catch (Throwable $e) {
            self::state(['last_at' => Clock::now(), 'last_status' => 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 300), 'last_remote' => $s['remote'] ? 'failed' : null, 'last_file' => $file && is_file($file) ? basename($file) : null]);
            Log::error('[Kodmob yedek] otomatik yedek: '.$e->getMessage());
            throw $e;
        }
    }

    public static function testRemote(): void
    {
        $s = self::settings();
        (new S3($s))->test();
    }

    /**
     * Klasördeki yedekler; uzak depodan indirilip buraya konan şifreli kopya (`.zip.kmbak`) da listelenir.
     *
     * @return list<array{name:string,size:int,created:string,encrypted:bool}> yeniden eskiye
     */
    public static function list(): array
    {
        $files = array_merge(glob(self::dir().'/*.zip') ?: [], glob(self::dir().'/*.'.EncryptedFile::EXTENSION) ?: []);
        rsort($files);

        return array_map(static fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f), 'created' => date(DATE_ATOM, (int) filemtime($f)), 'encrypted' => ! str_ends_with($f, '.zip')], $files);
    }

    public static function path(string $name): string
    {
        $file = self::dir().'/'.basename($name);
        if (! preg_match('/^[\w.-]+\.(zip|'.EncryptedFile::EXTENSION.')$/', basename($name)) || ! is_file($file)) {
            throw NotFoundException::message('Yedek bulunamadı.');
        }

        return $file;
    }

    /** Listedeki yedekten geri yükleme (şifreliyse şifreyle). */
    public static function restore(string $name, bool $personal = false, string $passphrase = ''): array
    {
        return self::restoreFile(self::path($name), $personal, $passphrase);
    }

    /**
     * Zip'ten (ya da şifreli kopyasından) geri yükleme: görseller backups/media/'ya, sonra içerik (Backup::import).
     * Şifreli dosya önce geçici zip'e açılır; şifre yanlışsa hiçbir şey değişmez.
     */
    public static function restoreFile(string $path, bool $personal = false, string $passphrase = ''): array
    {
        if (! EncryptedFile::is($path)) {
            return self::restoreZip($path, $personal);
        }
        if ($passphrase === '') {
            throw ValidationException::field('passphrase', 'Bu yedek şifreli: yedek şifresini girin.');
        }
        $plain = Backup::dir().'/acilan-'.bin2hex(random_bytes(6)).'.zip';
        try {
            EncryptedFile::decrypt($path, $plain, $passphrase);

            return self::restoreZip($plain, $personal);
        } finally {
            @unlink($plain);
        }
    }

    private static function restoreZip(string $path, bool $personal): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::field('file', 'Yedek dosyası açılamadı.');
        }
        $json = $zip->getFromName('backup.json');
        if ($json === false) {
            $zip->close();
            throw ValidationException::field('file', 'Bu zip bir Kodmob yedeği değil (backup.json yok).');
        }
        // Yalnızca media/<güvenli ad> (zip içinden dışarı yazma yok): backups/media/'ya düşer.
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^media/[\w.-]+$#', $entry) && ! str_contains($entry, '..')) {
                $entries[] = $entry;
            }
        }
        @mkdir(Backup::mediaDir(), 0755, true);
        if ($entries) {
            $zip->extractTo(Backup::dir(), $entries);
        }
        $zip->close();

        return (new Backup)->import((array) json_decode($json, true), $personal);
    }

    private static function prune(int $keep): void
    {
        // Elle konan şifreli kopya (geri yüklenmeyi bekliyor) silinmez.
        foreach (array_slice(array_values(array_filter(self::list(), static fn ($i) => ! $i['encrypted'])), max(1, $keep)) as $old) {
            @unlink(self::dir().'/'.$old['name']);
        }
    }

    private static function state(array $values): void
    {
        self::store(array_merge(self::settings(), $values));
    }

    private static function store(array $s): void
    {
        $s['secret'] = Secret::seal($s['secret']);
        $s['passphrase'] = Secret::seal($s['passphrase']);
        Options::set(self::OPTION, array_intersect_key($s, self::DEFAULTS));
    }
}
