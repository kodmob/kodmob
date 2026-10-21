<?php

namespace App\Modules\I18n\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Http\Controller;
use App\Modules\I18n\LanguageReport;
use App\Modules\I18n\LanguageService;
use Illuminate\Http\Request;

/**
 * Dil kaydı (`km_manage_languages`):
 *
 *   GET    /languages                 diller + derlemedeki diller + ayrılmış adlar
 *   POST   /languages                 dil ekle (pasif)
 *   PUT    /languages/{code}          düzenle (yayına alma dahil; önek ve varsayılan dil derlemeden gelir)
 *   DELETE /languages/{code}          sil (pasif ve içeriksiz dil)
 *   POST   /languages/order           { codes: [] } sıralama
 *   GET    /languages/overview        genel bakış: dil başına içerik ve metin durumu, sağlık özeti
 *   GET    /languages/health          sorun listesi (düzeltme bağlantılarıyla)
 *   GET    /languages/urls            derlemedeki adres yapısı, eski adresler, adres geçmişi
 *   GET    /languages/visitor         404'te sık ziyaret edilenler (dil başına)
 *   PUT    /languages/visitor         { popular: { dil: [{ label, url }] } }
 * Ana anahtar ve öneri bandı ayarlardır (`/settings`, grup "Çoklu dil").
 */
final class LanguagesController extends Controller
{
    public function __construct(private readonly LanguageService $languages) {}

    public function index(): array
    {
        return [
            'items' => $this->languages->list(),
            'default' => Locales::default(),
            'built' => LanguageService::builtLocales(),
            'reserved' => LanguageService::reserved(),
        ];
    }

    public function store(Request $request): array
    {
        $language = $this->languages->create($this->input($request));
        Activity::subject('language', 0, $language['name'].' ('.$language['code'].')');

        return $language;
    }

    public function update(Request $request, string $code): array
    {
        [$language, $changes] = $this->languages->update($code, $this->input($request));
        Activity::subject('language', 0, $language['name'].' ('.$code.')');
        Activity::with(['fields' => array_keys($changes)]);
        if (! $changes) {
            Activity::skip();
        }

        return $language;
    }

    public function overview(): array
    {
        return (new LanguageReport)->overview();
    }

    public function health(): array
    {
        return ['items' => (new LanguageReport)->health()];
    }

    public function urls(): array
    {
        return (new LanguageReport)->urls();
    }

    public function visitor(): array
    {
        return ['popular' => (object) LanguageReport::popular()];
    }

    public function saveVisitor(Request $request): array
    {
        $popular = LanguageReport::savePopular((array) ($this->input($request)['popular'] ?? []));
        Activity::subject('language', 0, '404 bağlantıları');
        Activity::with(['languages' => array_keys($popular)]);

        return ['popular' => (object) $popular];
    }

    public function destroy(string $code): array
    {
        $language = $this->languages->delete($code);
        Activity::subject('language', 0, $language['name'].' ('.$code.')');

        return ['deleted' => $code];
    }

    public function order(Request $request): array
    {
        Activity::subject('language', 0, 'Dil sırası');

        return ['items' => $this->languages->reorder((array) ($this->input($request)['codes'] ?? []))];
    }
}
