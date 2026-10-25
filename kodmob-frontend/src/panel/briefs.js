/**
 * Talepler (proje brifi, hesaplayıcı, iletişim formu) — ortak tablo (kmGrid): durum sekmeleri
 * sayılarıyla, kaynak / dil / tarih süzgeci, arama, "Seç" ile toplu okundu / arşiv, CSV ve yan
 * panelde detay. Detay açılınca talep "okundu" olur. Kalıcı silme (genel Sil) kişinin KVKK silme
 * talebi içindir (`km_delete_briefs`); talepler saklama süresi sonunda zaten otomatik silinir.
 *
 * `/panel/talepler/12` (bildirimden) talebi doğrudan açar. Yeni talep gelince (nabız `brief`) liste
 * kendiliğinden yenilenir ve yeni satır vurgulanır.
 */
import { api } from './api.js';
import { relative, localeLabels } from './meta.js';
import { fmt } from '../domain/format.js';
import { status } from '../domain/status.js';
import { toast } from '../ui/toast.js';
import { deleteRecord } from '../ui/records/records.js';
import { confirmAction } from '../ui/confirm/confirm.js';

export const BRIEF_STATUS = {
    new: { label: 'Yeni', badge: 'badge-primary' },
    read: { label: 'Okundu', badge: 'badge-neutral' },
    archived: { label: 'Arşiv', badge: 'badge-warning' },
};

const SOURCE_LABELS = { brief: 'Proje brifi', calculator: 'Teklif hesaplayıcı', contact: 'İletişim formu' };
/** Hizmet adları sunucudan (`service_labels`, sitenin sözlüğü: kodmob-frontend/src/i18n → brief.services). */
const services = (brief) => (brief?.service_labels || brief?.services || []).join(', ');

/** Talebin ana hattı (adım göstergesi; kodmob-core Brief). */
const FLOW = ['new', 'read', 'replied'];

const DONE = { read: 'Okundu sayıldı.', replied: 'Yanıtlandı olarak işaretlendi.', archived: 'Arşive alındı.', reopen: 'Arşivden çıkarıldı.' };

