<?php

namespace App\Modules\Site\Legal;

use App\Modules\Site\Blocks\Template;
use App\Modules\Site\Render\Router;

/**
 * Yasal belge sayfasının işaretlerini (tv/src/pages/legal/index.njk) doldurur:
 *
 *   <!--km:legal-nav-->      yan menü: açık belgeler gruplarıyla, açık olan işaretli
 *   <!--km:legal-body-->     belgenin metni (LegalPages: özelleştirilmiş ya da şablon)
 *   <!--km:legal-toc-->      içindekiler (h2'lerden)
 *   <!--km:legal-updated-->  son güncelleme tarihi
 *   <!--km:legal-index-->    özet sayfası: grup başına belge kartları
 *
 * Rotanın `legal`'ı belgenin adıdır (km-manifest.json); özet sayfasının rotası `legal`. Modülü kapalı belgenin rotası
 * Renderer'da zaten 404 olur.
 */
final class LegalSsr
{
    private const MARKER = '#<!--km:legal-(nav|body|toc|updated|index)-->.*?<!--/km:legal-\1-->#s';

    public static function apply(string $html, array $route, string $locale, Router $router): string
    {
        if (! str_contains($html, '<!--km:legal-')) {
            return $html;
        }
        $name = (string) ($route['legal'] ?? '');
        $page = $name !== '' ? (new LegalPages($router))->render($name, $locale) : null;
        $e = static fn (string $v) => Template::escape($v);

        return (string) preg_replace_callback(self::MARKER, static function (array $m) use ($name, $page, $locale, $router, $e) {
            return match ($m[1]) {
                'nav' => self::nav($name, $locale, $router),
                'body' => $page['html'] ?? '',
                'toc' => $page && $page['toc'] ? '<ol>'.implode('', array_map(static fn ($t) => '<li><a href="#'.$e($t['id']).'">'.$e($t['title']).'</a></li>', $page['toc'])).'</ol>' : '',
                'updated' => $page ? '<time datetime="'.$e($page['updated']).'">'.$e(LegalPages::date($page['updated'], $locale)).'</time>' : '',
                default => self::index($locale, $router),
            };
        }, $html);
    }

    /** @return array<string,list<array{name:string,title:string,description:string,url:string}>> grup → belgeler */
    private static function groups(string $locale, Router $router): array
    {
        $groups = [];
        foreach (LegalDocs::active() as $name => $doc) {
            $url = $router->url($name, $locale);
            if ($url === null) {
                continue;
            }
            $groups[(string) ($doc['group'] ?? 'general')][] = ['name' => $name, 'title' => LegalDocs::title($doc, $locale), 'description' => LegalDocs::description($doc, $locale), 'url' => $url];
        }

        return $groups;
    }

    private static function nav(string $current, string $locale, Router $router): string
    {
        $e = static fn (string $v) => Template::escape($v);
        $out = '';
        foreach (self::groups($locale, $router) as $group => $docs) {
            $out .= '<p class="legal-nav-group">'.$e(LegalDocs::groupLabel($group, $locale)).'</p><ul>';
            foreach ($docs as $doc) {
                $out .= '<li><a href="'.$e($doc['url']).'"'.($doc['name'] === $current ? ' aria-current="page"' : '').'>'.$e($doc['title']).'</a></li>';
            }
            $out .= '</ul>';
        }

        return $out;
    }

    private static function index(string $locale, Router $router): string
    {
        $e = static fn (string $v) => Template::escape($v);
        $out = '';
        foreach (self::groups($locale, $router) as $group => $docs) {
            $out .= '<section class="legal-index-group"><h2>'.$e(LegalDocs::groupLabel($group, $locale)).'</h2><ul class="legal-index-list">';
            foreach ($docs as $doc) {
                $out .= '<li><a href="'.$e($doc['url']).'" data-legal="'.$e($doc['name']).'"><strong>'.$e($doc['title']).'</strong><span>'.$e($doc['description']).'</span></a></li>';
            }
            $out .= '</ul></section>';
        }

        return $out;
    }
}
