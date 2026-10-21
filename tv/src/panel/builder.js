/**
 * Sayfa düzenleyici (/panel/sayfa-duzenleyici; docs/done-onyuz-raporu.md §4). Sitenin gerçek sayfası çerçevede
 * (builder/stage.js); solda katmanlar ve blok kitaplığı, sağda seçili bloğun alanları.
 *
 * - Her değişiklik bölgenin taslağına kendiliğinden kaydedilir (PUT /admin/layouts/{bölge}); ziyaretçi yalnızca
 *   "Yayınla" sonrasını görür. Taslak bekleyen bölge sayısı yayın düğmesinde.
 * - Değişen blok sunucuda çizilir (POST /admin/layouts/render) ve yalnızca o blok çerçevede değişir.
 * - Sürükle-bırak (builder/drag.js): kitaplıktan ya da katmanlardan sayfaya / katmanlara.
 * - Kısayollar: Ctrl+Z / Ctrl+Y, Delete, Ctrl+D çoğalt, Alt+↑/↓ taşı, Ctrl+C / Ctrl+V (sayfalar arası da),
 *   Ctrl+S taslağı hemen kaydet, Esc seçimi bırak. Yazıya çift tık: yerinde düzenleme.
 * - Jarvis: seçili bloğu isteğe göre yaz, diğer dillere çevir; sayfaya bölüm öner (BlockAssist).
 */
import { api } from './api.js';
import { href, replaceUrl, navigate } from './routes.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { createStage } from './builder/stage.js';
import { startDrag } from './builder/drag.js';
import * as M from './builder/model.js';
import { fmt } from '../domain/format.js';

const DEVICES = [
    { key: 'desktop', label: 'Masaüstü', icon: 'monitor', width: 0 },
    { key: 'tablet', label: 'Tablet', icon: 'smartphone', width: 820 },
    { key: 'mobile', label: 'Telefon', icon: 'smartphone', width: 390 },
];

/** Jarvis'in hazır istekleri (seçili blok). */
const PROMPTS = ['Daha kısa ve net yaz', 'Daha samimi bir dille yaz', 'Kurumsal ve güven veren bir dille yaz', 'Satışa yönelik, harekete geçiren yaz'];

const CLIPBOARD = 'km-builder-clipboard';
const SAVE_DELAY = 700;
const RENDER_DELAY = 160;

const px = (n) => `${Math.round(n)}px`;

