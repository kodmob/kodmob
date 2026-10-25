<?php

namespace App\Core\Setup;

use App\Core\Exceptions\ValidationException;

/**
 * Niyet dosyası (docs/done-sozlesme-raporu.md §4.5): yeni içerik türünü tek JSON dosyasında anlatır; yapay zekâ yazabilir
 * ya da inceleyebilir, üretici (ModuleGenerator) kodu deterministik kurar. Örnek: docs/sablonlar/modul-niyeti.json.
 *
 *   { "name": "courses", "label": "Kurslar", "singular": "Kurs", "icon": "book", "purpose": "…",
 *     "fields": { "shared": { "level": { "type": "select", "label": "Seviye", "options": { "a1": "Başlangıç" } } },
 *                 "localized": { "title": { "type": "text", "label": "Başlık" } } } }
 *
 * Bilinmeyen alan türü ya da anahtarı hatadır (uydurulmuş alan sessizce geçmesin).
 */
final class ModuleIntent
{
    /** Niyet dosyasında desteklenen alan türleri (Core\Content\Fields; ilişki ve satır tablosu sonra). */
    public const TYPES = ['text', 'textarea', 'html', 'url', 'bool', 'int', 'money', 'date', 'select', 'multiselect', 'icon', 'image', 'gallery', 'list'];

    private const KEYS = ['type', 'label', 'help', 'section', 'width', 'max', 'min', 'options'];

    private const TOP = ['name', 'label', 'singular', 'slug', 'record', 'icon', 'orderable', 'bookable', 'purpose', 'fields'];

    /** @return array<string,mixed> ModuleGenerator::plan seçenekleri */
    public static function read(string $file): array
    {
        if (! is_file($file)) {
            throw ValidationException::field('intent', "Niyet dosyası yok: {$file}");
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            throw ValidationException::field('intent', 'Niyet dosyası geçerli JSON değil: '.json_last_error_msg());
        }

        return self::parse($data);
    }

    /** @param array<string,mixed> $data */
    public static function parse(array $data): array
    {
        $errors = [];
        foreach (array_diff(array_keys($data), self::TOP) as $key) {
            $errors[] = "bilinmeyen anahtar: {$key}";
        }
        foreach (['name', 'label', 'singular'] as $key) {
            if (! is_string($data[$key] ?? null) || trim($data[$key]) === '') {
                $errors[] = "{$key} gerekli";
            }
        }
        $fields = ['shared' => [], 'localized' => []];
        foreach ((array) ($data['fields'] ?? []) as $section => $list) {
            if (! isset($fields[$section])) {
                $errors[] = "fields.{$section}: shared ya da localized olmalı";

                continue;
            }
            foreach ((array) $list as $name => $field) {
                $where = "fields.{$section}.{$name}";
                if (! is_string($name) || ! preg_match('/^[a-z][a-zA-Z0-9_]{0,40}$/', $name)) {
                    $errors[] = "{$where}: alan adı küçük harfle başlamalı (harf, rakam, alt çizgi)";

                    continue;
                }
                if (! is_array($field) || ! in_array($field['type'] ?? null, self::TYPES, true)) {
                    $errors[] = "{$where}: tür ".implode(', ', self::TYPES).' olmalı';

                    continue;
                }
                if (! is_string($field['label'] ?? null) || trim($field['label']) === '') {
                    $errors[] = "{$where}: label gerekli";
                }
                foreach (array_diff(array_keys($field), self::KEYS) as $key) {
                    $errors[] = "{$where}: bilinmeyen anahtar {$key}";
                }
                if (in_array($field['type'], ['select', 'multiselect'], true) && (! is_array($field['options'] ?? null) || ! $field['options'])) {
                    $errors[] = "{$where}: seçim alanı için options gerekli";
                }
                $fields[$section][$name] = $field;
            }
        }
        if (isset($fields['shared']['title']) || isset($fields['shared']['slug'])) {
            $errors[] = 'title ve slug dil başına alandır (fields.localized)';
        }
        if ($fields !== ['shared' => [], 'localized' => []] && ! empty($data['bookable'])) {
            $errors[] = 'rezervasyonlu türde alanlar profilden gelir; fields ile bookable birlikte kullanılamaz (şimdilik)';
        }
        if ($errors) {
            throw ValidationException::field('intent', 'Niyet dosyası: '.implode('; ', $errors).'.');
        }

        return array_filter([
            'name' => $data['name'],
            'label' => $data['label'],
            'singular' => $data['singular'],
            'slug' => (string) ($data['slug'] ?? ''),
            'record' => (string) ($data['record'] ?? ''),
            'icon' => (string) ($data['icon'] ?? 'layers'),
            'orderable' => (bool) ($data['orderable'] ?? true),
            'bookable' => (string) ($data['bookable'] ?? ''),
            'purpose' => trim((string) ($data['purpose'] ?? '')),
            'fields' => $fields === ['shared' => [], 'localized' => []] ? null : $fields,
        ], static fn ($v) => $v !== null);
    }
}
