/**
 * Yasal belgeler (/panel/yasal-belgeler; kodmob-api Modules\Site\Legal). Projede açık olan belgeler gruplarıyla
 * (Genel; modül açıkken İletişim, Bülten, Alışveriş, Rezervasyon); her dilde şablondan mı çiziliyor yoksa
 * özelleştirildi mi. Düzenle: zengin metin (şu anki hali), Kaydet ile o dilde özelleştirilir, "Şablona dön" ile
 * yeniden şablona bağlanır (firma bilgileri ve açılan modüllerin bölümleri kendiliğinden güncellenir). Proje
 * Ayarları › Yasal'da eksik bilgi varsa uyarı.
 */
import { api } from './api.js';
import { href } from './routes.js';
import { localeLabels } from './meta.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const blank = () => ({ name: '', locale: '', title: '', body: '', customized: false, url: '' });

export function kmLegal() {
    return {
        fmt,
        loading: true,
        items: [],
        missing: [],
        locales: [],
        hub: '',
        // Pencere açık / kapalı ayrı (form boşalmaz; kapanırken şablonun ifadeleri null okumasın).
        editing: false,
        form: blank(),
        busy: null,
        errors: {},

        async init() {
            await this.load();
        },

        async load() {
            try {
                const data = await api.legalDocs();
                Object.assign(this, { items: data.items, missing: data.missing, locales: data.locales, hub: data.hub || '' });
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        /** Gruplar (sunucunun sırasıyla): [{ label, items }]. */
        get groups() {
            const out = [];
            for (const item of this.items) {
                let group = out.find((g) => g.key === item.group);
                if (!group) out.push((group = { key: item.group, label: item.groupLabel, items: [] }));
                group.items.push(item);
            }
            return out;
        },

        get settingsHref() {
            return `${href('projectSettings')}#ayar=legal.name`;
        },

        langLabel(code) {
            return localeLabels()[code] || code.toUpperCase();
        },

        async open(item, locale) {
            if (this.busy) return;
            this.busy = `open:${item.name}:${locale}`;
            try {
                const doc = await api.legalDoc(item.name, locale);
                this.errors = {};
                this.form = { name: item.name, locale, title: doc.title, body: doc.html, customized: doc.customized, url: item.locales[locale]?.url || '' };
                this.editing = true;
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        async save() {
            if (this.busy) return;
            this.busy = 'save';
            this.errors = {};
            try {
                await api.saveLegal(this.form.name, this.form.locale, this.form.body);
                toast.success(`${this.form.title} (${this.form.locale.toUpperCase()}) özelleştirildi; sitede bu metin görünür.`);
                this.editing = false;
                await this.load();
            } catch (error) {
                this.errors = error?.details?.fields || {};
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },

        async reset() {
            const ok = await confirmAction({
                title: 'Şablona dönülsün mü?',
                message: 'Bu dildeki özelleştirme silinir; belge yeniden şablondan, Proje Ayarları › Yasal ve açık modüllerle çizilir.',
                confirmText: 'Şablona dön',
                tone: 'danger',
            });
            if (!ok) return;
            this.busy = 'reset';
            try {
                await api.resetLegal(this.form.name, this.form.locale);
                toast.success('Şablona dönüldü.');
                this.editing = false;
                await this.load();
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = null;
            }
        },
    };
}
