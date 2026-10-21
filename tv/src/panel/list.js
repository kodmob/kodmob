/**
 * İçerik listesi (projeler, hizmetler, referanslar, blog) — hepsi ortak tablo (kmGrid, bellek içi
 * kaynak): arama, durum sekmeleri, çeviri süzgeci, dil rozetleri, genel Sil ve Silinenler, CSV.
 *
 * - Proje, hizmet, referans: solda tutamakla sürükle-bırak sıralama (sıra sitedeki sıradır;
 *   arama/süzgeç yokken ve sıraya göre sıralıyken).
 * - Blog: aynı yazının dillerdeki karşılıkları gruplanır ("Grupla" ile açılıp kapanır).
 * - Canlı: başka kullanıcı (ya da sekme) kayıt ekleyip değiştirince tablonun üstünde "1 yeni
 *   kayıt · 1 güncelleme" şeridi; tıklayınca satırlar sarıdan sönerek gösterilir.
 * - "Çeviri" süzgeci adreste (`?translation=missing`); Çoklu Dil > Sağlık bağlantıları buraya gelir.
 *
 * Liste her yüklemede sunucudan yeniden alınır (küçük listeler); silme `km_delete_content`,
 * Silinenler `km_restore_records` ister (sunucu da denetler).
 */
import { href, navigate } from './routes.js';
import { api } from './api.js';
import { TYPES, localeLabels, activeLocaleCodes } from './meta.js';
import { toast } from '../ui/toast.js';
import { localSource } from '../ui/grid/local-source.js';
import { statusOptions } from '../domain/status.js';

/** Çeviri durumu (süzgeç): güncellenmeli > eksik > tam. */
const TRANSLATION = { stale: 'Güncellenmeli', missing: 'Eksik dil var', complete: 'Tüm dillerde' };

