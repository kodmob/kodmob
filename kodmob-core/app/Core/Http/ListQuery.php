<?php

namespace App\Core\Http;

use App\Core\Support\Text;
use Illuminate\Http\Request;

/**
 * Bütün liste uçlarının ortak sorgu sözleşmesi:
 * `page`, `per_page`, `sort`, `order`, `q` ve alan filtreleri.
 *
 * Bu sınıf yalnızca istek verisini normalize eder. Hangi sıralama ve
 * filtrelerin geçerli olduğuna repository'nin beyaz listesi karar verir;
 * listede olmayan anahtarlar SQL'e hiç ulaşmaz.
 */
final class ListQuery
{
    public const MAX_PER_PAGE = 100;

    private const RESERVED = ['page', 'per_page', 'sort', 'order', 'q', 'format', '_fields'];

    /**
     * @param  array<string,string|list<string>>  $filters
     */
    public function __construct(
        public readonly int $page = 1,
        public readonly int $perPage = 20,
        public readonly ?string $sort = null,
        public readonly string $order = 'desc',
        public readonly string $search = '',
        public readonly array $filters = []
    ) {}

    public static function fromRequest(Request $request, int $defaultPerPage = 20): self
    {
        $params = $request->query();

        $filters = [];
        foreach ($params as $key => $value) {
            if (! is_string($key) || in_array($key, self::RESERVED, true)) {
                continue;
            }
            $key = Text::key($key);
            if (is_array($value)) {
                $value = array_values(array_filter(array_map(
                    static fn ($v) => is_scalar($v) ? Text::field((string) $v) : '',
                    $value
                ), static fn ($v) => $v !== ''));
                if ($value) {
                    $filters[$key] = $value;
                }
            } elseif (is_scalar($value) && ($value = Text::field((string) $value)) !== '') {
                $filters[$key] = $value;
            }
        }

        $order = strtolower((string) ($params['order'] ?? 'desc'));

        return new self(
            max(1, (int) ($params['page'] ?? 1)),
            min(self::MAX_PER_PAGE, max(1, (int) ($params['per_page'] ?? $defaultPerPage))),
            isset($params['sort']) ? Text::key((string) $params['sort']) : null,
            $order === 'asc' ? 'asc' : 'desc',
            Text::field((string) ($params['q'] ?? '')),
            $filters
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function filter(string $key): string|array|null
    {
        return $this->filters[$key] ?? null;
    }

    public function withoutFilter(string $key): self
    {
        $filters = $this->filters;
        unset($filters[$key]);

        return new self($this->page, $this->perPage, $this->sort, $this->order, $this->search, $filters);
    }

    /** CSV dışa aktarma gibi sayfasız okumalar için. */
    public function withPage(int $page, int $perPage): self
    {
        return new self(max(1, $page), max(1, $perPage), $this->sort, $this->order, $this->search, $this->filters);
    }
}
