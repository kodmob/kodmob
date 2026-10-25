/**
 * Ayarlar > E-posta (`kmMail`), SMS (`kmSms`), Yapay zekâ (`kmAi`) ve Arka plan (`kmJobs`).
 * Yetki: km_manage_settings.
 *
 * - E-posta: gönderim yöntemi (.env, SMTP, sendmail, günlük), SMTP sunucusu ve gönderen. Şifre
 *   sunucuda şifreli saklanır, panele geri gelmez: boş bırakılırsa eskisi kalır. "Deneme gönder"
 *   kuyruğa girmeden hemen gönderir ve sunucunun hatasını gösterir. Altında son gönderimler.
 * - SMS: NetGSM hesabı (kullanıcı adı, API şifresi, onaylı gönderici başlığı, İYS filtresi). Şifre
 *   e-postadaki gibi saklanır. Kullanıcılar sayfasındaki SMS düğmeleri yalnızca SMS ayarlıyken görünür.
 * - Arka plan: cron son ne zaman çalıştı, kuyrukta bekleyen ve başarısız işler (yeniden dene / sil).
 *   Cron yoksa işler yanıttan sonra aynı istekte çalışır (kodmob-core App\Core\Jobs\Background).
 */
import { api } from './api.js';
import { relative } from './meta.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { analyze } from '../domain/sms.js';

const KINDS = { 'brief.notify': 'Talep bildirimi', 'brief.reply': 'Talep yanıtı', 'user.message': 'Kullanıcıya mesaj', test: 'Deneme' };
const PORTS = { ssl: 465, tls: 587 };

