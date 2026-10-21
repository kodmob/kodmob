/**
 * km-grid (dfGrid): panelde ve sitede
 * aynı tablo: sunucu ya da bellek içi kaynak, sayfalama, sıralama, durum sekmeleri
 * (sayılarıyla), filtre paneli ve çipleri, "Seç" ile çoklu seçim ve Toplu İşlem menüsü,
 * genel Sil ve Silinenler, gruplama, sürükle-bırak sıralama, sütun seçimi, CSV, mobilde kart, canlı güncelleme.
 * Her özelliğin canlı örneği /_ui/ (site) ve /_ui/panel/ (panel) sayfalarındadır.
 *
 * Dosyalar: grid.js (çekirdek: kaynak, süzgeç, sayfa, sütun, seçim, Sil, CSV), grid-live.js (canlı
 * güncelleme, vurgu), grid-groups.js (gruplama), grid-reorder.js (sürükle-bırak sıralama),
 * grid-format.js (biçimleme, compose), local-source.js (bellek içi kaynak), grid.html, grid.css.
 *
 *     <div x-data="kmGrid(usersGrid)"
 *          @grid-open="openDetail($event.detail.row)"
 *          @grid-action="onAction($event.detail.action, $event.detail.row)"
 *          @grid-bulk="onBulk($event.detail.action, $event.detail.rows)">
 *       {% include "ui/grid/grid.html" %}
 *     </div>
 *
 * Yapılandırma (sayfa bileşeninde, ör. `usersGrid: { … }`):
 *   id          benzersiz ad (sütun tercihleri bununla saklanır)
 *   source      async (query) => { items, total, totals?, facets?, record_type? }  (kodmob-api ListResult)
 *               ya da bellek içi: localSource(rows, { search, facets, sums })   (ui/grid/local-source.js)
 *   columns     [{ key, label, sortable, sortKey, format, badge, badgeLabel, align, hideBelow, card, hidden,
 *                  cell, chips, exportValue, export, filterBy, filterValue, strong, mono, muted, class }]
 *               chips:  (row) => [{ label, tone: 'success' | 'warning' | 'danger' | 'primary' | 'neutral' | 'muted', title }]
 *                       küçük etiketler (ör. dil rozetleri); CSV ve kartta etiketler metin olur
 *               format: 'number' | 'tl' | 'percent' | 'date' | 'time' | 'dateTime' | 'relative' | 'code' | 'image'
 *               ('image': değer adres ya da { src }; küçük önizleme basılır)
 *               badge:  domain/status.js türü (ör. 'brief'); card: 'title' | 'subtitle' | 'badge' | 'spec' | 'meta'
 *               filterBy: hücreye tıklayınca liste o değere süzülür (ör. 'user_id'; değer row[filterValue || filterBy])
 *   filters     [{ key, type: 'tabs' | 'search' | 'select' | 'date-range', label, options | statusKind | loadOptions, format, placeholder }]
 *               select seçenekleri verilmezse sunucunun `facets` sayılarından üretilir.
 *   rowActions  [{ key, label, icon, tone: 'primary' | 'secondary' | 'ghost' | 'danger', can, when: (row) => bool, primary, inline }]
 *   bulkActions [{ key, label, icon, tone, can, when }]  "Seç" ile seçilen satırlara (grid-bulk olayı; `when()`
 *               false ise görünmez). Yapılacak toplu iş
 *               yoksa "Seç" görünmez; silme yetkisi varsa menüde genel "Seçilenleri Sil" de çıkar.
 *   totals      [{ key, label, format }]  sunucunun `totals` alanı (filtreye uyan bütün kayıtlar)
 *   groupBy     { key, label: (row) => string, summary?: (members) => string, totals: ['amount'], minSize?: 1,
 *                 toggle?: true, when?: () => bool }
 *               Aynı anahtarlı satırlar sayfada bir araya toplanır (ilk görüldüğü sırada); başlığa tıklanınca açılır /
 *               kapanır. `minSize`: bu kadardan az satırlı grup başlıksız kalır (ör. çevirisi olmayan yazı).
 *               `toggle`: araç çubuğunda "Grupla" düğmesi (seçim tarayıcıda hatırlanır).
 *   reorder     { save: async (keys, { row, from, to }) => void, sortKey?: 'order', order?: 'asc', can?, when?: (grid) => bool, hint? }
 *               Solda tutamak: sürükle-bırak ya da tutamakta ↑/↓ ile sıralama; yeni sıra `save` ile kaydedilir (hata
 *               atarsa eski sıraya döner). Varsayılan: arama/süzgeç yokken, `sortKey`'e göre sıralıyken ve bütün
 *               kayıtlar tek sayfadayken açık; değilse tablonun üstünde ipucu çıkar.
 *   recordType  genel Sil türü (ör. 'post'); sunucu `record_type` dönerse o geçerli. `deleteCan`: silebilecek yetki(ler),
 *               `deleteWhen(row)`: satıra göre sınır, `deletable: false`: Sil kapalı. Silinenler `km_restore_records` ile.
 *               `records: { remove(item), removeMany(items), restore(item), hidden(type) }` sunucu yerine kullanılır
 *               (bellek içi listeler, vitrin).
 *   pageSize, pageSizes, sort: { key, order }, values: { filtre: değer } (başlangıç), syncUrl, exportName,
 *   exportCsv: (query) => … (sunucu CSV'si), empty: { title, text, icon }, columnsVersion, rowKey
 *   live        { types: ['project'], rowType? }  bu türde kayıt eklenince/değişince (başka
 *               kullanıcı ya da başka sekme) tablonun üstünde şerit: "1 yeni kayıt · 1 güncelleme — yüklemek için
 *               tıklayın". Tablo kendiliğinden kaymaz; tıklayınca yeni ve değişen satırlar sarıdan sönerek gösterilir.
 *               Değişiklikler kodmob-api `GET /changes` (olay defteri); nabız ($store.pulse) türün konusu artınca sorulur.
 *               Sayfanın kendi işleminden sonraki yenileme (grid-reload) değişen satırları doğrudan vurgular.
 *               { topics: ['events'], auto?: true }  türü olmayan nabız konusu: auto → sessizce yeniden yüklenir,
 *               değilse "Liste değişti" şeridi.
 *
 * Yetki (`can`): panelde `$store.session.can()`. Sitede oturum deposu yoktur: `can` isteyen işlem görünmez.
 *
 * Olaylar (kabarcıklanır): grid-open { row } (satırda Enter ya da birincil işlem), grid-action { action, row },
 * grid-bulk { action, rows }, grid-records-changed { id }.
 * Dışarıdan: window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id, options? } }))
 *            window.dispatchEvent(new CustomEvent('grid-filter', { detail: { id, values: { user_id: '3' } } }))
 *            window.dispatchEvent(new CustomEvent('grid-group', { detail: { id, on: true } }))
 */
