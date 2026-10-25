/**
 * Bülten (/panel/bulten; kodmob-core Modules\Newsletter). İki sekme:
 *
 * - Bültenler (varsayılan): taslaklar ve gönderilenler. Yeni / düzenle penceresi (konu, hedef dil, zengin metin),
 *   kendime deneme, gönder (onaylı abone sayısıyla onay), duraklat / devam et, ilerleme. Gönderim sunucuda parça
 *   parça (saatlik sınır, her dakika zamanlanmış görev); sayfa açıkken 20 sn'de bir yenilenir, cron çalışmıyorsa
 *   her yenilemede bir parça da gönderir (`continue`).
 * - Aboneler: tablo (kmGrid; durum sekmeleri, arama, CSV), abone ekle (onay e-postası gider), kalıcı sil.
 */
import { api } from './api.js';
import { href, replaceUrl } from './routes.js';
import { activeLocaleCodes, localeLabels } from './meta.js';
import { fmt } from '../domain/format.js';
import { status } from '../domain/status.js';
import { toast } from '../ui/toast.js';
import { localSource } from '../ui/grid/local-source.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const TABS = [
    { key: 'campaigns', label: 'Bültenler', icon: 'send' },
    { key: 'subscribers', label: 'Aboneler', icon: 'users' },
];

export const SOURCE_LABELS = { block: 'Sitedeki blok', page: 'Bülten sayfası', panel: 'Panelden' };

const POLL_MS = 20000;
const blank = () => ({ id: null, subject: '', body: '', locale: '' });

