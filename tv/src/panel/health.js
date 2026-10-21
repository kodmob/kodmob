/**
 * Sistem Sağlığı (/panel/sistem-sagligi): kurulumun tek bakışta özeti.
 * Kartlar sunucudan gelir (kodmob-api Core\Health; modüller `health()` kancasıyla kart ekler) ve burada
 * yalnızca çizilir: yeni modülün kartı için panelde kod gerekmez. Bulgular üstte, en kötüsü önce.
 *
 * Hata kodları (ErrorJournal): kullanıcı "Hata kodu: DB-3F9A1C" iletince ayrıntısı burada aranır.
 * "Bildir": özeti Ayarlar › Sistem sağlığı'ndaki rapor alıcısına e-postayla gönderir.
 */
import { api } from './api.js';
import { href } from './routes.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const LEVELS = {
    ok: ['İyi', 'badge-success'],
    info: ['Bilgi', 'badge-neutral'],
    warn: ['Dikkat', 'badge-warning'],
    critical: ['Kritik', 'badge-danger'],
};
const OVERALL = { ok: 'Sistem iyi durumda', warn: 'Dikkat gerekiyor', critical: 'Kritik bulgu var' };
const RANK = { critical: 0, warn: 1, info: 2 };

export function kmHealth() {
    return {
        report: null,
        loading: true,
        error: '',
        sending: false,
        errors: [],
        query: '',

        async init() {
            await this.load();
        },

        async load() {
            this.loading = true;
            this.error = '';
            api.healthErrors().then((items) => (this.errors = items)).catch(() => {});
            try {
                this.report = await api.health();
            } catch (e) {
                this.error = e?.message || 'Sağlık verisi alınamadı.';
            } finally {
                this.loading = false;
            }
        },

        get sections() {
            return this.report?.sections || [];
        },

        /** Bütün kartların bulguları (bilgi notları hariç), kart adıyla; kritik önce. */
        get findings() {
            return this.sections
                .flatMap((s) => (s.flags || []).filter((f) => f.level !== 'info').map((f) => ({ ...f, area: s.title, link: s.link })))
                .sort((a, b) => RANK[a.level] - RANK[b.level]);
        },

        levelText: (level) => (LEVELS[level] || LEVELS.ok)[0],
        levelBadge: (level) => (LEVELS[level] || LEVELS.ok)[1],

        get overallText() {
            return OVERALL[this.report?.level] || '';
        },

        link(target) {
            return target ? href(target.route, target.tab ? { tab: target.tab } : {}) : null;
        },

        /** Rapor alıcısı ayarı: Ayarlar sayfası açılıp alan vurgulanır (ui/spotlight.js). */
        get recipientSetting() {
            return `${href('settings')}#ayar=health.report_to`;
        },

        async send() {
            if (!this.report?.report_to) {
                toast.warning('Önce rapor alıcısını yazın: Ayarlar › Sistem sağlığı.');
                return;
            }
            this.sending = true;
            try {
                await api.healthReport();
                toast.success('Sağlık özeti rapor alıcısına gönderildi.');
            } catch (e) {
                toast.error(e);
            } finally {
                this.sending = false;
            }
        },

        /** Koda ya da metne göre süzülmüş hata kodları (kullanıcının ilettiği kod buraya yazılır). */
        get shownErrors() {
            const q = this.query.trim().toLocaleLowerCase('tr-TR');
            if (!q) return this.errors;
            return this.errors.filter((e) => [e.ref, e.error, e.request, e.context, e.user_name].some((v) => String(v || '').toLocaleLowerCase('tr-TR').includes(q)));
        },

        async clearErrors() {
            const ok = await confirmAction({
                title: 'Hata kodları temizlensin mi?',
                message: 'Kayıtlı bütün hata kodları ve ayrıntıları silinir. Kullanıcının ileteceği eski kodlar bir daha bulunamaz.',
                confirmText: 'Temizle',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                await api.clearHealthErrors();
                this.errors = [];
                toast.success('Hata kodları temizlendi.');
            } catch (e) {
                toast.error(e);
            }
        },
    };
}