import { fmt } from '../../domain/format.js';
import { status, statusOptions } from '../../domain/status.js';
import { storage } from '../../services/storage.js';
import { toCsv, downloadCsv } from './csv.js';
import { deleteRecord, deleteRecords, restoreRecord, hiddenRecords } from '../records/records.js';
import { NUMERIC, NOWRAP, formatValue, compose } from './grid-format.js';
import { liveFeature } from './grid-live.js';
import { groupFeature } from './grid-groups.js';
import { reorderFeature } from './grid-reorder.js';

const HIDE_ACTION = { key: 'km-hide', label: 'Sil', icon: 'trash', tone: 'danger' };
const RECORD_MANAGER = 'km_restore_records';

const SEARCH_DEBOUNCE = 300;

const HIDE_BELOW = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
    xl: 'hidden xl:table-cell',
    '2xl': 'hidden 2xl:table-cell',
};
const ALIGN = { start: 'text-left', center: 'text-center', end: 'text-right' };
const RESERVED = ['page', 'per_page', 'sort', 'order', 'q'];

export function kmGrid(config = {}) {
    const columns = (config.columns || []).map((c) => ({ align: NUMERIC.includes(c.format) ? 'end' : 'start', ...c }));
    const hasCardRoles = columns.some((c) => c.card !== undefined);
    const filters = (config.filters || []).map((f) => ({ ...f, options: f.options || (f.statusKind ? statusOptions(f.statusKind) : []) }));
    // `columnsVersion`: varsayılan sütunlar değişince artırılır; kişinin eski seçimi bir kez bırakılır.
    const prefsKey = `km_grid_cols:${config.id || 'grid'}${config.columnsVersion ? `:v${config.columnsVersion}` : ''}`;

    const core = {
        id: config.id || 'grid',
        columns,
        filters,
        rowActions: config.rowActions || [],
        bulkActions: config.bulkActions || [],
        totalsDef: config.totals || [],
        /** Genel Sil türü; `showHidden`: Silinenler görünümü. */
        recordType: config.recordType || null,
        showHidden: false,
        hiddenRows: [],
        /** Seçim kipi: "Seç" ile açılır; satırlarda onay kutusu ve toplu işlem çubuğu görünür. */
        selectMode: false,
        bulkMenuOpen: false,
        bulkBusy: false,
        pageSizes: config.pageSizes || [10, 20, 50, 100],
        emptyState: config.empty || {},

        rows: [],
        total: 0,
        totals: {},
        facets: {},
        loading: false,
        loadedOnce: false,
        error: null,
        exporting: false,

        page: 1,
        perPage: config.pageSize || 20,
        sort: config.sort?.key || null,
        order: config.sort?.order || 'desc',
        q: '',
        values: { ...(config.values || {}) },
        selected: [],
        hidden: storage.getJSON(prefsKey, null) ?? columns.filter((c) => c.hidden).map((c) => c.key),
        menuFor: null,
        menuTimer: null,
        menuPos: { top: '0px', left: '0px' },
        columnsMenu: false,
        filtersOpen: false,
        seq: 0,
        timer: null,

        // ------------------------------------------------------------ yaşam döngüsü
        init() {
            if (config.syncUrl) {
                this.readUrl();
                // Yalnızca aynı sayfada kalan geri/ileri: başka sayfaya dönülüyorsa (panelde görünüm değişir) yükleme yok.
                const path = window.location.pathname;
                this._onPop = () => {
                    if (window.location.pathname !== path) return;
                    this.readUrl();
                    this.load();
                };
                window.addEventListener('popstate', this._onPop);
            }
            this._onReload = (e) => {
                if (e.detail?.id && e.detail.id !== this.id) return;
                // `options: true`: sunucudan gelen süzgeç seçenekleri de yenilenir.
                if (e.detail?.options) this.loadFilterOptions();
                this.load({ silent: true, flashChanges: true });
            };
            this._onGroup = (e) => {
                if (e.detail?.id === this.id) this.setGrouping(!!e.detail.on, false);
            };
            this._onFilter = (e) => {
                if (e.detail?.id !== this.id) return;
                this.values = { ...this.values, ...(e.detail.values || {}) };
                this.refresh();
            };
            window.addEventListener('grid-reload', this._onReload);
            window.addEventListener('grid-group', this._onGroup);
            window.addEventListener('grid-filter', this._onFilter);
            this.loadFilterOptions();
            this.watchLive();
            this.load();
        },

        destroy() {
            window.removeEventListener('grid-reload', this._onReload);
            window.removeEventListener('grid-group', this._onGroup);
            window.removeEventListener('grid-filter', this._onFilter);
            if (this._onPop) window.removeEventListener('popstate', this._onPop);
            this.destroyLive();
            clearTimeout(this.menuTimer);
        },

        /**
         * `silent`: arka planda yenile (canlı güncelleme, sayfanın kendi işlemi). İskelet çıkmaz,
         * yeni gelen satırlar sarı vurguyla görünür.
         * `flashChanges` (sayılı canlı kip): son yoklamadan beri değişen satırlar (işlemi yapanın kendi
         * değişikliği dahil) vurgulanır.
         */
        async load({ silent = false, flashChanges = false } = {}) {
            if (typeof config.source !== 'function') {
                this.error = { message: 'Tablo için veri kaynağı tanımlanmadı.' };
                return;
            }
            const current = ++this.seq;
            const isCurrent = () => current === this.seq;
            const before = new Set(this.rows.map((r) => String(this.keyOf(r))));
            if (!silent) this.loading = true;
            this.error = null;
            const live = this.liveBeforeLoad({ flashChanges });
            try {
                const result = await config.source(this.query());
                if (!isCurrent() || !(await this.liveCursor(live, isCurrent))) return;
                this.rows = result?.items || [];
                this.total = Number(result?.total ?? this.rows.length);
                this.totals = result?.totals || {};
                this.facets = result?.facets || {};
                this.recordType = result?.record_type || config.recordType || null;
                this.liveChanged = false;
                this.selected = this.selected.filter((key) => this.rows.some((r) => this.keyOf(r) === key));
                this.rebuildView();
                const freshKeys = await this.liveFreshKeys(live, { silent, before }, isCurrent);
                if (freshKeys === null) return;
                if (freshKeys.length) this.flash(freshKeys);
                // Son sayfadaki kayıtlar silindiyse geçerli son sayfaya dön.
                if (!this.rows.length && this.page > 1 && this.total > 0) {
                    this.page = this.pages;
                    return this.load();
                }
            } catch (error) {
                if (current === this.seq && !silent) this.error = error;
            } finally {
                if (current === this.seq) {
                    this.loading = false;
                    this.liveLoading = false;
                    this.loadedOnce = true;
                }
            }
        },

        query(overrides = {}) {
            const q = { page: this.page, per_page: this.perPage, ...overrides };
            if (this.sort) {
                q.sort = this.sort;
                q.order = this.order;
            }
            if (this.q.trim()) q.q = this.q.trim();
            Object.entries(this.values).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined && !(Array.isArray(value) && !value.length)) q[key] = value;
            });
            return q;
        },

        /** Yetki: panelde oturumun yetkisi; sitede (oturum yok) yalnızca yetki istemeyen işlemler. */
        allowed(cap) {
            if (!cap || (Array.isArray(cap) && !cap.length)) return true;
            const session = this.$store.session;
            return session ? [].concat(cap).some((c) => session.can(c)) : false;
        },

        // ------------------------------------------------------------ adres çubuğu
        /** `syncUrl`: süzgeç, sayfa ve sıralama adresin sorgusunda durur (paylaşılabilir, yenilemede korunur). */
        params() {
            return new URLSearchParams(window.location.search);
        },

        readUrl() {
            const params = this.params();
            this.page = Math.max(1, Number(params.get('page')) || 1);
            this.perPage = this.pageSizes.includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : this.perPage;
            this.sort = params.get('sort') || config.sort?.key || null;
            this.order = params.get('order') === 'asc' ? 'asc' : params.get('order') === 'desc' ? 'desc' : config.sort?.order || 'desc';
            this.q = params.get('q') || '';
            const values = {};
            this.filterKeys().forEach((key) => {
                const all = params.getAll(`${key}[]`);
                if (all.length) values[key] = all;
                else if (params.has(key)) values[key] = params.get(key);
            });
            // Adreste filtre yoksa yapılandırmadaki başlangıç filtreleri geçerli.
            this.values = Object.keys(values).length || params.has('page') || params.has('q') ? values : { ...(config.values || {}) };
        },

        writeUrl() {
            if (!config.syncUrl) return;
            const params = this.params();
            RESERVED.concat(this.filterKeys()).forEach((k) => {
                params.delete(k);
                params.delete(`${k}[]`);
            });
            // Varsayılanlar adrese yazılmaz (kısa adres): sıralama ve başlangıç süzgeçleri. Süzgeçler
            // ya hep birlikte yazılır ya hiç (readUrl adreste süzgeç yoksa başlangıç süzgeçlerini alır);
            // başlangıçta dolu olup boşaltılan süzgeç (ör. "Tümü") `status=` olarak yazılır.
            const defaults = config.values || {};
            const same = (a, b) => JSON.stringify(a ?? '') === JSON.stringify(b ?? '');
            const keys = new Set([...Object.keys(defaults), ...Object.keys(this.values)]);
            const atDefaults = [...keys].every((k) => same(this.values[k], defaults[k]));
            const defaultSort = config.sort?.key || null;
            const defaultOrder = config.sort?.order || 'desc';
            Object.entries(this.query()).forEach(([key, value]) => {
                if (key === 'page' && value === 1) return;
                if (key === 'per_page' && value === (config.pageSize || 20)) return;
                if ((key === 'sort' || key === 'order') && this.sort === defaultSort && this.order === defaultOrder) return;
                if (atDefaults && this.filterKeys().includes(key)) return;
                if (Array.isArray(value)) value.forEach((v) => params.append(`${key}[]`, v));
                else params.set(key, value);
            });
            if (!atDefaults) {
                Object.keys(defaults).forEach((k) => {
                    const v = this.values[k];
                    if (v === '' || v === null || v === undefined || (Array.isArray(v) && !v.length)) params.set(k, '');
                });
            }
            const search = params.toString();
            const url = new URL(window.location.href);
            url.search = search;
            // Görünüm yeniden kurulmasın: replaceState; panel yönlendiricisi yeni adresi öğrenir (km-url-replaced).
            if (url.toString() !== window.location.href) {
                history.replaceState(history.state, '', url);
                window.dispatchEvent(new CustomEvent('km-url-replaced', { detail: url.pathname + url.search }));
            }
        },

        filterKeys() {
            return this.filters.flatMap((f) => (f.type === 'date-range' ? [`${f.key}_from`, `${f.key}_to`] : f.type === 'search' ? [] : [f.key]));
        },

        refresh({ resetPage = true } = {}) {
            if (resetPage) this.page = 1;
            this.writeUrl();
            this.load();
        },

        // ------------------------------------------------------------ filtreler
        onSearchInput() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.refresh(), SEARCH_DEBOUNCE);
        },

        setFilter(key, value) {
            this.values = { ...this.values, [key]: value };
            this.refresh();
        },

        /** Hızlı süzme: hücredeki değere tıklayınca (ör. kullanıcı) liste o değere daralır. */
        canFilterBy(col, row) {
            if (!col.filterBy) return false;
            const v = row[col.filterValue || col.filterBy];
            return v !== null && v !== undefined && v !== '';
        },

        filterBy(col, row) {
            const value = String(row[col.filterValue || col.filterBy]);
            if (String(this.values[col.filterBy] ?? '') === value) return;
            this.setFilter(col.filterBy, value);
        },

        filterTitle(col, row) {
            return `Yalnızca ${this.text(col, row)} kayıtları`;
        },

        clearFilter(key) {
            const values = { ...this.values };
            delete values[key];
            this.values = values;
            this.refresh();
        },

        clearAll() {
            this.values = {};
            this.q = '';
            this.refresh();
        },

        get tabFilter() {
            return this.filters.find((f) => f.type === 'tabs') || null;
        },

        get searchFilter() {
            return this.filters.find((f) => f.type === 'search') || null;
        },

        get panelFilters() {
            return this.filters.filter((f) => f.type === 'select' || f.type === 'date-range');
        },

        tabs() {
            const f = this.tabFilter;
            if (!f) return [];
            const counts = this.facets[f.key] || {};
            // `alias: true` sekmeler başka durumları kapsar; "Tümü" toplamına girmez.
            const aliases = new Set(f.options.filter((o) => o.alias).map((o) => o.value));
            const all = Object.entries(counts).reduce((a, [k, n]) => (aliases.has(k) ? a : a + n), 0);
            const list = f.options.map((o) => ({ ...o, count: counts[o.value] ?? 0 }));
            return f.noAll ? list : [{ value: '', label: 'Tümü', count: all }].concat(list);
        },

        isTab(value) {
            return String(this.values[this.tabFilter?.key] ?? '') === String(value);
        },

        /** Seçim kutusu seçenekleri: verilmediyse sunucunun sayılarından (facets) üretilir. */
        optionsFor(f) {
            if (f.options?.length) return f.options;
            const counts = this.facets[f.key] || {};
            return Object.keys(counts).sort((a, b) => (Number(a) - Number(b)) || a.localeCompare(b)).map((v) => ({ value: v, label: `${f.format ? f.format(v) : v} (${fmt.number(counts[v])})` }));
        },

        get chips() {
            const chips = [];
            if (this.q.trim()) chips.push({ key: 'q', label: `Arama: ${this.q.trim()}` });
            this.filters.forEach((f) => {
                if (f.type === 'tabs' || f.type === 'search') return;
                if (f.type === 'date-range') {
                    const from = this.values[`${f.key}_from`];
                    const to = this.values[`${f.key}_to`];
                    if (from) chips.push({ key: `${f.key}_from`, label: `${f.label} ≥ ${fmt.date(from)}` });
                    if (to) chips.push({ key: `${f.key}_to`, label: `${f.label} ≤ ${fmt.date(to)}` });
                    return;
                }
                const v = this.values[f.key];
                if (v === undefined || v === '' || (Array.isArray(v) && !v.length)) return;
                const label = [].concat(v).map((x) => f.options.find((o) => String(o.value) === String(x))?.label ?? (f.format ? f.format(x) : x)).join(', ');
                chips.push({ key: f.key, label: `${f.label}: ${label}` });
            });
            return chips;
        },

        removeChip(chip) {
            if (chip.key === 'q') {
                this.q = '';
                this.refresh();
            } else {
                this.clearFilter(chip.key);
            }
        },

        /** Filtre ya da arama uygulanınca: bulunan kayıt sayısı ve tanımlı toplamlar (filtreye uyan bütün kayıtlar). */
        get filterSummary() {
            if (!this.chips.length || !this.loadedOnce) return null;
            const sums = this.totalsDef.filter((d) => this.totals[d.key] !== undefined && this.totals[d.key] !== null);
            return {
                count: `${fmt.number(this.total)} kayıt bulundu`,
                totals: sums.map((d) => ({ key: d.key, label: d.label, value: this.totalText(d) })),
            };
        },

        loadFilterOptions() {
            this.filters.forEach((f) => {
                if (typeof f.loadOptions === 'function') f.loadOptions().then((options) => (f.options = options)).catch(() => {});
            });
        },

        get hasActiveFilters() {
            return this.chips.length > 0 || !!(this.tabFilter && this.values[this.tabFilter.key] && !this.tabFilter.noAll);
        },

        /** Boş liste süzgeçten mi: sekme seçili ama hiçbir sekmede kayıt yoksa tablo gerçekten boştur. */
        get emptyByFilter() {
            if (this.chips.length) return true;
            if (!this.hasActiveFilters) return false;
            return Object.values(this.facets[this.tabFilter.key] || {}).some((n) => n > 0);
        },

        // ------------------------------------------------------------ sıralama ve sayfalama
        toggleSort(col) {
            if (!col.sortable) return;
            if (this.sort === (col.sortKey || col.key)) this.order = this.order === 'asc' ? 'desc' : 'asc';
            else {
                this.sort = col.sortKey || col.key;
                this.order = col.format === 'date' || col.format === 'dateTime' || col.format === 'relative' || col.align === 'end' ? 'desc' : 'asc';
            }
            this.refresh();
        },

        ariaSort(col) {
            if (!col.sortable) return null;
            return this.sort === (col.sortKey || col.key) ? (this.order === 'asc' ? 'ascending' : 'descending') : 'none';
        },

        sortIcon(col) {
            return this.sort !== (col.sortKey || col.key) ? 'opacity-30' : this.order === 'asc' ? 'rotate-180' : '';
        },

        get pages() {
            return Math.max(1, Math.ceil(this.total / this.perPage));
        },

        get pageWindow() {
            const last = this.pages;
            const set = new Set([1, last, this.page - 1, this.page, this.page + 1].filter((p) => p >= 1 && p <= last));
            const list = [...set].sort((a, b) => a - b);
            const out = [];
            list.forEach((p, i) => {
                if (i && p - list[i - 1] > 1) out.push(null);
                out.push(p);
            });
            return out;
        },

        go(page) {
            if (!page || page < 1 || page > this.pages || page === this.page) return;
            this.page = page;
            this.writeUrl();
            this.load();
            this.$root.scrollIntoView?.({ block: 'start', behavior: 'smooth' });
        },

        setPerPage(value) {
            this.perPage = Number(value);
            this.refresh();
        },

        get rangeText() {
            if (!this.total) return '0 kayıt';
            const from = (this.page - 1) * this.perPage + 1;
            const to = Math.min(this.total, this.page * this.perPage);
            return `${fmt.number(from)}–${fmt.number(to)} / ${fmt.number(this.total)} kayıt`;
        },

        // ------------------------------------------------------------ sütunlar
        get visibleColumns() {
            return this.columns.filter((c) => !this.hidden.includes(c.key));
        },

        isHidden(col) {
            return this.hidden.includes(col.key);
        },

        toggleColumn(col) {
            this.hidden = this.isHidden(col) ? this.hidden.filter((k) => k !== col.key) : [...this.hidden, col.key];
            storage.setJSON(prefsKey, this.hidden);
        },

        cellClass(col) {
            return [HIDE_BELOW[col.hideBelow] || '', ALIGN[col.align] || '', NOWRAP.includes(col.format) ? 'whitespace-nowrap' : '', col.format === 'image' ? 'w-16' : '', col.class || ''].join(' ');
        },

        text(col, row) {
            if (col.cell) return col.cell(row);
            if (col.chips) return this.chipsOf(col, row).map((c) => c.label).join(' ') || '—';
            return formatValue(col.format, row[col.key]);
        },

        /** `chips` sütunu: [{ label, tone, title }]. */
        chipsOf(col, row) {
            // Satır kaldırılırken Alpine ifadeyi satırsız bir kez daha değerlendirebilir.
            if (!row) return [];
            return (col.chips?.(row) || []).filter((c) => c && c.label !== undefined && c.label !== '');
        },

        textClass(col) {
            return [this.isNumeric(col) ? 'num' : '', col.format === 'code' || col.mono ? 'code' : '', col.strong ? 'font-medium text-ink' : col.muted ? 'text-ink-2' : 'text-ink'];
        },

        /** 'image' sütunu: değer adres ya da { src, thumb }. */
        imageSrc(col, row) {
            const v = row[col.key];
            return typeof v === 'string' ? v : v?.thumb || v?.src || '';
        },

        /** `badgeLabel(row)`: rozetin adı sunucudan gelir; renk ve simge status.js'ten. */
        badge(col, row) {
            const b = status(col.badge, row[col.key]);
            return col.badgeLabel ? { ...b, label: col.badgeLabel(row) || b.label } : b;
        },

        /** Boş değer rozet yerine "—" basılır. */
        hasBadge(col, row) {
            const v = row[col.key];
            return v !== null && v !== undefined && v !== '';
        },

        isNumeric(col) {
            return [...NUMERIC, 'code'].includes(col.format);
        },

        get colspan() {
            return this.visibleColumns.length + (this.selectMode ? 1 : 0) + (this.hasActions ? 1 : 0) + (this.reorderable ? 1 : 0);
        },

        // ------------------------------------------------------------ mobil kart
        get cardTitle() {
            return hasCardRoles ? this.columns.find((c) => c.card === 'title') : this.columns.find((c) => c.format !== 'image');
        },

        get cardImage() {
            return this.columns.find((c) => c.format === 'image' && c.card !== false && !this.isHidden(c)) || null;
        },

        get cardBadges() {
            return hasCardRoles ? this.columns.filter((c) => c.card === 'badge') : this.columns.filter((c) => c.badge);
        },

        get cardSpecs() {
            if (hasCardRoles) return this.columns.filter((c) => c.card === 'spec');
            return this.columns.filter((c) => c !== this.cardTitle && !c.badge && c.format !== 'image').slice(0, 4);
        },

        get cardSubtitle() {
            return hasCardRoles ? this.columns.find((c) => c.card === 'subtitle') : null;
        },

        get cardMeta() {
            return hasCardRoles ? this.columns.filter((c) => c.card === 'meta') : [];
        },

        metaText(row) {
            return this.cardMeta.map((c) => this.text(c, row)).filter((t) => t && t !== '—').join(' · ');
        },

        // ------------------------------------------------------------ satırlar ve işlemler
        keyOf(row) {
            return row[config.rowKey || 'id'];
        },

        open(row) {
            this.$dispatch('grid-open', { row });
        },

        onRowKey(event, row) {
            if (event.key === 'Enter' && event.target === event.currentTarget) return this.open(row);
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
            event.preventDefault();
            // Satırlar ayrı <tbody>'lerde (grup başlığı için); sıradaki satırı kök içinde bul.
            const rowsEls = [...this.$root.querySelectorAll('tr[data-grid-row]')];
            const next = rowsEls[rowsEls.indexOf(event.currentTarget) + (event.key === 'ArrowDown' ? 1 : -1)];
            next?.focus();
        },

        actionsFor(row) {
            const list = this.rowActions.filter((a) => this.allowed(a.can) && (!a.when || a.when(row)));
            return this.canHideRow(row) ? [...list, HIDE_ACTION] : list;
        },

        // ------------------------------------------------------------ genel Sil — ui/records, kodmob-api Modules/Records
        /**
         * Her tabloda aynı "Sil". Kaydın türü `recordType` (ya da liste yanıtındaki `record_type`).
         * Silinenleri yöneten (`km_restore_records`) her kaydı siler; `deleteCan` verilirse o yetki de
         * siler, `deleteWhen(row)` satıra göre sınırlar (sunucu aynı kuralı denetler).
         * Sayfa kendi silme düğmesini yazmaz; `deletable: false` tabloda Sil'i kapatır.
         */
        get canHide() {
            if (!this.recordType || config.deletable === false) return false;
            return this.isRecordManager || [].concat(config.deleteCan || []).some((cap) => this.allowed(cap));
        },

        get isRecordManager() {
            return this.allowed(RECORD_MANAGER);
        },

        /** Silinenler düğmesi: kalıcı silinen türlerde (talep) yoktur. */
        get canShowHidden() {
            return this.canHide && this.isRecordManager && config.restorable !== false;
        },

        canHideRow(row) {
            return this.canHide && (!config.deleteWhen || config.deleteWhen(row));
        },

        get hasActions() {
            return this.rowActions.length > 0 || this.canHide;
        },

        rowLabel(row) {
            return String((this.cardTitle ? this.text(this.cardTitle, row) : '') || `#${this.keyOf(row)}`);
        },

        async hideRow(row) {
            const done = await (config.records?.remove || deleteRecord)({
                type: row.record_type || this.recordType,
                id: row.record_key ?? this.keyOf(row),
                label: this.rowLabel(row),
                onRestored: () => this.afterRecordChange(),
            });
            if (done) this.afterRecordChange();
        },

        async restoreHidden(h) {
            if (await (config.records?.restore || restoreRecord)({ type: h.object_type || this.recordType, id: h.object_id, label: h.label })) this.afterRecordChange();
        },

        /** Silme ve geri yükleme göstergeleri de değiştirir: tablo yenilenir, sayfaya haber verilir. */
        afterRecordChange() {
            this.load({ silent: true, flashChanges: true });
            if (this.showHidden) this.loadHidden();
            this.$dispatch('grid-records-changed', { id: this.id });
        },

        /** "Silinenler": bu tabloda silinen kayıtlar tablonun üstünde listelenir, her biri geri yüklenebilir. */
        toggleHidden() {
            this.showHidden = !this.showHidden;
            if (this.showHidden) this.loadHidden();
        },

        async loadHidden() {
            this.hiddenRows = await (config.records?.hidden || hiddenRecords)(this.recordType);
        },

        hiddenMeta(h) {
            return fmt.dateTime(h.hidden_at) + (h.hidden_by_name ? ` · ${h.hidden_by_name}` : '') + (h.linked ? ` · ${h.linked} bağlı kayıtla` : '');
        },

        /**
         * Satırda: ikiye kadar işlem; fazlasında birincil ve ilk vurgulu (tone: primary) işlem, kalanı menüde.
         * Sil ve tehlikeli (tone: danger) işlemler her satırda göze batmasın diye daima "⋮" menüsündedir
         * (`inline: true` verilen hariç).
         */
        inlineActions(row) {
            const list = this.actionsFor(row).filter((a) => a.key !== HIDE_ACTION.key && (a.tone !== 'danger' || a.inline));
            if (list.length <= 2) return list;
            // `inline: true` işlem menüye düşmez, satırda görünür.
            const pinned = list.filter((a) => !a.primary && a.inline);
            const first = list.find((a) => a.primary) || list[0];
            return [first, ...(pinned.length ? pinned : [list.find((a) => a !== first && a.tone === 'primary')])].filter(Boolean);
        },

        menuActions(row) {
            const inline = this.inlineActions(row);
            return this.actionsFor(row).filter((a) => !inline.some((i) => i.key === a.key));
        },

        actionClass(action) {
            return { primary: 'btn-primary', danger: 'btn-danger', ghost: 'btn-ghost' }[action.tone] || 'btn-secondary';
        },

        run(action, row) {
            this.menuFor = null;
            // Anahtarla karşılaştırılır: x-for işlemi tepkisel vekile sarar, nesne eşitliği tutmaz.
            if (action?.key === HIDE_ACTION.key) return this.hideRow(row);
            if (action?.open) return this.open(row);
            this.$dispatch('grid-action', { action: action.key, row });
        },

        /**
         * Satır menüsü ekrana sabit konumlanır (tablo kabının `overflow`'u son satırlarda menüyü
         * kırpmasın). Altta yer yoksa yukarı açılır; kaydırınca ya da pencere boyutu değişince kapanır.
         */
        toggleMenu(row, event) {
            const key = this.keyOf(row);
            if (this.menuFor === key) {
                this.menuFor = null;
                return;
            }
            const r = event.currentTarget.getBoundingClientRect();
            const height = this.menuActions(row).length * (window.innerWidth < 768 ? 44 : 40) + 16;
            const width = 208;
            const openUp = window.innerHeight - r.bottom < height + 8 && r.top > height + 8;
            this.menuPos = {
                top: `${Math.round(openUp ? r.top - height - 4 : r.bottom + 4)}px`,
                left: `${Math.round(Math.min(Math.max(8, r.right - width), window.innerWidth - width - 8))}px`,
            };
            this.menuFor = key;
            this.closeMenu ??= () => (this.menuFor = null);
            // Açılıştan önceki kaydırmanın geç gelen olayı menüyü hemen kapatmasın.
            clearTimeout(this.menuTimer);
            this.menuTimer = setTimeout(() => {
                if (this.menuFor !== key) return;
                window.addEventListener('scroll', this.closeMenu, { passive: true, capture: true, once: true });
                window.addEventListener('resize', this.closeMenu, { once: true });
            }, 250);
        },

        isMenuOpen(row) {
            return this.menuFor === this.keyOf(row);
        },

        // ------------------------------------------------------------ seçim
        isSelected(row) {
            return this.selected.includes(this.keyOf(row));
        },

        toggleRow(row) {
            const key = this.keyOf(row);
            this.selected = this.isSelected(row) ? this.selected.filter((k) => k !== key) : [...this.selected, key];
        },

        get allSelected() {
            return this.rows.length > 0 && this.rows.every((r) => this.isSelected(r));
        },

        toggleAll() {
            this.selected = this.allSelected ? [] : this.rows.map((r) => this.keyOf(r));
        },

        get selectedRows() {
            return this.rows.filter((r) => this.isSelected(r));
        },

        /** Yetkisi olmayan ya da `when()` false olan toplu işlem görünmez (satır işlemlerindeki gibi). */
        get allowedBulkActions() {
            return this.bulkActions.filter((a) => this.allowed(a.can) && (!a.when || a.when()));
        },

        /** "Toplu İşlem" menüsü: sayfanın işlemleri, sonra (silebiliyorsa) genel Sil. */
        get bulkMenu() {
            return this.canHide ? [...this.allowedBulkActions, { ...HIDE_ACTION, label: 'Seçilenleri Sil' }] : this.allowedBulkActions;
        },

        /** Yapılacak toplu iş varsa "Seç" düğmesi görünür. */
        get canSelect() {
            return this.bulkMenu.length > 0;
        },

        toggleSelectMode() {
            this.selectMode = !this.selectMode;
            this.bulkMenuOpen = false;
            if (!this.selectMode) this.selected = [];
        },

        bulk(action) {
            this.bulkMenuOpen = false;
            if (action?.key === HIDE_ACTION.key) return this.bulkHide();
            this.$dispatch('grid-bulk', { action: action.key, rows: this.selectedRows });
        },

        /** Seçilenleri sil: silinebilenler tek onayla gider, silinemeyenler nedeniyle bildirilir. */
        async bulkHide() {
            const rows = this.selectedRows.filter((row) => this.canHideRow(row));
            const skipped = this.selectedRows.length - rows.length;
            if (!rows.length) {
                this.$store.toast.info('Seçilen kayıtlar silinebilir durumda değil.');
                return;
            }
            this.bulkBusy = true;
            try {
                const result = await (config.records?.removeMany || deleteRecords)(rows.map((row) => ({
                    type: row.record_type || this.recordType,
                    id: row.record_key ?? this.keyOf(row),
                    label: this.rowLabel(row),
                })));
                if (!result) return;
                if (skipped) this.$store.toast.info(`${skipped} kayıt silinebilir durumda olmadığı için atlandı.`);
                this.selected = [];
                if (result.done) this.afterRecordChange();
            } finally {
                this.bulkBusy = false;
            }
        },

        // ------------------------------------------------------------ toplamlar
        totalText(def) {
            return formatValue(def.format || 'number', this.totals[def.key]);
        },

        // ------------------------------------------------------------ durum
        get isEmpty() {
            return this.loadedOnce && !this.loading && !this.error && this.rows.length === 0;
        },

        get showSkeleton() {
            return this.loading && !this.loadedOnce;
        },

        get emptyTitle() {
            return this.emptyByFilter ? 'Filtrelere uyan kayıt yok' : this.emptyState.title || 'Henüz kayıt yok';
        },

        get emptyText() {
            return this.emptyByFilter ? 'Filtreleri değiştirin ya da temizleyin.' : this.emptyState.text || '';
        },

        // ------------------------------------------------------------ CSV
        get canExport() {
            return config.export !== false;
        },

        async exportCsv() {
            if (this.exporting) return;
            const ok = await this.$store.confirm.ask({
                title: 'Tablo indirilsin mi?',
                message: `Tablodaki ${fmt.number(Math.min(Number(this.total) || 0, 10000))} kayıt, Excel'de açabileceğiniz bir dosya olarak bilgisayarınıza indirilecek. Filtre uyguladıysanız yalnızca filtreye uyan kayıtlar yer alır.`,
                confirmText: 'İndir',
            });
            if (!ok) return;
            this.exporting = true;
            try {
                // Sunucu kendi CSV'sini üretiyorsa (büyük tablolar) o kullanılır.
                if (typeof config.exportCsv === 'function') {
                    await config.exportCsv(this.query());
                    return;
                }
                const rows = [];
                const per = 100;
                for (let page = 1; rows.length < 10000; page++) {
                    const res = await config.source(this.query({ page, per_page: per }));
                    rows.push(...(res?.items || []));
                    if (!res?.items?.length || rows.length >= Number(res.total ?? 0)) break;
                }
                const cols = this.visibleColumns.filter((c) => c.export !== false && c.format !== 'image');
                const csv = toCsv(cols.map((c) => ({
                    label: c.label,
                    value: (row) => (c.exportValue ? c.exportValue(row) : c.badge ? this.badge(c, row).label : c.cell ? c.cell(row) : row[c.key]),
                })), rows);
                downloadCsv(`${config.exportName || this.id}-${new Date().toISOString().slice(0, 10)}.csv`, csv);
                this.$store.toast.success(`${fmt.number(rows.length)} kayıt CSV olarak indirildi.`);
            } catch (error) {
                this.$store.toast.error(error);
            } finally {
                this.exporting = false;
            }
        },
    };

    return compose(core, liveFeature(config), groupFeature(config), reorderFeature(config));
}

/** Eski adıyla. */
export const dfGrid = kmGrid;