export function kmNewsletter(route = {}) {
    return {
        fmt,
        status,
        // init / destroy'da atananlar burada tanımlı olmalı (Alpine tanımsız özelliği panelin kök bileşenine yazar).
        _onRoute: null,
        _timer: null,
        tabs: TABS,
        tab: 'campaigns',
        loading: true,
        campaigns: [],
        audience: {},
        limit: 0,
        available: 0,
        cron: true,
        counts: { pending: 0, active: 0, unsubscribed: 0 },
        grid: null,
        // Pencereler: açık / kapalı ayrı (form nesnesi boşalmaz; kapanırken şablonun ifadeleri null okumasın).
        editing: false,
        form: blank(),
        addOpen: false,
        adding: { email: '', name: '', locale: '' },
        errors: {},
        busy: null,

        async init() {
            this.show(route.tab);
            this._onRoute = (e) => this.show(e.detail?.tab);
            window.addEventListener('km-route', this._onRoute);
            this.grid = this.gridConfig();
            await this.load();
            this._timer = setInterval(() => this.poll(), POLL_MS);
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
            clearInterval(this._timer);
        },

        show(key) {
            this.tab = TABS.some((t) => t.key === key) ? key : 'campaigns';
            replaceUrl(href('newsletter', { tab: this.tab }));
        },

        async load() {
            try {
                const data = await api.newsletterCampaigns();
                Object.assign(this, { campaigns: data.items, audience: data.audience, limit: data.limit, available: data.available, cron: data.cron });
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        /** Gönderilmekte olan bülten varken: ilerlemeyi yenile; cron yoksa bir parça gönder. */
        async poll() {
            const sending = this.campaigns.filter((c) => c.status === 'sending');
            if (!sending.length || document.hidden) return;
            if (!this.cron) {
                for (const c of sending) await api.campaignAction(c.id, 'continue').catch(() => null);
            }
            await this.load();
        },

        // ------------------------------------------------------------ bültenler

        get languages() {
            const labels = localeLabels();
            return [{ code: '', label: 'Bütün diller' }, ...activeLocaleCodes().map((code) => ({ code, label: labels[code] || code.toUpperCase() }))];
        },

        /** "Türkçe · 120 abone" */
        audienceText(locale) {
            const lang = this.languages.find((l) => l.code === (locale || ''));
            return `${lang?.label || locale} · ${this.audience[locale || ''] ?? 0} onaylı abone`;
        },

        progress(c) {
            return c.total ? Math.min(100, Math.round(((c.sent + c.failed) / c.total) * 100)) : 0;
        },

        open(campaign = null) {
            this.errors = {};
            this.form = campaign ? { id: campaign.id, subject: campaign.subject, body: campaign.body, locale: campaign.locale } : blank();
            this.editing = true;
        },

        async save(quiet = false) {
            if (this.busy) return null;
            this.busy = 'save';
            this.errors = {};
            const f = this.form;
            try {
                const saved = await api.saveCampaign(f.id, { subject: f.subject, body: f.body, locale: f.locale });
                this.form.id = saved.id;
                if (!quiet) toast.success('Bülten kaydedildi.');
                await this.load();
                return saved;
            } catch (error) {
                this.errors = error?.details?.fields || {};
                toast.error(error);
                return null;
            } finally {
                this.busy = null;
            }
        },

        /** Kaydedip kendi adresime gönderir. */
        async test() {
            const saved = await this.save(true);
            if (!saved) return;
            this.busy = 'test';
            try {
                const res = await api.campaignAction(saved.id, 'test');
                toast.success(`Deneme ${res.to} adresine gönderildi.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        /** Formdan: kaydet ve gönder. */
        async saveAndSend() {
            const saved = await this.save(true);
            if (saved && (await this.send(saved))) this.editing = false;
        },

        async send(c) {
            const count = this.audience[c.locale || ''] ?? 0;
            if (c.status === 'draft') {
                const ok = await confirmAction({
                    title: `"${c.subject}" gönderilsin mi?`,
                    message: `${this.audienceText(c.locale)}. Gönderim saatte en çok ${this.limit} e-postayla parça parça sürer; başladıktan sonra bülten değiştirilemez.`,
                    confirmText: `${count} kişiye gönder`,
                });
                if (!ok) return false;
            }
            this.busy = `send:${c.id}`;
            try {
                await api.campaignAction(c.id, 'send');
                toast.success(c.status === 'paused' ? 'Gönderim sürüyor.' : 'Gönderim başladı. İlerlemesi bu sayfada.');
                await this.load();
                if (!this.cron) await this.poll();
                return true;
            } catch (error) {
                toast.error(error);
                return false;
            } finally {
                this.busy = null;
            }
        },

        async pause(c) {
            this.busy = `pause:${c.id}`;
            try {
                await api.campaignAction(c.id, 'pause');
                toast.success('Gönderim duraklatıldı.');
                await this.load();
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        async remove(c) {
            const ok = await confirmAction({ title: `"${c.subject}" silinsin mi?`, message: c.status === 'sent' ? 'Gönderilmiş bültenin kaydı silinir; abonelere giden e-postalar etkilenmez.' : 'Taslak kalıcı olarak silinir.', confirmText: 'Sil', tone: 'danger' });
            if (!ok) return;
            try {
                await api.deleteCampaign(c.id);
                toast.success('Bülten silindi.');
                await this.load();
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ aboneler

        gridConfig() {
            return {
                id: 'newsletter-subscribers',
                source: async (q) => {
                    const data = await api.newsletterSubscribers();
                    this.counts = data.counts;
                    return localSource(data.items, { search: ['email', 'name'], facets: ['status', 'locale'] })(q);
                },
                pageSize: 25,
                sort: { key: 'created_at', order: 'desc' },
                exportName: 'bulten-aboneleri',
                empty: { title: 'Bu görünümde abone yok', text: 'Sitedeki Bülten bloğundan abone olanlar e-postalarını onaylayınca burada "Abone" görünür.', icon: 'users' },
                columns: [
                    { key: 'email', label: 'E-posta', sortable: true, strong: true, card: 'title' },
                    { key: 'name', label: 'Ad', cell: (r) => r.name || '—', card: 'subtitle', hideBelow: 'md' },
                    { key: 'locale', label: 'Dil', cell: (r) => r.locale.toUpperCase(), hideBelow: 'lg', card: 'spec' },
                    { key: 'source', label: 'Kaynak', cell: (r) => SOURCE_LABELS[r.source] || r.source, hideBelow: 'lg', card: 'spec' },
                    { key: 'status', label: 'Durum', badge: 'subscriber', card: 'badge' },
                    { key: 'created_at', label: 'Tarih', format: 'relative', sortable: true, card: 'meta', exportValue: (r) => fmt.dateTime(r.created_at) },
                    { key: 'confirmed_at', label: 'Onay', hidden: true, exportValue: (r) => (r.confirmed_at ? fmt.dateTime(r.confirmed_at) : '') },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'E-posta ya da ad' },
                    { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'subscriber' },
                ],
                rowActions: [{ key: 'delete', label: 'Kalıcı sil', icon: 'trash', danger: true }],
            };
        },

        reloadGrid() {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'newsletter-subscribers' } }));
        },

        openAdd() {
            this.errors = {};
            this.adding = { email: '', name: '', locale: activeLocaleCodes()[0] || 'tr' };
            this.addOpen = true;
        },

        async add() {
            if (this.busy) return;
            this.busy = 'add';
            this.errors = {};
            try {
                const row = await api.addSubscriber(this.adding);
                toast.success(`${row.email} adresine onay e-postası gönderildi; onaylayınca abone olur.`);
                this.addOpen = false;
                this.reloadGrid();
            } catch (error) {
                this.errors = error?.details?.fields || {};
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        async onAction({ action, row }) {
            if (action !== 'delete') return;
            const ok = await confirmAction({
                title: `${row.email} kalıcı olarak silinsin mi?`,
                message: 'Abonelik ve onay kaydı silinir (KVKK silme isteği). Yalnızca bülten almak istemiyorsa silmek yerine e-postadaki bağlantıyla çıkması yeterli.',
                confirmText: 'Kalıcı sil',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                await api.deleteSubscriber(row.id);
                toast.success('Abone silindi.');
                this.reloadGrid();
                await this.load();
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
