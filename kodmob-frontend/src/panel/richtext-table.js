/**
 * Zengin metin editöründe tablo (panel/richtext.js). TipTap'in tablo eklentisi kurulu değil; bu
 * düğümler olmadan editör tabloyu tanımaz ve hücreleri tek paragrafa düzleştirir (kaydedilince
 * tablo bozulur). Burada yalnızca var olan tabloyu korumak ve hücre metnini düzenlemek için sade
 * bir şema var: `table > thead? tbody > tr > th|td`, hücrede satır içi metin (kalın, bağlantı…).
 *
 * Hücreler yalıtılmış (isolating): Enter hücreyi bölmez, Backspace hücreleri birleştirmez; hücrede
 * yeni satır Shift+Enter. Satır/sütun ekleme yok (sunucu HtmlSanitizer tabloya izin verir;
 * yeni tablo gerekirse HTML'den gelir).
 */
import { Node, mergeAttributes } from '@tiptap/core';

const cellAttributes = (names) => Object.fromEntries(names.map((name) => [name, {
    default: null,
    parseHTML: (element) => element.getAttribute(name),
    renderHTML: (attributes) => (attributes[name] ? { [name]: attributes[name] } : {}),
}]));

const section = (name, tag) => Node.create({
    name,
    content: 'tableRow+',
    parseHTML: () => [{ tag }],
    renderHTML: () => [tag, 0],
});

const cell = (name, tag, attrs) => Node.create({
    name,
    content: 'inline*',
    marks: '_',
    isolating: true,
    addAttributes: () => cellAttributes(attrs),
    parseHTML: () => [{ tag }],
    renderHTML: ({ HTMLAttributes }) => [tag, mergeAttributes(HTMLAttributes), 0],
});

const Table = Node.create({
    name: 'table',
    group: 'block',
    content: 'tableHead? tableBody',
    isolating: true,
    parseHTML: () => [{ tag: 'table' }],
    renderHTML: () => ['table', 0],
    addKeyboardShortcuts() {
        // Hücrede Enter tabloyu bozmasın (hücre bölünüp satırlar eşitsiz kalmasın).
        const inCell = () => this.editor.isActive('tableCell') || this.editor.isActive('tableHeader');
        return { Enter: inCell, 'Mod-Enter': inCell };
    },
});

const TableRow = Node.create({
    name: 'tableRow',
    content: '(tableHeader | tableCell)+',
    parseHTML: () => [{ tag: 'tr' }],
    renderHTML: () => ['tr', 0],
});

export const TableNodes = [
    Table,
    section('tableHead', 'thead'),
    section('tableBody', 'tbody'),
    TableRow,
    cell('tableHeader', 'th', ['scope', 'colspan', 'rowspan']),
    cell('tableCell', 'td', ['colspan', 'rowspan']),
];
