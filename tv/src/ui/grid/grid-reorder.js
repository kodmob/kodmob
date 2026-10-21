/**
 * dfGrid sürükle-bırak sıralama (`reorder`). grid.js'e karışır (compose).
 *
 *   reorder: { save: async (keys, { row, from, to }) => void, sortKey?: 'order', order?: 'asc',
 *              can?, when?: (grid) => bool, hint? }
 *
 * Solda tutamak: sürükle-bırak ya da tutamakta ↑/↓; telefonda yukarı/aşağı düğmeleri. Yeni sıra
 * `save` ile kaydedilir; hata atarsa eski sıraya döner (hatayı sayfa gösterir). Varsayılan koşul:
 * arama/süzgeç yok, `sortKey`'e göre sıralı, bütün kayıtlar tek sayfada, gruplama kapalı. Değilse
 * tablonun üstünde ipucu ve "Sıraya göre göster" çıkar.
 */
export function reorderFeature(config) {
    return {
        reorder: config.reorder || null,
        dragKey: null,
        overKey: null,
        reorderBusy: false,

        /** Sıralama şu an yapılabilir mi. */
        get reorderable() {
            const r = this.reorder;
            if (!r || !this.allowed(r.can) || this.showHidden || this.selectMode || this.grouping || this.rows.length < 2) return false;
            if (r.when) return !!r.when(this);
            const filtered = !!this.q.trim() || Object.values(this.values).some((v) => !(v === '' || v === null || v === undefined || (Array.isArray(v) && !v.length)));
            const sorted = !r.sortKey || (this.sort === r.sortKey && this.order === (r.order || 'asc'));
            return !filtered && sorted && this.rows.length >= this.total;
        },

        /** Sıralama tanımlı ama şu an kapalıysa neden (tablonun üstünde ipucu). */
        get reorderHint() {
            if (!this.reorder || this.reorderable || this.rows.length < 2 || !this.allowed(this.reorder.can) || this.showHidden || this.selectMode) return '';
            return this.reorder.hint || 'Sıralamayı değiştirmek için aramayı ve süzgeçleri temizleyin, listeyi sıraya göre sıralayın.';
        },

        /** İpucundaki bağlantı: süzgeçler temizlenir, sıraya göre sıralanır, bütün kayıtlar tek sayfaya. */
        sortForReorder() {
            this.q = '';
            this.values = Object.fromEntries(Object.keys(this.values).map((k) => [k, '']));
            if (this.reorder?.sortKey) {
                this.sort = this.reorder.sortKey;
                this.order = this.reorder.order || 'asc';
            }
            if (this.perPage < this.total) this.perPage = Math.max(...this.pageSizes);
            this.refresh();
        },

        dragStart(row, event) {
            if (!this.reorderable || this.reorderBusy) {
                event.preventDefault();
                return;
            }
            this.dragKey = this.keyOf(row);
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', String(this.dragKey));
            const item = event.target.closest('tr, li');
            if (item) event.dataTransfer.setDragImage(item, 24, 24);
        },

        dragOver(row) {
            if (this.dragKey !== null) this.overKey = this.keyOf(row);
        },

        drop(row) {
            const from = this.rows.findIndex((r) => this.keyOf(r) === this.dragKey);
            const to = this.rows.findIndex((r) => this.keyOf(r) === this.keyOf(row));
            this.dragEnd();
            if (from >= 0 && to >= 0 && from !== to) this.moveRow(from, to);
        },

        dragEnd() {
            this.dragKey = null;
            this.overKey = null;
        },

        isDropTarget(row) {
            return this.dragKey !== null && this.overKey === this.keyOf(row) && this.dragKey !== this.keyOf(row);
        },

        /** Tutamakta ↑ / ↓: satırı bir yukarı / aşağı taşır (odak tutamakta kalır). */
        onHandleKey(event, row) {
            const step = { ArrowUp: -1, ArrowDown: 1 }[event.key];
            if (!step) return;
            event.preventDefault();
            this.moveBy(row, step);
        },

        moveBy(row, step) {
            const from = this.rows.findIndex((r) => this.keyOf(r) === this.keyOf(row));
            const to = from + step;
            if (from < 0 || to < 0 || to >= this.rows.length) return;
            this.moveRow(from, to).then(() => {
                this.$nextTick(() => this.$root.querySelector(`[data-handle="${this.keyOf(row)}"]:not([hidden])`)?.focus());
            });
        },

        async moveRow(from, to) {
            if (this.reorderBusy || !this.reorderable) return;
            const before = this.rows;
            const rows = [...before];
            const [moved] = rows.splice(from, 1);
            rows.splice(to, 0, moved);
            this.rows = rows;
            this.rebuildView();
            this.reorderBusy = true;
            try {
                await this.reorder.save(rows.map((r) => this.keyOf(r)), { row: moved, from, to });
                const field = this.reorder.sortKey;
                if (field) rows.forEach((r, i) => (r[field] = i));
                this.flash([String(this.keyOf(moved))]);
            } catch {
                // Kaydedilemedi: eski sıra.
                this.rows = before;
                this.rebuildView();
            } finally {
                this.reorderBusy = false;
            }
        },
    };
}
