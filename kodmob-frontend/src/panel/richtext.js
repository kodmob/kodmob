/**
 * Zengin metin editörü (TipTap) — blog yazısının gövdesi.
 *
 *     <div x-data="kmRichText(() => item.html, (v) => item.html = v, () => item.locale)">…</div>
 *
 * Başlık (H2, H3), kalın, italik, liste, alıntı, kod, bağlantı, görsel (medya penceresinden; alt
 * metni yazının dilinde eklenir) ve tablo (var olan tablolar korunur, hücre metni düzenlenir;
 * panel/richtext-table.js). Çıktı sade HTML'dir; sunucu kayıtta
 * izin listesiyle temizler (kodmob-core HtmlSanitizer). TipTap örneği Alpine'ın reaktif durumu dışında
 * tutulur (vekil nesne editörü bozar). Stil enjeksiyonu kapalı: CSP satır içi
 * <style>'a izin vermez; editör stilleri styles/panel.css'tedir.
 */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import { TableNodes } from './richtext-table.js';
import { defaultLocale } from './meta.js';

/**
 * Eski (WordPress) içerikteki blok yorumları (<!-- wp:… -->) ve bloklar arasındaki satır sonları
 * editörde boş paragraf olmasın.
 */
function normalize(html) {
    return String(html || '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/(<\/(?:p|h[1-6]|ul|ol|li|blockquote|pre|figure|table|hr)>|<hr\s*\/?>)\s+(?=<)/gi, '$1')
        .replace(/>\s+(?=<(?:p|h[1-6]|ul|ol|li|blockquote|pre|figure|table|hr)[\s>])/gi, '>')
        .trim();
}

export function kmRichText(get, set, locale = () => 'tr') {
    let editor = null;

    return {
        tick: 0,

        init() {
            editor = new Editor({
                element: this.$refs.area,
                injectCSS: false,
                content: normalize(get()),
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [2, 3] },
                        link: { openOnClick: false, autolink: true, defaultProtocol: 'https', HTMLAttributes: { rel: 'noopener', target: null } },
                    }),
                    Image.configure({ HTMLAttributes: { loading: 'lazy', decoding: 'async' } }),
                    ...TableNodes,
                    Placeholder.configure({ placeholder: 'Yazmaya başlayın… Başlık için araç çubuğunu ya da "## " kısayolunu kullanın.' }),
                ],
                editorProps: { attributes: { class: 'prose rte-content', 'aria-label': 'Yazı içeriği', 'aria-multiline': 'true', role: 'textbox' } },
                onUpdate: ({ editor: e }) => set(e.isEmpty ? '' : e.getHTML()),
                onTransaction: () => {
                    this.tick++;
                },
            });
        },

        destroy() {
            editor?.destroy();
            editor = null;
        },

        /** Araç çubuğu düğmesinin etkin durumu (her işlemde yeniden hesaplanır). */
        is(name, attrs) {
            void this.tick;
            return editor?.isActive(name, attrs) ?? false;
        },

        can(command) {
            void this.tick;
            return editor?.can()[command]?.() ?? false;
        },

        run(command, ...args) {
            editor?.chain().focus()[command](...args).run();
        },

        link() {
            const previous = editor.getAttributes('link').href || '';
            const url = window.prompt('Bağlantı adresi (boş bırakırsanız bağlantı kaldırılır):', previous);
            if (url === null) return;
            const chain = editor.chain().focus().extendMarkRange('link');
            url.trim() === '' ? chain.unsetLink().run() : chain.setLink({ href: url.trim() }).run();
        },

        async image() {
            const picked = await this.$store.media.pick();
            const media = picked?.[0];
            if (!media) return;
            const alt = media.alts?.[locale()] || media.alts?.[defaultLocale()] || '';
            editor.chain().focus().setImage({ src: media.src, alt }).run();
        },
    };
}
