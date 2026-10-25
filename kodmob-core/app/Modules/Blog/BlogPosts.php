<?php

namespace App\Modules\Blog;

use App\Core\Content\EditableContent;
use App\Core\Content\Fields;
use App\Core\Content\Locales;
use App\Core\Content\MediaPayload;
use App\Core\Content\PageCache;
use App\Core\Content\SlugHistory;
use App\Core\Content\Translations;
use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Search\Searchable;
use App\Core\Search\SearchQuery;
use App\Core\Security\Capability;
use App\Core\Support\Clock;
use App\Core\Support\HtmlSanitizer;
use App\Core\Support\SiteManifest;
use App\Core\Support\Text;
use App\Core\Support\UserNames;
use App\Modules\Live\PulseFile;
use App\Modules\Media\MediaLibrary;
use App\Modules\Seo\Services\ContentFilters;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Blog yazıları (`posts` tablosu). Her yazı tek dilde yazılır; aynı çeviri grubundaki
 * (`translation_group`, yönetilen kimlik) yazılar birbirinin çevirisidir (dil seçici ve
 * hreflang aralarında bağlantı kurar). Bir grupta her dilde en fazla bir yazı olur.
 *
 * Çeviriler panelde "Diller" kutusundan yönetilir: oluştur (boş / kopya / Jarvis ile çeviri),
 * var olan yazıyı bağla, gruptan ayır. Grubun kaynağı varsayılan dildeki yazıdır (yoksa ilk
 * yazılan); çeviri kaydedilirken kaynağın özeti `source_hash`'e yazılır, kaynak sonradan
 * değişirse çeviri "Güncellenmeli" görünür (Core\Content\Translations). Adresi ya da dili
 * değişen yazının eski adresi Core\Content\SlugHistory'ye girer (sitede 301).
 *
 * Panel formu diğer içeriklerle aynı şemadan çizilir (dil sekmesi yoktur;
 * dil bir alandır). Yayın tarihi ileriyse yazı "zamanlanmış"tır (`future`):
 * o ana kadar sitede görünmez.
 */
final class BlogPosts implements EditableContent, Searchable
{
    /** Okuma hızı (kelime/dakika). */
    private const WPM = 200;

    /** Yedekten geri yüklemede eski çeviri anahtarı → grup kimliği (bir yükleme boyunca). */
    private array $restoredGroups = [];

    public function fields(): array
    {
        return [
            'title' => ['type' => 'text', 'label' => 'Başlık', 'required' => true, 'section' => 'Yazı'],
            'slug' => ['type' => 'slug', 'label' => 'Adres', 'from' => 'title', 'help' => 'Boşsa başlıktan üretilir: /blog/<adres>/ (İngilizce /en/blog/<adres>/)', 'section' => 'Yazı', 'width' => 'half'],
            'locale' => ['type' => 'select', 'label' => 'Dil', 'options' => Locales::labels(), 'section' => 'Yazı', 'width' => 'half'],
            'excerpt' => ['type' => 'textarea', 'label' => 'Özet', 'max' => 400, 'help' => 'Kartta, aramada ve paylaşım önizlemesinde görünür. Boşsa yazının başından üretilir.', 'section' => 'Yazı'],
            'html' => ['type' => 'html', 'label' => 'İçerik', 'section' => 'Yazı'],
            'cover' => ['type' => 'image', 'label' => 'Kapak görseli', 'help' => 'En az 1600×1000 önerilir.', 'section' => 'Yayın'],
            'category' => ['type' => 'select', 'label' => 'Kategori', 'options' => SiteManifest::vocab(BlogModule::VOCAB), 'section' => 'Yayın'],
            'date' => ['type' => 'date', 'label' => 'Yayın tarihi', 'help' => 'Boşsa kaydedildiği an. İleri bir tarih yazıyı o güne zamanlar.', 'section' => 'Yayın'],
        ];
    }

    public function name(): string
    {
        return 'posts';
    }

    public function recordKind(): string
    {
        return 'post';
    }

    public function capability(): string
    {
        return Capability::MANAGE_POSTS;
    }

    public function capabilityLabel(): string
    {
        return 'Blog yazılarını ekleme ve düzenleme';
    }

