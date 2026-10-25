<?php

namespace App\Modules\Content\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Support\Paths;
use App\Core\Support\Text;
use App\Modules\Content\EstimateConfig;
use App\Modules\Content\PageData;
use App\Modules\Content\PageTexts;
use App\Modules\I18n\ContentTranslator;
use App\Modules\Site\Render\Router;
use App\Modules\Site\Render\Suggestions;
use Illuminate\Http\Request;

/**
 * Sayfa metinleri, teklif hesaplayıcı (panel) ve ziyaretçi sayfa verisi.
 *
 *   GET /admin/texts       { texts: { anahtar: { on, tr, en, de } }, values: { dil: { anahtar: metin } } }
 *   PUT /admin/texts       { values: { dil: { anahtar: metin } } } — boş metin sözlüğe döner
 *   GET /admin/estimate    { value, defaults, labels: { types, designs, features } }
 *   PUT /admin/estimate    { value } → { value }
 *
 *   GET /page-data?route=project&locale=tr&slug=harman&needs=projects,posts   (herkese açık)
 *   GET /search-index?locale=tr                                                  (Ctrl+K arama dizini)
 *
 * Canlıda sayfa verisi render.php ile sayfaya gömülür; /page-data yedektir ve
 * geliştirme sunucusunda kullanılır.
 */
final class PagesController extends Controller
{
    public function texts(): array
    {
        return ['texts' => (object) PageTexts::definitions(), 'values' => (object) PageTexts::overrides()];
    }

    public function saveTexts(Request $request): array
    {
        $values = (array) ($this->input($request)['values'] ?? []);
        Activity::with(['count' => count($values), 'fields' => []]);

        return ['values' => (object) PageTexts::save($values)];
    }

    /**
     * Sayfa metinlerini Jarvis ile çevirir (kaydetmez): { from, to, texts: { anahtar: metin } } →
     * { texts }. Gönderilen yalnızca bu metinlerdir.
     */
    public function translateTexts(Request $request): array
    {
        ContentTranslator::ensureAvailable();
        $in = $this->input($request);
        $texts = array_filter(array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 2000) : null, (array) ($in['texts'] ?? [])), static fn ($v) => $v !== null && trim($v) !== '');
        if (count($texts) > 200) {
            throw ValidationException::field('texts', 'Bir seferde en fazla 200 metin çevrilir.');
        }

        return ['texts' => (object) (new ContentTranslator)->translate($texts, (string) ($in['from'] ?? ''), (string) ($in['to'] ?? ''))];
    }

    public function estimate(): array
    {
        return ['value' => EstimateConfig::get(), 'defaults' => EstimateConfig::DEFAULTS, 'labels' => $this->labels()];
    }

    public function saveEstimate(Request $request): array
    {
        Activity::with(['fields' => []]);

        return ['value' => EstimateConfig::save((array) ($this->input($request)['value'] ?? []))];
    }

    public function pageData(Request $request): array
    {
        return PageData::build(
            Text::key($request->query('route', '')),
            Text::key($request->query('locale', '')),
            Text::slug($request->query('slug', '')),
            array_filter(array_map([Text::class, 'key'], explode(',', (string) $request->query('needs', ''))))
        );
    }

    /** 404 sayfası: bulunamayan adrese benzeyen adresler (Site\Render\Suggestions). */
    public function suggest(Request $request): array
    {
        $router = Router::load(Paths::siteRoot());
        $path = mb_substr((string) $request->query('path', ''), 0, 300);

        return ['items' => $router && $path !== '' ? (new Suggestions($router))->for($path) : []];
    }

    public function searchIndex(Request $request): array
    {
        return PageData::search(Text::key($request->query('locale', '')));
    }

    /** Seçeneklerin Türkçe adları (sayfa metinlerindeki haliyle). */
    private function labels(): array
    {
        $definitions = PageTexts::definitions();
        $d = EstimateConfig::DEFAULTS;
        $groups = ['types' => array_keys($d['weeks']), 'designs' => array_keys($d['design']), 'features' => array_keys($d['featureWeeks'])];
        $labels = [];
        foreach ($groups as $group => $keys) {
            foreach ($keys as $key) {
                $labels[$group][$key] = PageTexts::get("estimate.{$group}.{$key}", Locales::default(), $definitions) ?: $key;
            }
        }

        return $labels;
    }
}
