/**
 * dfGrid canlı güncelleme (`live`) ve satır vurgusu. grid.js'e karışır (compose).
 *
 *   live: { types: ['project'], rowType? }
 *       Sayılı kip: bu türde kayıt eklenince/değişince/silinince (başka kullanıcı
 *       ya da başka sekme) tablonun üstünde şerit: "1 yeni kayıt · 1 güncelleme · 1 kayıt silindi ·
 *       sıralama değişti — yüklemek için tıklayın". Tablo kendiliğinden kaymaz; tıklayınca yeni ve
 *       değişen satırlar sarıdan sönerek gösterilir. Değişiklikler kodmob-api `GET /changes`
 *       (olay defteri); nabız ($store.pulse) türün konusu artınca sorulur. Sayfanın kendi işleminden
 *       sonraki yenileme (grid-reload) değişen satırları doğrudan vurgular.
 *   live: { topics: ['events'], auto?: true }
 *       Türü olmayan nabız konusu: auto → sessizce yeniden yüklenir (yeni satırlar vurgulanır),
 *       değilse "Liste değişti" şeridi.
 *
 * Çekirdek (grid.js) yüklemede `liveBeforeLoad` → `liveCursor` → `liveFreshKeys` sırasıyla çağırır.
 */
import { http } from '../../services/http.js';

/** Vurgulanan satırın sarıdan beyaza dönüş süresi (CSS ile aynı) + pay. */
const FRESH_MS = 3600;
const LIVE_DEBOUNCE = 900;

function parseLive(config) {
    const types = Array.isArray(config.live?.types) ? config.live.types : [];
    const topics = Array.isArray(config.live?.topics) && config.live.topics.length ? config.live.topics : types;
    if (!topics.length) return null;
    return { types, topics, counted: types.length > 0, auto: !types.length && !!config.live.auto, rowType: config.live.rowType || types[0] || null };
}

