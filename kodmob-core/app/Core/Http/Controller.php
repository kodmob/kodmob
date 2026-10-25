<?php

namespace App\Core\Http;

use App\Core\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Uçların tabanı. İşleyiciler düz veri (dizi, ListResult) döner; Laravel JSON'a
 * çevirir. Hatalar istisnayla bildirilir (Core\Exceptions), ErrorRenderer
 * `{ code, message, details }` yanıtına çevirir.
 */
abstract class Controller
{
    protected function created(mixed $data): JsonResponse
    {
        return new JsonResponse($data, 201);
    }

    protected function noContent(): Response
    {
        return response()->noContent();
    }

    /** JSON gövdesi (form gönderimlerinde form alanları). */
    protected function input(Request $request): array
    {
        $json = $request->isJson() ? $request->json()->all() : null;

        return is_array($json) ? $json : $request->post();
    }

    /**
     * CSV yanıtı (Excel'de açılır: UTF-8 BOM + noktalı virgül ayırıcı).
     *
     * @param  array<string,string>  $columns  anahtar => başlık
     * @param  iterable<array<string,mixed>>  $rows
     */
    protected function csv(string $filename, array $columns, iterable $rows): Response
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($columns), ';', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $value = $row[$key] ?? '';
                if (is_bool($value)) {
                    $value = $value ? 'Evet' : 'Hayır';
                } elseif (is_float($value)) {
                    $value = str_replace('.', ',', (string) $value);
                } elseif (! is_scalar($value)) {
                    $value = Text::json($value);
                }
                // CSV formül enjeksiyonuna karşı (=, +, -, @ ile başlayan metin).
                if (is_string($value) && $value !== '' && strpbrk($value[0], '=+-@') !== false && ! is_numeric($value)) {
                    $value = "'".$value;
                }
                $line[] = $value;
            }
            fputcsv($handle, $line, ';', '"', '\\');
        }
        rewind($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Text::fileName($filename).'"',
        ]);
    }
}
