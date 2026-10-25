<?php

namespace App\Modules\System;

use App\Core\Activity\Activity;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Modules\Auth\Services\UserPreferences;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Çok dilli panel (PanelLanguage):
 *
 *   GET    /panel-lang/{dil}                    çalışma anı sözlüğü (oturum yeter)
 *   PUT    /me/panel-language   { code }         kişinin panel dili (tr ya da kurulu dil)
 *   GET    /system/panel-languages               kurulu diller, çevrilmiş / toplam (Sistem Ayarları › Panel dili)
 *   GET    /system/panel-languages/export?code=en&name=English   frontend-lang.json indirir
 *   POST   /system/panel-languages/import        dosya (multipart `file`) → dil kurulur / güncellenir
 *   DELETE /system/panel-languages/{dil}
 */
final class PanelLanguageController extends Controller
{
    public const PREFERENCE = 'panel_language';

    public function dictionary(string $code): array
    {
        return PanelLanguage::dictionary($code);
    }

    public function choose(Request $request): array
    {
        $code = (string) ($this->input($request)['code'] ?? '');
        if (! PanelLanguage::exists($code)) {
            throw ValidationException::field('code', 'Bu dil kurulu değil.');
        }
        $code === 'tr' ? UserPreferences::forget($request->user(), self::PREFERENCE) : UserPreferences::set($request->user(), self::PREFERENCE, $code);

        return ['code' => $code];
    }

    public function index(): array
    {
        return PanelLanguage::summary();
    }

    public function export(Request $request): Response
    {
        $body = PanelLanguage::export((string) $request->query('code', ''), (string) $request->query('name', ''));
        $json = (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="frontend-lang.'.$body['language'].'.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function import(Request $request): array
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid() || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::field('file', 'Dil dosyası yüklenemedi (en çok 5 MB JSON).');
        }
        $result = PanelLanguage::import(json_decode((string) file_get_contents($file->getRealPath()), true));
        Activity::with(['name' => $result['name'], 'code' => $result['code']]);

        return $result + PanelLanguage::summary();
    }

    public function destroy(string $code): array
    {
        PanelLanguage::remove($code);

        return PanelLanguage::summary();
    }
}
