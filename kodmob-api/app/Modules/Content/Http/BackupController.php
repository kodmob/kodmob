<?php

namespace App\Modules\Content\Http;

use App\Core\Activity\Activity;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Jobs\Background;
use App\Core\Storage\EncryptedFile;
use App\Modules\Content\AutoBackup;
use App\Modules\Content\Backup;
use App\Modules\Content\RunBackup;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Yedekleme uçları — yalnızca yönetici (bkz. Backup).
 *
 *   GET  /admin/backup                 tam yedek (JSON); `?personal=1` kişisel kayıtlar da (siparişler, talepler…)
 *   POST /admin/backup                 geri yükle: multipart `file` (JSON, otomatik yedeğin zip'i ya da şifreli
 *                                      kopyası + `passphrase`) ya da JSON gövde; `personal` kişisel kayıtlar da
 *   GET  /admin/backups                anlık yedekler (geri yükleme öncesi otomatik alınanlar)
 *   GET  /admin/backups/{name}         bir anlık yedeğin kendisi
 */
final class BackupController extends Controller
{
    public function export(Request $request): array
    {
        $personal = $request->boolean('personal');
        Activity::force();
        Activity::subject('backup', 0, $personal ? 'Yedek dosyası (kişisel kayıtlarla)' : 'Yedek dosyası');

        return (new Backup)->export($personal);
    }

    public function import(Request $request): array
    {
        $personal = $request->boolean('personal');
        $file = $request->file('file');
        // Otomatik yedeğin zip'i ya da uzak depodaki şifreli kopyası (sunucu yandıysa yeni kurulumda buradan).
        $head = $file ? (string) file_get_contents($file->getRealPath(), false, null, 0, 4) : '';
        if ($file && ($head === "PK\x03\x04" || $head === EncryptedFile::MAGIC)) {
            $result = AutoBackup::restoreFile($file->getRealPath(), $personal, (string) $request->input('passphrase', ''));
        } else {
            $backup = $file ? json_decode((string) file_get_contents($file->getRealPath()), true) : $this->input($request);
            if (! is_array($backup) || ! $backup) {
                throw ValidationException::field('file', 'Yedek dosyası okunamadı.');
            }
            $result = (new Backup)->import($backup, $personal);
        }
        Activity::subject('backup', 0, 'Yedekten geri yükleme');
        Activity::with(['counts' => array_filter((array) ($result['counts'] ?? $result), 'is_int'), 'fields' => []]);

        return $result;
    }

    // ------------------------------------------------------------ otomatik yedek

    public function auto(): array
    {
        return AutoBackup::view();
    }

    public function saveAuto(Request $request): array
    {
        return AutoBackup::save($this->input($request));
    }

    /** Hemen yedekle: arka planda (Background); durum ayarlarda (last_*), panel yeniler. */
    public function runAuto(): array
    {
        Background::dispatch(new RunBackup);
        Activity::subject('backup', 0, 'Otomatik yedek');

        return AutoBackup::view() + ['started' => true];
    }

    public function testRemote(): array
    {
        AutoBackup::testRemote();

        return ['ok' => true];
    }

    public function downloadAuto(string $name): BinaryFileResponse
    {
        Activity::force();
        Activity::subject('backup', 0, $name);

        $type = str_ends_with($name, '.zip') ? 'application/zip' : 'application/octet-stream';

        return response()->download(AutoBackup::path($name), $name, ['Content-Type' => $type, 'Cache-Control' => 'no-store']);
    }

    public function restoreAuto(Request $request, string $name): array
    {
        $result = AutoBackup::restore($name, $request->boolean('personal'), (string) $request->input('passphrase', ''));
        Activity::subject('backup', 0, 'Otomatik yedekten geri yükleme: '.$name);
        Activity::with(['counts' => array_filter((array) ($result['counts'] ?? []), 'is_int'), 'fields' => []]);

        return $result;
    }

    public function list(): array
    {
        return ['items' => (new Backup)->list(), 'personal' => Backup::personalLabels()];
    }

    public function show(string $name): array
    {
        return (new Backup)->read($name);
    }
}
