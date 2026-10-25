<?php

namespace App\Core\Http;

/**
 * Sunucunun kullanıcıya giden mesajları, kodlu: her mesajın sabit kodu metninden türer (`km_m_` + 8 hane), çok dilli
 * panel çeviriyi bu kodla bulur (Modules\System\PanelLanguage, frontend-lang.json). Hata yanıtı `message_code`, alan
 * hataları `details.codes` taşır; iş mantığının kodu (`code`, ör. km_brief_not_found) ayrı kalır.
 *
 * Katalog (`resources/messages.json`) koddan üretilir: `php artisan kodmob:messages` uygulamadaki sabit mesajları
 * toplar (DomainException, ValidationException, alan şeması, hata dizileri). İçinde değişken olan mesaj katalogda
 * yoktur ve Türkçe kalır. Katalog güncel değilse MessagesTest düşer.
 */
final class MessageCatalog
{
    public static function code(string $message): string
    {
        return 'km_m_'.hash('crc32b', $message);
    }

    /** @return array<string,string> kod → mesaj (sıralı) */
    public static function scan(string $dir): array
    {
        $patterns = [
            // new DomainException('kod', 'mesaj'  ·  ValidationException::field('alan', 'mesaj'
            "/new (?:\\w+)?Exception\\(\\s*'[^']*'\\s*,\\s*('(?:[^'\\\\]|\\\\.)*')/",
            "/Exception::field\\(\\s*'[^']*'\\s*,\\s*('(?:[^'\\\\]|\\\\.)*'|\"[^\"\$]*\")/",
            // [durum, 'km_kod', 'mesaj'] (ErrorRenderer) ve benzerleri: kod değişmezinin hemen ardındaki mesaj
            "/'km_[a-z0-9_]+'\\s*,\\s*('(?:[^'\\\\]|\\\\.)*')/",
            // Field::string('mesaj')  ·  Field::enum(fn, 'mesaj')
            "/Field::\\w+\\(\\s*('(?:[^'\\\\]|\\\\.)*')/",
            "/Field::enum\\([^,]+,\\s*('(?:[^'\\\\]|\\\\.)*')/",
            // \$errors['alan'] = 'mesaj';  ·  ValidationException::fields(['alan' => 'mesaj'
            "/\\\$errors\\[[^\\]]+\\]\\s*=\\s*('(?:[^'\\\\]|\\\\.)*'|\"[^\"\$]*\")\\s*;/",
            "/::fields\\(\\[\\s*'[^']*'\\s*=>\\s*('(?:[^'\\\\]|\\\\.)*')/",
        ];
        $messages = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $m);
                foreach ($m[1] as $literal) {
                    $text = self::literal($literal);
                    if ($text !== null && preg_match('/\p{L}{2}/u', $text) && ! str_contains($text, '{$')) {
                        $messages[self::code($text)] = $text;
                    }
                }
            }
        }
        ksort($messages);

        return $messages;
    }

    /** PHP dizgi değişmezi → metin (değişkenli çift tırnak null). */
    private static function literal(string $literal): ?string
    {
        if ($literal === '' || strlen($literal) < 3) {
            return null;
        }
        if ($literal[0] === '"') {
            return str_contains($literal, '$') ? null : stripcslashes(substr($literal, 1, -1));
        }

        return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($literal, 1, -1));
    }
}
