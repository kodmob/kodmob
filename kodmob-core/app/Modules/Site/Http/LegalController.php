<?php

namespace App\Modules\Site\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Http\Controller;
use App\Core\Support\Paths;
use App\Modules\Site\Legal\LegalData;
use App\Modules\Site\Legal\LegalDocs;
use App\Modules\Site\Legal\LegalPages;
use App\Modules\Site\Render\Router;
use Illuminate\Http\Request;

/**
 * Panel › Yasal belgeler (`cap:km_manage_legal`).
 *
 *   GET    /admin/legal                    açık belgeler, dil başına durum, eksik yasal bilgiler
 *   GET    /admin/legal/{ad}/{dil}         şu anki metin, şablonun bu projedeki hali, özelleştirildi mi
 *   PUT    /admin/legal/{ad}/{dil}         { body } özelleştir (temizlenmiş HTML)
 *   DELETE /admin/legal/{ad}/{dil}         şablona dön
 */
final class LegalController extends Controller
{
    public function index(): array
    {
        return [
            'items' => $this->pages()->adminList(),
            'missing' => LegalData::missing(),
            'locales' => Locales::active(),
            'hub' => $this->router()?->url('legal', Locales::default()),
        ];
    }

    public function show(string $name, string $locale): array
    {
        $pages = $this->pages();
        $page = $pages->render($name, $locale);

        return [
            'name' => $name,
            'locale' => $locale,
            'title' => LegalDocs::title(LegalDocs::find($name) ?? [], $locale),
            'html' => $page['html'] ?? '',
            'template' => $pages->template($name, $locale),
            'customized' => (bool) ($page['customized'] ?? false),
            'updated' => $page['updated'] ?? '',
        ];
    }

    public function save(Request $request, string $name, string $locale): array
    {
        $page = $this->pages()->save($name, $locale, (string) ($this->input($request)['body'] ?? ''), $request->user()?->id);
        $this->log($name, $locale);

        return $page + ['name' => $name, 'locale' => $locale];
    }

    public function reset(string $name, string $locale): array
    {
        $this->pages()->reset($name, $locale);
        $this->log($name, $locale);

        return ['reset' => true];
    }

    private function log(string $name, string $locale): void
    {
        $title = LegalDocs::title(LegalDocs::find($name) ?? [], Locales::default());
        Activity::subject('legal_document', 0, $title);
        Activity::with(['title' => $title, 'locale' => $locale]);
    }

    private function pages(): LegalPages
    {
        return new LegalPages($this->router());
    }

    private function router(): ?Router
    {
        return Router::load(Paths::siteRoot());
    }
}