export function kmList(type) {
    const meta = TYPES[type];
    const codes = activeLocaleCodes();
    /** Grup başlığındaki diller: dil kaydının sırasıyla (satır sırası tarihe göre değişir). */
    const rank = (code) => (codes.indexOf(code) === -1 ? 99 : codes.indexOf(code));
    const groupLanguages = (members) => [...new Set(members.map((m) => m.locale || ''))].sort((a, b) => rank(a) - rank(b)).map((c) => c.toUpperCase()).join(' · ');
    const isPosts = type === 'posts';

    /** Dil başına durum: yayında / taslak / güncellenmeli / yok. */
    const languageState = (row, code) => {
        const state = (row?.languages || []).find((l) => l.code === code);
        if (!state) return { tone: 'muted', title: 'Eklenmemiş', key: 'missing' };
        if (state.stale) return { tone: 'warning', title: 'Güncellenmeli: kaynak dil değişti', key: 'stale' };
        return state.status === 'draft' ? { tone: 'neutral', title: 'Taslak', key: 'draft' } : { tone: 'success', title: 'Yayında', key: 'publish' };
    };

    /** Satırın çeviri durumu: sitede açık dillerden biri eksik/taslak mı, kaynak değişti mi. */
    const translationOf = (row) => {
        if (isPosts) {
            if (row.stale) return 'stale';
            const have = (row.languages || []).map((l) => l.code);
            return codes.every((code) => have.includes(code)) ? 'complete' : 'missing';
        }
        const states = codes.map((code) => languageState(row, code).key);
        if (states.includes('stale')) return 'stale';
        return states.every((s) => s === 'publish') ? 'complete' : 'missing';
    };

    const languageChips = (row) => codes.map((code) => {
        const state = isPosts ? postLanguage(row, code) : languageState(row, code);
        return { label: code.toUpperCase(), tone: state.tone, title: `${localeLabels()[code] || code}: ${state.title}` };
    });

    /** Blog: grubun o dildeki yazısı. */
    const postLanguage = (row, code) => {
        const state = (row?.languages || []).find((l) => l.code === code);
        if (!state) return { tone: 'muted', title: 'Çevirisi yok' };
        if (state.id === row.id && row.stale) return { tone: 'warning', title: 'Bu yazı · güncellenmeli' };
        const title = state.id === row.id ? 'Bu yazı' : 'Çevirisi var';
        return state.status === 'publish' ? { tone: 'success', title: `${title} · yayında` } : { tone: 'neutral', title: `${title} · taslak` };
    };

    return {
        type,
        meta,
        items: [],
        grid: null,

        init() {
            this.grid = this.gridConfig();
        },

        /** Listeyi sunucudan alır (tablo her yüklemede ister: canlı şerit ve yenileme güncel veriyi görür). */
        async fetchItems() {
            const items = (await api.list(type)).items;
            this.items = items.map((row) => ({ ...row, translation: translationOf(row) }));
            return this.items;
        },

        gridConfig() {
            const self = this;
            const columns = [
                { key: 'image', label: isPosts ? 'Kapak' : 'Görsel', format: 'image', icon: meta.icon, export: false },
                { key: 'title', label: 'Başlık', strong: true, sortable: true, card: 'title' },
            ];
            if (isPosts) {
                columns.push(
                    { key: 'category', label: 'Kategori', filterBy: 'category', sortable: true, card: 'subtitle', hideBelow: 'lg' },
                    { key: 'locale', label: 'Dil', cell: (r) => localeLabels()[r.locale] || r.locale, filterBy: 'locale', card: 'spec' },
                );
            } else {
                columns.push({ key: 'subtitle', label: 'Açıklama', muted: true, card: 'subtitle', hideBelow: 'lg' });
            }
            columns.push(
                { key: 'languages', label: isPosts ? 'Çeviriler' : 'Diller', chips: languageChips, card: 'spec', hideBelow: 'md' },
                { key: 'status', label: 'Durum', badge: 'content', card: 'badge' },
            );
            if (isPosts) {
                columns.push(
                    { key: 'author', label: 'Yazar', muted: true, hideBelow: 'xl', card: 'meta' },
                    { key: 'published_at', label: 'Yayın tarihi', format: 'date', sortable: true, card: 'spec' },
                );
            } else {
                if (TYPES[type]?.featured) columns.push({ key: 'featured', label: 'Öne çıkan', chips: (r) => (r?.featured ? [{ label: '★ Öne çıkan', tone: 'warning' }] : []), hideBelow: 'xl' });
                columns.push({ key: 'order', label: 'Sıra', format: 'number', sortable: true, hideBelow: 'md', card: false, cell: (r) => String((Number(r.order) || 0) + 1) });
            }
            columns.push({ key: 'modified', label: 'Değişti', format: 'relative', sortable: true, hideBelow: 'lg', card: 'meta', hidden: isPosts });

            const filters = [
                { key: 'q', type: 'search', placeholder: isPosts ? 'Başlık, kategori ya da yazar' : `${meta.label} içinde ara` },
                // Zamanlanmış yayın yalnızca blogda var.
                { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'content', ...(isPosts ? {} : { options: statusOptions('content').filter((o) => o.value !== 'future') }) },
            ];
            if (isPosts) {
                filters.push({ key: 'locale', type: 'select', label: 'Dil', options: Object.entries(localeLabels()).map(([value, label]) => ({ value, label })) });
            }
            if (codes.length > 1) {
                filters.push({ key: 'translation', type: 'select', label: 'Çeviri', options: Object.entries(TRANSLATION).map(([value, label]) => ({ value, label })) });
            }
            if (isPosts) filters.push({ key: 'category', type: 'select', label: 'Kategori' });

            return {
                id: `content-${type}`,
                syncUrl: true,
                source: async (q) => localSource(await self.fetchItems(), {
                    search: isPosts ? ['title', 'category', 'author'] : ['title', 'subtitle'],
                    facets: ['status', 'translation', ...(isPosts ? ['locale', 'category'] : [])],
                    group: isPosts ? 'group' : null,
                })(q),
                recordType: meta.record,
                deleteCan: 'km_delete_content',
                pageSize: isPosts ? 20 : 100,
                sort: isPosts ? { key: 'published_at', order: 'desc' } : { key: 'order', order: 'asc' },
                exportName: type,
                empty: { title: 'Henüz kayıt yok', text: isPosts ? 'Yeni yazı ile başlayın; yayınlamak için Editör ya da Yönetici yetkisi gerekir.' : `${meta.newLabel} ile başlayın.`, icon: meta.icon },
                columns,
                filters,
                rowActions: [{ key: 'edit', label: 'Düzenle', primary: true, open: true }],
                live: { types: [meta.record] },
                groupBy: isPosts
                    ? { key: 'group', label: (row) => row.group_title || row.title, summary: (members) => groupLanguages(members), minSize: 2 }
                    : null,
                reorder: isPosts ? null : {
                    sortKey: 'order',
                    can: meta.can,
                    save: async (keys) => {
                        try {
                            await api.order(type, keys);
                            toast.success('Sıralama kaydedildi; sitede aynı sırayla görünür.');
                        } catch (error) {
                            toast.error(error);
                            throw error;
                        }
                    },
                },
            };
        },

        onGridOpen(row) {
            navigate(href('editor', { type, id: row.id }));
        },
    };
}
