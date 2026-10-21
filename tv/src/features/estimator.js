/**
 * Teklif hesaplayıcı (pages/estimate). Seçimlere göre yaklaşık fiyat aralığı
 * ve süre; "Bu tahminle teklif iste" sonucu brif sihirbazına taşır
 * (sessionStorage + ?estimate).
 *
 * Fiyatlar panelden düzenlenir ve sayfa verisiyle gelir ($store.km.estimate;
 * yoksa features/estimate-config.js). Seçeneklerin görünen adları sayfadaki
 * etiketlerden okunur (panelden değiştirilmiş olabilir).
 */
import { DEFAULTS, TYPE_SERVICE } from './estimate-config.js';
import { ESTIMATE_KEY } from './brief-wizard.js';
import { intlLocale } from '@/i18n/client.js';

export function estimator({ currency = 'TRY', contactUrl = '/', weeksTemplate = '{min}–{max}' } = {}) {
    const money = new Intl.NumberFormat(intlLocale(), { style: 'currency', currency, maximumFractionDigits: 0 });

    return {
        type: 'corporate',
        design: 'custom',
        features: ['cms', 'seo'],
        config: DEFAULTS,

        init() {
            const data = this.$store.km?.estimate;
            if (data?.prices?.[currency]) this.config = data;
            // Gizlenen seçenek seçili kalmasın.
            if (!this.visible('types', this.type)) this.type = Object.keys(this.prices.types).find((key) => this.visible('types', key)) || this.type;
            this.features = this.features.filter((key) => this.visible('features', key));
        },

        get prices() {
            return this.config.prices[currency] || DEFAULTS.prices.TRY;
        },

        visible(group, key) {
            return !(this.config.off?.[group] || []).includes(key);
        },

        round(value) {
            const step = this.prices.round || 1;
            return Math.round(value / step) * step;
        },

        get range() {
            const [low, high] = this.prices.types[this.type] || [0, 0];
            const factor = this.config.design[this.design] ?? 1;
            const extra = this.features.reduce((sum, key) => sum + (this.prices.features[key] || 0), 0);
            return [this.round(low * factor + extra), this.round(high * factor + extra * 1.25)];
        },

        get weeks() {
            const [low, high] = this.config.weeks[this.type] || [0, 0];
            const extra = this.features.reduce((sum, key) => sum + (this.config.featureWeeks[key] || 0), 0);
            const designExtra = this.design === 'premium' ? 1 : 0;
            return [low + Math.ceil(extra / 2), high + extra + designExtra];
        },

        get rangeLabel() {
            const [min, max] = this.range;
            return `${money.format(min)} – ${money.format(max)}`;
        },

        get weeksLabel() {
            const [min, max] = this.weeks;
            return weeksTemplate.replace('{min}', min).replace('{max}', max);
        },

        label(name, value) {
            return this.$root.querySelector(`input[name="${name}"][value="${value}"]`)?.closest('label')?.textContent.trim() || value;
        },

        request() {
            const [min, max] = this.range;
            const items = [this.label('type', this.type), this.label('design', this.design), ...this.features.map((f) => this.label('features', f))];
            const estimate = {
                min,
                max,
                currency,
                range: this.rangeLabel,
                weeks: this.weeksLabel,
                items,
                services: [TYPE_SERVICE[this.type]],
                summary: `${items.join(', ')}\n${this.rangeLabel} · ${this.weeksLabel}\n\n`,
            };
            try {
                sessionStorage.setItem(ESTIMATE_KEY, JSON.stringify(estimate));
            } catch {
                /* depolama kapalı: sihirbaz boş açılır */
            }
            window.location.assign(`${contactUrl}?estimate=1`);
        },
    };
}
