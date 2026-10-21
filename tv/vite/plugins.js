/**
 * Kodmob Vite yardımcı eklentileri.
 *
 * Sayfa üretimi, dil yönlendirmesi ve şablonlar vite/i18n-pages.js'tedir.
 *
 * - `htmlMinifier`: yalnızca build'de yorum ve boşlukları temizler (`km:` işaretleri hariç).
 * - `iconSprite`: build'de ikon seti sayfalara gömülmez, ayrı dosyaya çıkar.
 */
import fs from 'node:fs';
import crypto from 'node:crypto';

/**
 * İkon seti (src/shell/icons.html) build'de her sayfaya gömülmez (ana sayfa HTML'inin ~%40'ıydı):
 * ayrı, içeriğe göre adlandırılan ve uzun süre önbelleklenen bir dosyaya çıkar
 * (assets/img/icons-<hash>.svg). HTML'deki bütün `#i-…` başvuruları, Alpine ifadelerinin içindekiler
 * dahil (`'#i-' + item.icon`), o dosyaya çevrilir; JS'in ürettikleri `<html data-icons>`'tan
 * okunur (src/ui/icon.js). İkon stilleri dıştaki `<svg class="icon">`'dadır ve kalıtımla geçer.
 * Geliştirmede set sayfada gömülü kalır.
 */
export function iconSprite({ file }) {
    let url = '';
    return {
        name: 'km-icon-sprite',
        apply: 'build',
        buildStart() {
            // Dosyanın başındaki kullanım örneği (yorumdaki <svg>) eşleşmesin.
            const svg = fs.readFileSync(file, 'utf-8').replace(/<!--[\s\S]*?-->/g, '').match(/<svg[\s\S]*<\/svg>/)?.[0];
            if (!svg) return;
            // Bağımsız dosya: sayfadaki görünmezlik sınıfları ve sıfır boyut gerekmez.
            const source = svg.replace(/<svg[^>]*>/, '<svg xmlns="http://www.w3.org/2000/svg">');
            url = `/assets/img/icons-${crypto.createHash('sha256').update(source).digest('hex').slice(0, 8)}.svg`;
            this.emitFile({ type: 'asset', fileName: url.slice(1), source });
        },
        transformIndexHtml: {
            order: 'post',
            handler(html) {
                if (!url) return html;
                return html
                    .replace(/<svg[^>]*width="0" height="0"[^>]*>[\s\S]*?<\/svg>/, '')
                    .replace(/(["'`])#i-/g, `$1${url}#i-`)
                    .replace(/<html\b/, `<html data-icons="${url}"`);
            },
        },
    };
}

export function htmlMinifier() {
    return {
        name: 'km-html-minifier',
        transformIndexHtml: {
            order: 'post',
            handler(html, ctx) {
                if (!ctx.bundle) return html;
                return html
                    // `<!--km:…-->`: render.php'nin doldurduğu işaretler kalır.
                    .replace(/<!--(?!\[if|\/?km:)[\s\S]*?-->/g, '')
                    // Metin işaretinin (`<!--km:t …-->`) yanındaki boşluk metnin parçasıdır.
                    .replace(/>\s+</g, (match, at, source) => (source.startsWith('--', at - 2) || source.startsWith('<!--', at + match.length - 1) ? '> <' : '><'))
                    .replace(/\s{2,}/g, ' ')
                    .trim();
            },
        },
    };
}
