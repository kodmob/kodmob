/**
 * Düzenleyicide diller (done-coklu-dil-raporu.md Faz 4). kmEditor'a karışan yöntemler (getter yok:
 * nesne yayılırken çalışmasınlar).
 *
 * Tek kayıt türleri (proje, hizmet, referans): `item.locales` = { kod: { status, stale } }.
 * - Sekmeler yalnızca eklenen diller. "+ Dil ekle": boş, başka dilden kopya ya da Jarvis
 *   çevirisi; kaldırılıp yeniden eklenen dil kayıtlı metniyle gelir.
 * - Kaldırılan dil sitede görünmez, metni saklanır. En az bir dil kalır.
 * - Dil başına yayın: taslak dil sitede yok. Yayın yetkisi yoksa sunucu dil durumlarını değiştirmez.
 * - Kaynak dil (varsayılan dil, eklenmemişse ilk eklenen) değişince diğerleri "Güncellenmeli"
 *   olur; çeviri değişince ya da "Güncel say" ile kalkar (sunucu ContentType::stampSources).
 *
 * Blog: her dil ayrı yazı, bir çeviri grubunda (`item.translations`). Dil başına Düzenle,
 * Oluştur (boş / kopya / Jarvis), Var olanı bağla; bu yazıyı gruptan ayır; "Güncel say".
 */
import { href, navigate } from './routes.js';
import { api } from './api.js';
import { languages, localeLabels, localeCodes, defaultLocale } from './meta.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

/** Kayıt metin alanları (adres/slug hariç): JSON dışa aktarma, Jarvis çevirisi, eskime. kodmob-api Translations::TEXT_TYPES ile aynı. */
export const TEXT_TYPES = ['text', 'textarea', 'html', 'list', 'rows'];
/** Satır alanlarında çevrilen sütunlar (ikon, adres çevrilmez). */
const TEXT_COLUMNS = ['text', 'textarea', 'html'];
const clone = (value) => JSON.parse(JSON.stringify(value ?? null));
const filled = (value) => (Array.isArray(value) ? value.some(filled) : value && typeof value === 'object' ? Object.values(value).some(filled) : String(value ?? '').trim() !== '');

/** Jarvis'e giden metin: satırlarda yalnızca yazı sütunları. */
function textOf(field, value) {
    if (field.type !== 'rows') return clone(value);
    const columns = (field.columns || []).filter((c) => TEXT_COLUMNS.includes(c.type)).map((c) => c.name);
    return (value || []).map((row) => Object.fromEntries(columns.map((name) => [name, row?.[name] ?? ''])));
}

/** Çeviriyi kaynağın üzerine yerleştirir (satırlarda ikon gibi sütunlar korunur). */
function mergeText(field, source, translated) {
    if (field.type !== 'rows') return clone(translated);
    return (source || []).map((row, i) => ({ ...clone(row), ...(translated?.[i] || {}) }));
}

