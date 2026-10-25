<?php

namespace App\Modules\Seo\Services;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\Field;
use App\Core\Http\Schema;
use App\Core\Support\Clock;
use App\Core\Support\Text;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Yönlendirmeler (`seo_redirects`). render.php her ziyarette önce bunlara bakar
 * (SeoConfig derler); eşleşme yoksa sayfa normal açılır.
 *
 * - Kesin eşleşme: `/eski-sayfa` (sondaki / önemsiz, sorgu dizesi karşılaştırılmaz).
 * - Düzenli ifade: `^/blog/(\d+)/(.*)$` → `/blog/$2/` (PCRE, ayraçsız yazılır).
 * - 410: içerik kalıcı olarak kaldırıldı (hedef yok).
 */
final class Redirects
{
    public const CODES = [301 => '301 Kalıcı', 302 => '302 Geçici', 307 => '307 Geçici (yöntem korunur)', 410 => '410 Kaldırıldı'];

    /** Kesin eşleşme anahtarı: baştaki / var, sondaki yok, kök "/". */
    public static function key(string $path): string
    {
        $path = '/'.ltrim(rawurldecode((string) (parse_url($path, PHP_URL_PATH) ?? $path)), '/');

        return rtrim($path, '/') ?: '/';
    }

    /** Kullanıcının yazdığı ifadeden PCRE kalıbı. */
    public static function pattern(string $source): string
    {
        return '#'.str_replace('#', '\#', $source).'#u';
    }

    /**
     * İstek şeması (Core\Http\Schema; OpenAPI POST / PUT /seo/redirects): biçim burada; düzenli ifadenin geçerliliği,
     * yönlendirilemeyen adresler, döngü ve çakışma save()'de.
     */
    public static function schema(): Schema
    {
        return Schema::object([
            'source' => Field::string('Kaynak adres gerekli.')->max(500)->describe('Eski adres (/eski-sayfa/) ya da is_regex ile düzenli ifade.'),
            'target' => Field::string()->optional()->max(1000)->describe('Hedef: / ile başlayan adres ya da tam adres; 410\'da boş.'),
            'code' => Field::enum(static fn () => array_keys(self::CODES), 'Geçersiz yönlendirme türü.')->optional()->default(301),
            'is_regex' => Field::boolean()->default(false),
            'active' => Field::boolean()->default(true),
            'note' => Field::string()->optional()->cut(255),
        ]);
    }

    /** @return array<string,mixed> kayıt */
    public static function save(?int $id, array $input): array
    {
        [$clean, $errors] = self::schema()->check($input);
        $isRegex = (bool) $clean['is_regex'];
        $code = (int) $clean['code'];
        $source = trim((string) ($input['source'] ?? ''));
        $target = trim((string) ($input['target'] ?? ''));

        // Kaynağın biçimi (boş ya da uzun) şemada; düzenli ifade ve yönlendirilemeyen adresler burada.
        if (! isset($errors['source']) && $isRegex) {
            if (@preg_match(self::pattern($source), '') === false) {
                $errors['source'] = 'Düzenli ifade geçersiz.';
            }
        } elseif (! isset($errors['source'])) {
            if (preg_match('#^https?://#i', $source)) {
                $source = (string) (parse_url($source, PHP_URL_PATH) ?: '/');
            }
            $source = self::key($source);
            if (preg_match('#^/(api|panel|media|assets)(/|$)#', $source)) {
                $errors['source'] = 'Panel, API, görsel ve dosya adresleri yönlendirilemez.';
            }
        }
        if ($code !== 410) {
            if ($target === '') {
                $errors['target'] = 'Hedef adres gerekli.';
            } elseif (! str_starts_with($target, '/') && Text::url($target) === '') {
                $errors['target'] = 'Hedef / ile başlamalı ya da tam adres (https://…) olmalı.';
            } elseif (! $isRegex && self::key($target) === $source) {
                $errors['target'] = 'Kaynak ve hedef aynı; sonsuz döngü olur.';
            }
        } else {
            $target = '';
        }
        if (! $errors && ! $isRegex) {
            $clash = DB::table('seo_redirects')->where('is_regex', false)->where('source', $source)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
            if ($clash) {
                $errors['source'] = 'Bu adres için zaten bir yönlendirme var.';
            }
        }
        Schema::fail($errors);

        $values = [
            'source' => $source,
            'target' => mb_substr($target, 0, 1000),
            'code' => $code,
            'is_regex' => $isRegex,
            'active' => (bool) $clean['active'],
            'note' => $clean['note'],
            'updated_at' => Clock::now(),
        ];
        if ($id) {
            if (! DB::table('seo_redirects')->where('id', $id)->update($values) && ! DB::table('seo_redirects')->where('id', $id)->exists()) {
                throw NotFoundException::message('Yönlendirme bulunamadı.');
            }
        } else {
            $id = (int) DB::table('seo_redirects')->insertGetId($values + [
                'position' => (int) DB::table('seo_redirects')->max('position') + 1,
                'created_by' => Auth::id(),
                'created_at' => Clock::now(),
            ]);
        }

        return self::find($id);
    }

