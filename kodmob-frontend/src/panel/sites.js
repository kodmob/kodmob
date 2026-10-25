/**
 * Siteler (/panel/siteler): aynı kurulumdan alt siteler (kodmob-core Core\Sites; yalnızca ana sitede,
 * yetki km_manage_sites). Her alt sitenin veritabanı, kullanıcıları, ayarları ve görselleri ayrıdır;
 * kod ortaktır. Site isteğin alan adından seçilir: alan adı sunucuda ana siteyle aynı klasöre bakmalı.
 *
 * - Yeni site: anahtar, ad, alan adları, modüller, veritabanı (varsayılan SQLite dosyası; paylaşımlı
 *   hostingde ayrı veritabanı açmak gerekmez), ilk yönetici. Kurulum tabloları da kurar (birkaç saniye).
 * - Düzenle: ad, alan adları, adres, modüller (veritabanı değişmez).
 * - Tabloları güncelle: yeni sürüm yüklenince (ana sitenin kurulum adresi de yapar).
 * - Kayıttan çıkar: veriler silinmez; aynı anahtarla yeniden kurulunca site geri gelir.
 * - Site Sihirbazı (yeni sitede varsayılan): işletme bilgisinden yapay zekâ önerisi (logo yazısı, renkler,
 *   site açıklaması, sayfa metinleri, taslak hizmetler; kodmob-core Core\Sites\SiteWizard). Öneri önizlenir ve
 *   düzenlenir; "Siteyi kur" ile site kurulup öneri içine uygulanır. Rakam ve müşteri adı uydurulmaz.
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { fieldErrors } from '../domain/errors.js';
import { isLocalHost, localDomain, parseDomains, siteKey, siteOrigin } from '../domain/sites.js';

export const DRIVERS = [
    { key: 'sqlite', label: 'SQLite (önerilen)', help: 'Tek dosya, sunucuda veritabanı açmak gerekmez. Paylaşımlı hosting ve küçük-orta siteler için.' },
    { key: 'mysql', label: 'MySQL', help: 'Hostinger\'da hPanel > Veritabanları\'ndan boş bir veritabanı açıp bilgilerini yazın.' },
    { key: 'mariadb', label: 'MariaDB', help: 'MySQL ile aynı bilgiler.' },
    { key: 'pgsql', label: 'PostgreSQL', help: 'VPS ya da bulut. Bütün siteler tek veritabanında ayrı şemalarda durabilir.' },
];

export const TONES = { kurumsal: 'Kurumsal', samimi: 'Samimi', premium: 'Premium', teknik: 'Teknik' };

const blankWizard = (languages) => ({
    on: true, sector: '', city: '', about: '', facts: '', tone: 'kurumsal', color: '',
    languages: languages.filter((l) => l.default).map((l) => l.code),
});

const blankForm = (modules) => ({
    key: '', name: '', domains: '', url: '',
    // Panel bu bilgisayarda açıksa yeni siteye `anahtar.localhost` eklenir (yerelde gerçek alan adı açılmaz).
    local: isLocalHost(window.location.hostname),
    modules: modules.map((m) => m.key),
    db: { driver: 'sqlite', host: '', port: '', database: '', username: '', password: '', schema: '' },
    admin: { username: '', email: '', display_name: '', password: '' },
});

export function kmSites() {
    return {
        sites: [],
        modules: [],
        languages: [],
        aiReady: false,
        loading: true,
        // Site Sihirbazı: işletme bilgisi, öneri (sunucudan, düzenlenebilir)
        wizard: null,
        plan: null,
        planning: false,
        tones: TONES,
        busy: '',
        // Açık pencere: null | { mode: 'create' | 'edit', key? }
        dialog: null,
        form: null,
        keyTouched: false,
        errors: {},
        saving: false,
        created: null,
        drivers: DRIVERS,

        async init() {
            await this.load();
            // Proje seçicideki "+ Yeni proje": ?yeni ile gelince kurulum penceresi açılır.
            if (new URLSearchParams(window.location.search).has('yeni') && this.modules.length) this.openCreate();
        },

        async load() {
            try {
                const data = await api.sites();
                this.sites = data.sites;
                this.modules = data.modules;
                this.languages = data.languages || [];
                this.aiReady = !!data.ai;
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        get pending() {
            return this.sites.filter((s) => s.status && (s.status.pending > 0 || s.status.error));
        },

        status(site) {
            const s = site.status || {};
            if (s.error) return { tone: 'danger', label: 'Hata', title: s.error };
            if (s.pending) return { tone: 'warning', label: `${s.pending} güncelleme bekliyor`, title: 'Yeni sürümün tabloları kurulmamış: "Tabloları güncelle".' };
            if (!s.admins) return { tone: 'warning', label: 'Yönetici yok', title: 'Etkin yönetici yok; komut satırından kodmob:admin ile eklenir.' };
            return { tone: 'success', label: 'Hazır', title: 'Tablolar güncel, yönetici var.' };
        },

        dbLabel(site) {
            const d = site.db || {};
            const name = DRIVERS.find((x) => x.key === d.driver)?.label.replace(' (önerilen)', '') || d.driver;
            return [name, d.host, d.database, d.schema && `şema ${d.schema}`].filter(Boolean).join(' · ');
        },

        moduleLabels(site) {
            if (!site.modules) return 'Ana sitenin modülleri';
            if (!site.modules.length) return 'Yalnızca çekirdek';
            return site.modules.map((k) => this.modules.find((m) => m.key === k)?.label || k).join(', ');
        },

        siteUrl(site) {
            return siteOrigin(site, window.location);
        },

        /** Panel bu bilgisayarda mı açık (yeni sitede yerel deneme adresi kutusu). */
        get isLocal() {
            return isLocalHost(window.location.hostname);
        },

        /** Yerel deneme adresinin tarayıcıdaki hali: http://anahtar.localhost:8090/ */
        get localPreview() {
            const host = localDomain(this.form?.key.trim());
            return host ? `${window.location.protocol}//${host}${window.location.port ? `:${window.location.port}` : ''}/` : '';
        },

        // ------------------------------------------------------------ pencere

        openCreate() {
            this.form = blankForm(this.modules);
            this.wizard = blankWizard(this.languages);
            this.plan = null;
            this.keyTouched = false;
            this.errors = {};
            this.created = null;
            this.dialog = { mode: 'create' };
            this.$nextTick(() => this.$root.querySelector('[data-site-first]')?.focus());
        },

        openEdit(site) {
            this.form = {
                ...blankForm(this.modules),
                key: site.key,
                name: site.name,
                domains: site.domains.join('\n'),
                url: site.url !== `https://${site.domains[0]}` ? site.url : '',
                modules: site.modules ?? this.modules.map((m) => m.key),
                inheritModules: site.modules === null,
            };
            this.errors = {};
            this.created = null;
            this.dialog = { mode: 'edit', key: site.key };
            this.$nextTick(() => this.$root.querySelector('[data-site-first]')?.focus());
        },

        close() {
            if (this.saving) return;
            this.dialog = null;
            this.form = null;
        },

        nameChanged() {
            if (this.dialog?.mode === 'create' && !this.keyTouched) this.form.key = siteKey(this.form.name);
        },

        toggleModule(key) {
            const list = this.form.modules;
            this.form.modules = list.includes(key) ? list.filter((k) => k !== key) : [...list, key];
            this.form.inheritModules = false;
        },

        get driver() {
            return DRIVERS.find((d) => d.key === this.form?.db.driver) || DRIVERS[0];
        },

        body() {
            const f = this.form;
            const domains = parseDomains(f.domains);
            const local = this.dialog.mode === 'create' && f.local && this.isLocal ? localDomain(f.key.trim()) : '';
            if (local && !domains.includes(local)) domains.push(local);
            const body = { name: f.name.trim(), domains, url: f.url.trim(), modules: f.inheritModules ? null : f.modules };
            if (this.dialog.mode === 'edit') return body;
            const db = f.db.driver === 'sqlite' ? { driver: 'sqlite' } : Object.fromEntries(Object.entries(f.db).filter(([k, v]) => v !== '' && (k !== 'schema' || f.db.driver === 'pgsql')));
            const created = { ...body, key: f.key.trim(), db, admin: { ...f.admin, username: f.admin.username.trim(), email: f.admin.email.trim() } };
            return this.wizard?.on && this.plan ? { ...created, plan: this.plan } : created;
        },

        // ------------------------------------------------------------ Site Sihirbazı

        toggleLanguage(code) {
            const list = this.wizard.languages;
            this.wizard.languages = list.includes(code) ? list.filter((c) => c !== code) : [...list, code];
        },

        /** İşletme bilgisinden öneri (yapay zekâ anahtarı yoksa yalnızca kimlik ve renkler). */
        async makePlan() {
            if (this.planning) return;
            this.planning = true;
            this.errors = {};
            try {
                const w = this.wizard;
                this.plan = await api.planSite({ name: this.form.name.trim(), sector: w.sector.trim(), city: w.city.trim(), about: w.about.trim(), facts: w.facts.trim(), tone: w.tone, color: w.color, languages: w.languages });
                this.$nextTick(() => this.$root.querySelector('[data-plan]')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            } catch (error) {
                this.errors = fieldErrors(error);
                toast.error(Object.keys(this.errors).length ? Object.values(this.errors)[0] : error);
            } finally {
                this.planning = false;
            }
        },

        /** Öneride ana sayfa metni (varsayılan dil); düzenlenince öneriye yazılır. */
        planText(key) {
            return this.plan?.texts?.[this.plan.default]?.[key] ?? '';
        },

        setPlanText(key, value) {
            if (this.plan?.texts?.[this.plan.default]) this.plan.texts[this.plan.default][key] = value;
        },

        removeService(index) {
            this.plan.services.splice(index, 1);
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.errors = {};
            let saved = false;
            try {
                if (this.dialog.mode === 'create') {
                    const site = await api.createSite(this.body());
                    this.created = site;
                    toast.success(`${site.name} kuruldu.`);
                } else {
                    const site = await api.updateSite(this.dialog.key, this.body());
                    toast.success(`${site.name} kaydedildi.`);
                    this.dialog = null;
                }
                saved = true;
            } catch (error) {
                this.errors = fieldErrors(error);
                toast.error(Object.keys(this.errors).length ? Object.values(this.errors)[0] : error);
            } finally {
                this.saving = false;
            }
            // Liste arkada yenilenir; pencere bu sırada kapatılabilir.
            if (saved) await this.load();
        },

        // ------------------------------------------------------------ işlemler

        async migrate(site = null) {
            if (this.busy) return;
            this.busy = site ? site.key : 'all';
            try {
                const { results, sites } = await api.migrateSites(site ? site.key : 'all');
                this.sites = sites;
                const failed = results.filter((r) => !r.ok);
                if (failed.length) toast.error(failed.map((r) => `${r.key}: ${r.output}`).join(' · '));
                else toast.success(site ? `${site.name}: tablolar güncel.` : 'Bütün sitelerin tabloları güncel.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async remove(site) {
            const ok = await confirmAction({
                title: `${site.name} kayıttan çıkarılsın mı?`,
                message: 'Alan adları artık ana siteyi açar. Veritabanı, ayarlar ve görseller silinmez; aynı anahtarla yeniden kurunca site geri gelir.',
                confirmText: 'Kayıttan çıkar',
                tone: 'danger',
            });
            if (!ok) return;
            this.busy = site.key;
            try {
                await api.removeSite(site.key);
                this.sites = this.sites.filter((s) => s.key !== site.key);
                toast.success(`${site.name} kayıttan çıkarıldı.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },
    };
}
