/**
 * Araç Pazarı (/panel/araclar; docs/done-panel-yapisi-raporu.md §6). Araç: projeye eklenen, açılıp kapanan modül
 * (kodmob-api Core\Modules::optional(); `kind`: temel ya da araç; `source`: çatı ya da paket).
 *
 * - Kurulu: projenin araçları (profilin `modules`'ü, alt projede kaydı, ve panelden eklenenler). Projeye göre
 *   aç / kapat: çalışma anında (projenin depolamasında), derleme gerekmez. Kapalı aracın menüsü, yetkileri, uçları
 *   ve site sayfaları yok olur; verisi silinmez. Panelden eklenen "Projeden çıkar"la çıkar.
 * - Keşfet: çatıda olup bu projede kurulu olmayan araçlar ("Projeye ekle": derlemesiz, takıldıklarıyla birlikte;
 *   PUT /system/tools/{key}/install) ve topluluk kataloğundaki paketler (`php artisan kodmob:add <anahtar>`;
 *   docs/arac-paketi.md). Yüklenemeyen paketler uyarı olarak.
 *   Sayfa düzenleyicinin blokları da burada (kategoriyle: Animasyon, Bölümler…): "Kitaplığa ekle" bloğu
 *   düzenleyicide eklenebilir yapar (GET/PUT /admin/blocks; kodmob-api Site\Blocks\BlockLibrary).
 */
import { href, replaceUrl } from './routes.js';
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const TABS = [
    { key: 'installed', label: 'Kurulu', icon: 'package' },
    { key: 'discover', label: 'Keşfet', icon: 'compass' },
];

/** Aracın ikonu (modül anahtarı). */
const ICONS = { blog: 'pencil', briefs: 'mail', estimate: 'clipboard', jarvis: 'sparkles', domains: 'link', sms: 'message', sites: 'layers', commerce: 'cart', booking: 'calendar', hotels: 'home', newsletter: 'send', stripe: 'lock', paytr: 'lock' };

