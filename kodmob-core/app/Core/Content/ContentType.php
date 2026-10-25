<?php

namespace App\Core\Content;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Search\Searchable;
use App\Core\Search\SearchQuery;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use App\Modules\Live\PulseFile;
use App\Modules\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;

/**
 * Üç dilli içerik türü (projenin türleri: kodmob.com'da projeler, hizmetler, referanslar): `contents`
 * tablosunda tek JSON sütununda veri.
 *
 * Veri biçimi:
 *   [ ortak alanlar…, 'i18n' => [ 'tr' => [dil alanları…], 'en' => …, 'de' => … ],
 *     'locales' => [ 'tr' => ['status' => 'publish', 'source' => null], 'en' => ['status' => 'draft', 'source' => 'a1b2…'] ] ]
 * Yayın durumu `status` (publish | draft; kaydın tamamı), sıra `position`.
 *
 * Diller (done-coklu-dil-raporu.md §4.4, §6): `locales` kayda eklenmiş dillerdir; her dilin kendi
 * yayın durumu vardır. Bir kayıt bir dilde, kayıt yayındaysa, dil eklenmiş ve yayındaysa ve o
 * dilin "dolu" alanı (`localeKey()`, ör. başlık) varsa sitede görünür. Dil kaldırılınca
 * metinleri `i18n`'de saklanır; yeniden eklenince geri gelir. `source`: çevirinin dayandığı
 * kaynak dil metinlerinin özeti (Translations); tutmazsa çeviri "Güncellenmeli"dir.
 * `locales` olmayan eski veride diller dolu alanlardan çıkarılır.
 *
 * Alt sınıf alanlarını (`shared()`, `localized()`) ve ziyaretçi çıktısını
 * (`publicItem()`) tanımlar; kayıt, liste, sıralama, silme ve şema buradadır.
 */
abstract class ContentType implements EditableContent, Searchable
{
    /** Kayıt türü (`contents.type`), ör. `km_project`. */
    abstract public function postType(): string;

    /** Panelde görünen ad: ['Projeler', 'Proje']. */
    abstract public function labels(): array;

    /** @return array<string,array> */
    abstract public function shared(): array;

    /** @return array<string,array> */
    abstract public function localized(): array;

    /** API adı: kayıt adının çoğulu (km_project → projects). Düzensiz çoğulda üzerine yazılır. */
    public function name(): string
    {
        return $this->recordKind().'s';
    }

