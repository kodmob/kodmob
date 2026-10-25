<?php

namespace App\Modules\Jarvis\Skills;

use App\Core\Exceptions\DomainException;
use App\Core\Http\InternalRequest;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Sözleşmedeki bir panel ucundan Jarvis aracı (docs/done-sozlesme-raporu.md §4.5): araç uca kullanıcının adına içeriden
 * istek atar (Core\Http\InternalRequest), yani yetki, modül kapısı, hız sınırı ve İşlem Geçmişi panelde olduğu gibi.
 * Aracın görünmesi için gereken yetki rotanın `cap:` ara katmanından okunur (biri yeter).
 *
 * Yazma aracı (`write`) hemen çalışmaz: Jarvis önerir, kullanıcı panelde onaylar (Operatör; JarvisActions).
 * `pick` yanıttan modele gidecek kısmı seçer (kişisel veri ve gereksiz alan gitmesin), `describe` önerinin kullanıcıya
 * gösterilen tek cümlesidir.
 */
final class RouteSkill implements Skill
{
    /** Rotanın yetkisi (ilk sorguda okunur). */
    private ?string $capability = null;

    /**
     * @param  array<string,array>  $properties  girdi şemasının alanları; yoldaki {param}'lar da burada
     * @param  list<string>  $required
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly string $description,
        private readonly string $method,
        private readonly string $path,
        private readonly array $properties = [],
        private readonly array $required = [],
        private readonly bool $write = false,
        private readonly ?Closure $pick = null,
        private readonly ?Closure $describe = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description.($this->write ? ' Bu işlem kullanıcı onayıyla çalışır: önce ne yapacağını söyle, panelde onaylanınca uygulanır.' : '');
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) $this->properties, 'required' => $this->required];
    }

    public function write(): bool
    {
        return $this->write;
    }

    /** Onay kartındaki cümle: "Yönlendirme ekle: /eski/ → /yeni/ (301)". */
    public function summary(array $input): string
    {
        return $this->describe ? (string) ($this->describe)($input) : $this->title;
    }

    /** Aracı görmek için gereken yetkiler (rotanın `cap:`'ı; biri yeter), `a|b` biçiminde. */
    public function capability(): string
    {
        if ($this->capability === null) {
            $caps = [];
            foreach ($this->route()?->gatherMiddleware() ?? [] as $m) {
                if (is_string($m) && str_starts_with($m, 'cap:') && $m !== 'cap:km_access_panel') {
                    $caps = explode('|', substr($m, 4));
                }
            }
            // Rota yoksa (modülü kapalı değil, uç kaldırılmış) araç kimseye görünmez.
            $this->capability = $caps ? implode('|', $caps) : ($this->route() ? 'km_access_panel' : 'km_none');
        }

        return $this->capability;
    }

    public function run(array $input): array
    {
        $path = $this->path;
        $rest = $input;
        foreach ($this->placeholders() as $param) {
            $value = (string) ($input[$param] ?? '');
            if (! preg_match('/^[A-Za-z0-9_-]{1,80}$/', $value)) {
                throw new DomainException('km_validation_failed', "Geçersiz {$param}.", 422);
            }
            $path = str_replace('{'.$param.'}', $value, $path);
            unset($rest[$param]);
        }
        $result = InternalRequest::send($this->method, $path, $rest);
        if ($result['status'] >= 400) {
            $body = is_array($result['body']) ? $result['body'] : [];
            throw new DomainException((string) ($body['code'] ?? 'km_tool_failed'), (string) ($body['message'] ?? 'İşlem yapılamadı.'), $result['status'], (array) ($body['details'] ?? []));
        }
        $body = is_array($result['body']) ? $result['body'] : ['result' => $result['body']];

        return $this->pick ? (array) ($this->pick)($body) : $body;
    }

    /** @return list<string> */
    private function placeholders(): array
    {
        preg_match_all('/\{([a-zA-Z_]+)\}/', $this->path, $m);

        return $m[1];
    }

    private function route(): ?Route
    {
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ('/'.$route->uri() === $this->path && in_array($this->method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
