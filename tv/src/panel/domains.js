/**
 * Jarvis → Domain arama (/panel/jarvis/domain-arama, iş: /panel/jarvis/domain-arama/3).
 *
 * - Hızlı kontrol: birkaç ad (en çok 100), beklemeden sonuç.
 * - Tarama: tarif (kısa 2–3 karakter / kalıp 4–5 / jenerik / liste) → canlı
 *   önizleme (aday sayısı, süre, örnek) → iş. İş sunucuda parça parça ilerler;
 *   bu ekran açıkken ilerletmeyi panel de yapar (`/jarvis/tick`), kapalıyken cron.
 * - Sonuçlar: kmGrid (sunucu taraflı; durum sekmeleri, uzunluk/uzantı/iş
 *   filtreleri, CSV). Nabız `jarvis_job` değişince kendiliğinden yenilenir.
 */
import { href, replaceUrl } from './routes.js';
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { status } from '../domain/status.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { copyText } from '../ui/clipboard.js';
import { fieldErrors } from '../domain/errors.js';

const MODES = [
    { key: 'short', label: 'Kısa (2–3)', hint: 'Seçilen uzunluktaki bütün kombinasyonlar: aa, ab … zz.' },
    { key: 'pattern', label: 'Kalıp (4–5)', hint: 'Okunuşu kolay adlar: C ünsüz, V ünlü, L harf, D rakam, A harf/rakam; küçük harfler sabit (ör. CVCio).' },
    { key: 'words', label: 'Jenerik', hint: 'Kelime listeleri, kendi kelimeleriniz ve yaygın ön/son ekler (get…, …hub).' },
    { key: 'list', label: 'Liste', hint: 'Her satıra bir ad; uzantısız satır seçili uzantılarla, uzantılı satır kendisiyle denenir.' },
];
const CHARSETS = [
    { key: 'letters', label: 'Harf' },
    { key: 'alnum', label: 'Harf + rakam' },
    { key: 'digits', label: 'Rakam' },
    { key: 'consonants', label: 'Ünsüz' },
];