export function editorLanguages() {
    const LABELS = localeLabels();
    const LOCALES = localeCodes();
    const DEFAULT = defaultLocale();
    const INFO = Object.fromEntries(languages().map((l) => [l.code, l]));
    const label = (code) => LABELS[code] || code.toUpperCase();

    return {
        /** "+ Dil ekle" penceresi. mode: keep | empty | copy | ai */
        adder: { open: false, code: '', mode: 'copy', from: DEFAULT, busy: false },
        /** Blog: "Var olanı bağla" penceresi. */
        linker: { open: false, code: '', posts: [], postId: 0, busy: false },
        translating: '',

        // ------------------------------------------------------------ tek kayıt türleri
        hasLanguages() {
            return !!this.item?.locales && this.schemaLocalized().length > 0;
        },

        addedLocales() {
            return LOCALES.filter((code) => this.item?.locales?.[code]);
        },

        missingLocales() {
            return LOCALES.filter((code) => !this.item?.locales?.[code]);
        },

        sourceLocale() {
            const added = this.addedLocales();
            return added.includes(DEFAULT) ? DEFAULT : added[0] || DEFAULT;
        },

        localeLabel(code) {
            return label(code);
        },

        localeInactive(code) {
            return INFO[code]?.active === false;
        },

        localeState(code) {
            return this.item?.locales?.[code] || null;
        },

        localePublished(code) {
            return this.localeState(code)?.status === 'publish';
        },

        setLocalePublished(code, on) {
            const state = this.localeState(code);
            if (state) this.item.locales = { ...this.item.locales, [code]: { ...state, status: on ? 'publish' : 'draft' } };
        },

        localeStale(code) {
            return !!this.localeState(code)?.stale && code !== this.sourceLocale();
        },

        /** Sekme noktası: yayında / taslak / güncellenmeli / hatalı. */
        localeDot(code) {
            if (this.localeHasError(code)) return { cls: 'is-error', title: 'Hatalı alan var' };
            if (this.localeStale(code)) return { cls: 'is-stale', title: 'Güncellenmeli: kaynak dil değişti' };
            return this.localePublished(code) ? { cls: 'is-on', title: 'Bu dilde yayında' } : { cls: '', title: 'Bu dil taslak' };
        },

        /** Kaynak dil metinleri (Jarvis'e giden): dolu yazı alanları. */
        sourceTexts(from) {
            const values = this.item.i18n[from] || {};
            const fields = {};
            for (const field of this.schemaLocalized().filter((f) => TEXT_TYPES.includes(f.type))) {
                const value = textOf(field, values[field.name]);
                if (filled(value)) fields[field.name] = value;
            }
            return fields;
        },

        /** Bir dili kaynak dilin kopyasıyla doldurur; `translated` varsa yazı alanları onunla. Adres kopyalanmaz. */
        fillLocale(code, from, translated = null) {
            const copy = clone(this.item.i18n[from]);
            if ('slug' in copy) copy.slug = '';
            if (translated) {
                for (const field of this.schemaLocalized()) {
                    if (field.name in translated) copy[field.name] = mergeText(field, copy[field.name], translated[field.name]);
                }
            }
            this.item.i18n[code] = copy;
        },

        emptyLocale(code) {
            const blank = this.normalize({ ...this.item, i18n: { ...this.item.i18n, [code]: {} } }).i18n[code];
            this.item.i18n[code] = blank;
        },

        async jarvisTranslate(from, to) {
            const fields = this.sourceTexts(from);
            if (!Object.keys(fields).length) throw new Error(`${label(from)} dilinde çevrilecek metin yok.`);
            return (await api.translate(this.type, { from, to, fields })).fields;
        },

        openAdder(code) {
            const others = this.addedLocales();
            Object.assign(this.adder, {
                open: true,
                code,
                mode: this.localeFilled(code) ? 'keep' : others.length ? 'copy' : 'empty',
                from: this.sourceLocale(),
                busy: false,
            });
        },

        async addLocale() {
            const { code, mode, from } = this.adder;
            if (!code || this.adder.busy) return;
            this.adder.busy = true;
            try {
                if (mode === 'empty') this.emptyLocale(code);
                if (mode === 'copy') this.fillLocale(code, from);
                if (mode === 'ai') this.fillLocale(code, from, await this.jarvisTranslate(from, code));
                // Yeni dil taslak gelir: gözden geçirip yayına alın.
                this.item.locales = { ...this.item.locales, [code]: { status: 'draft', stale: false } };
                this.tab = code;
                this.adder.open = false;
                toast.success(`${label(code)} eklendi (taslak). ${mode === 'ai' ? 'Jarvis çevirisini gözden geçirip ' : ''}Kaydet'e basınca yazılır.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.adder.busy = false;
            }
        },

        async removeLocale(code) {
            if (this.addedLocales().length < 2) {
                toast.info('En az bir dil kalmalı.');
                return;
            }
            const ok = await confirmAction({
                title: `${label(code)} kaldırılsın mı?`,
                message: `Kayıt ${label(code)} sitesinde görünmez. Yazdığınız metinler saklanır; dili yeniden eklerseniz geri gelir. Kaydet'e basınca uygulanır.`,
                confirmText: 'Kaldır',
                tone: 'danger',
            });
            if (!ok) return;
            const locales = { ...this.item.locales };
            delete locales[code];
            this.item.locales = locales;
            if (this.tab === code) this.tab = this.sourceLocale();
        },

        /** "Güncel say": çeviri kaynağa göre güncel kabul edilir (kaydedince). */
        markCurrent(code) {
            const state = this.localeState(code);
            if (state) this.item.locales = { ...this.item.locales, [code]: { ...state, stale: false, current: true } };
        },

        /** Bu sekmeyi başka dilden Jarvis ile yeniden çevirir (üzerine yazar). */
        async translateInto(from) {
            const to = this.tab;
            const ok = await confirmAction({
                title: `${label(from)} dilinden Jarvis ile çevrilsin mi?`,
                message: `${label(to)} sekmesindeki metinler çeviriyle değiştirilir (adres korunur). Kaydet'e basana kadar hiçbir şey yazılmaz.`,
                confirmText: 'Çevir',
            });
            if (!ok) return;
            this.translating = to;
            try {
                const slug = this.item.i18n[to]?.slug ?? '';
                this.fillLocale(to, from, await this.jarvisTranslate(from, to));
                if ('slug' in this.item.i18n[to]) this.item.i18n[to].slug = slug;
                toast.success(`${label(to)} çevirisi hazır; gözden geçirip kaydedin.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.translating = '';
            }
        },

        /** Sunucuya giden dil durumları: { kod: { status, current? } } */
        localesPayload() {
            return Object.fromEntries(this.addedLocales().map((code) => {
                const state = this.item.locales[code];
                return [code, state.current ? { status: state.status, current: true } : { status: state.status }];
            }));
        },

        // ------------------------------------------------------------ blog: çeviri grubu
        postTranslations() {
            return (this.item?.translations || []).filter((t) => t.id || !this.localeInactive(t.code));
        },

        postStale() {
            return !!this.item?.translations?.find((t) => t.current)?.stale && !this.item.translation_current;
        },

        groupSize() {
            return (this.item?.translations || []).filter((t) => t.id).length;
        },

        /** Bekleyen değişiklik varsa önce kaydeder (çeviri kaydedilmiş kaynaktan üretilir). */
        async savedFirst() {
            if (!this.dirty) return true;
            await this.save();
            return !this.dirty;
        },

        async createPostTranslation(code, mode) {
            if (!(await this.savedFirst())) return;
            this.translating = code;
            try {
                const { id } = await api.createPostTranslation(this.item.id, { locale: code, mode });
                toast.success(`${label(code)} çevirisi taslak olarak oluşturuldu.`);
                navigate(href('editor', { type: 'posts', id }));
            } catch (error) {
                toast.error(error);
            } finally {
                this.translating = '';
            }
        },

        async openLinker(code) {
            Object.assign(this.linker, { open: true, code, posts: [], postId: 0, busy: true });
            try {
                const items = (await api.list('posts')).items.filter((p) => p.locale === code && p.id !== this.item.id);
                // Grubu olmayanlar önce.
                this.linker.posts = items.sort((a, b) => (a.languages?.length || 1) - (b.languages?.length || 1));
                this.linker.postId = this.linker.posts[0]?.id || 0;
            } catch (error) {
                toast.error(error);
                this.linker.open = false;
            } finally {
                this.linker.busy = false;
            }
        },

        async linkPost() {
            if (!this.linker.postId || this.linker.busy) return;
            this.linker.busy = true;
            try {
                const saved = await api.linkPost(this.item.id, Number(this.linker.postId));
                this.item.translations = saved.translations;
                this.linker.open = false;
                toast.success(`${label(this.linker.code)} yazısı bu yazının çevirisi olarak bağlandı.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.linker.busy = false;
            }
        },

        async unlinkPost() {
            const ok = await confirmAction({
                title: 'Bu yazı gruptan ayrılsın mı?',
                message: 'Yazı diğer dillerdeki karşılıklarından ayrılır: sitede dil değiştirici ona bağlanmaz. Yazılar silinmez.',
                confirmText: 'Ayır',
                tone: 'danger',
            });
            if (!ok) return;
            try {
                this.item.translations = (await api.unlinkPost(this.item.id)).translations;
                toast.success('Yazı çeviri grubundan ayrıldı.');
            } catch (error) {
                toast.error(error);
            }
        },

        /** Blog "Güncel say": kaydedince kaynağa göre güncel kabul edilir. */
        markPostCurrent() {
            this.item.translation_current = true;
        },

        translatorReady() {
            return !!this.$store.session.me?.translator;
        },
    };
}