    public static function find(int $id): array
    {
        $row = DB::table('seo_redirects')->where('id', $id)->first();
        if (! $row) {
            throw NotFoundException::message('Yönlendirme bulunamadı.');
        }

        return self::present((array) $row);
    }

    public static function delete(int $id): array
    {
        $row = self::find($id);
        DB::table('seo_redirects')->where('id', $id)->delete();

        return $row;
    }

    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'source' => (string) $row['source'],
            'target' => (string) $row['target'],
            'code' => (int) $row['code'],
            'code_label' => self::CODES[(int) $row['code']] ?? (string) $row['code'],
            'is_regex' => (bool) $row['is_regex'],
            'active' => (bool) $row['active'],
            'note' => (string) $row['note'],
            'hits' => (int) $row['hits'],
            'last_hit_at' => Clock::local($row['last_hit_at'] ?? null),
            'created_at' => Clock::local($row['created_at'] ?? null),
        ];
    }

    /**
     * Adres hangi kurala takılır (panelde "Dene")?
     *
     * @return array{rule:?array,location:?string,code:?int}
     */
    public static function test(string $url): array
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $key = self::key($path);
        foreach (DB::table('seo_redirects')->where('active', true)->orderBy('position')->orderBy('id')->get() as $row) {
            if (! $row->is_regex && $row->source === $key) {
                return ['rule' => self::present((array) $row), 'location' => $row->target ?: null, 'code' => (int) $row->code];
            }
        }
        foreach (DB::table('seo_redirects')->where('active', true)->where('is_regex', true)->orderBy('position')->orderBy('id')->get() as $row) {
            if (@preg_match(self::pattern((string) $row->source), $path)) {
                return ['rule' => self::present((array) $row), 'location' => $row->code === 410 ? null : (string) preg_replace(self::pattern((string) $row->source), (string) $row->target, $path), 'code' => (int) $row->code];
            }
        }

        return ['rule' => null, 'location' => null, 'code' => null];
    }

    /** render.php'nin yazdığı tıklanmaları sayaçlara ekler. */
    public static function importHits(): void
    {
        $counts = [];
        $last = [];
        foreach (SeoConfig::drain('hits.log') as $line) {
            [$id, $time] = array_pad(explode("\t", $line, 2), 2, '');
            if (ctype_digit($id)) {
                $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
                $last[(int) $id] = max($last[(int) $id] ?? '', (string) $time);
            }
        }
        foreach ($counts as $id => $n) {
            DB::table('seo_redirects')->where('id', $id)->update([
                'hits' => DB::raw('hits + '.(int) $n),
                'last_hit_at' => $last[$id] !== '' && strtotime($last[$id]) ? date('Y-m-d H:i:s', (int) strtotime($last[$id])) : Clock::now(),
            ]);
        }
    }

    // ------------------------------------------------------------ CSV

    public static function exportCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['source', 'target', 'code', 'regex', 'active', 'note'], ';');
        foreach (DB::table('seo_redirects')->orderBy('position')->orderBy('id')->get() as $r) {
            fputcsv($out, [$r->source, $r->target, $r->code, $r->is_regex ? 1 : 0, $r->active ? 1 : 0, $r->note], ';');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * CSV içe aktarma: başlık satırı isteğe bağlı; ayraç ; ya da , (kendiliğinden).
     * Sütunlar: kaynak, hedef, kod, regex (0/1), aktif (0/1), not.
     *
     * @return array{added:int,updated:int,errors:list<string>}
     */
    public static function importCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $result = ['added' => 0, 'updated' => 0, 'errors' => []];
        foreach ($lines as $n => $line) {
            $cols = str_getcsv($line, $delimiter);
            if ($n === 0 && in_array(strtolower(trim($cols[0] ?? '')), ['source', 'kaynak'], true)) {
                continue;
            }
            if (trim(implode('', $cols)) === '') {
                continue;
            }
            $isRegex = filter_var($cols[3] ?? false, FILTER_VALIDATE_BOOLEAN);
            $existing = ! $isRegex ? DB::table('seo_redirects')->where('is_regex', false)->where('source', self::key((string) ($cols[0] ?? '')))->value('id') : null;
            try {
                self::save($existing ? (int) $existing : null, [
                    'source' => $cols[0] ?? '',
                    'target' => $cols[1] ?? '',
                    'code' => (int) (($cols[2] ?? '') !== '' ? $cols[2] : 301),
                    'is_regex' => $isRegex,
                    'active' => ($cols[4] ?? '1') !== '0',
                    'note' => $cols[5] ?? 'CSV',
                ]);
                $existing ? $result['updated']++ : $result['added']++;
            } catch (ValidationException $e) {
                $fields = $e->details()['fields'] ?? [];
                $result['errors'][] = ($n + 1).'. satır: '.(reset($fields) ?: $e->getMessage());
            }
            if (count($result['errors']) > 50) {
                break;
            }
        }

        return $result;
    }
}
