<?php

namespace App\Modules\Blog;

use App\Core\Module;

/**
 * Blog: `posts` tablosu; /panel/'den yazılır (BlogPosts). Her yazı tek dildedir:
 *
 * - Dil (tr/en/de): yazı yalnızca o dilin blogunda çıkar.
 * - Çeviri anahtarı: aynı anahtarı taşıyan yazılar birbirinin çevirisidir;
 *   dil seçici ve hreflang aralarında bağlantı kurar. Boşsa `post-<ID>`.
 * - Kategori: anahtarları ve etiketleri ön yüz sözlüğünde (tv/src/i18n → blog.categories),
 *   sunucuya manifestle gelir (SiteManifest::vocab('blogCategories')).
 */
final class BlogModule extends Module
{
    public const VOCAB = 'blogCategories';
}
