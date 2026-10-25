<?php

namespace App\Modules\Media;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Content\MediaPayload;
use App\Core\Content\PageCache;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Controller;
use App\Core\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panel görsel kitaplığı — yalnızca yönetici. Alt metin her dilde ayrıdır.
 *
 *   GET  /admin/media?search=&page=     görseller (yeniden eskiye, sayfalı)
 *   POST /admin/media                   yükle (multipart `file`; isteğe bağlı alts[tr|en|de])
 *   POST /admin/media/url               { url, alts } adresten indir, kitaplığa ekle
 *   PUT  /admin/media/{id}              { alts: { tr, en, de } } alt metinleri (TR zorunlu)
 *
 * Yükleme alt metinsiz yapılabilir (sürükle-bırak); panel alt metin
 * yazılmadan görseli seçtirmez, içerik kaydı da alt metinsiz görseli reddeder.
 */
final class MediaController extends Controller
{
    private const PER_PAGE = 40;

    public function __construct(private readonly MediaLibrary $library) {}

    public function index(Request $request): array
    {
        $page = $this->library->page(Text::field($request->query('search', '')), max(1, (int) $request->query('page', 1)), self::PER_PAGE);

        return [
            'items' => array_values(array_filter(array_map([MediaPayload::class, 'admin'], $page['items']))),
            'total' => $page['total'],
            'pages' => $page['pages'],
        ];
    }

    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');
        $id = $this->library->storeUpload($file);
        $alts = (array) $request->input('alts', []);
        if (array_filter($alts)) {
            MediaPayload::saveAlts($id, $alts);
        }
        Activity::subject('media', $id, (string) $file?->getClientOriginalName());
        Activity::with(['fields' => []]);

        return $this->created(MediaPayload::admin($id));
    }

    public function fromUrl(Request $request): JsonResponse
    {
        $body = $this->input($request);
        $url = Text::url($body['url'] ?? '');
        if ($url === '' || str_starts_with($url, '/')) {
            throw ValidationException::field('url', 'Geçerli bir görsel adresi yazın (https://…).');
        }
        $id = $this->library->storeUrl($url);
        if (array_filter((array) ($body['alts'] ?? []))) {
            MediaPayload::saveAlts($id, (array) $body['alts']);
        }
        Activity::subject('media', $id, basename((string) parse_url($url, PHP_URL_PATH)));
        Activity::with(['url' => mb_substr($url, 0, 300), 'fields' => []]);

        return $this->created(MediaPayload::admin($id));
    }

    public function update(Request $request, int $id): array
    {
        if (! MediaLibrary::find($id)) {
            throw NotFoundException::message('Görsel bulunamadı.');
        }
        $alts = (array) ($this->input($request)['alts'] ?? []);
        $default = Locales::default();
        if (trim((string) ($alts[$default] ?? '')) === '') {
            throw ValidationException::fields(['alts.'.$default => sprintf('%s alt metin gerekli: görselde ne olduğunu kısaca anlatın.', Locales::labels()[$default] ?? $default)]);
        }
        MediaPayload::saveAlts($id, $alts);
        PageCache::flush();
        Activity::subject('media', $id, (string) (MediaLibrary::find($id)['file'] ?? ''));

        return MediaPayload::admin($id);
    }
}