export function kmTools(route = {}) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        tabs: TABS,
        tab: 'installed',
        items: [],
        catalog: [],
        problems: [],
        loading: true,
        busy: null,
        blocks: [],
        blockCategories: {},
        /** Keşfet süzgeci: `all`, `tools` ya da blok kategorisi (animation, section…). */
        filter: 'all',

        async init() {
            this.show(route.tab);
            this._onRoute = (e) => this.show(e.detail?.tab);
            window.addEventListener('km-route', this._onRoute);
            await this.load();
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        show(key) {
            this.tab = TABS.some((t) => t.key === key) ? key : 'installed';
            replaceUrl(href('tools', { tab: this.tab }));
        },

        async load() {
            try {
                const data = await api.tools();
                this.items = data.items;
                this.catalog = data.catalog || [];
                this.problems = data.problems || [];
                // Bloklar (yetki yoksa ya da derlemede kitaplık yoksa boş).
                const blocks = await api.blocks().catch(() => null);
                this.blocks = blocks?.items || [];
                this.blockCategories = blocks?.categories || {};
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        icon(item) {
            return ICONS[item.key] || 'package';
        },

        get installed() {
            return this.items.filter((i) => i.installed);
        },

        get available() {
            return this.items.filter((i) => !i.installed);
        },

        /** Keşfet sekmesindeki sayı: çatının kurulu olmayanları + katalog + kitaplıkta olmayan bloklar. */
        get discoverCount() {
            return this.available.length + this.catalog.length + this.blocks.filter((b) => !b.inLibrary).length;
        },

        /** Süzgeç düğmeleri: Tümü, Araçlar ve blokların kategorileri (yalnızca bloğu olanlar). */
        get filters() {
            const used = new Set(this.blocks.map((b) => b.category));
            return [{ key: 'all', label: 'Tümü' }, { key: 'tools', label: 'Araçlar' }, ...Object.entries(this.blockCategories).filter(([key]) => used.has(key)).map(([key, label]) => ({ key, label }))];
        },

        get shownBlocks() {
            return this.filter === 'all' ? this.blocks : this.blocks.filter((b) => b.category === this.filter);
        },

        categoryLabel(key) {
            return this.blockCategories[key] || key;
        },

        /** Bloğu düzenleyicinin kitaplığına ekler ya da çıkarır (sayfadaki kullanımı silinmez). */
        async toggleBlock(block) {
            if (this.busy) return;
            this.busy = `block:${block.type}`;
            try {
                Object.assign(block, await api.toggleBlock(block.type, !block.inLibrary));
                toast.success(block.inLibrary ? `${block.label} kitaplığa eklendi; Sayfa düzenleyici › Ekle'de.` : `${block.label} kitaplıktan çıkarıldı (sayfalardaki kalır).`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        /** Kartın alt satırı: paketin sürümü, yazarı, lisansı. */
        meta(item) {
            return [item.version && `v${item.version}`, item.author, item.license].filter(Boolean).join(' · ');
        },

        /** Anahtarın konumu: bu projede kapatılmamış (açık ya da takıldığı modülü bekliyor). */
        switchedOn(item) {
            return item.enabled || item.waiting;
        },

        /** Modül anahtarlarından adlar (takıldığı / ona takılı modüller). */
        labels(keys = []) {
            return keys.map((key) => this.items.find((i) => i.key === key)?.label || key).join(', ');
        },

        /** Kartın durum satırı. */
        state(item) {
            if (item.enabled) return 'Bu projede açık';
            if (item.waiting) return `${this.labels(item.requires)} kapalı olduğu için çalışmıyor`;
            return 'Bu projede kapalı';
        },

        /** Eklenince birlikte gelecek (kurulu olmayan) takıldığı araçlar: Oteller → Rezervasyon, Mağaza. */
        missingRequires(item, seen = new Set()) {
            const out = [];
            for (const key of item.requires) {
                const req = this.items.find((i) => i.key === key);
                if (!req || seen.has(key)) continue;
                seen.add(key);
                out.push(...this.missingRequires(req, seen));
                if (!req.installed) out.push(key);
            }
            return out;
        },

        /** Sitedeki sayfaları (araç açıkken görünür): "Mağaza, Sepet, Ödeme…" yerine sayısı. */
        pagesNote(item) {
            return item.pages?.length ? `Sitede ${item.pages.length} sayfası açılır (araç kapalıyken 404).` : '';
        },

        /** Çatının aracını bu projeye ekler (derleme gerekmez; takıldığı araçlar da gelir). */
        async install(item) {
            if (this.busy) return;
            const extra = this.missingRequires(item);
            if (extra.length) {
                const ok = await confirmAction({
                    title: `${item.label} eklensin mi?`,
                    message: `${item.label}, ${this.labels(extra)} aracına takılı; birlikte eklenir. Menüleri ve yetkileri bu projede açılır.`,
                    confirmText: 'Ekle',
                });
                if (!ok) return;
            }
            this.busy = `install:${item.key}`;
            try {
                const res = await api.installTool(item.key, true);
                this.items = res.items;
            } catch (error) {
                toast.error(error);
                return;
            } finally {
                this.busy = null;
            }
            toast.success(`${item.label} eklendi${extra.length ? ` (birlikte: ${this.labels(extra)})` : ''}. Kurulu sekmesinde açılıp kapanır.`);
            await this.$store.session.load();
        },

        /** Panelden eklenmiş aracı çıkarır (verisi silinmez; yeniden eklenince geri gelir). */
        async uninstall(item) {
            if (this.busy) return;
            const ok = await confirmAction({
                title: `${item.label} çıkarılsın mı?`,
                message: 'Menüsü, yetkileri ve sitedeki sayfaları bu projede kapanır; verisi silinmez, yeniden eklenince geri gelir.',
                confirmText: 'Çıkar',
                tone: 'danger',
            });
            if (!ok) return;
            this.busy = `install:${item.key}`;
            try {
                this.items = (await api.installTool(item.key, false)).items;
            } catch (error) {
                toast.error(error);
                return;
            } finally {
                this.busy = null;
            }
            toast.success(`${item.label} çıkarıldı.`);
            await this.$store.session.load();
        },

        /** Geliştiriciler için API'nin OpenAPI belgesi (kodmob-api Core\Contracts): indirilir, herkese açık değil. */
        async openapi() {
            try {
                const doc = await api.openapi();
                const url = URL.createObjectURL(new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' }));
                const link = Object.assign(document.createElement('a'), { href: url, download: 'kodmob-openapi.json' });
                link.click();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (error) {
                toast.error(error);
            }
        },

        async toggle(item) {
            if (this.busy) return;
            const on = !this.switchedOn(item);
            if (!on) {
                const linked = this.items.filter((i) => item.dependents.includes(i.key) && i.enabled);
                const ok = await confirmAction({
                    title: `${item.label} kapatılsın mı?`,
                    message: 'Menüsü, yetkileri ve işlemleri bu projede kapanır; verisi silinmez, yeniden açınca geri gelir.'
                        + (linked.length ? ` Ona takılı ${this.labels(linked.map((i) => i.key))} de çalışmaz.` : ''),
                    confirmText: 'Kapat',
                    tone: 'danger',
                });
                if (!ok) return;
            }
            this.busy = item.key;
            try {
                Object.assign(item, await api.toggleTool(item.key, on));
            } catch (error) {
                toast.error(error);
                return;
            } finally {
                this.busy = null;
            }
            toast.success(on ? `${item.label} açıldı.` : `${item.label} kapatıldı.`);
            // Ona takılı araçların durumu da değişir; menü ve yetkiler oturum bilgisinden (/me) gelir.
            if (item.dependents.length) await this.load();
            await this.$store.session.load();
        },
    };
}