export function kmBriefs(openId = null) {
    return {
        status: BRIEF_STATUS,
        relative,
        fmt,
        grid: null,
        brief: null,
        briefing: false,

        init() {
            this.grid = this.gridConfig();
            if (openId) this.show({ id: openId, status: 'read' });
        },

        get canManage() {
            return this.$store.session.can('km_manage_briefs');
        },

        gridConfig() {
            return {
                id: 'briefs',
                syncUrl: true,
                source: (q) => api.briefs(q),
                recordType: 'brief',
                deleteCan: 'km_delete_briefs',
                restorable: false,
                pageSize: 20,
                sort: { key: 'created_at', order: 'desc' },
                values: { status: 'new' },
                live: { types: ['brief'] },
                exportName: 'talepler',
                empty: { title: 'Bu görünümde talep yok', text: 'Siteden gelen proje brifi, hesaplayıcı ve iletişim talepleri burada.', icon: 'inbox' },
                columns: [
                    { key: 'name', label: 'Kişi', sortable: true, strong: true, card: 'title' },
                    { key: 'company', label: 'Şirket', muted: true, card: 'subtitle', hideBelow: 'lg' },
                    { key: 'services', label: 'Hizmetler', cell: (r) => services(r) || '—', hideBelow: 'lg', card: 'meta' },
                    { key: 'source', label: 'Kaynak', cell: (r) => SOURCE_LABELS[r.source] || r.source, filterBy: 'source', hideBelow: 'xl', card: 'spec' },
                    { key: 'locale', label: 'Dil', cell: (r) => String(r.locale || '').toUpperCase(), filterBy: 'locale', hideBelow: 'xl', hidden: true },
                    { key: 'email', label: 'E-posta', muted: true, hidden: true, hideBelow: 'xl' },
                    { key: 'phone', label: 'Telefon', muted: true, hidden: true, hideBelow: 'xl' },
                    { key: 'status', label: 'Durum', badge: 'brief', card: 'badge' },
                    { key: 'created_at', label: 'Geliş', format: 'relative', sortable: true, card: 'spec', exportValue: (r) => fmt.dateTime(r.created_at) },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Ad, e-posta, şirket ya da mesaj' },
                    { key: 'status', type: 'tabs', label: 'Durum', statusKind: 'brief' },
                    { key: 'source', type: 'select', label: 'Kaynak', options: Object.entries(SOURCE_LABELS).map(([value, label]) => ({ value, label })) },
                    { key: 'locale', type: 'select', label: 'Dil', options: Object.entries(localeLabels()).map(([value, label]) => ({ value, label })) },
                    { key: 'created_at', type: 'date-range', label: 'Geliş tarihi' },
                ],
                rowActions: [
                    { key: 'open', label: 'Aç', primary: true, open: true },
                    { key: 'read', label: 'Okundu say', icon: 'check', can: 'km_manage_briefs', when: (r) => r.status === 'new' },
                    { key: 'replied', label: 'Yanıtlandı say', icon: 'send', can: 'km_manage_briefs', when: (r) => ['new', 'read'].includes(r.status) },
                    { key: 'archive', label: 'Arşive al', icon: 'inbox', can: 'km_manage_briefs', when: (r) => r.status !== 'archived' },
                    { key: 'reopen', label: 'Arşivden çıkar', icon: 'rotate-ccw', can: 'km_manage_briefs', when: (r) => r.status === 'archived' },
                ],
                bulkActions: [
                    { key: 'read', label: 'Okundu say', icon: 'check', can: 'km_manage_briefs' },
                    { key: 'archive', label: 'Arşive al', icon: 'inbox', can: 'km_manage_briefs' },
                ],
            };
        },

        reload() {
            window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'briefs' } }));
            this.$store.panel.refreshBadges();
        },

        async onAction({ action, row }) {
            const status = { read: 'read', replied: 'replied', archive: 'archived', reopen: 'reopen' }[action];
            if (!status) return;
            try {
                await api.briefStatus(row.id, status);
                toast.success(DONE[status]);
                this.reload();
            } catch (error) {
                toast.error(error);
            }
        },

        async onBulk({ action, rows }) {
            const status = action === 'archive' ? 'archived' : 'read';
            const list = rows.filter((r) => (status === 'read' ? r.status === 'new' : r.status !== 'archived'));
            if (!list.length) return toast.info('Seçilenler arasında bu işlemi bekleyen talep yok.');
            if (status === 'archived' && !(await confirmAction({ title: `${list.length} talep arşive alınsın mı?`, message: 'Arşivdeki talepler "Arşiv" sekmesinde durur; istediğinizde geri alınır.', confirmText: 'Arşive al' }))) return;
            for (const row of list) await api.briefStatus(row.id, status).catch((e) => toast.error(e));
            toast.success(`${list.length} talep güncellendi.`);
            this.reload();
        },

        async show(row) {
            this.briefing = true;
            this.brief = row;
            try {
                this.brief = await api.brief(row.id);
                if (row.status === 'new') this.reload();
            } catch (error) {
                toast.error(error);
                this.brief = null;
            } finally {
                this.briefing = false;
            }
        },

        services,

        source(key) {
            return SOURCE_LABELS[key] || key;
        },

        money(estimate) {
            if (!estimate) return '';
            const f = (n) => new Intl.NumberFormat('tr-TR').format(n);
            return `${f(estimate.min)} – ${f(estimate.max)} ${estimate.currency}${estimate.weeks ? ` · ${estimate.weeks}` : ''}`;
        },

        get replyHref() {
            if (!this.brief) return '';
            // Konu sitenin adıyla (Proje Ayarları > Kimlik ya da derlemedeki ad); çatıda sabit ad yok.
            const site = this.$store.session.me?.project?.name || '';
            return `mailto:${this.brief.email}?subject=${encodeURIComponent(site ? `${site} · Talebiniz` : 'Talebiniz')}`;
        },

        async toggleArchive() {
            await this.move(this.brief.status === 'archived' ? 'reopen' : 'archived');
        },

        /** Talebin durumunu ilerletir (okundu, yanıtlandı, arşiv, arşivden geri). */
        async move(status) {
            try {
                this.brief = await api.briefStatus(this.brief.id, status);
                toast.success(DONE[status]);
                this.reload();
            } catch (error) {
                toast.error(error);
            }
        },

        /** Talebin yolu: yeni → okundu → yanıtlandı (arşiv yolun dışında; ayrıntıda not olarak). */
        flow(brief) {
            const at = FLOW.indexOf(brief?.status);
            return FLOW.map((key, i) => ({ key, label: status('brief', key).label, state: at < 0 ? 'off' : i < at ? 'done' : i === at ? 'now' : 'todo' }));
        },

        async remove() {
            if (await deleteRecord({ type: 'brief', id: this.brief.id, label: this.brief.name })) {
                this.brief = null;
                this.reload();
            }
        },
    };
}
