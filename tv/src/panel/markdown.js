/**
 * Jarvis yanıtları için küçük, güvenli Markdown: önce bütün metin HTML'e
 * kaçırılır, sonra yalnızca sınırlı biçimler eklenir (başlık, kalın, italik,
 * satır içi kod, kod bloğu, liste, tablo, bağlantı). Bağlantılar yalnızca
 * http(s) (yeni sekmede) ya da panel içi (`/panel/…`, eski kayıtlarda `#/…`; aynı sekmede,
 * sayfa yenilenmeden) olabilir.
 */
import { toPanelUrl } from './routes.js';

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const escape = (s) => String(s).replace(/[&<>"']/g, (c) => ESC[c]);

function inline(text) {
    return text
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
        .replace(/\[([^\]]+)\]\(((?:https?:\/\/|\/panel\/|#\/)[^\s)]+)\)/g, (m, label, url) => {
            const external = url.startsWith('http');
            // Metin önceden kaçırıldı: panel adresi çevrilmeden önce &amp; geri alınır.
            return `<a href="${external ? url : escape(toPanelUrl(url.replace(/&amp;/g, '&')))}"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}>${label}</a>`;
        });
}

function table(lines) {
    const rows = lines.filter((l) => !/^\s*\|?\s*:?-{2,}/.test(l)).map((l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => inline(c.trim())));
    if (!rows.length) return '';
    const [head, ...body] = rows;
    return `<table><thead><tr>${head.map((c) => `<th>${c}</th>`).join('')}</tr></thead><tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
}

export function markdown(source = '') {
    const out = [];
    const blocks = escape(source).replace(/\r\n/g, '\n').split(/\n```[^\n]*\n([\s\S]*?)\n```/);
    blocks.forEach((block, i) => {
        if (i % 2 === 1) {
            out.push(`<pre><code>${block}</code></pre>`);
            return;
        }
        const lines = block.split('\n');
        let list = null;
        let tableLines = [];
        const flushList = () => {
            if (list) out.push(`<${list.type}>${list.items.map((x) => `<li>${x}</li>`).join('')}</${list.type}>`);
            list = null;
        };
        const flushTable = () => {
            if (tableLines.length) out.push(table(tableLines));
            tableLines = [];
        };
        let paragraph = [];
        const flushParagraph = () => {
            if (paragraph.length) out.push(`<p>${paragraph.map(inline).join('<br>')}</p>`);
            paragraph = [];
        };
        for (const line of lines) {
            if (/^\s*\|.*\|\s*$/.test(line)) {
                flushParagraph();
                flushList();
                tableLines.push(line);
                continue;
            }
            flushTable();
            const heading = line.match(/^(#{1,4})\s+(.*)$/);
            const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
            const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
            if (heading) {
                flushParagraph();
                flushList();
                out.push(`<h${Math.min(4, heading[1].length + 2)}>${inline(heading[2])}</h${Math.min(4, heading[1].length + 2)}>`);
            } else if (bullet || numbered) {
                flushParagraph();
                const type = bullet ? 'ul' : 'ol';
                if (list && list.type !== type) flushList();
                list ??= { type, items: [] };
                list.items.push(inline((bullet || numbered)[1]));
            } else if (!line.trim()) {
                flushParagraph();
                flushList();
            } else {
                flushList();
                paragraph.push(line);
            }
        }
        flushTable();
        flushParagraph();
        flushList();
    });
    return out.join('');
}