    /** Panel araması (Core\Search): başlıkta ya da adreste geçen yazılar, bütün dillerde. */
    public function searchRecords(string $term, int $limit): array
    {
        $rows = SearchQuery::anyLike(DB::table('posts')->whereNull('deleted_at'), ['title', 'slug'], $term)
            ->orderByDesc('updated_at')->limit($limit)->get(['id', 'title', 'slug', 'locale', 'status']);

        return $rows->map(static fn ($r) => [
            'id' => (int) $r->id, 'title' => (string) $r->title, 'subtitle' => strtoupper((string) $r->locale).' · '.$r->slug, 'badge' => SearchQuery::statusBadge((string) $r->status),
        ])->all();
    }

    public function labels(): array
    {
        return ['Blog', 'Yazı'];
    }

    public function schema(): array
    {
        return [
            'type' => 'post',
            'labels' => ['plural' => 'Blog', 'singular' => 'Yazı'],
            'locales' => Locales::labels(),
            'localeKey' => null,
            'shared' => Fields::schema($this->fields()),
            'localized' => [],
        ];
    }

    // ------------------------------------------------------------ panel

    public function adminList(): array
    {
        $rows = $this->all();
        MediaLibrary::prime(array_map(static fn ($p) => (int) $p['cover_id'], $rows));
        $authors = UserNames::map(array_column($rows, 'author_id'));
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row['translation_group']][Locales::normalize($row['locale'])] ??= $row;
        }

        $categories = SiteManifest::vocab(BlogModule::VOCAB);

        return array_map(function (array $post) use ($authors, $groups, $categories) {
            $cover = (int) $post['cover_id'];
            $group = $groups[(string) $post['translation_group']] ?? [];

            return [
                'id' => (int) $post['id'],
                'status' => self::status($post),
                'order' => 0,
                'title' => $post['title'] ?: '(Başlıksız)',
                'subtitle' => ($categories[$post['category']] ?? '').' · '.Clock::carbon($post['published_at'])->locale('tr')->translatedFormat('j M Y'),
                'image' => $cover ? MediaPayload::image($cover, 'thumbnail') : null,
                'locales' => [Locales::normalize($post['locale'])],
                // Liste tablosunun dil sütunu: grubun dilleri; bu yazı kaynağına göre eskimiş mi.
                'languages' => array_map(static fn ($code) => ['code' => $code, 'id' => (int) $group[$code]['id'], 'status' => self::status($group[$code])], array_values(array_intersect(Locales::all(), array_keys($group)))),
                'stale' => self::staleIn($post, $group),
                // Panel tablosunda gruplama: aynı yazının dilleri bir arada (başlık: kaynak yazının başlığı).
                'group' => self::translationKey($post),
                'group_title' => (string) ((self::sourceOf($group) ?? $post)['title'] ?? '') ?: '(Başlıksız)',
                'featured' => false,
                'modified' => Clock::atom($post['updated_at']),
                // Panel blog tablosu (ortak tablo, bellek içi kaynak) için
                'locale' => Locales::normalize($post['locale']),
                'category' => $categories[$post['category']] ?? $post['category'],
                'published_at' => Clock::local($post['published_at']),
                'author' => $post['author_id'] ? ($authors[(int) $post['author_id']] ?? null) : null,
            ];
        }, $rows);
    }

    public function adminItem(int $id): array
    {
        $post = $this->get($id);

        return [
            'id' => (int) $post['id'],
            'status' => self::status($post),
            'order' => 0,
            'modified' => Clock::atom($post['updated_at']),
            'title' => $post['title'],
            'slug' => $post['slug'],
            'locale' => Locales::normalize($post['locale']),
            'excerpt' => $post['excerpt'],
            'html' => $post['html'],
            'cover' => Fields::expand(['type' => 'image'], (int) $post['cover_id']),
            'category' => $post['category'] ?: 'web',
            'date' => Clock::carbon($post['published_at'])->format('Y-m-d\TH:i'),
            'translations' => $this->translations($post),
            'i18n' => (object) [],
        ];
    }

    /**
     * "Diller" kutusu: her kayıtlı dil için gruptaki yazı (yoksa id null).
     *
     * @return list<array{code:string,id:?int,title:string,status:?string,current:bool,source:bool,stale:bool}>
     */
    public function translations(array $post): array
    {
        $group = $this->group((string) $post['translation_group']);
        $source = self::sourceOf($group);
        $out = [];
        foreach (Locales::all() as $code) {
            $row = $group[$code] ?? null;
            $out[] = [
                'code' => $code,
                'id' => $row ? (int) $row['id'] : null,
                'title' => $row ? (string) $row['title'] : '',
                'status' => $row ? self::status($row) : null,
                'current' => $row !== null && (int) $row['id'] === (int) $post['id'],
                'source' => $row !== null && $source !== null && (int) $source['id'] === (int) $row['id'],
                'stale' => $row !== null && self::staleIn($row, $group),
            ];
        }

        return $out;
    }

    /**
     * Çeviri oluşturur (taslak): `copy` kaynağın metinleriyle, `empty` yalnızca başlıkla,
     * `ai` verilen çevrilmiş metinlerle (Modules\I18n\ContentTranslator). Kapak ve kategori
     * kaynaktan gelir. Yeni yazının kimliği döner.
     */
    public function createTranslation(int $id, string $locale, string $mode, array $translated = []): int
    {
        $source = $this->get($id);
        if (! Locales::valid($locale)) {
            throw ValidationException::field('locale', 'Geçersiz dil.');
        }
        $group = (string) $source['translation_group'];
        if ($group === '') {
            $group = Translations::newGroup();
            DB::table('posts')->where('id', $id)->update(['translation_group' => $group]);
        }
        $existing = $this->group($group)[$locale] ?? null;
        if ($existing !== null) {
            throw new ConflictException('km_translation_exists', sprintf('Bu yazının %s çevirisi zaten var: "%s".', Locales::labels()[$locale] ?? $locale, $existing['title']), ['id' => (int) $existing['id']]);
        }
        $texts = match ($mode) {
            'copy' => ['title' => $source['title'], 'excerpt' => $source['excerpt'], 'html' => $source['html']],
            'ai' => ['title' => (string) ($translated['title'] ?? ''), 'excerpt' => (string) ($translated['excerpt'] ?? ''), 'html' => (string) ($translated['html'] ?? '')],
            default => ['title' => $source['title'], 'excerpt' => '', 'html' => ''],
        };
        $title = Text::field($texts['title']) ?: (string) $source['title'];
        $sourcePost = self::sourceOf($this->group($group));
        $now = Clock::now();
        $newId = (int) DB::table('posts')->insertGetId([
            'locale' => $locale,
            'translation_group' => $group,
            // Jarvis çevirisi kaynağın bugünkü haline dayanır; kopya ve boş yazı henüz çeviri değildir.
            'source_hash' => $mode === 'ai' && $sourcePost ? self::textHash($sourcePost) : null,
            'slug' => $this->uniqueSlug(Text::slug($title), $locale, 0),
            'title' => $title,
            'excerpt' => Text::textarea($texts['excerpt']),
            'html' => HtmlSanitizer::clean((string) $texts['html']),
            'cover_id' => (int) $source['cover_id'],
            'category' => (string) $source['category'],
            'status' => 'draft',
            'published_at' => $now,
            'author_id' => Auth::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->changed();

        return $newId;
    }

    /** Var olan yazıyı bu yazının çeviri grubuna bağlar (o dilde grupta yazı olmamalı). */
    public function link(int $id, int $otherId): void
    {
        $post = $this->get($id);
        $other = $this->get($otherId);
        if ($id === $otherId) {
            throw ValidationException::field('post_id', 'Yazı kendisine bağlanamaz.');
        }
        $group = (string) $post['translation_group'] ?: Translations::newGroup();
        $locale = Locales::normalize($other['locale']);
        $taken = $this->group($group)[$locale] ?? null;
        if ($taken !== null && (int) $taken['id'] !== $otherId) {
            throw new ConflictException('km_translation_exists', sprintf('Bu grupta %s yazı zaten var: "%s". Önce onu gruptan ayırın.', Locales::labels()[$locale] ?? $locale, $taken['title']));
        }
        DB::table('posts')->whereIn('id', [$id, $otherId])->update(['translation_group' => $group]);
        DB::table('posts')->where('id', $otherId)->update(['source_hash' => null]);
        $this->changed();
    }

    /**
     * Yazıdaki bağlantıları değiştirir (Seo\Services\LinkChecker). Kaynak yazının bağlantı düzeltmesi
     * çevirileri eskitmez: güncel olan çeviriler güncel kalır.
     *
     * @param  array<string,string>  $map  eski href → yeni href
     */
    public function replaceLinks(int $id, array $map): bool
    {
        $post = $this->get($id);
        $values = ['html' => Translations::swapLinks((string) $post['html'], $map), 'excerpt' => Translations::swapLinks((string) $post['excerpt'], $map)];
        if ($values['html'] === $post['html'] && $values['excerpt'] === $post['excerpt']) {
            return false;
        }
        $source = self::sourceOf($this->group((string) $post['translation_group']));
        DB::table('posts')->where('id', $id)->update($values + ['updated_at' => Clock::now()]);
        if ($source !== null && (int) $source['id'] === $id) {
            DB::table('posts')->where('translation_group', $post['translation_group'])->where('source_hash', self::textHash($post))
                ->update(['source_hash' => self::textHash($values + $post)]);
        }
        $this->changed();

        return true;
    }

    /** Yazıyı çeviri grubundan ayırır (kendi grubunu alır). */
    public function unlink(int $id): void
    {
        $this->get($id);
        DB::table('posts')->where('id', $id)->update(['translation_group' => Translations::newGroup(), 'source_hash' => null]);
        $this->changed();
    }

    public function blankItem(): array
    {
        $item = ['id' => 0, 'status' => 'draft', 'order' => 0, 'modified' => null, 'i18n' => (object) []];
        foreach ($this->fields() as $name => $field) {
            $item[$name] = Fields::expand($field, Fields::blank($field));
        }
        $item['locale'] = Locales::default();
        $item['category'] = 'web';

        return $item;
    }

    public function save(?int $id, array $input): int
    {
        $post = $id ? $this->get($id) : null;
        $clean = [];
        foreach ($this->fields() as $name => $field) {
            $clean[$name] = Fields::clean($field, $input[$name] ?? Fields::blank($field));
        }
        $errors = $clean['title'] === '' ? ['title' => 'Başlık gerekli.'] : [];
        $locale = Locales::normalize($clean['locale']);
        // Çeviri grubu: mevcut yazıda kendisininki; yeni yazıda `translation_of` (çevirisi olduğu yazı) ya da yeni grup.
        $group = $post ? ((string) $post['translation_group'] ?: Translations::newGroup()) : $this->groupFor($input['translation_of'] ?? null);
        $groupRows = $this->group($group);
        $clash = $groupRows[$locale] ?? null;
        if ($clash !== null && (int) $clash['id'] !== (int) ($post['id'] ?? 0)) {
            $errors['locale'] = sprintf('Bu yazının %s çevirisi zaten var: "%s".', Locales::labels()[$locale] ?? $locale, $clash['title']);
        }
        $errors += Fields::missingAlts(['cover' => $this->fields()['cover']], $clean);
        $inline = Fields::imagesWithoutAlt($clean['html']);
        if ($inline > 0) {
            $errors['html'] = $inline.' görselin alt metni yok. Görseli seçip yeniden ekleyin ya da kaldırın.';
        }
        if ($errors) {
            throw ValidationException::fields($errors);
        }

        $values = [
            'locale' => $locale,
            'translation_group' => $group,
            'slug' => $this->uniqueSlug($clean['slug'] !== '' ? $clean['slug'] : Text::slug($clean['title']), $locale, $post ? (int) $post['id'] : 0),
            'title' => $clean['title'],
            'excerpt' => $clean['excerpt'],
            'html' => $clean['html'],
            'cover_id' => (int) $clean['cover'],
            'category' => $clean['category'],
            'status' => ($input['status'] ?? '') === 'publish' ? 'publish' : 'draft',
            'updated_at' => Clock::now(),
        ];
        // Panel tarihi site saatiyle yollar; boşsa yeni yazıda şimdi, mevcutta eski tarih kalır.
        if ($clean['date'] !== '') {
            $values['published_at'] = str_replace('T', ' ', $clean['date']).':00';
        } elseif (! $post) {
            $values['published_at'] = Clock::now();
        }

        $values['source_hash'] = $this->stampSource($post, $values, $groupRows, ! empty($input['translation_current']));
        if ($post) {
            if ($post['slug'] !== $values['slug'] || Locales::normalize($post['locale']) !== $locale) {
                SlugHistory::remember('post', Locales::normalize($post['locale']), (string) $post['slug'], (int) $post['id']);
            }
            SlugHistory::forget('post', $locale, $values['slug']);
            DB::table('posts')->where('id', $post['id'])->update($values);
            $postId = (int) $post['id'];
        } else {
            SlugHistory::forget('post', $locale, $values['slug']);
            $values['author_id'] = Auth::id();
            $values['created_at'] = Clock::now();
            $postId = (int) DB::table('posts')->insertGetId($values);
        }
        $this->changed();

        return $postId;
    }

    public function delete(int $id): void
    {
        $this->get($id);
        DB::table('posts')->where('id', $id)->update(['deleted_at' => Clock::now(), 'deleted_by' => Auth::id()]);
        $this->changed();
    }

    /** Çöpten geri alır (Silinenler); adres bu arada başka yazıya verildiyse sonuna sayı eklenir. */
    public function untrash(int $id): bool
    {
        $post = DB::table('posts')->where('id', $id)->whereNotNull('deleted_at')->first();
        if (! $post) {
            return false;
        }
        DB::table('posts')->where('id', $id)->update([
            'deleted_at' => null,
            'deleted_by' => null,
            'slug' => $this->uniqueSlug((string) $post->slug, (string) $post->locale, $id),
        ]);
        $this->changed();

        return true;
    }

    // ------------------------------------------------------------ yedek

    /** @return list<array> panelde görünen bütün yazılar (çöp hariç), yeniden eskiye */
    public function all(): array
    {
        return DB::table('posts')->whereNull('deleted_at')->orderByDesc('published_at')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    public function export(array $post): array
    {
        return [
            'id' => (int) $post['id'],
            'status' => $post['status'] === 'draft' ? 'draft' : 'publish',
            'date' => (string) $post['published_at'],
            'title' => $post['title'],
            'slug' => $post['slug'],
            'excerpt' => $post['excerpt'],
            'html' => $post['html'],
            'cover' => (int) $post['cover_id'],
            'locale' => Locales::normalize($post['locale']),
            'category' => (string) $post['category'],
            'translation_group' => (string) $post['translation_group'],
            'source_hash' => $post['source_hash'] !== null ? (string) $post['source_hash'] : null,
        ];
    }

    /** Yedekten geri yükleme (doğrulamasız; `cover` bu sitedeki görsel kimliği). */
    public function restore(array $record): int
    {
        $locale = Locales::normalize((string) ($record['locale'] ?? ''));
        $category = (string) ($record['category'] ?? '');
        $date = strtotime((string) ($record['date'] ?? '')) ? date('Y-m-d H:i:s', strtotime((string) $record['date'])) : Clock::now();
        $title = Text::field($record['title'] ?? '');
        $slug = Text::slug($record['slug'] ?? '') ?: Text::slug($title);

        // Grup: yedekteki kimlik (eski yedekte serbest metin anahtar → bu yüklemede tek kimliğe eşlenir).
        $key = (string) ($record['translation_group'] ?? $record['translation'] ?? '');
        $group = preg_match('/^[0-9a-f-]{36}$/', $key) ? $key : ($key !== '' ? ($this->restoredGroups[$key] ??= Translations::newGroup()) : Translations::newGroup());

        return (int) DB::table('posts')->insertGetId([
            'locale' => $locale,
            'translation_group' => $group,
            'source_hash' => isset($record['source_hash']) && is_string($record['source_hash']) ? mb_substr($record['source_hash'], 0, 16) : null,
            'slug' => $this->uniqueSlug($slug, $locale, 0),
            'title' => $title,
            'excerpt' => Text::textarea($record['excerpt'] ?? ''),
            'html' => HtmlSanitizer::clean((string) ($record['html'] ?? '')),
            'cover_id' => (int) ($record['cover'] ?? 0),
            'category' => SiteManifest::inVocab(BlogModule::VOCAB, $category) ? $category : (string) array_key_first(SiteManifest::vocab(BlogModule::VOCAB)),
            'status' => ($record['status'] ?? '') === 'publish' ? 'publish' : 'draft',
            'published_at' => $date,
            'author_id' => Auth::id(),
            'created_at' => Clock::now(),
            'updated_at' => Clock::now(),
        ]);
    }

    // ------------------------------------------------------------ ziyaretçi

    /**
     * Bir dildeki yayında yazılar, yeniden eskiye (gövde HTML'i olmadan).
     *
     * @return list<array>
     */
    public function publicList(string $locale): array
    {
        $groups = $this->translationGroups();
        $posts = $this->published($locale);
        MediaLibrary::prime(array_map(static fn ($p) => (int) $p['cover_id'], $posts));

        return array_map(fn (array $post) => $this->present($post, $groups, false), $posts);
    }

    /** Adresle tek yazı (gövdesiyle); bu dilde yoksa null. */
    public function findBySlug(string $slug, string $locale): ?array
    {
        foreach ($this->published($locale) as $post) {
            if ($post['slug'] === $slug) {
                return $this->present($post, $this->translationGroups(), true);
            }
        }

        return null;
    }

    /**
     * Sitemap için: çeviri anahtarı → dil → adres.
     *
     * @return array<string,array<string,string>>
     */
    public function translationGroups(): array
    {
        $groups = [];
        foreach ($this->published(null) as $post) {
            $groups[self::translationKey($post)][Locales::normalize($post['locale'])] = $post['slug'];
        }

        return $groups;
    }

    /**
     * Site haritası için: çeviri anahtarı → dil → yazı (id, slug, updated_at, cover_id).
     *
     * @return array<string,array<string,array>>
     */
    public function sitemapGroups(): array
    {
        $groups = [];
        foreach ($this->published(null) as $post) {
            $groups[self::translationKey($post)][Locales::normalize($post['locale'])] = $post;
        }

        return $groups;
    }

    public static function translationKey(array $post): string
    {
        return ! empty($post['translation_group']) ? (string) $post['translation_group'] : 'post-'.$post['id'];
    }

    /** Metinlerin özeti (eskimiş çeviri karşılaştırması). */
    public static function textHash(array $post): string
    {
        return Translations::hash(['title' => (string) ($post['title'] ?? ''), 'excerpt' => (string) ($post['excerpt'] ?? ''), 'html' => (string) ($post['html'] ?? '')]);
    }

    /** Grubun kaynak yazısı: varsayılan dildeki, yoksa ilk yazılan. @param array<string,array> $group */
    private static function sourceOf(array $group): ?array
    {
        $default = Locales::default();
        if (isset($group[$default])) {
            return $group[$default];
        }
        $first = null;
        foreach ($group as $post) {
            $id = (int) ($post['id'] ?? 0) ?: PHP_INT_MAX;
            if ($first === null || $id < ((int) ($first['id'] ?? 0) ?: PHP_INT_MAX)) {
                $first = $post;
            }
        }

        return $first;
    }

    /** Yazı, grubunun kaynağına göre eskimiş mi (özeti varsa ve tutmuyorsa). */
    public static function staleIn(array $post, array $group): bool
    {
        $source = self::sourceOf($group);

        return $source !== null && (int) $source['id'] !== (int) $post['id'] && ! empty($post['source_hash']) && $post['source_hash'] !== self::textHash($source);
    }

    /** @return array<string,array> grubun yazıları (çöp hariç): dil → satır */
    private function group(string $group): array
    {
        if ($group === '') {
            return [];
        }
        $out = [];
        foreach (DB::table('posts')->whereNull('deleted_at')->where('translation_group', $group)->orderBy('id')->get() as $row) {
            $out[Locales::normalize($row->locale)] ??= (array) $row;
        }

        return $out;
    }

    /** Yeni yazının grubu: çevirisi olduğu yazının grubu, yoksa yeni. */
    private function groupFor(mixed $translationOf): string
    {
        $group = is_numeric($translationOf) ? (string) DB::table('posts')->whereNull('deleted_at')->where('id', (int) $translationOf)->value('translation_group') : '';

        return $group !== '' ? $group : Translations::newGroup();
    }

    /**
     * Kaydedilen yazının kaynak özeti: kaynak yazının kendisinde yok; çeviri yeni yazıldıysa,
     * metni değiştiyse ya da "Güncel say" denildiyse kaynağın bugünkü özeti, değilse önceki.
     */
    private function stampSource(?array $post, array $values, array $group, bool $current): ?string
    {
        $self = ['id' => $post['id'] ?? 0] + $values + ($post ?? []);
        foreach ($group as $code => $row) {
            if ((int) $row['id'] === (int) $self['id']) {
                unset($group[$code]);
            }
        }
        $group[$values['locale']] = $self;
        $source = self::sourceOf($group);
        // Kaydedilen yazı grubun kaynağıysa özeti yok.
        if ($source === null || $source === $self) {
            return null;
        }
        $changed = $post === null || self::textHash($post) !== self::textHash($values) || $current || empty($post['source_hash']);

        return $changed ? self::textHash($source) : (string) $post['source_hash'];
    }

    /** Yayında ama tarihi ileride olan yazı "zamanlanmış"tır. */
    public static function status(array $post): string
    {
        if ($post['status'] === 'publish' && Clock::carbon($post['published_at'])->isFuture()) {
            return 'future';
        }

        return $post['status'];
    }

    /** @return list<array> */
    /** @var array<string,list<array>> istek içi önbellek: dil (ya da '*') → yayındaki yazılar */
    private static array $published = [];

    public static function forget(): void
    {
        self::$published = [];
    }

    private function published(?string $locale): array
    {
        return self::$published[$locale ?? '*'] ??= $this->loadPublished($locale);
    }

    private function loadPublished(?string $locale): array
    {
        $query = DB::table('posts')->whereNull('deleted_at')->where('status', 'publish')->where('published_at', '<=', Clock::now())
            ->orderByDesc('published_at')->orderBy('id');
        if ($locale !== null) {
            $query->where('locale', $locale);
        }

        return $query->get()->map(fn ($r) => (array) $r)->all();
    }

    private function present(array $post, array $groups, bool $withHtml): array
    {
        $html = self::autop((string) $post['html']);
        $text = Text::stripTags($html);
        $words = str_word_count($text, 0, 'ÇçĞğİıÖöŞşÜüÄäß');
        $excerpt = $post['excerpt'] !== '' ? $post['excerpt'] : Text::words($text, 30, '…');
        $translation = self::translationKey($post);
        $locale = Locales::normalize($post['locale']);

        $item = [
            'id' => (int) $post['id'],
            'locale' => $locale,
            'slug' => $post['slug'],
            'slugs' => $groups[$translation] ?? [],
            'title' => $post['title'],
            'excerpt' => html_entity_decode($excerpt, ENT_QUOTES, 'UTF-8'),
            'category' => $post['category'] ?: 'web',
            'date' => Clock::atom($post['published_at']),
            'modified' => Clock::atom($post['updated_at']),
            'minutes' => max(1, (int) ceil($words / self::WPM)),
            'author' => (string) UserNames::of($post['author_id'] ? (int) $post['author_id'] : null),
            'cover' => MediaPayload::image((int) $post['cover_id'], 'large', $locale),
        ];
        if ($withHtml) {
            // SEO modülü: içindekiler ve dış bağlantı öznitelikleri (kayıttaki HTML değişmez).
            $item['html'] = ContentFilters::post($html, $locale);
        }

        return $item;
    }

    /** Blok etiketi olmayan düz metinde boş satırlar paragraf olur. */
    private static function autop(string $html): string
    {
        if (trim($html) === '' || preg_match('/<(p|h[1-6]|ul|ol|blockquote|pre|figure|table|div)[\s>]/i', $html)) {
            return $html;
        }
        $paragraphs = array_filter(array_map('trim', preg_split('/\R\s*\R/', $html) ?: []));

        return implode("\n", array_map(static fn ($p) => '<p>'.nl2br($p, false).'</p>', $paragraphs));
    }

    private function uniqueSlug(string $slug, string $locale, int $id): string
    {
        $slug = $slug !== '' ? $slug : 'yazi';
        $candidate = $slug;
        for ($n = 2; DB::table('posts')->whereNull('deleted_at')->where('locale', $locale)->where('slug', $candidate)->where('id', '<>', $id)->exists(); $n++) {
            $candidate = $slug.'-'.$n;
        }

        return $candidate;
    }

    private function get(int $id): array
    {
        $post = $id ? DB::table('posts')->where('id', $id)->whereNull('deleted_at')->first() : null;
        if (! $post) {
            throw NotFoundException::message('Yazı bulunamadı. Silinmiş olabilir.');
        }

        return (array) $post;
    }

    private function changed(): void
    {
        self::$published = [];
        PageCache::flush();
        PulseFile::touch('content');
    }
}