export function liveFeature(config) {
    return {
        live: parseLive(config),
        liveChanged: false,
        liveTimer: null,
        liveBase: 0,
        liveReady: false,
        liveNewKeys: [],
        liveUpdKeys: [],
        liveDelKeys: [],
        liveReordered: false,
        liveStale: false,
        liveChecking: false,
        liveAgain: false,
        liveLoading: false,
        pendingFresh: [],
        /** Şu an sarı vurgulu satırlar. */
        fresh: [],
        freshTimer: null,

        /** init: nabızda tablonun konusu artınca neler değiştiğini sor (ya da yenile). */
        watchLive() {
            if (!this.live) return;
            this.$watch(() => this.live.topics.map((t) => this.$store.pulse?.topics?.[t] || 0).join(':'), () => {
                if (!this.loadedOnce) return;
                if (this.live.counted) {
                    this.checkLive();
                    return;
                }
                if (!this.live.auto) {
                    this.liveChanged = true;
                    return;
                }
                clearTimeout(this.liveTimer);
                this.liveTimer = setTimeout(() => this.load({ silent: true }), LIVE_DEBOUNCE);
            });
        },

        destroyLive() {
            clearTimeout(this.liveTimer);
            clearTimeout(this.freshTimer);
        },

        /**
         * Yükleme başlıyor. "Bu veri şu olay no'ya kadar güncel" imleci veriden ÖNCE istenir; böylece
         * arada olan değişiklik kaçmaz (en kötü, yüklenmiş bir satır bir kez daha vurgulanır).
         */
        liveBeforeLoad({ flashChanges = false } = {}) {
            this.liveLoading = true;
            const counted = !!this.live?.counted;
            const since = flashChanges && counted && this.liveReady ? this.liveBase : 0;
            return {
                counted,
                changed: since ? [...this.liveNewKeys, ...this.liveUpdKeys] : [],
                changesRequest: since ? http.get('/changes', { since, types: this.live.types.join(',') }).catch(() => null) : null,
                cursorRequest: counted ? http.get('/changes/cursor').catch(() => null) : null,
            };
        },

        /** Veri geldi: imleç tabana yazılır, bekleyen değişiklikler sıfırlanır. false: yükleme eskidi. */
        async liveCursor(ctx, isCurrent) {
            if (!ctx.cursorRequest) return true;
            const info = await ctx.cursorRequest;
            if (!isCurrent()) return false;
            if (info) {
                this.liveBase = Number(info.cursor) || 0;
                this.liveReady = true;
            }
            this.resetLive();
            return true;
        },

        /**
         * Vurgulanacak satırlar: şeritten gelenler, sayfanın kendi işlemiyle değişenler (flashChanges),
         * nabız kipinde sessiz yenilemede yeni gelenler. null: yükleme eskidi.
         */
        async liveFreshKeys(ctx, { silent, before }, isCurrent) {
            const keys = [...this.pendingFresh, ...ctx.changed];
            this.pendingFresh = [];
            if (ctx.changesRequest) {
                const changes = await ctx.changesRequest;
                if (!isCurrent()) return null;
                for (const item of changes?.items || []) {
                    if (item.type === this.live.rowType && !item.deleted && !item.reordered) keys.push(String(item.id));
                }
            }
            if (!ctx.counted && silent && before.size) keys.push(...this.rows.map((r) => String(this.keyOf(r))).filter((k) => !before.has(k)));
            return keys;
        },

        /**
         * Sayılı kip: nabız bu tablonun türünde artış gösterdi, `liveBase`'ten beri neler değişti?
         * Yeni kayıtlar ve bu sayfada görünen satırlardaki değişiklikler şeride eklenir; başka
         * sayfadaki eski kayıtların değişimi sayılmaz.
         */
        async checkLive() {
            if (!this.live?.counted || !this.liveReady) return;
            if (this.liveChecking) {
                this.liveAgain = true;
                return;
            }
            this.liveChecking = true;
            this.liveAgain = false;
            const base = this.liveBase;
            try {
                const res = await http.get('/changes', { since: base, types: this.live.types.join(',') });
                // Bu arada tablo yeniden yüklendiyse (ya da yükleniyorsa) sonuç eskidir.
                if (this.liveBase !== base || this.liveLoading) return;
                if (res?.stale) {
                    this.liveStale = true;
                    return;
                }
                const visible = new Set(this.rows.map((r) => String(this.keyOf(r))));
                const added = new Set(this.liveNewKeys);
                const updated = new Set(this.liveUpdKeys);
                const deleted = new Set(this.liveDelKeys);
                for (const item of res?.items || []) {
                    if (item.type !== this.live.rowType) continue;
                    if (item.reordered) {
                        this.liveReordered = true;
                        continue;
                    }
                    const key = String(item.id);
                    if (item.deleted) {
                        if (added.has(key)) added.delete(key);
                        else if (visible.has(key)) deleted.add(key);
                        updated.delete(key);
                    } else if (item.created) {
                        if (!visible.has(key)) added.add(key);
                    } else if (visible.has(key) && !deleted.has(key)) {
                        updated.add(key);
                    }
                }
                this.liveNewKeys = [...added];
                this.liveUpdKeys = [...updated].filter((k) => !added.has(k));
                this.liveDelKeys = [...deleted];
                this.liveBase = Number(res?.cursor) || base;
                if (res?.truncated) this.liveAgain = true;
            } catch {
                /* yoklama başarısızsa tablo olduğu gibi kalır; sonraki nabızda yeniden denenir */
            } finally {
                this.liveChecking = false;
                if (this.liveAgain) {
                    this.liveAgain = false;
                    this.checkLive();
                }
            }
        },

        resetLive() {
            this.liveNewKeys = [];
            this.liveUpdKeys = [];
            this.liveDelKeys = [];
            this.liveReordered = false;
            this.liveStale = false;
        },

        get liveCount() {
            return this.liveNewKeys.length + this.liveUpdKeys.length + this.liveDelKeys.length + (this.liveReordered ? 1 : 0);
        },

        get liveVisible() {
            if (!this.live) return false;
            return this.live.counted ? this.liveCount > 0 || this.liveStale : this.liveChanged;
        },

        get liveText() {
            if (!this.live?.counted) return 'Liste değişti';
            if (this.liveStale && !this.liveCount) return 'Liste uzun süredir güncellenmedi';
            const parts = [];
            if (this.liveNewKeys.length) parts.push(`${this.liveNewKeys.length} yeni kayıt`);
            if (this.liveUpdKeys.length) parts.push(`${this.liveUpdKeys.length} güncelleme`);
            if (this.liveDelKeys.length) parts.push(`${this.liveDelKeys.length} kayıt silindi`);
            if (this.liveReordered) parts.push('sıralama değişti');
            return parts.join(' · ');
        },

        /** Şeride tıklandı: yükle; yeni ve değişen satırlar sarıdan sönerek gösterilir (yeniler için ilk sayfa). */
        applyLive() {
            if (this.live?.counted) {
                this.pendingFresh = [...this.liveNewKeys, ...this.liveUpdKeys];
                if (this.liveNewKeys.length && this.page !== 1) this.page = 1;
                this.resetLive();
            }
            this.liveChanged = false;
            this.load({ silent: true });
        },

        /** Satırları sarı arka planla vurgula; CSS animasyonu beyaza döndürür. */
        flash(keys) {
            clearTimeout(this.freshTimer);
            this.fresh = keys.map(String);
            if (!this.fresh.length) return;
            this.freshTimer = setTimeout(() => (this.fresh = []), FRESH_MS);
        },

        isFresh(row) {
            return this.fresh.length > 0 && this.fresh.includes(String(this.keyOf(row)));
        },
    };
}