export function kmMail() {
    return {
        loading: true,
        saving: false,
        testing: false,
        view: null,
        form: {},
        password: '',
        errors: {},
        log: [],
        testTo: '',
        fmt,
        relative,

        async init() {
            this.testTo = this.$store.session.me?.email || '';
            try {
                this.apply(await api.mailSettings());
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        apply(view) {
            this.view = view;
            this.log = view.log || [];
            this.form = {
                mailer: view.mailer, host: view.host, port: view.port, encryption: view.encryption,
                username: view.username, from_address: view.from_address, from_name: view.from_name,
            };
            this.password = '';
        },

        get smtp() {
            return this.form.mailer === 'smtp';
        },

        /** .env seçiliyken gerçekte ne kullanılıyor. */
        get envSummary() {
            const env = this.view?.env || {};
            return [env.mailer || '—', env.host, env.from_address].filter(Boolean).join(' · ');
        },

        /** Şifreleme değişince bilinen port da değişir (elle girilmiş başka port korunur). */
        setEncryption(value) {
            if (Object.values(PORTS).includes(Number(this.form.port))) this.form.port = PORTS[value] ?? this.form.port;
            this.form.encryption = value;
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            this.errors = {};
            try {
                this.apply(await api.saveMailSettings({ ...this.form, ...(this.password ? { password: this.password } : {}) }));
                toast.success('E-posta ayarları kaydedildi.');
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        async clearPassword() {
            try {
                this.apply(await api.saveMailSettings({ clear_password: true }));
                toast.success('SMTP şifresi silindi.');
            } catch (error) {
                toast.error(error);
            }
        },

        async test() {
            if (this.testing) return;
            this.testing = true;
            this.errors = {};
            try {
                const result = await api.testMail(this.testTo);
                this.log = result.log;
                toast.success(`Deneme e-postası gönderildi: ${result.to}`);
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
                api.mailSettings().then((view) => (this.log = view.log)).catch(() => {});
            } finally {
                this.testing = false;
            }
        },

        kindLabel(kind) {
            return KINDS[kind] || kind;
        },
    };
}

/**
 * Ayarlar > SMS: sağlayıcı (kapalı, NetGSM, deneme), NetGSM hesabı, onaylı gönderici başlıkları
 * ("Bağlantıyı dene" kredi harcamadan başlıkları getirir), deneme SMS'i ve son gönderimler.
 */
export function kmSms() {
    return {
        loading: true,
        saving: false,
        checking: false,
        testing: false,
        view: null,
        form: {},
        password: '',
        senders: [],
        errors: {},
        log: [],
        testTo: '',
        testMessage: '',
        relative,

        async init() {
            this.testTo = this.$store.session.me?.phone || '';
            try {
                this.apply(await api.smsSettings());
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        apply(view) {
            this.view = view;
            this.log = view.log || [];
            this.form = { provider: view.provider, username: view.username, sender: view.sender, iys_filter: view.iys_filter, app_name: view.app_name };
            this.password = '';
        },

        get netgsm() {
            return this.form.provider === 'netgsm';
        },

        get dirty() {
            const v = this.view || {};
            return !!this.password || ['provider', 'username', 'sender', 'iys_filter', 'app_name'].some((k) => (this.form[k] ?? '') !== (v[k] ?? ''));
        },

        get info() {
            return analyze(this.testMessage);
        },

        async save({ quiet = false } = {}) {
            if (this.saving) return false;
            this.saving = true;
            this.errors = {};
            try {
                this.apply(await api.saveSmsSettings({ ...this.form, ...(this.password ? { password: this.password } : {}) }));
                if (!quiet) toast.success('SMS ayarları kaydedildi.');
                return true;
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
                return false;
            } finally {
                this.saving = false;
            }
        },

        async clearPassword() {
            try {
                this.apply(await api.saveSmsSettings({ clear_password: true }));
                toast.success('NetGSM şifresi silindi.');
            } catch (error) {
                toast.error(error);
            }
        },

        /** Kaydedilmemiş değişiklik varsa önce kaydeder; sonra onaylı başlıkları getirir. */
        async check() {
            if (this.checking) return;
            if (this.dirty && !(await this.save({ quiet: true }))) return;
            this.checking = true;
            try {
                this.senders = (await api.smsSenders()).senders || [];
                const known = this.senders.includes(this.form.sender);
                if (known) toast.success(`Bağlantı çalışıyor. Onaylı başlıklar: ${this.senders.join(', ')}`);
                else toast.info(`Bağlantı çalışıyor ama "${this.form.sender}" başlığı listede yok. Aşağıdan seçin.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.checking = false;
            }
        },

        async useSender(sender) {
            this.form.sender = sender;
            await this.save();
        },

        async test() {
            if (this.testing) return;
            if (this.dirty && !(await this.save({ quiet: true }))) return;
            this.testing = true;
            this.errors = {};
            try {
                const result = await api.testSms(this.testTo, this.testMessage);
                this.log = result.log;
                toast.success(`Deneme SMS'i gönderildi (${result.parts} boy).`);
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
                api.smsSettings().then((view) => (this.log = view.log)).catch(() => {});
            } finally {
                this.testing = false;
            }
        },

        kindLabel(kind) {
            return KINDS[kind] || kind;
        },
    };
}

/**
 * Ayarlar > Yapay zekâ (`kmAi`; kodmob-core App\Core\Ai): Claude, OpenAI, Gemini. Her sağlayıcının anahtarı
 * ayrı ve şifreli saklanır, panele geri gelmez (boş bırakılırsa eskisi kalır). Etkin sağlayıcı Jarvis
 * sohbetinde ve içerik çevirisinde kullanılır. Model alanı önerilenlerden seçilir ya da elle yazılır;
 * "Modelleri getir" anahtarın erişebildiği modelleri sağlayıcıdan çeker.
 */
export function kmAi() {
    return {
        loading: true,
        saving: false,
        view: null,
        form: { provider: 'anthropic', effort: 'medium', web_search: true, max_steps: 10, models: {} },
        keys: {},
        errors: {},
        busy: null,
        tests: {},
        fetched: {},

        async init() {
            try {
                this.apply(await api.aiSettings());
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        apply(view) {
            this.view = view;
            this.form = {
                provider: view.provider,
                effort: view.effort,
                web_search: view.web_search,
                max_steps: view.max_steps,
                models: Object.fromEntries(view.providers.map((p) => [p.key, p.model])),
            };
            this.keys = Object.fromEntries(view.providers.map((p) => [p.key, '']));
        },

        get providers() {
            return this.view?.providers || [];
        },

        get active() {
            return this.providers.find((p) => p.key === this.form.provider) || null;
        },

        get dirty() {
            const v = this.view;
            if (!v) return false;
            return this.form.provider !== v.provider || this.form.effort !== v.effort || this.form.web_search !== v.web_search
                || Number(this.form.max_steps) !== v.max_steps || this.providers.some((p) => this.form.models[p.key] !== p.model || !!this.keys[p.key]);
        },

        /** Önerilenler + sağlayıcıdan getirilenler (aynı model bir kez). */
        modelOptions(provider) {
            const seen = new Set();
            return [...provider.models, ...(this.fetched[provider.key] || [])].filter((m) => !seen.has(m.id) && seen.add(m.id));
        },

        body() {
            return {
                provider: this.form.provider,
                effort: this.form.effort,
                web_search: this.form.web_search,
                max_steps: Number(this.form.max_steps),
                providers: Object.fromEntries(this.providers.map((p) => [p.key, { model: this.form.models[p.key], ...(this.keys[p.key] ? { api_key: this.keys[p.key] } : {}) }])),
            };
        },

        async save({ quiet = false } = {}) {
            if (this.saving) return false;
            this.saving = true;
            this.errors = {};
            try {
                this.apply(await api.saveAiSettings(this.body()));
                if (!quiet) toast.success('Yapay zekâ ayarları kaydedildi.');
                return true;
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
                return false;
            } finally {
                this.saving = false;
            }
        },

        fieldError(provider, field) {
            return this.errors[`providers.${provider.key}.${field}`] || '';
        },

        async clearKey(provider) {
            const ok = await confirmAction({
                title: `${provider.label} anahtarı silinsin mi?`,
                message: provider.key === this.form.provider ? 'Bu etkin sağlayıcı: Jarvis sohbeti ve içerik çevirisi anahtar girilene ya da başka sağlayıcı seçilene kadar çalışmaz.' : 'Etkin sağlayıcı değişmez.',
                confirmText: 'Sil',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                this.apply(await api.saveAiSettings({ providers: { [provider.key]: { clear_key: true } } }));
                toast.success('Anahtar silindi.');
            } catch (error) {
                toast.error(error);
            }
        },

        /** Kaydedilmemiş değişiklik varsa önce kaydeder; sonra kısa bir deneme isteği. */
        async test(provider) {
            if (this.busy) return;
            if (this.dirty && !(await this.save({ quiet: true }))) return;
            this.busy = `test:${provider.key}`;
            this.tests = { ...this.tests, [provider.key]: null };
            try {
                const res = await api.testAi(provider.key);
                this.tests = { ...this.tests, [provider.key]: { ok: true, text: `${res.model} · ${fmt.number(res.ms)} ms · "${res.reply}"` } };
            } catch (error) {
                this.tests = { ...this.tests, [provider.key]: { ok: false, text: error.message } };
            } finally {
                this.busy = null;
            }
        },

        async fetchModels(provider) {
            if (this.busy) return;
            if (this.dirty && !(await this.save({ quiet: true }))) return;
            this.busy = `models:${provider.key}`;
            try {
                const res = await api.aiModels(provider.key);
                this.fetched = { ...this.fetched, [provider.key]: res.models };
                toast.success(`${provider.label}: ${res.models.length} model bulundu; model alanında listelenir.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },
    };
}

export function kmJobs() {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _timer: null,
        loading: true,
        data: null,
        busy: null,
        relative,
        fmt,

        async init() {
            await this.load();
            this._timer = setInterval(() => this.load(), 30000);
        },

        destroy() {
            clearInterval(this._timer);
        },

        async load() {
            try {
                this.data = await api.jobs();
            } catch (error) {
                if (this.loading) toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        get cronText() {
            const last = this.data?.cron?.last;
            if (!last) return 'Hiç çalışmadı: cron kurulmamış.';
            return this.data.cron.alive ? `Çalışıyor · son ${relative(last)}` : `Durmuş · son ${relative(last)}`;
        },

        async retry(job) {
            this.busy = job.uuid;
            try {
                this.data = await api.retryJob(job.uuid);
                toast.success('İş yeniden kuyruğa alındı.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        async forget(job) {
            const ok = await confirmAction({
                title: 'Başarısız iş silinsin mi?',
                message: 'İş bir daha denenmez. E-postaysa alıcıya gitmemiş olarak kalır.',
                summary: [{ label: 'İş', value: job.name }, { label: 'Hata', value: job.error || '—' }],
                confirmText: 'Sil',
                tone: 'danger',
            });
            if (!ok) return;
            this.busy = job.uuid;
            try {
                this.data = await api.forgetJob(job.uuid);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },
    };
}