    /** Ekleme ve düzenleme yetkisi (yetki listesine Types ekler). */
    /** Panel araması (Core\Search): başlıkta geçen kayıtlar. */
    public function searchRecords(string $term, int $limit): array
    {
        $rows = SearchQuery::anyLike(DB::table('contents')->where('type', $this->postType())->whereNull('deleted_at'), ['title'], $term)
            ->orderByDesc('updated_at')->limit($limit)->get(['id', 'title', 'status']);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'title' => (string) $r->title, 'subtitle' => $this->labels()[1], 'badge' => SearchQuery::statusBadge((string) $r->status),
        ])->all();
    }

    public function capability(): string
    {
        return 'km_manage_'.$this->name();
    }

    /** Rol düzenleyicideki adı; Türkçe ek gerektiren tür üzerine yazar ("Projeleri ekleme…"). */
    public function capabilityLabel(): string
    {
        return $this->labels()[0].' ekleme ve düzenleme';
    }

    /**
     * Ziyaretçi çıktısı (bir dil). `$item`: ortak + o dilin alanları, görseller
     * `Fields::present` ile çevrilmiş; `id`, `order`, `date`, `modified`, `slugs` hazır.
     */
    abstract protected function publicItem(array $item, string $locale): array;

    /** Kaydın bir dilde yayında sayılması için dolu olması gereken dil alanı. */
    public function localeKey(): ?string
    {
        return 'title';
    }

    /** Panel listesinde başlık ve görsel olarak kullanılan alanlar. */
    protected function listTitle(array $data): string
    {
        foreach (Locales::all() as $locale) {
            $title = $data['i18n'][$locale]['title'] ?? '';
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    protected function listImage(array $data): int
    {
        return (int) ($data['cover'] ?? 0);
    }

    protected function listSubtitle(array $data): string
    {
        foreach (Locales::all() as $locale) {
            if (! empty($data['i18n'][$locale]['tagline'])) {
                return $data['i18n'][$locale]['tagline'];
            }
        }

        return '';
    }

    // ------------------------------------------------------------ okuma

    public function find(int $id): ?Record
    {
        return $id ? ContentRepository::find($id, $this->postType()) : null;
    }

    public function get(int $id): Record
    {
        return $this->find($id) ?? throw NotFoundException::message($this->labels()[1].' bulunamadı. Silinmiş olabilir.');
    }

    /** @return list<Record> */
    public function records(bool $publishedOnly): array
    {
        return ContentRepository::all($this->postType(), $publishedOnly);
    }

    // ------------------------------------------------------------ panel

    /** Panel listesi: hafif satırlar. */
    public function adminList(): array
    {
        $records = $this->records(false);
        MediaLibrary::prime(array_map(fn (Record $r) => $this->listImage($r->data), $records));
        $key = $this->localeKey();

        return array_map(function (Record $record) {
            $data = $record->data;
            $image = $this->listImage($data);

            return [
                'id' => $record->id,
                'status' => $record->status,
                'order' => $record->position,
                'title' => $this->listTitle($data) ?: '(Başlıksız)',
                'subtitle' => $this->listSubtitle($data),
                'image' => $image ? MediaPayload::image($image, 'thumbnail') : null,
                'locales' => array_keys($this->languages($data)),
                // Liste tablosunun dil sütunu: eklenmiş diller, durum ve eskime.
                'languages' => $this->languageStates($data),
                'featured' => ! empty($data['featured']),
                'modified' => Clock::atom($record->updatedAt),
            ];
        }, $records);
    }

    /** Panel düzenleme formu: bütün alanlar, görseller önizlemeli. */
    public function adminItem(int $id): array
    {
        $record = $this->get($id);

        return [
            'id' => $record->id,
            'status' => $record->status,
            'order' => $record->position,
            'modified' => Clock::atom($record->updatedAt),
            'locales' => $this->adminLocales($record->data),
        ] + $this->expandData($record->data);
    }

    /** Boş kayıt (yeni form). */
    public function blankItem(): array
    {
        $item = ['id' => 0, 'status' => 'draft', 'order' => 0, 'modified' => null, 'locales' => [Locales::default() => ['status' => 'publish', 'stale' => false]]];
        foreach ($this->shared() as $name => $field) {
            $item[$name] = Fields::expand($field, Fields::blank($field));
        }
        foreach (Locales::all() as $locale) {
            foreach ($this->localized() as $name => $field) {
                $item['i18n'][$locale][$name] = Fields::blank($field);
            }
        }

        return $item;
    }

    public function schema(): array
    {
        [$plural, $singular] = $this->labels();

        return [
            'type' => $this->postType(),
            'labels' => ['plural' => $plural, 'singular' => $singular],
            'locales' => Locales::labels(),
            'localeKey' => $this->localeKey(),
            'shared' => Fields::schema($this->shared()),
            'localized' => Fields::schema($this->localized()),
        ];
    }

    /**
     * Kaydeder (yeni ya da mevcut). Hatalı alanlar `details.fields` ile döner
     * (`i18n.en.title` gibi yollarla).
     */
    public function save(?int $id, array $input): int
    {
        $record = $id ? $this->get($id) : null;
        $data = ['i18n' => []];
        $errors = [];

        foreach ($this->shared() as $name => $field) {
            $data[$name] = Fields::clean($field, $input[$name] ?? Fields::blank($field));
            if (! empty($field['required']) && Fields::isEmpty($data[$name])) {
                $errors[$name] = $field['label'].' gerekli.';
            }
        }
        foreach (Locales::all() as $locale) {
            $in = (array) ($input['i18n'][$locale] ?? []);
            foreach ($this->localized() as $name => $field) {
                $data['i18n'][$locale][$name] = Fields::clean($field, $in[$name] ?? Fields::blank($field));
            }
        }

        // Eklenmiş diller: her birinde dolu alan (başlık) gerekli.
        $languages = $this->cleanLanguages($input['locales'] ?? null, $record?->data, $data);
        $key = $this->localeKey();
        if (! $languages) {
            $errors['locales'] = 'En az bir dil ekleyin.';
        }
        foreach (array_keys($languages) as $code) {
            if ($key !== null && empty($data['i18n'][$code][$key])) {
                $errors['i18n.'.$code.'.'.$key] = sprintf('%s: %s gerekli.', Locales::labels()[$code] ?? $code, mb_strtolower($this->localized()[$key]['label']));
            }
        }
        $data['locales'] = $this->stampSources($languages, $data, $record?->data);
        $errors += Fields::missingAlts($this->shared(), $data);
        $errors += $this->validate($data, $record?->id ?? 0);
        if ($errors) {
            throw ValidationException::fields($errors);
        }

        $data = $this->fillSlugs($data, $record?->id ?? 0);
        $status = ($input['status'] ?? '') === 'publish' ? 'publish' : 'draft';
        $this->rememberSlugs($record?->id ?? 0, $record?->data ?? [], $data);
        $title = Text::stripTags($this->listTitle($data)) ?: $this->labels()[1];
        if ($record) {
            ContentRepository::update($record->id, $this->postType(), ['status' => $status, 'title' => $title, 'data' => $data]);
            $id = $record->id;
        } else {
            $id = ContentRepository::insert($this->postType(), $status, ContentRepository::nextPosition($this->postType()), $title, $data);
        }
        $this->changed();

        return $id;
    }

    public function delete(int $id): void
    {
        $this->get($id);
        ContentRepository::trash($id, $this->postType());
        $this->changed();
    }

    /** Çöpten geri alır (Silinenler). */
    public function untrash(int $id): bool
    {
        $done = ContentRepository::untrash($id, $this->postType());
        if ($done) {
            $this->changed();
        }

        return $done;
    }

    /** Sürükle-bırak sıralaması: verilen sırayla 0, 1, 2… */
    public function reorder(array $ids): void
    {
        foreach (array_values(array_map(static fn ($id) => abs((int) $id), $ids)) as $order => $id) {
            if ($this->find($id)) {
                ContentRepository::update($id, $this->postType(), ['position' => $order]);
            }
        }
        $this->changed();
    }

    // ------------------------------------------------------------ yedek

    /** Yedek için ham kayıt: durum, sıra, tarih ve veri (görseller kimlik). */
    public function export(Record $record): array
    {
        return [
            'id' => $record->id,
            'status' => $record->status,
            'order' => $record->position,
            'date' => $record->createdAt,
            'data' => $record->data,
        ];
    }

    /**
     * Yedekten geri yükleme: alanlar temizlenir ama doğrulanmaz (eksik görsel
     * geri yüklemeyi durdurmasın). `$data` görsel kimlikleri bu sitedekilerdir.
     */
    public function restore(array $record, array $data): int
    {
        $clean = ['i18n' => []];
        foreach ($this->shared() as $name => $field) {
            $clean[$name] = Fields::clean($field, $data[$name] ?? Fields::blank($field));
        }
        foreach (Locales::all() as $locale) {
            foreach ($this->localized() as $name => $field) {
                $clean['i18n'][$locale][$name] = Fields::clean($field, $data['i18n'][$locale][$name] ?? Fields::blank($field));
            }
        }
        // Dil durumları yedekte varsa korunur, yoksa (eski yedek) dolu alanlardan çıkarılır.
        $clean = $this->withLanguages($clean + (isset($data['locales']) && is_array($data['locales']) ? ['locales' => $data['locales']] : []));
        $clean = $this->fillSlugs($clean, 0);
        $date = strtotime((string) ($record['date'] ?? '')) ? date('Y-m-d H:i:s', strtotime((string) $record['date'])) : null;

        return ContentRepository::insert(
            $this->postType(),
            ($record['status'] ?? '') === 'publish' ? 'publish' : 'draft',
            (int) ($record['order'] ?? 0),
            Text::stripTags($this->listTitle($clean)) ?: $this->labels()[1],
            $clean,
            $date
        );
    }

    /** Türe özel ek doğrulama. @return array<string,string> */
    protected function validate(array $data, int $id): array
    {
        return [];
    }

    // ------------------------------------------------------------ ziyaretçi

    /**
     * Bir dilde yayındaki kayıtlar (sıralı).
     *
     * @return list<array>
     */
    public function publicList(string $locale): array
    {
        $records = $this->records(true);
        MediaLibrary::prime($this->imageIds($records));
        $items = [];
        foreach ($records as $record) {
            $item = $this->localize($record, $locale);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** Dile göre tek kayıt; o dilde yayında değilse null. */
    public function localize(Record $record, string $locale): ?array
    {
        $data = $record->data;
        if (! $this->visibleIn($data, $locale)) {
            return null;
        }
        $l = $data['i18n'][$locale] ?? [];
        $item = [
            'id' => $record->id,
            'order' => $record->position,
            'date' => Clock::atom($record->createdAt),
            'modified' => Clock::atom($record->updatedAt),
            'slugs' => $this->slugs($data),
        ];
        foreach ($this->shared() as $name => $field) {
            $item[$name] = Fields::present($field, $data[$name] ?? Fields::blank($field), $locale);
        }
        foreach ($this->localized() as $name => $field) {
            $item[$name] = Fields::present($field, $l[$name] ?? Fields::blank($field), $locale);
        }

        return $this->publicItem($item, $locale);
    }

    /** Dil → adres (slug alanı olan türlerde; yalnızca kaydın görünür olduğu diller). */
    public function slugs(array $data): array
    {
        if (! isset($this->localized()['slug'])) {
            return [];
        }
        $out = [];
        foreach (Locales::all() as $locale) {
            $slug = (string) ($data['i18n'][$locale]['slug'] ?? '');
            if ($slug !== '' && $this->visibleIn($data, $locale)) {
                $out[$locale] = $slug;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ diller

    /** İşlem kaydı ve adres geçmişindeki tür adı: km_project → project. */
    public function recordKind(): string
    {
        return (string) preg_replace('/^km_/', '', $this->postType());
    }

    /**
     * Kayda eklenmiş diller (kayıt sırasıyla): kod → ['status' => publish|draft, 'source' => özet|null].
     * Eski veride (`locales` yok) dolu dillerden çıkarılır; dil alanı olmayan türde hiç dolu dil
     * yoksa bütün diller sayılır (ör. yalnızca logosu olan referans).
     */
    public function languages(array $data): array
    {
        $out = [];
        if (isset($data['locales']) && is_array($data['locales'])) {
            foreach (Locales::all() as $code) {
                $l = $data['locales'][$code] ?? null;
                if (is_array($l)) {
                    $out[$code] = ['status' => ($l['status'] ?? '') === 'draft' ? 'draft' : 'publish', 'source' => isset($l['source']) && is_string($l['source']) ? $l['source'] : null];
                }
            }

            return $out;
        }
        $key = $this->localeKey();
        foreach (Locales::all() as $code) {
            $l = (array) ($data['i18n'][$code] ?? []);
            if ($key !== null ? ! empty($l[$key]) : Translations::filled($l)) {
                $out[$code] = ['status' => 'publish', 'source' => null];
            }
        }
        if (! $out && $key === null) {
            foreach (Locales::all() as $code) {
                $out[$code] = ['status' => 'publish', 'source' => null];
            }
        }

        return $out;
    }

    /** `locales` yoksa ekler (kaynak özetleriyle; eskimiş sayılmaz). Göç ve yedekten geri yükleme için. */
    public function withLanguages(array $data): array
    {
        $languages = $this->languages($data);
        $source = $this->sourceLocale($languages);
        foreach ($languages as $code => &$language) {
            $language['source'] = $code === $source ? null : ($language['source'] ?? $this->sourceHash($data, (string) $source));
        }
        unset($language);
        $data['locales'] = $languages;

        return $data;
    }

    /** Kaynak dil: varsayılan dil eklenmişse o, değilse ilk eklenen. */
    public function sourceLocale(array $languages): ?string
    {
        $default = Locales::default();

        return isset($languages[$default]) ? $default : (array_key_first($languages) ?? null);
    }

    /** Bir dilin metinlerinin özeti (adres hariç). */
    public function sourceHash(array $data, string $code): string
    {
        return Translations::hash(Translations::texts($this->localized(), (array) ($data['i18n'][$code] ?? [])));
    }

    /** Kayıt bu dilde sitede görünür mü (kaydın kendi yayın durumu ayrıca denetlenir). */
    public function visibleIn(array $data, string $locale): bool
    {
        $language = $this->languages($data)[$locale] ?? null;
        if ($language === null || $language['status'] !== 'publish') {
            return false;
        }
        $key = $this->localeKey();

        return $key === null || ! empty($data['i18n'][$locale][$key]);
    }

    /** Panel formu: kod → ['status', 'stale']. */
    public function adminLocales(array $data): array
    {
        $out = [];
        foreach ($this->languageStates($data) as $state) {
            $out[$state['code']] = ['status' => $state['status'], 'stale' => $state['stale']];
        }

        return $out;
    }

    /** @return list<array{code:string,status:string,stale:bool,source:bool}> */
    public function languageStates(array $data): array
    {
        $languages = $this->languages($data);
        $source = $this->sourceLocale($languages);
        $current = $source !== null ? $this->sourceHash($data, $source) : null;
        $out = [];
        foreach ($languages as $code => $language) {
            $out[] = [
                'code' => $code,
                'status' => $language['status'],
                'source' => $code === $source,
                'stale' => $code !== $source && $language['source'] !== null && $language['source'] !== $current,
            ];
        }

        return $out;
    }

    /**
     * Formdan gelen diller: yalnızca kayıtlı diller; durum publish|draft. Gelmezse (eski istemci)
     * önceki diller, o da yoksa dolu diller. `current: true` = "Güncel say" (özet yenilenir).
     */
    private function cleanLanguages(mixed $input, ?array $old, array $data): array
    {
        if (! is_array($input)) {
            // Yeni kayıtta dil bilgisi yoksa varsayılan dil: eksik başlık o dilin alanında gösterilir.
            return $old !== null ? $this->languages($old) : ($this->languages($data) ?: [Locales::default() => ['status' => 'publish', 'source' => null]]);
        }
        $out = [];
        foreach (Locales::all() as $code) {
            $l = $input[$code] ?? null;
            if (is_array($l)) {
                $out[$code] = ['status' => ($l['status'] ?? '') === 'draft' ? 'draft' : 'publish', 'current' => ! empty($l['current'])];
            }
        }

        return $out;
    }

    /**
     * Kaynak özetleri: kaynak dilin özeti yok; çeviri yeni eklendiyse, metni değiştiyse ya da
     * "Güncel say" denildiyse bugünkü kaynak özeti yazılır; yoksa önceki özet kalır.
     */
    private function stampSources(array $languages, array $data, ?array $old): array
    {
        $source = $this->sourceLocale($languages);
        $current = $source !== null ? $this->sourceHash($data, $source) : null;
        $before = $old !== null ? $this->languages($old) : [];
        $out = [];
        foreach ($languages as $code => $language) {
            $stamp = null;
            if ($code !== $source) {
                $changed = $old === null || ! isset($before[$code]) || $this->sourceHash($data, $code) !== $this->sourceHash($old, $code);
                $stamp = $changed || ! empty($language['current']) || ($before[$code]['source'] ?? null) === null ? $current : $before[$code]['source'];
            }
            $out[$code] = ['status' => $language['status'], 'source' => $stamp];
        }

        return $out;
    }

    /**
     * Metinlerdeki bağlantıları değiştirir (Seo\Services\LinkChecker). Bağlantı düzeltmesi çeviriyi
     * eskitmez: kaynağa göre güncel olan diller güncel kalır.
     *
     * @param  array<string,string>  $map  eski href → yeni href
     */
    public function replaceLinks(int $id, array $map): bool
    {
        $record = $this->find($id);
        if ($record === null) {
            return false;
        }
        $data = $record->data;
        $source = $this->sourceLocale($this->languages($data));
        $before = $source !== null ? $this->sourceHash($data, $source) : null;
        $changed = false;
        foreach ((array) ($data['i18n'] ?? []) as $code => $values) {
            array_walk_recursive($values, static function (&$value) use ($map, &$changed) {
                if (is_string($value) && ($swapped = Translations::swapLinks($value, $map)) !== $value) {
                    $value = $swapped;
                    $changed = true;
                }
            });
            $data['i18n'][$code] = $values;
        }
        if (! $changed) {
            return false;
        }
        if ($source !== null && isset($data['locales'])) {
            $after = $this->sourceHash($data, $source);
            foreach ($data['locales'] as $code => $state) {
                if (($state['source'] ?? null) === $before) {
                    $data['locales'][$code]['source'] = $after;
                }
            }
        }
        ContentRepository::update($id, $this->postType(), ['data' => $data]);
        $this->changed();

        return true;
    }

    /** Adresi değişen dillerin eski adresi geçmişe yazılır (sitede 301); yeni adres geçmişten düşer. */
    private function rememberSlugs(int $id, array $old, array $new): void
    {
        if (! isset($this->localized()['slug'])) {
            return;
        }
        foreach (Locales::all() as $code) {
            $before = (string) ($old['i18n'][$code]['slug'] ?? '');
            $after = (string) ($new['i18n'][$code]['slug'] ?? '');
            if ($after !== '') {
                SlugHistory::forget($this->recordKind(), $code, $after);
            }
            if ($before !== '' && $before !== $after) {
                SlugHistory::remember($this->recordKind(), $code, $before, $id);
            }
        }
    }

    /** Adres (slug) ile kayıt; yalnızca yayındakiler. */
    public function findBySlug(string $slug, string $locale): ?Record
    {
        foreach ($this->records(true) as $record) {
            if (($this->slugs($record->data)[$locale] ?? null) === $slug) {
                return $record;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ kayıt sayfası (Types::paged)
    // Türün kendi sayfası varsa (sitenin rotasında `dynamic: '<kayıt adı>'`) çatı bunları kullanır.
    // `$entry` ziyaretçi çıktısıdır (publicItem); tür kendi alanlarına göre üzerine yazar.

    /**
     * Arama motoru varsayılanları (SEO modülünde düzenleme yoksa): başlık ve açıklama.
     *
     * @return array{title:string,description:string}
     */
    public function seoDefaults(array $entry): array
    {
        return ['title' => (string) ($entry['title'] ?? ''), 'description' => (string) ($entry['summary'] ?? '')];
    }

    /** SEO analizinin okuduğu metin (SEO > İçerik puanı). */
    public function seoText(array $entry): string
    {
        return trim((string) ($entry['summary'] ?? '').' '.Text::stripTags((string) ($entry['body'] ?? '')));
    }

    /**
     * Kayıt sayfasının yapısal verisi (schema.org); boş alanlar sonra atılır.
     *
     * @param  array{url:string,description:string,image:string,locale:string,organization:string}  $page
     */
    public function structuredData(array $entry, array $page): array
    {
        return [
            '@type' => 'CreativeWork',
            'name' => $entry['title'] ?? '',
            'description' => $page['description'],
            'url' => $page['url'],
            'inLanguage' => $page['locale'],
            'image' => $page['image'] ?: null,
            'dateCreated' => $entry['date'] ?? null,
            'dateModified' => $entry['modified'] ?? null,
            'creator' => ['@id' => $page['organization']],
        ];
    }

    /**
     * llms-full.txt: kaydın başlığı ve düz metin paragrafları.
     *
     * @return array{title:string,paragraphs:list<string>}
     */
    public function llmsText(array $entry): array
    {
        return [
            'title' => (string) ($entry['title'] ?? ''),
            'paragraphs' => array_values(array_filter([(string) ($entry['summary'] ?? ''), Text::stripTags((string) ($entry['body'] ?? ''))])),
        ];
    }

    /**
     * Site içi aramanın kaydı: adres parçası, başlık, metin.
     *
     * @return array{slug:string,title:string,text:string}
     */
    public function searchItem(array $entry): array
    {
        return ['slug' => (string) ($entry['slug'] ?? ''), 'title' => (string) ($entry['title'] ?? ''), 'text' => (string) ($entry['summary'] ?? '')];
    }

    // ------------------------------------------------------------ yardımcılar

    /** Kayıt değişti: sayfa önbelleği silinir, açık paneller haberdar edilir. */
    protected function changed(): void
    {
        PageCache::flush();
        PulseFile::touch('content');
    }

    /** @param list<Record> $records @return list<int> */
    private function imageIds(array $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            array_push($ids, ...$this->imagesOf($record->data));
        }

        return $ids;
    }

    /**
     * Kaydın ortak görsel alanlarındaki görseller (alan sırasıyla; ör. kapak, galeri): site haritası.
     *
     * @return list<int>
     */
    public function imagesOf(array $data): array
    {
        $ids = [];
        foreach ($this->shared() as $name => $field) {
            if ($field['type'] === 'image') {
                $ids[] = (int) ($data[$name] ?? 0);
            } elseif ($field['type'] === 'gallery') {
                array_push($ids, ...array_map('intval', (array) ($data[$name] ?? [])));
            }
        }

        return $ids;
    }

    private function expandData(array $data): array
    {
        $out = [];
        foreach ($this->shared() as $name => $field) {
            $out[$name] = Fields::expand($field, $data[$name] ?? Fields::blank($field));
        }
        foreach (Locales::all() as $locale) {
            foreach ($this->localized() as $name => $field) {
                $out['i18n'][$locale][$name] = $data['i18n'][$locale][$name] ?? Fields::blank($field);
            }
        }

        return $out;
    }

    /**
     * Boş adresleri kaynak alandan (ör. başlık) üretir; aynı dilde aynı adres
     * başka kayıtta varsa sonuna -2, -3… ekler.
     */
    private function fillSlugs(array $data, int $id): array
    {
        $fields = $this->localized();
        if (! isset($fields['slug'])) {
            return $data;
        }
        $from = $fields['slug']['from'] ?? 'title';
        $taken = [];
        foreach ($this->records(false) as $other) {
            if ($other->id === $id) {
                continue;
            }
            foreach ($other->data['i18n'] ?? [] as $locale => $l) {
                if (! empty($l['slug'])) {
                    $taken[$locale][] = $l['slug'];
                }
            }
        }
        foreach (Locales::all() as $locale) {
            $l = &$data['i18n'][$locale];
            if (($l[$from] ?? '') === '') {
                $l['slug'] = $l['slug'] ?? '';
                unset($l);

                continue;
            }
            $base = ($l['slug'] ?? '') !== '' ? $l['slug'] : Text::slug($l[$from]);
            $slug = $base;
            for ($n = 2; in_array($slug, $taken[$locale] ?? [], true); $n++) {
                $slug = $base.'-'.$n;
            }
            $l['slug'] = $slug;
            unset($l);
        }

        return $data;
    }
}
