<?php

namespace App\Modules\System;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Clock;
use App\Core\Support\Options;
use App\Core\Support\Paths;

/**
 * Çok dilli panel: panelin bütün metinleri ve sunucunun mesajları tek dosyayla (frontend-lang.json) başka dile geçer.
 *
 * - Katalog: panelin metinleri derlemeden (`km-panel-strings.json`, kodmob-frontend/vite/panel-strings.js) ve sunucunun
 *   kodlu mesajları (`resources/messages.json`, `php artisan kodmob:messages`). Anahtar Türkçe metnin kendisidir.
 * - Dışa aktar: seçilen dilin dosyası; çevrilmiş olanlar dolu, eksikler boş. Doldurulan dosya içe aktarılınca dil
 *   kurulur ya da güncellenir. Türkçe kaynaktır, içe aktarılmaz.
 * - Diller kurulumun tamamınındır (Options::SHARED): alt projeler ana projeninkini kullanır. Kişi dilini Profilim'den
 *   seçer (UserPreferences `panel_language`).
 */
final class PanelLanguage
{
    public const OPTION = 'panel_languages';

    public const FORMAT = 'kodmob-panel-lang/1';

    private const CODE = '/^[a-z]{2}(-[a-z]{2})?$/';

    /** @return array{strings:list<string>,messages:array<string,string>} */
    public static function catalog(): array
    {
        $file = Paths::siteRoot().'/km-panel-strings.json';
        $strings = is_file($file) ? (array) (json_decode((string) file_get_contents($file), true)['strings'] ?? []) : [];
        $messages = is_file($path = resource_path('messages.json')) ? (array) json_decode((string) file_get_contents($path), true) : [];

        return ['strings' => array_values(array_filter($strings, 'is_string')), 'messages' => array_filter($messages, 'is_string')];
    }

    /** @return array<string,array{name:string,strings:array<string,string>,messages:array<string,string>,updated_at?:string}> */
    public static function all(): array
    {
        $stored = Options::get(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** Panelde seçilebilen diller (Türkçe her zaman ilk). @return list<array{code:string,name:string}> */
    public static function choices(): array
    {
        $out = [['code' => 'tr', 'name' => 'Türkçe']];
        foreach (self::all() as $code => $lang) {
            $out[] = ['code' => (string) $code, 'name' => (string) $lang['name']];
        }

        return $out;
    }

    /** Yönetim listesi: dil, çevrilmiş / toplam. */
    public static function summary(): array
    {
        $catalog = self::catalog();
        $total = count($catalog['strings']) + count($catalog['messages']);
        $rows = [];
        foreach (self::all() as $code => $lang) {
            $done = count(array_intersect_key($lang['strings'], array_flip($catalog['strings']))) + count(array_intersect_key($lang['messages'], $catalog['messages']));
            $rows[] = ['code' => (string) $code, 'name' => (string) $lang['name'], 'translated' => $done, 'total' => $total, 'updated_at' => $lang['updated_at'] ?? null];
        }

        return ['languages' => $rows, 'total' => $total, 'strings' => count($catalog['strings']), 'messages' => count($catalog['messages'])];
    }

    /** Çalışma anı sözlüğü (panel/i18n.js). */
    public static function dictionary(string $code): array
    {
        $lang = self::all()[$code] ?? null;

        return ['code' => $code, 'strings' => (object) ($lang['strings'] ?? []), 'messages' => (object) ($lang['messages'] ?? [])];
    }

    /** frontend-lang.json: katalogdaki her metin, varsa çevirisiyle. */
    public static function export(string $code, string $name = ''): array
    {
        $code = strtolower(trim($code));
        if (! preg_match(self::CODE, $code) || $code === 'tr') {
            throw ValidationException::field('code', 'Dil kodu iki harf olmalı (ör. en, de, ar); Türkçe kaynak dildir.');
        }
        $catalog = self::catalog();
        $lang = self::all()[$code] ?? ['name' => '', 'strings' => [], 'messages' => []];
        $strings = [];
        foreach ($catalog['strings'] as $text) {
            $strings[$text] = (string) ($lang['strings'][$text] ?? '');
        }
        $messages = [];
        foreach ($catalog['messages'] as $key => $text) {
            $messages[$key] = ['tr' => $text, 'text' => (string) ($lang['messages'][$key] ?? '')];
        }

        return [
            'format' => self::FORMAT,
            'language' => $code,
            'name' => trim($name) !== '' ? trim($name) : ($lang['name'] ?: strtoupper($code)),
            'help' => 'Her "strings" anahtarının karşısına çevirisini, her "messages" kaydının "text" alanına mesajın çevirisini yazın; boş bırakılan Türkçe kalır. {…} yer tutucularını değiştirmeyin. Dosyayı Sistem Ayarları › Panel dili › İçe aktar ile yükleyin.',
            'strings' => (object) $strings,
            'messages' => (object) $messages,
        ];
    }

    /** İçe aktarır (dil yoksa kurar, varsa günceller): boş çeviriler atlanır. @return array{code:string,name:string,strings:int,messages:int} */
    public static function import(mixed $data): array
    {
        if (! is_array($data) || ($data['format'] ?? '') !== self::FORMAT) {
            throw ValidationException::field('file', 'Bu dosya bir panel dili dosyası değil (frontend-lang.json bekleniyor).');
        }
        $code = strtolower(trim((string) ($data['language'] ?? '')));
        if (! preg_match(self::CODE, $code) || $code === 'tr') {
            throw ValidationException::field('file', 'Dosyadaki dil kodu geçersiz (ör. en, de); Türkçe kaynak dildir.');
        }
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 40) ?: strtoupper($code);
        $strings = [];
        foreach ((array) ($data['strings'] ?? []) as $source => $text) {
            if (is_string($source) && is_string($text) && trim($text) !== '' && mb_strlen($source) <= 400 && mb_strlen($text) <= 800) {
                $strings[$source] = trim($text);
            }
        }
        $messages = [];
        foreach ((array) ($data['messages'] ?? []) as $key => $entry) {
            $text = is_array($entry) ? ($entry['text'] ?? '') : $entry;
            if (is_string($key) && preg_match('/^km_[a-z0-9_]{1,80}$/', $key) && is_string($text) && trim($text) !== '' && mb_strlen($text) <= 800) {
                $messages[$key] = trim($text);
            }
        }
        if (! $strings && ! $messages) {
            throw ValidationException::field('file', 'Dosyada çevrilmiş metin yok.');
        }
        $all = self::all();
        $all[$code] = ['name' => $name, 'strings' => $strings, 'messages' => $messages, 'updated_at' => Clock::local(Clock::now())];
        Options::set(self::OPTION, $all);

        return ['code' => $code, 'name' => $name, 'strings' => count($strings), 'messages' => count($messages)];
    }

    public static function remove(string $code): void
    {
        $all = self::all();
        unset($all[$code]);
        Options::set(self::OPTION, $all);
    }

    public static function exists(string $code): bool
    {
        return $code === 'tr' || isset(self::all()[$code]);
    }
}