export function kmBuilder() {
    // Alpine'ın reaktif vekili dışında: çerçeve, zamanlayıcılar, geçmiş.
    let stage = null;
    const timers = {};
    const renders = {};
    const history = M.createHistory();
    let lastEdit = { key: '', at: 0 };
    let token = { value: '', expires: 0 };
    let scrollKeep = null;

    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _resize: null,
        _key: null,
        _unload: null,
        destroyed: false,
        devices: DEVICES,
        prompts: PROMPTS,
        loading: true,
        built: true,
        defs: {},
        categories: {},
        icons: [],
        pages: [],
        zones: {},
        locales: [],
        defaultLocale: 'tr',
        aiOn: false,
        /** Bloklardaki görsellerin küçük resmi: kimlik → adres. */
        media: {},
        layout: {},
        pageName: '',
        locale: '',
        device: 'desktop',
        mode: 'edit',
        selected: null,
        hover: null,
        insertAt: null,
        tab: 'layers',
        query: '',
        category: 'all',
        pending: {},
        saving: 0,
        savedAt: null,
        frameReady: false,
        frameNote: '',
        boxes: { hover: null, selected: null, insert: null, drop: null, zones: [] },
        layerDrop: null,
        dragging: false,
        canUndo: false,
        canRedo: false,
        pagePicker: false,
        pageQuery: '',
        /** Yeni blok sayfası penceresi (Blok sayfaları; kodmob-api Site/Pages/PageType). */
        newPage: { open: false, title: '', slug: '', busy: false, errors: {} },
        publishing: false,
        menu: false,
        revisions: { open: false, zone: '', items: [], loading: false },
        ai: { busy: false, prompt: '', translating: false, suggest: false, brief: '', suggesting: false },
        leftOpen: false,
        rightOpen: false,
        /** Alan formunu yeniden kurar (geri al, Jarvis, eski hal: zengin metin değeri yalnızca açılışta okur). */
        rev: 0,
        fit: { scale: 1, width: 0 },

        async init() {
            const query = new URLSearchParams(window.location.search);
            try {
                const data = await api.layouts();
                this.built = data.built;
                this.defs = data.blocks || {};
                this.categories = data.categories || {};
                this.icons = data.icons || [];
                this.pages = data.pages || [];
                this.zones = data.zones || {};
                this.locales = data.locales || [];
                this.defaultLocale = data.defaultLocale || this.locales[0] || 'tr';
                this.aiOn = !!data.ai;
                this.media = data.media || {};
                this.layout = Object.fromEntries(Object.entries(this.zones).map(([key, zone]) => [key, M.workingCopy(zone)]));
                const wanted = query.get('sayfa');
                const choosable = this.pages.filter((p) => !p.records);
                this.pageName = choosable.some((p) => p.name === wanted) ? wanted : choosable.find((p) => p.name === 'home')?.name || choosable[0]?.name || '';
                const lang = query.get('dil');
                this.locale = this.locales.includes(lang) ? lang : this.defaultLocale;
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
            // Veri gelmeden ekrandan çıkıldıysa (Panele dön) açılış sürmez.
            if (this.destroyed || !this.pageName) return;

            stage = createStage({
                onReady: () => {
                    if (this.destroyed) return;
                    this.frameReady = true;
                    if (scrollKeep !== null) {
                        stage.scrollTo(scrollKeep);
                        scrollKeep = null;
                    }
                    this.overlay();
                },
                onLayout: () => this.overlay(),
                onSelect: (hit) => this.select(hit),
                onHover: (hit) => {
                    this.hover = hit;
                    this.overlay();
                },
                onInlineStart: (hit) => this.inline(hit),
                onNavigate: (path, hash) => this.followLink(path, hash),
                onKey: (event) => this.key(event),
            });
            this.$nextTick(() => {
                if (this.destroyed || !this.$refs.frame) return;
                stage.mount(this.$refs.frame);
                this.resize();
                this.openPage();
            });
            this._resize = () => this.resize();
            this._key = (event) => this.key(event);
            this._unload = (event) => {
                if (this.pendingCount) {
                    this.flush();
                    event.preventDefault();
                }
            };
            window.addEventListener('resize', this._resize);
            window.addEventListener('keydown', this._key);
            window.addEventListener('beforeunload', this._unload);
            // İşlev biçimi: görünüm kaldırılırken çalışan bir hesap `device is not defined` atmasın.
            this.$watch(() => this.device, () => !this.destroyed && this.$nextTick(() => this.resize()));
        },

        destroy() {
            this.destroyed = true;
            window.removeEventListener('resize', this._resize);
            window.removeEventListener('keydown', this._key);
            window.removeEventListener('beforeunload', this._unload);
            this.flush();
            this.$store.panel.dirty = false;
        },

        // ------------------------------------------------------------ sayfa ve çerçeve
        get page() {
            return this.pages.find((p) => p.name === this.pageName) || null;
        },

        get pageZones() {
            return (this.page?.zones || []).filter((key) => this.zones[key]);
        },

        /** Seçicideki sayfalar: blok sayfasının şablonu (`records`) değil, kayıtları (`record`) listelenir. */
        get filteredPages() {
            const q = this.pageQuery.trim().toLocaleLowerCase('tr');
            return this.pages.filter((p) => !p.records && (!q || p.label.toLocaleLowerCase('tr').includes(q) || p.name.toLowerCase().includes(q)));
        },

        get sitePages() {
            return this.filteredPages.filter((p) => !p.record);
        },

        get blockPages() {
            return this.filteredPages.filter((p) => p.record);
        },

        /** Blok sayfası açılabiliyor mu (derlemede şablonu var). */
        get canCreatePages() {
            return this.pages.some((p) => p.records);
        },

        /** Açık sayfa taslaktaki bir blok sayfası mı (Yayınla onu da yayına alır). */
        get pageIsDraft() {
            return this.page?.record?.status === 'draft';
        },

        /** Sayfanın bu dildeki adresi; kayıt şablonunda yayındaki son kayıt. */
        pageUrl(page = this.page, locale = this.locale) {
            if (!page) return '';
            return page.dynamic ? page.samples?.[locale] || '' : page.paths?.[locale] || '';
        },

        pageHasDraft(page) {
            return (page.zones || []).some((key) => this.zones[key]?.draft || this.pending[key]);
        },

        async previewKey() {
            if (!token.value || token.expires * 1000 - Date.now() < 5 * 60 * 1000) {
                const res = await api.previewToken();
                token = { value: res.token, expires: res.expires };
            }
            return token.value;
        },

        async openPage({ keepScroll = false } = {}) {
            this.selected = null;
            this.hover = null;
            this.insertAt = null;
            this.frameReady = false;
            this.overlay();
            replaceUrl(href('builder', {}, { sayfa: this.pageName, ...(this.locale !== this.defaultLocale ? { dil: this.locale } : {}) }));
            const url = this.pageUrl();
            if (!url) {
                this.frameNote = this.page?.dynamic
                    ? 'Bu kayıt sayfası şablonunu görmek için bu dilde yayında bir kayıt olmalı. Önce bir kayıt yayınlayın.'
                    : this.page?.record
                      ? 'Bu sayfanın bu dilde başlığı yok. Sayfa ayarlarından bu dili ekleyin.'
                      : 'Bu sayfa bu dilde yok.';
                return;
            }
            this.frameNote = '';
            scrollKeep = keepScroll && stage.ready ? stage.scrollY() : null;
            try {
                const key = await this.previewKey();
                stage.load(`${url}${url.includes('?') ? '&' : '?'}km-preview=${key}`);
            } catch (error) {
                toast.error(error);
            }
        },

        async choosePage(name) {
            this.pagePicker = false;
            this.pageQuery = '';
            if (name === this.pageName) return;
            await this.flush();
            this.pageName = name;
            this.openPage();
        },

        setLocale(code) {
            if (code === this.locale) return;
            this.locale = code;
            this.openPage({ keepScroll: true });
        },

        /** Gezinme kipinde sitedeki bağlantı: o sayfa düzenleyicide açılır (yoksa yeni sekmede). */
        followLink(path, hash) {
            const page = this.pages.find((p) => !p.dynamic && !p.records && Object.entries(p.paths || {}).some(([, v]) => v === path));
            if (page) {
                const locale = Object.entries(page.paths).find(([, v]) => v === path)[0];
                this.locale = locale;
                this.choosePage(page.name);
                return;
            }
            window.open(path + (hash || ''), '_blank', 'noopener');
        },

        setMode(mode) {
            this.mode = mode;
            stage?.setMode(mode);
            if (mode !== 'edit') this.selected = null;
            this.overlay();
        },

        /** Cihaz genişliği: çerçeve sahneye sığmazsa küçültülür. */
        resize() {
            const box = this.$refs.viewport;
            if (!box) return;
            const device = DEVICES.find((d) => d.key === this.device);
            const available = box.clientWidth - (device.width ? 48 : 0);
            const width = device.width || box.clientWidth;
            const scale = device.width && width > available ? available / width : 1;
            this.fit = { scale, width };
            stage?.setScale(scale);
        },

        get deviceStyle() {
            const { scale, width } = this.fit;
            if (this.device === 'desktop') return { width: '100%', height: '100%' };
            return { width: px(width), height: `${100 / scale}%`, transform: scale < 1 ? `scale(${scale})` : 'none' };
        },

        // ------------------------------------------------------------ katman (çerçeveler, araç çubuğu)
        overlay() {
            const container = this.$refs.stage;
            if (!stage || !container || !this.frameReady || this.mode !== 'edit') {
                this.boxes = { hover: null, selected: null, insert: null, drop: this.boxes.drop, zones: [] };
                return;
            }
            const sel = this.selected;
            const hov = this.hover && !(sel && sel.zone === this.hover.zone && sel.id === this.hover.id) ? this.hover : null;
            this.boxes = {
                hover: hov ? stage.blockRect(hov.zone, hov.id, container) : null,
                selected: sel ? stage.blockRect(sel.zone, sel.id, container) : null,
                insert: this.insertAt ? this.insertLine(this.insertAt, container) : null,
                drop: this.boxes.drop,
                zones: this.pageZones.map((key) => ({ key, label: this.zoneLabel(key), empty: !(this.layout[key] || []).length, rect: stage.zoneRect(key, container) })).filter((z) => z.rect),
            };
        },

        insertLine({ zone, index }, container) {
            const blocks = this.layout[zone] || [];
            const zr = stage.zoneRect(zone, container);
            if (!zr) return null;
            const at = blocks[index] ? stage.blockRect(zone, blocks[index].id, container) : blocks.length ? stage.blockRect(zone, blocks[blocks.length - 1].id, container) : null;
            const top = blocks[index] && at ? at.top : at ? at.top + at.height : zr.top + zr.height / 2;
            return { top, left: zr.left, width: zr.width };
        },

        box(rect, extra = {}) {
            return rect ? { top: px(rect.top), left: px(rect.left), width: px(rect.width), height: px(rect.height), ...extra } : { display: 'none' };
        },

        line(rect) {
            return rect ? { top: px(rect.top - 2), left: px(rect.left + 8), width: px(Math.max(0, rect.width - 16)) } : { display: 'none' };
        },

        /** Seçili bloğun araç çubuğu: kutunun üstünde; sayfanın en üstündeyse içinde. */
        get toolbarStyle() {
            const r = this.boxes.selected;
            if (!r) return { display: 'none' };
            return { top: px(r.top < 44 ? r.top + 8 : r.top - 40), left: px(Math.max(8, r.left + r.width - 8)) };
        },

        // ------------------------------------------------------------ bloklar
        blocksOf(zone) {
            return this.layout[zone] || [];
        },

        def(type) {
            return this.defs[type] || null;
        },

        zoneLabel(key) {
            return M.zoneLabel(key, this.zones[key], this.page);
        },

        isGlobal: M.isGlobal,

        label(block) {
            return this.def(block.type)?.label || block.type;
        },

        summary(block) {
            return M.summary(block, this.def(block.type), this.locale, this.defaultLocale);
        },

        isSelected(zone, id) {
            return this.selected?.zone === zone && this.selected?.id === id;
        },

        get selectedBlock() {
            return this.selected ? this.blocksOf(this.selected.zone).find((b) => b.id === this.selected.id) || null : null;
        },

        get selectedDef() {
            return this.selectedBlock ? this.def(this.selectedBlock.type) : null;
        },

        select(hit) {
            stage?.endInline(true);
            this.selected = hit && this.blocksOf(hit.zone).some((b) => b.id === hit.id) ? hit : null;
            this.insertAt = null;
            if (this.selected) {
                this.rightOpen = true;
                stage?.reveal(hit.zone, hit.id);
            }
            this.overlay();
        },

        /** Bölgedeki bloğu değiştirir (değişmez nesne; Alpine görsün). */
        replace(zone, block) {
            this.layout[zone] = this.blocksOf(zone).map((b) => (b.id === block.id ? block : b));
        },

        // ------------------------------------------------------------ değişiklik akışı
        snapshot() {
            return M.deepClone(this.layout);
        },

        /** Geri al noktası; aynı alana art arda yazmak tek adımdır. */
        remember(key = '') {
            const now = Date.now();
            if (key && key === lastEdit.key && now - lastEdit.at < 1200) {
                lastEdit.at = now;
                return;
            }
            lastEdit = { key, at: now };
            history.push(this.snapshot());
            this.canUndo = true;
            this.canRedo = false;
        },

        queueSave(zone) {
            this.pending = { ...this.pending, [zone]: true };
            this.$store.panel.dirty = true;
            clearTimeout(timers[zone]);
            timers[zone] = setTimeout(() => this.save(zone), SAVE_DELAY);
        },

        async save(zone) {
            clearTimeout(timers[zone]);
            const sent = JSON.stringify(this.blocksOf(zone));
            this.saving++;
            try {
                const res = await api.saveLayout(zone, this.blocksOf(zone));
                this.zones[zone] = { ...this.zones[zone], draft: res.draft, draftAt: res.draftAt, draftBy: res.draftBy };
                // Kayıt sırasında yeni değişiklik yoksa sunucunun temizlediği hal alınır.
                if (JSON.stringify(this.blocksOf(zone)) === sent) {
                    this.layout[zone] = res.draft.map(M.normalizeBlock);
                    const { [zone]: _, ...rest } = this.pending;
                    this.pending = rest;
                }
                this.savedAt = new Date();
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving--;
                this.$store.panel.dirty = this.pendingCount > 0;
            }
        },

        /** Bekleyen taslakları hemen yazar (sayfa değişimi, yayın, çıkış). */
        async flush() {
            await Promise.all(Object.keys(this.pending).map((zone) => this.save(zone)));
        },

        get pendingCount() {
            return Object.keys(this.pending).length;
        },

        /** Yayında olmayan değişikliği olan bölgeler (sunucudaki taslak ya da kaydı bekleyen). */
        get draftZones() {
            return Object.keys(this.zones).filter((key) => this.zones[key].draft || this.pending[key]);
        },

        get status() {
            if (this.saving || this.pendingCount) return 'Kaydediliyor…';
            if (this.draftZones.length) return 'Taslak kaydedildi';
            if (this.pageIsDraft) return 'Sayfa yayında değil';
            return 'Yayında';
        },

        /** Yayınla düğmesinin sayısı: taslak bölgeler (+ taslaktaki blok sayfası). */
        get publishCount() {
            return this.draftZones.length + (this.pageIsDraft ? 1 : 0);
        },

        // ------------------------------------------------------------ önizlemeyi güncelleme
        /** Tek blok (alan değişti): kısa beklemeyle, yalnızca son istek uygulanır. */
        renderBlock(zone, id, { now = false } = {}) {
            const key = `${zone}:${id}`;
            clearTimeout(renders[key]?.timer);
            const run = async () => {
                const block = this.blocksOf(zone).find((b) => b.id === id);
                if (!block || !stage) return;
                const seq = (renders[key]?.seq || 0) + 1;
                renders[key] = { seq };
                try {
                    const res = await api.renderBlocks({ block, locale: this.locale });
                    if (renders[key]?.seq === seq) {
                        stage.replaceBlock(zone, id, res.html);
                        this.overlay();
                    }
                } catch (error) {
                    toast.error(error);
                }
            };
            if (now) run();
            else renders[key] = { ...(renders[key] || {}), timer: setTimeout(run, RENDER_DELAY) };
        },

        /** Bölgenin tamamı (geri al, yapıştır, eski hal, Jarvis önerisi). */
        async renderZone(zone) {
            if (!stage?.hasZone(zone)) return;
            try {
                const res = await api.renderBlocks({ blocks: this.blocksOf(zone), locale: this.locale });
                stage.replaceZone(zone, res.items);
                this.overlay();
            } catch (error) {
                toast.error(error);
            }
        },

        /** Bloğun veri ihtiyacı (ör. Son yazılar → posts) sayfada yoksa çerçeve taslakla yeniden açılır. */
        async needsReload(def) {
            if (!def?.needs?.length) return false;
            await this.flush();
            this.openPage({ keepScroll: true });
            return true;
        },

        // ------------------------------------------------------------ işlemler
        async insert(type, target = null) {
            const def = this.def(type);
            if (!def) return;
            if (!def.inLibrary) {
                try {
                    Object.assign(def, await api.toggleBlock(type, true));
                    toast.success(`${def.label} kitaplığa eklendi.`);
                } catch (error) {
                    toast.error(error);
                    return;
                }
            }
            const at = target || this.insertAt || M.insertTarget(this.page, this.layout, this.selected);
            if (!at) return;
            this.remember();
            const block = M.blockFromDefaults(def, type);
            const list = [...this.blocksOf(at.zone)];
            list.splice(at.index, 0, block);
            this.layout[at.zone] = list;
            this.queueSave(at.zone);
            this.insertAt = null;
            this.tab = 'layers';
            this.leftOpen = false;
            if (await this.needsReload(def)) {
                this.selected = { zone: at.zone, id: block.id };
                return;
            }
            try {
                const res = await api.renderBlocks({ block, locale: this.locale });
                stage.insertBlock(at.zone, at.index, res.html);
            } catch (error) {
                toast.error(error);
            }
            this.select({ zone: at.zone, id: block.id });
        },

        remove(hit = this.selected) {
            if (!hit) return;
            const list = this.blocksOf(hit.zone);
            const index = list.findIndex((b) => b.id === hit.id);
            if (index === -1) return;
            this.remember();
            this.layout[hit.zone] = list.filter((b) => b.id !== hit.id);
            stage?.removeBlock(hit.zone, hit.id);
            this.queueSave(hit.zone);
            const next = this.layout[hit.zone][index] || this.layout[hit.zone][index - 1];
            this.selected = next ? { zone: hit.zone, id: next.id } : null;
            toast.info('Blok kaldırıldı. Geri almak için Ctrl+Z.');
            this.overlay();
        },

        async duplicate(hit = this.selected) {
            const block = hit && this.blocksOf(hit.zone).find((b) => b.id === hit.id);
            if (!block) return;
            this.remember();
            const copy = M.copyBlock(block);
            const list = [...this.blocksOf(hit.zone)];
            const index = list.findIndex((b) => b.id === hit.id) + 1;
            list.splice(index, 0, copy);
            this.layout[hit.zone] = list;
            this.queueSave(hit.zone);
            const res = await api.renderBlocks({ block: copy, locale: this.locale }).catch((error) => toast.error(error));
            if (res) stage.insertBlock(hit.zone, index, res.html);
            this.select({ zone: hit.zone, id: copy.id });
        },

        toggleHidden(hit = this.selected) {
            const block = hit && this.blocksOf(hit.zone).find((b) => b.id === hit.id);
            if (!block) return;
            this.remember();
            this.replace(hit.zone, { ...block, hidden: !block.hidden });
            stage?.setHidden(hit.zone, hit.id, !block.hidden);
            this.queueSave(hit.zone);
            toast.info(block.hidden ? 'Blok yeniden görünür.' : 'Blok gizlendi: sitede görünmez, burada soluk.');
        },

        /** Bloğu taşır (aynı ya da başka bölge). */
        moveTo(from, to) {
            if (!from || !to) return;
            const next = M.moveBlock(this.layout, from, to);
            if (next === this.layout) return;
            this.remember();
            this.layout = next;
            const index = next[to.zone].findIndex((b) => b.id === from.id);
            stage?.moveBlock(from.zone, from.id, to.zone, index);
            this.queueSave(from.zone);
            if (to.zone !== from.zone) this.queueSave(to.zone);
            this.selected = { zone: to.zone, id: from.id };
            this.$nextTick(() => {
                stage?.reveal(to.zone, from.id);
                this.overlay();
            });
        },

        move(step, hit = this.selected) {
            if (!hit) return;
            const index = this.blocksOf(hit.zone).findIndex((b) => b.id === hit.id);
            const to = index + step;
            if (index === -1 || to < 0 || to >= this.blocksOf(hit.zone).length) return;
            this.moveTo(hit, { zone: hit.zone, index: step > 0 ? to + 1 : to });
        },

        copy(hit = this.selected) {
            const block = hit && this.blocksOf(hit.zone).find((b) => b.id === hit.id);
            if (!block) return;
            try {
                localStorage.setItem(CLIPBOARD, JSON.stringify(block));
                toast.success(`${this.label(block)} kopyalandı; başka bir yere Ctrl+V ile yapıştırın.`);
            } catch {
                // Tarayıcı depolaması kapalı.
            }
        },

        async paste() {
            let block = null;
            try {
                block = JSON.parse(localStorage.getItem(CLIPBOARD) || 'null');
            } catch {
                block = null;
            }
            if (!block?.type || !this.def(block.type)) return;
            const at = this.insertAt || M.insertTarget(this.page, this.layout, this.selected);
            if (!at) return;
            this.remember();
            const copy = M.copyBlock(M.normalizeBlock(block));
            const list = [...this.blocksOf(at.zone)];
            list.splice(at.index, 0, copy);
            this.layout[at.zone] = list;
            this.queueSave(at.zone);
            if (await this.needsReload(this.def(copy.type))) return;
            await this.renderZone(at.zone);
            this.select({ zone: at.zone, id: copy.id });
        },

        async restoreSnapshot(snapshot) {
            const before = this.layout;
            this.layout = snapshot;
            this.canUndo = history.canUndo;
            this.canRedo = history.canRedo;
            for (const zone of Object.keys(snapshot)) {
                if (JSON.stringify(before[zone]) === JSON.stringify(snapshot[zone])) continue;
                this.queueSave(zone);
                if (this.pageZones.includes(zone)) await this.renderZone(zone);
            }
            if (this.selected && !this.selectedBlock) this.selected = null;
            this.rev++;
            this.overlay();
        },

        undo() {
            const prev = history.undo(this.snapshot());
            if (prev) this.restoreSnapshot(prev);
        },

        redo() {
            const next = history.redo(this.snapshot());
            if (next) this.restoreSnapshot(next);
        },

        // ------------------------------------------------------------ alanlar (sağ panel)
        get fields() {
            return this.selectedDef?.fields || [];
        },

        value(field) {
            return M.valueOf(this.selectedBlock, this.selectedDef, field, this.locale, this.defaultLocale);
        },

        /** Alanı yazar; önizlemedeki blok kısa beklemeyle yenilenir. */
        set(field, value) {
            const sel = this.selected;
            const block = this.selectedBlock;
            if (!block) return;
            this.remember(`${sel.zone}:${sel.id}:${field.name}`);
            this.replace(sel.zone, M.setValue(block, field, this.locale, value));
            this.queueSave(sel.zone);
            this.renderBlock(sel.zone, sel.id);
        },

        rows(field) {
            const value = this.value(field);
            return Array.isArray(value) ? value : [];
        },

        setCell(field, index, column, value) {
            const rows = M.deepClone(this.rows(field));
            if (!rows[index]) return;
            rows[index] = { ...rows[index], [column.name]: value };
            this.set(field, rows);
        },

        addRow(field) {
            if (field.max && this.rows(field).length >= field.max) return;
            lastEdit = { key: '', at: 0 };
            this.set(field, [...M.deepClone(this.rows(field)), M.blankRow(field)]);
        },

        removeRow(field, index) {
            lastEdit = { key: '', at: 0 };
            this.set(field, this.rows(field).filter((_, i) => i !== index));
        },

        moveRow(field, index, step) {
            const rows = M.deepClone(this.rows(field));
            const to = index + step;
            if (to < 0 || to >= rows.length) return;
            const [row] = rows.splice(index, 1);
            rows.splice(to, 0, row);
            lastEdit = { key: '', at: 0 };
            this.set(field, rows);
        },

        /** Seçenekler `{ değer: etiket }` → liste. */
        options(field) {
            return Object.entries(field.options || {}).map(([value, label]) => ({ value, label }));
        },

        /** Bağlantı alanı: sitenin sayfaları (`route:<ad>`), blok sayfaları (`page:<kimlik>`) ya da elle adres. */
        get linkPages() {
            return [
                ...this.pages.filter((p) => !p.dynamic && !p.records && !p.record).map((p) => ({ value: `route:${p.name}`, label: p.label })),
                ...this.pages.filter((p) => p.record).map((p) => ({ value: `page:${p.record.id}`, label: `${p.label} · blok sayfası` })),
            ];
        },

        linkKind(value) {
            return /^(route|page):/.test(String(value || '')) ? 'page' : value ? 'url' : 'none';
        },

        async pickImage(field, row = null, column = null) {
            const picked = await this.$store.media.pick({});
            if (!picked?.[0]) return;
            const value = { id: picked[0].id, src: picked[0].thumb || picked[0].src };
            this.media = { ...this.media, [value.id]: value.src };
            if (row === null) this.set(field, value);
            else this.setCell(field, row, column, value);
        },

        /** Görsel alanının küçük resmi: seçilen nesne ya da kayıtlı kimlik (sunucunun `media` haritası). */
        imageSrc(value) {
            if (value && typeof value === 'object') return value.src || this.media[value.id] || '';
            return value ? this.media[value] || '' : '';
        },

        /** Bu dilde kendi metni olmayan (varsayılan dilinkini gösteren) alan. */
        untranslated(field) {
            const block = this.selectedBlock;
            return !!(field.localized && this.locale !== this.defaultLocale && block && block.i18n?.[this.locale]?.[field.name] === undefined && block.i18n?.[this.defaultLocale]?.[field.name] !== undefined);
        },

        // ------------------------------------------------------------ yerinde düzenleme
        inline({ zone, id, path, el }) {
            this.select({ zone, id });
            const block = this.blocksOf(zone).find((b) => b.id === id);
            const def = this.def(block?.type);
            const target = M.resolvePath(def, path);
            if (!target) return;
            const current = target.row === null ? M.valueOf(block, def, target.field, this.locale, this.defaultLocale) : this.rowsValue(block, def, target);
            const type = (target.column || target.field).type;
            if (!['text', 'textarea'].includes(type)) return;
            this.remember();
            stage.beginInline(el, String(current ?? ''), {
                multiline: type === 'textarea',
                onInput: (text) => {
                    const fresh = this.blocksOf(zone).find((b) => b.id === id);
                    if (!fresh) return;
                    this.replace(zone, M.setPath(fresh, def, path, this.locale, this.defaultLocale, text));
                    this.queueSave(zone);
                },
                onDone: () => this.renderBlock(zone, id, { now: true }),
            });
        },

        rowsValue(block, def, target) {
            const rows = M.valueOf(block, def, target.field, this.locale, this.defaultLocale) || [];
            return rows[target.row]?.[target.column.name] ?? '';
        },

        // ------------------------------------------------------------ kitaplık (sol panel › Ekle)
        /** Bu sayfada eklenebilen bloklar: "Kayıt sayfası" blokları (`scope: entry`) yalnızca kayıt sayfası şablonunda. */
        get usable() {
            return Object.entries(this.defs).map(([type, def]) => ({ ...def, type })).filter((b) => b.scope !== 'entry' || this.page?.dynamic);
        },

        get library() {
            const q = this.query.trim().toLocaleLowerCase('tr');
            return this.usable
                .filter((b) => this.category === 'all' || b.category === this.category)
                .filter((b) => !q || `${b.label} ${b.description}`.toLocaleLowerCase('tr').includes(q));
        },

        get libraryCategories() {
            const used = new Set(this.usable.map((d) => d.category));
            return [{ key: 'all', label: 'Tümü' }, ...Object.entries(this.categories).filter(([key]) => used.has(key)).map(([key, label]) => ({ key, label }))];
        },

        /** Kartın küçük resmi yoksa ikonlu yer tutucu. */
        preview(def) {
            return def.preview || '';
        },

        /** Seçili bloğun üstüne (0) ya da altına (1) ekleme yeri. */
        insertNear(offset) {
            const sel = this.selected;
            if (sel) this.openInsert(sel.zone, this.blocksOf(sel.zone).findIndex((b) => b.id === sel.id) + offset);
        },

        openInsert(zone, index) {
            this.insertAt = { zone, index };
            this.tab = 'add';
            this.leftOpen = true;
            this.overlay();
            this.$nextTick(() => this.$refs.libraryQuery?.focus());
        },

        // ------------------------------------------------------------ sürükle-bırak
        /** Kütüphaneden (`type`) ya da katmandan / sahneden (`from`) sürükleme. */
        drag(event, { type = null, from = null }) {
            const def = type ? this.def(type) : this.def(this.blocksOf(from.zone).find((b) => b.id === from.id)?.type);
            if (!def) return;
            let target = null;
            startDrag(event, {
                label: def.label,
                icon: def.icon,
                onStart: () => {
                    this.dragging = true;
                    this.hover = null;
                    this.overlay();
                },
                onMove: (x, y) => {
                    target = this.dropTarget(x, y);
                    this.layerDrop = target?.where === 'layers' ? target : null;
                    this.boxes.drop = target?.where === 'stage' ? target.line : null;
                    stage?.autoscroll(y);
                },
                onDrop: () => {
                    this.dragging = false;
                    this.layerDrop = null;
                    this.boxes.drop = null;
                    if (!target) return;
                    if (type) this.insert(type, { zone: target.zone, index: target.index });
                    else this.moveTo(from, { zone: target.zone, index: target.index });
                },
                onCancel: () => {
                    this.dragging = false;
                    this.layerDrop = null;
                    this.boxes.drop = null;
                },
                onClick: () => (type ? this.insert(type) : this.select(from)),
            });
        },

        dropTarget(x, y) {
            const list = document.elementFromPoint(x, y)?.closest?.('[data-layer-zone]');
            if (list) {
                const zone = list.dataset.layerZone;
                const items = [...list.querySelectorAll('[data-layer-id]')];
                let index = items.findIndex((item) => {
                    const r = item.getBoundingClientRect();
                    return y < r.top + r.height / 2;
                });
                if (index === -1) index = items.length;
                return { where: 'layers', zone, index };
            }
            const hit = stage?.dropAt(x, y, this.$refs.stage, this.pageZones);
            return hit ? { where: 'stage', ...hit } : null;
        },

        // ------------------------------------------------------------ yayın, taslak, geçmiş
        async publish() {
            if (this.publishing) return;
            this.publishing = true;
            try {
                await this.flush();
                const res = await api.publishLayouts(null, this.pageIsDraft ? [this.page.record.id] : []);
                for (const zone of res.published) {
                    this.zones[zone] = { ...this.zones[zone], ...res.zones[zone] };
                }
                const pages = res.pages || [];
                if (pages.length) this.pages = this.pages.map((p) => (pages.includes(p.record?.id) ? { ...p, record: { ...p.record, status: 'publish' } } : p));
                if (this.pageIsDraft) toast.warning('Sayfayı yayına alma yetkiniz yok: bölümler yayınlandı, sayfa taslakta kaldı.');
                else if (pages.length) toast.success('Sayfa yayında. Ziyaretçiler adresinden açabilir.');
                else toast.success(res.published.length ? `Yayınlandı: ${res.published.length} bölge. Ziyaretçiler yeni hali görüyor.` : 'Yayınlanacak değişiklik yok.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.publishing = false;
            }
        },

        async discard() {
            this.menu = false;
            const zones = this.draftZones;
            if (!zones.length) return;
            const ok = await confirmAction({
                title: 'Yayınlanmamış değişiklikler atılsın mı?',
                message: `${zones.length} bölgedeki taslak silinir; sayfalar yayındaki haline döner. Bu geri alınamaz.`,
                confirmText: 'Taslakları at',
                tone: 'danger',
            });
            if (!ok) return;
            Object.keys(timers).forEach((zone) => clearTimeout(timers[zone]));
            this.pending = {};
            try {
                for (const zone of zones) {
                    const res = await api.discardLayout(zone);
                    this.zones[zone] = { ...this.zones[zone], draft: null, published: res.published ?? this.zones[zone].published };
                    this.layout[zone] = M.workingCopy(this.zones[zone]);
                }
                history.clear();
                this.canUndo = this.canRedo = false;
                this.$store.panel.dirty = false;
                this.openPage({ keepScroll: true });
                toast.success('Taslaklar atıldı.');
            } catch (error) {
                toast.error(error);
            }
        },

        async openRevisions(zone) {
            this.menu = false;
            this.revisions = { open: true, zone, items: [], loading: true };
            try {
                this.revisions.items = (await api.layoutRevisions(zone)).items;
            } catch (error) {
                toast.error(error);
            } finally {
                this.revisions.loading = false;
            }
        },

        async restore(item) {
            const zone = this.revisions.zone;
            await this.flush();
            try {
                const res = await api.restoreLayout(zone, item.id);
                this.remember();
                this.zones[zone] = { ...this.zones[zone], draft: res.draft };
                this.layout[zone] = res.draft.map(M.normalizeBlock);
                this.revisions.open = false;
                await this.renderZone(zone);
                toast.success('Eski hal taslağa alındı; beğendiyseniz Yayınla.');
            } catch (error) {
                toast.error(error);
            }
        },

        /** Bölgeyi derlemenin varsayılan bloklarına döndürür (taslak olarak). */
        async resetZone(zone) {
            this.remember();
            this.layout[zone] = M.deepClone(this.zones[zone].defaults || []).map(M.normalizeBlock);
            this.queueSave(zone);
            await this.renderZone(zone);
        },

        // ------------------------------------------------------------ blok sayfaları
        openNewPage() {
            this.pagePicker = false;
            this.newPage = { open: true, title: this.pageQuery.trim(), slug: '', busy: false, errors: {} };
            this.pageQuery = '';
            this.$nextTick(() => this.$refs.newPageTitle?.focus());
        },

        /** Başlıktan önerilen adres (sunucu kayıtta aynı kuralla türetir; elle değiştirilebilir). */
        get newPageSlug() {
            return M.slugify(this.newPage.slug || this.newPage.title);
        },

        /** Yeni sayfanın bu dildeki adresi (önizleme): blok sayfasının kalıbıyla. */
        get newPagePath() {
            const pattern = this.pages.find((p) => p.records)?.paths?.[this.locale] || '/:slug/';
            return pattern.replace(':slug', this.newPageSlug || '…');
        },

        /**
         * Yeni blok sayfası: taslak olarak bu dilde açılır; ilk bölümü sayfanın başlığıyla bir karşılama (taslak).
         * Yayına almak düzenleyicinin Yayınla düğmesinde.
         */
        async createPage() {
            const title = this.newPage.title.trim();
            if (!title || this.newPage.busy) return;
            this.newPage.busy = true;
            this.newPage.errors = {};
            const locale = this.locale;
            try {
                await this.flush();
                const item = await api.create('pages', { status: 'draft', locales: { [locale]: { status: 'publish' } }, cover: 0, i18n: { [locale]: { title, slug: this.newPage.slug.trim(), description: '' } } });
                this.merge(await api.layouts());
                const name = `page@${item.id}`;
                const zone = this.pages.find((p) => p.name === name)?.zones.find((z) => z.includes('@'));
                const hero = this.def('hero');
                if (zone && hero) {
                    const texts = { ...(hero.defaults?.i18n?.[locale] || {}), eyebrow: '', title, titleAccent: '', lead: '', secondary: '' };
                    this.layout[zone] = [M.normalizeBlock({ type: 'hero', data: M.deepClone(hero.defaults?.data || {}), i18n: { [locale]: texts } })];
                    await this.save(zone);
                }
                this.newPage = { open: false, title: '', slug: '', busy: false, errors: {} };
                history.clear();
                this.canUndo = this.canRedo = false;
                this.pageName = name;
                this.openPage();
                toast.success('Sayfa açıldı (taslak). Bölüm ekleyin; hazır olunca Yayınla.');
            } catch (error) {
                this.newPage.errors = error?.fields || {};
                if (!error?.fields) toast.error(error);
            } finally {
                this.newPage.busy = false;
            }
        },

        /** Hata: alan adından (`i18n.tr.slug`) ilk mesaj. */
        newPageError(name) {
            const key = Object.keys(this.newPage.errors).find((k) => k === name || k.endsWith(`.${name}`));
            return key ? this.newPage.errors[key] : '';
        },

        /** Sunucudan gelen yeni sayfa ve bölgeleri ekler (açık düzen ve kaydı bekleyen değişiklikler korunur). */
        merge(data) {
            this.pages = data.pages || this.pages;
            for (const [key, zone] of Object.entries(data.zones || {})) {
                if (this.zones[key]) continue;
                this.zones[key] = zone;
                this.layout[key] = M.workingCopy(zone);
            }
            this.media = { ...this.media, ...(data.media || {}) };
        },

        /** Sayfa ayarları (başlık, adres, açıklama, paylaşım görseli, diller): içerik düzenleyicisinde. */
        pageSettingsUrl() {
            return this.page?.record ? href('editor', { type: 'pages', id: this.page.record.id }) : '';
        },

        // ------------------------------------------------------------ Jarvis
        async assist(instruction = this.ai.prompt) {
            const sel = this.selected;
            const block = this.selectedBlock;
            if (!block || this.ai.busy || !String(instruction || '').trim()) return;
            this.ai.busy = true;
            try {
                const res = await api.assistBlock({ type: block.type, locale: this.locale, values: M.textsOf(block, this.selectedDef, this.locale, this.defaultLocale), instruction });
                const fresh = this.blocksOf(sel.zone).find((b) => b.id === sel.id);
                if (!fresh) return;
                this.remember();
                this.replace(sel.zone, M.applyTexts(fresh, this.def(fresh.type), this.locale, this.defaultLocale, res.texts));
                this.queueSave(sel.zone);
                this.renderBlock(sel.zone, sel.id, { now: true });
                this.ai.prompt = '';
                this.rev++;
                toast.success('Jarvis metni yazdı. Beğenmezseniz Ctrl+Z.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.ai.busy = false;
            }
        },

        async translate() {
            const sel = this.selected;
            const block = this.selectedBlock;
            const to = this.locales.filter((code) => code !== this.locale);
            if (!block || !to.length || this.ai.translating) return;
            this.ai.translating = true;
            try {
                const res = await api.translateBlock({ type: block.type, from: this.locale, to, values: M.textsOf(block, this.selectedDef, this.locale, this.defaultLocale) });
                let next = this.blocksOf(sel.zone).find((b) => b.id === sel.id);
                this.remember();
                for (const [code, texts] of Object.entries(res.i18n || {})) next = M.applyTexts(next, this.def(next.type), code, this.defaultLocale, texts);
                this.replace(sel.zone, next);
                this.queueSave(sel.zone);
                this.rev++;
                toast.success(`Çevrildi: ${Object.keys(res.i18n || {}).map((c) => c.toUpperCase()).join(', ')}. Dil düğmesinden bakabilirsiniz.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.ai.translating = false;
            }
        },

        async suggest() {
            if (this.ai.suggesting) return;
            const at = M.insertTarget(this.page, this.layout, null);
            if (!at) return;
            this.ai.suggesting = true;
            try {
                const res = await api.suggestBlocks({ page: this.page.label, locale: this.locale, brief: this.ai.brief });
                this.remember();
                const blocks = res.blocks.filter((s) => this.def(s.type)).map((s) => M.applyTexts(M.blockFromDefaults(this.def(s.type), s.type), this.def(s.type), this.locale, this.defaultLocale, s.values));
                const list = [...this.blocksOf(at.zone)];
                list.splice(at.index, 0, ...blocks);
                this.layout[at.zone] = list;
                this.queueSave(at.zone);
                this.ai.suggest = false;
                this.ai.brief = '';
                // Veri isteyen blok (Son yazılar) geldiyse çerçeve taslakla yeniden açılır; yoksa yalnızca bölge çizilir.
                const needs = blocks.flatMap((b) => this.def(b.type)?.needs || []);
                if (!(await this.needsReload({ needs }))) await this.renderZone(at.zone);
                if (blocks[0]) this.select({ zone: at.zone, id: blocks[0].id });
                toast.success(`Jarvis ${blocks.length} bölüm ekledi (taslak). Beğenmezseniz Ctrl+Z.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.ai.suggesting = false;
            }
        },

        // ------------------------------------------------------------ kısayollar
        key(event) {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName) || event.target?.isContentEditable;
            const mod = event.ctrlKey || event.metaKey;
            const k = event.key.toLowerCase();
            if (mod && k === 's') {
                event.preventDefault();
                this.flush().then(() => toast.success('Taslak kaydedildi.'));
                return;
            }
            if (typing) return;
            if (mod && k === 'z' && !event.shiftKey) {
                event.preventDefault();
                this.undo();
            } else if (mod && (k === 'y' || (k === 'z' && event.shiftKey))) {
                event.preventDefault();
                this.redo();
            } else if (mod && k === 'd' && this.selected) {
                event.preventDefault();
                this.duplicate();
            } else if (mod && k === 'c' && this.selected) {
                this.copy();
            } else if (mod && k === 'v') {
                event.preventDefault();
                this.paste();
            } else if ((k === 'delete' || k === 'backspace') && this.selected) {
                event.preventDefault();
                this.remove();
            } else if (event.altKey && (k === 'arrowup' || k === 'arrowdown') && this.selected) {
                event.preventDefault();
                this.move(k === 'arrowup' ? -1 : 1);
            } else if (k === 'escape') {
                this.insertAt = null;
                this.pagePicker = false;
                this.select(null);
            }
        },

        hoverLabel() {
            const block = this.hover && this.blocksOf(this.hover.zone).find((b) => b.id === this.hover.id);
            return block ? this.label(block) : '';
        },

        when(iso) {
            return fmt.dateTime(iso);
        },

        backToPanel() {
            navigate(href('overview'));
        },

        siteUrl() {
            return this.pageUrl() || '/';
        },
    };
}