export function kmDomains(jobId = null) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _visible: null,
        fmt,
        status,
        modes: MODES,
        charsets: CHARSETS,
        options: null,

        // Hızlı kontrol
        quick: { names: '', tlds: ['si'], busy: false, result: null },

        // Tarama tarifi
        spec: {
            mode: 'short',
            tlds: ['si'],
            lengths: [2, 3],
            charset: 'letters',
            prefix: '',
            suffix: '',
            patterns: ['CVCV', 'CVCVC'],
            customPattern: '',
            limit: 20000,
            shuffle: true,
            wordsets: ['en-short'],
            words: '',
            affixes: false,
            max_length: 0,
            names: '',
            hack: false,
            title: '',
        },
        preview: null,
        previewError: '',
        previewBusy: false,
        starting: false,
        errors: {},
        previewTimer: null,

        // İşler
        jobs: [],
        jobsLoading: true,
        selectedJob: jobId,
        driving: false,
        alive: true,

        grid: null,

        async init() {
            this.grid = this.gridConfig();
            try {
                this.options = await api.domainOptions();
            } catch (error) {
                toast.error(error);
            }
            this.$watch(() => this.spec, () => this.schedulePreview(), { deep: true });
            this.schedulePreview(0);
            await this.loadJobs();
            this.$watch(() => this.$store.pulse.version('jarvis_job'), () => this.loadJobs());
            this._visible = () => document.visibilityState === 'visible' && this.drive();
            document.addEventListener('visibilitychange', this._visible);
        },

        destroy() {
            this.alive = false;
            clearTimeout(this.previewTimer);
            document.removeEventListener('visibilitychange', this._visible);
            this.$store.pulse.fast(false);
        },

        // ------------------------------------------------------------ hızlı kontrol
        toggleTld(list, tld) {
            const i = list.indexOf(tld);
            if (i >= 0) {
                if (list.length > 1) list.splice(i, 1);
            } else if (list.length < 10) {
                list.push(tld);
            }
        },

        async check() {
            const names = this.quick.names.split(/[\s,;]+/).map((s) => s.trim()).filter(Boolean);
            if (!names.length) {
                toast.warning('Denenecek en az bir ad yazın.');
                return;
            }
            this.quick.busy = true;
            try {
                this.quick.result = await api.domainCheck({ names, tlds: this.quick.tlds });
                const free = this.quick.result.items.filter((i) => i.status === 'available').length;
                toast[free ? 'success' : 'info'](free ? `${free} boş ad bulundu.` : 'Boş ad çıkmadı.');
                window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'domains' } }));
            } catch (error) {
                toast.error(error);
            } finally {
                this.quick.busy = false;
            }
        },

        get quickFree() {
            return (this.quick.result?.items || []).filter((i) => i.status === 'available' || i.status === 'expiring');
        },

        copy(list) {
            const text = list.map((i) => i.domain || i).join('\n');
            copyText(text).then(() => toast.success(`${list.length} ad panoya kopyalandı.`)).catch(() => toast.error('Kopyalanamadı.'));
        },

        // ------------------------------------------------------------ tarama tarifi
        get modeHint() {
            return MODES.find((m) => m.key === this.spec.mode)?.hint || '';
        },

        toggleIn(list, value) {
            const i = list.indexOf(value);
            if (i >= 0) list.splice(i, 1);
            else list.push(value);
        },

        addPattern() {
            const p = this.spec.customPattern.replace(/\s+/g, '');
            if (p && !this.spec.patterns.includes(p)) this.spec.patterns.push(p);
            this.spec.customPattern = '';
        },

        /** Sunucuya giden tarif (yalnızca seçili türün alanları). */
        payload() {
            const s = this.spec;
            const base = { mode: s.mode, tlds: s.tlds, title: s.title.trim() };
            if (s.mode === 'short') return { ...base, lengths: s.lengths, charset: s.charset, prefix: s.prefix, suffix: s.suffix };
            if (s.mode === 'pattern') return { ...base, patterns: s.patterns, limit: Number(s.limit) || 0, shuffle: s.shuffle };
            if (s.mode === 'words') return { ...base, wordsets: s.wordsets, words: s.words, affixes: s.affixes, max_length: Number(s.max_length) || 0 };
            return { ...base, names: s.names, hack: s.hack };
        },

        schedulePreview(delay = 400) {
            clearTimeout(this.previewTimer);
            this.previewTimer = setTimeout(() => this.loadPreview(), delay);
        },

        async loadPreview() {
            this.previewBusy = true;
            try {
                this.preview = await api.domainPreview(this.payload());
                this.previewError = '';
                this.errors = {};
            } catch (error) {
                this.preview = null;
                this.errors = fieldErrors(error);
                this.previewError = error.message || 'Önizleme alınamadı.';
            } finally {
                this.previewBusy = false;
            }
        },

        get eta() {
            const m = this.preview?.estimate_minutes || 0;
            if (m < 1) return '1 dakikadan kısa';
            if (m < 90) return `~${fmt.number(Math.ceil(m))} dk`;
            return `~${fmt.number(m / 60, 1)} saat`;
        },

        async start() {
            if (!this.preview || this.starting) return;
            if (this.preview.total > 50000 && !(await confirmAction({
                title: 'Büyük tarama',
                message: `${fmt.number(this.preview.total)} aday taranacak (${this.eta}). Tarama arka planda sürer; bitince bildirim gelir.`,
                confirmText: 'Başlat',
            }))) {
                return;
            }
            this.starting = true;
            try {
                const job = await api.domainScan(this.payload());
                toast.success(`Tarama başladı: ${job.title}`);
                this.spec.title = '';
                this.selectJob(job.id);
                await this.loadJobs();
            } catch (error) {
                this.errors = fieldErrors(error);
                toast.error(error);
            } finally {
                this.starting = false;
            }
        },

        // ------------------------------------------------------------ işler
        async loadJobs() {
            try {
                const res = await api.jarvisJobs({ kind: 'domain_scan', per_page: 15, sort: 'id', order: 'desc' });
                this.jobs = res.items || [];
                this.$store.panel.activeJobs = this.jobs.filter((j) => ['queued', 'running', 'paused'].includes(j.status)).length;
            } catch (error) {
                toast.error(error);
            } finally {
                this.jobsLoading = false;
            }
            this.drive();
        },

        get running() {
            return this.jobs.filter((j) => j.status === 'queued' || j.status === 'running');
        },

        /** Ekran açıkken çalışan işi ilerlet (sunucu kilidi: aynı anda tek ilerletme). */
        async drive() {
            if (this.driving || !this.running.length) return;
            this.driving = true;
            this.$store.pulse.fast(true);
            try {
                while (this.alive && this.running.length && document.visibilityState === 'visible') {
                    const res = await api.jarvisTick({ budget: 8 });
                    const byId = Object.fromEntries((res.jobs || []).map((j) => [j.id, j]));
                    this.jobs = this.jobs.map((j) => byId[j.id] || (['queued', 'running'].includes(j.status) ? { ...j, status: 'done' } : j));
                    if (!(res.jobs || []).some((j) => j.status === 'queued' || j.status === 'running')) {
                        await this.loadJobs();
                        break;
                    }
                    const wait = this.running.some((j) => j.waiting_until) ? 5000 : 400;
                    await new Promise((r) => setTimeout(r, wait));
                }
            } catch {
                /* ağ hatası: cron devam eder, nabız yeniden tetikler */
            } finally {
                this.driving = false;
                this.$store.pulse.fast(this.running.length > 0);
                // İş bitti: bildirim zili ve diğer sekmeler beklemeden haberdar olsun.
                this.$store.pulse.poll();
            }
        },

        selectJob(id) {
            this.selectedJob = id;
            replaceUrl(href('domains', { job: id || null }));
            window.dispatchEvent(new CustomEvent('grid-filter', { detail: { id: 'domains', values: { job_id: id ? String(id) : '' } } }));
        },

        async control(job, action) {
            if (action === 'cancel' && !(await confirmAction({ title: 'Tarama durdurulsun mu?', message: 'Şimdiye kadarki sonuçlar kalır; tarama kaldığı yerden sürdürülemez.', confirmText: 'Durdur', tone: 'danger' }))) return;
            try {
                await api.jarvisJobControl(job.id, action);
                await this.loadJobs();
            } catch (error) {
                toast.error(error);
            }
        },

        async remove(job) {
            if (!(await confirmAction({ title: 'Tarama silinsin mi?', message: `"${job.title}" ve ${fmt.number(job.processed)} sonucu kalıcı olarak silinir.`, confirmText: 'Sil', tone: 'danger' }))) return;
            try {
                await api.jarvisJobDelete(job.id);
                if (this.selectedJob === job.id) this.selectJob(null);
                await this.loadJobs();
                toast.success('Tarama silindi.');
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ sonuç tablosu
        gridConfig() {
            const self = this;
            return {
                id: 'domains',
                source: (q) => api.domains(q),
                pageSize: 50,
                pageSizes: [50, 100],
                sort: { key: 'length', order: 'asc' },
                values: { status: 'found', ...(jobId ? { job_id: String(jobId) } : {}) },
                live: { topics: ['jarvis_job'], auto: true },
                exportName: 'domainler',
                exportCsv: (q) => self.exportCsv(q),
                selectable: true,
                empty: { title: 'Henüz sonuç yok', text: 'Hızlı kontrol yapın ya da bir tarama başlatın.', icon: 'globe' },
                columns: [
                    { key: 'domain', label: 'Alan adı', sortable: true, strong: true, mono: true, card: 'title' },
                    { key: 'status', label: 'Durum', badge: 'domain', sortable: true, card: 'badge' },
                    { key: 'length', label: 'Uzunluk', format: 'number', sortable: true, card: 'spec' },
                    { key: 'tld', label: 'Uzantı', cell: (r) => `.${r.tld}`, sortable: true, card: 'spec', hideBelow: 'lg' },
                    { key: 'registered_at', label: 'Kayıt', format: 'date', sortable: true, card: 'spec' },
                    { key: 'expires_at', label: 'Bitiş', format: 'date', sortable: true, card: 'spec', hideBelow: 'lg' },
                    { key: 'registrar', label: 'Kayıt firması', hideBelow: 'xl', hidden: true, muted: true },
                    { key: 'note', label: 'Not', hideBelow: 'xl', muted: true, card: 'meta' },
                    { key: 'checked_at', label: 'Kontrol', format: 'relative', sortable: true, hideBelow: 'lg', muted: true },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Ad içinde ara…' },
                    {
                        key: 'status',
                        type: 'tabs',
                        label: 'Durum',
                        options: [
                            { value: 'found', label: 'Boş + düşen', alias: true },
                            { value: 'available', label: 'Boş' },
                            { value: 'expiring', label: 'Düşüyor' },
                            { value: 'taken', label: 'Kayıtlı' },
                            { value: 'unknown', label: 'Belirsiz' },
                        ],
                    },
                    { key: 'job_id', type: 'select', label: 'Tarama', options: [], loadOptions: () => self.jobOptions() },
                    { key: 'length', type: 'select', label: 'Uzunluk', format: (v) => `${v} karakter` },
                    { key: 'tld', type: 'select', label: 'Uzantı', format: (v) => `.${v}` },
                ],
                rowActions: [{ key: 'copy', label: 'Kopyala', tone: 'ghost' }],
                bulkActions: [{ key: 'copy', label: 'Kopyala', tone: 'secondary' }],
            };
        },

        async jobOptions() {
            const res = await api.jarvisJobs({ kind: 'domain_scan', per_page: 50, sort: 'id', order: 'desc' });
            return [{ value: '0', label: 'Anlık kontroller' }, ...(res.items || []).map((j) => ({ value: String(j.id), label: `#${j.id} · ${j.title}` }))];
        },

        onGrid(event) {
            const { action, row, rows } = event.detail;
            if (action === 'copy') this.copy(rows || [row]);
        },

        async exportCsv(query) {
            try {
                const { page, per_page, ...rest } = query; // eslint-disable-line no-unused-vars
                const res = await api.domainExport(rest);
                const blob = await res.blob();
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `domainler-${new Date().toISOString().slice(0, 10)}.csv`;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (error) {
                toast.error(error);
            }
        },

        jobBadge(job) {
            return status('job', job.status);
        },
    };
}
