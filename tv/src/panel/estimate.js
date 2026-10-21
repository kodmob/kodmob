/**
 * Teklif hesaplayıcı (/panel/teklif-hesaplayici): proje türü fiyat aralıkları ve süreleri,
 * özellik fiyatları, tasarım çarpanları, yuvarlama; sitede gösterilmeyecek
 * seçenekler. Seçeneklerin adları "Sayfa metinleri"nden değiştirilir.
 *
 * Hesap (features/estimator.js): (tür fiyatı × tasarım çarpanı) + özellikler;
 * üst sınırda özellikler ×1,25. Sonuç yuvarlama adımına yuvarlanır.
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { fieldErrors } from '../domain/errors.js';

export const CURRENCIES = { TRY: '₺', EUR: '€' };

const clone = (value) => JSON.parse(JSON.stringify(value));

export function kmEstimate() {
    return {
        value: null,
        original: '',
        defaults: null,
        labels: { types: {}, designs: {}, features: {} },
        errors: {},
        loading: true,
        saving: false,
        currencies: CURRENCIES,
        preview: { currency: 'TRY', type: 'corporate', design: 'custom' },

        async init() {
            try {
                const data = await api.estimate();
                this.defaults = data.defaults;
                this.labels = data.labels;
                this.apply(data.value);
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
            this.$watch(() => this.dirty, (dirty) => (this.$store.panel.dirty = dirty));
        },

        apply(value) {
            this.value = clone(value);
            this.original = JSON.stringify(value);
        },

        get dirty() {
            return !!this.value && JSON.stringify(this.value) !== this.original;
        },

        get isDefault() {
            return !!this.value && JSON.stringify(this.value) === JSON.stringify(this.defaults);
        },

        shown(group, key) {
            return !this.value.off[group].includes(key);
        },

        toggle(group, key) {
            const list = this.value.off[group];
            this.value.off[group] = list.includes(key) ? list.filter((k) => k !== key) : [...list, key];
        },

        money(amount, currency) {
            return new Intl.NumberFormat('tr-TR', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount || 0);
        },

        /** Örnek: seçili tür + tasarım, özelliksiz (sitedeki hesapla aynı). */
        get sample() {
            const prices = this.value.prices[this.preview.currency];
            const [low, high] = prices.types[this.preview.type];
            const factor = Number(this.value.design[this.preview.design]) || 1;
            const step = Number(prices.round) || 1;
            const round = (n) => Math.round(n / step) * step;
            return `${this.money(round(low * factor), this.preview.currency)} – ${this.money(round(high * factor), this.preview.currency)}`;
        },

        restoreDefaults() {
            this.value = clone(this.defaults);
            this.errors = {};
        },

        undo() {
            this.value = JSON.parse(this.original);
            this.errors = {};
        },

        async save() {
            if (!this.dirty || this.saving) return;
            this.saving = true;
            this.errors = {};
            try {
                const { value } = await api.saveEstimate(this.value);
                this.apply(value);
                this.$store.panel.dirty = false;
                toast.success('Hesaplayıcı fiyatları kaydedildi; site güncellendi.');
            } catch (error) {
                this.errors = fieldErrors(error);
                toast.error(Object.keys(this.errors).length ? Object.values(this.errors)[0] : error);
            } finally {
                this.saving = false;
            }
        },

        destroy() {
            this.$store.panel.dirty = false;
        },
    };
}
