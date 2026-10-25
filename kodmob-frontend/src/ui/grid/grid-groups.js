/**
 * dfGrid gruplama (`groupBy`). grid.js'e karışır (compose).
 *
 *   groupBy: { key, label: (row) => string, summary?: (members) => string, totals: ['amount'],
 *              minSize?: 1, toggle?: true, when?: () => bool }
 *
 * Aynı anahtarlı satırlar sayfada ilk görüldükleri yerde bir araya toplanır (grup içi sıra
 * korunur); başlığa tıklanınca grup açılır / kapanır. `minSize`'dan küçük gruplar başlıksız, düz
 * satır kalır. `toggle`: araç çubuğunda "Grupla" (seçim tarayıcıda hatırlanır). Dışarıdan:
 * window.dispatchEvent(new CustomEvent('grid-group', { detail: { id, on: true } })).
 */
import { storage } from '../../services/storage.js';
import { formatValue } from './grid-format.js';

export function groupFeature(config) {
    const prefKey = `km_grid_group:${config.id || 'grid'}`;
    const remembered = config.groupBy?.toggle !== false ? storage.getJSON(prefKey, null) : null;

    return {
        groupBy: config.groupBy || null,
        collapsedGroups: [],
        /** Gruplama açık mı; `groupBy.when` başlangıcı verir, "Grupla" ya da `grid-group` değiştirir. */
        groupOn: remembered ?? (config.groupBy?.when ? !!config.groupBy.when() : true),
        /** Ekrandaki sıra: gruplama açıkken aynı gruptaki satırlar bir arada. */
        viewRows: [],
        groupSizes: {},

        groupLabel(row) {
            if (!this.groupBy) return '';
            return this.groupBy.label ? this.groupBy.label(row) : String(row[this.groupBy.key] ?? '—');
        },

        /** Grup anahtarı (`groupBy.key`; yoksa başlık). */
        groupKeyOf(row) {
            if (!this.groupBy) return '';
            return String(this.groupBy.key ? row[this.groupBy.key] ?? '' : this.groupLabel(row));
        },

        /** Ekrandaki sırayı kurar (her yüklemede ve gruplama değişince). */
        rebuildView() {
            if (!this.grouping) {
                this.viewRows = this.rows;
                this.groupSizes = {};
                return;
            }
            const order = [];
            const members = new Map();
            for (const row of this.rows) {
                const key = this.groupKeyOf(row);
                if (!members.has(key)) {
                    members.set(key, []);
                    order.push(key);
                }
                members.get(key).push(row);
            }
            this.viewRows = order.flatMap((key) => members.get(key));
            this.groupSizes = Object.fromEntries(order.map((key) => [key, members.get(key).length]));
        },

        /** Satır başlıklı bir grubun üyesi mi (`minSize`'dan küçük gruplar başlıksız, düz satır). */
        inGroup(row) {
            return this.grouping && (this.groupSizes[this.groupKeyOf(row)] || 0) >= (this.groupBy.minSize || 1);
        },

        /** Kapalı grup: başlığı kalır, satırları gizlenir (yenilemede açık/kapalı durumu korunur). */
        isGroupCollapsed(row) {
            return this.inGroup(row) && this.collapsedGroups.includes(this.groupKeyOf(row));
        },

        toggleGroup(row) {
            const key = this.groupKeyOf(row);
            this.collapsedGroups = this.collapsedGroups.includes(key) ? this.collapsedGroups.filter((g) => g !== key) : [...this.collapsedGroups, key];
        },

        get grouping() {
            return !!this.groupBy && this.groupOn;
        },

        get canToggleGroup() {
            return !!this.groupBy && this.groupBy.toggle !== false;
        },

        setGrouping(on, remember = true) {
            this.groupOn = on;
            if (remember && this.canToggleGroup) storage.setJSON(prefKey, on);
            this.rebuildView();
        },

        startsGroup(index) {
            const row = this.viewRows[index];
            if (!row || !this.inGroup(row)) return false;
            return index === 0 || this.groupKeyOf(this.viewRows[index - 1]) !== this.groupKeyOf(row);
        },

        groupSummary(index) {
            const key = this.groupKeyOf(this.viewRows[index]);
            const members = this.viewRows.filter((r) => this.groupKeyOf(r) === key);
            if (this.groupBy.summary) return this.groupBy.summary(members);
            const parts = [`${members.length} kayıt`];
            (this.groupBy.totals || []).forEach((name) => {
                const col = this.columns.find((c) => c.key === name);
                const sum = members.reduce((acc, r) => acc + (Number(r[name]) || 0), 0);
                parts.push(formatValue(col?.format || 'number', sum));
            });
            return parts.join(' · ');
        },
    };
}
