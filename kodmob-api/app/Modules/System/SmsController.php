<?php

namespace App\Modules\System;

use App\Core\Http\Controller;
use App\Core\Sms\Sms;
use App\Core\Sms\SmsLog;
use App\Core\Sms\SmsSettings;
use App\Core\Support\SiteManifest;
use Illuminate\Http\Request;

/**
 * Ayarlar > SMS: sağlayıcı ayarları, bağlantı denemesi (onaylı başlıklar), deneme SMS'i ve son
 * gönderimler. Yetki: km_manage_settings.
 *
 *   GET  /system/sms            ayarlar + son gönderimler
 *   PUT  /system/sms            kaydet
 *   POST /system/sms/senders    kayıtlı ayarlarla bağlan, onaylı gönderici başlıklarını getir (kredi harcamaz)
 *   POST /system/sms/test       { to, message? } deneme SMS'i (hemen; sağlayıcının hatası döner)
 */
final class SmsController extends Controller
{
    public function show(): array
    {
        return SmsSettings::view() + ['log' => SmsLog::recent()];
    }

    public function save(Request $request): array
    {
        return SmsSettings::save($this->input($request)) + ['log' => SmsLog::recent()];
    }

    public function senders(): array
    {
        return ['senders' => SmsSettings::gateway()->senders()];
    }

    public function test(Request $request): array
    {
        $input = $this->input($request);
        $message = trim((string) ($input['message'] ?? '')) ?: SiteManifest::siteName().' panelinden deneme SMS\'i. Size ulaştıysa SMS ayarları çalışıyor.';
        $result = Sms::send([['to' => (string) ($input['to'] ?? ''), 'name' => 'Deneme']], $message, 'test');

        return $result + ['log' => SmsLog::recent()];
    }
}
