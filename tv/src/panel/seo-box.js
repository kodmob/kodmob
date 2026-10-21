/**
 * Düzenleyicideki SEO kutusu (proje ve blog yazısı; SEO modülü açıkken). kmEditor'ün içinde
 * çalışır: kaydın formunu (`item`, `tab`) okur. kodmob-seo-core meta kutusunun karşılığı:
 *
 * - SEO başlığı, açıklama, odak kelime (projede dil başına; dil düzenleyicinin sekmesini izler)
 * - arama sonucu önizlemesi ve karakter sayaçları
 * - canlı SEO puanı ve öneriler (POST /seo/analyze, yazarken)
 * - arama motorlarından gizle (noindex), canonical, paylaşım görseli
 *
 * Kayıt düzenleyicinin "Kaydet"iyle birlikte kaydedilir (`km-editor-saved` olayı).
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { localeCodes, defaultLocale } from './meta.js';

/** Dil kaydından (sunucu). */
const blankSeo = () => ({ locales: Object.fromEntries(localeCodes().map((l) => [l, { title: '', description: '', focus: '' }])), noindex: false, canonical: '', image_id: 0, image: null });
const strip = (html) => String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

export function kmSeoBox(kind) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onSaved: null,
        seoKind: kind,
        seo: blankSeo(),
        seoOriginal: '',
        seoReady: false,
        seoSaving: false,
        seoOpen: true,
        analysis: null,
        analysisTimer: null,

        async init() {
            this._onSaved = (e) => this.saveSeo(e.detail?.id);
            window.addEventListener('km-editor-saved', this._onSaved);
            const id = this.item?.id || 0;
            if (id) {
                try {
                    this.seo = { ...blankSeo(), ...(await api.seoMeta(kind, id)) };
                } catch (error) {
                    toast.error(error);
                }
            }
            this.seoOriginal = JSON.stringify(this.seoPayload());
            this.seoReady = true;
            this.$watch(() => JSON.stringify(this.seo) + JSON.stringify(this.item) + this.seoLocale, () => {
                // Yalnızca SEO alanı değiştiyse de sayfadan çıkarken uyarılsın.
                if (this.seoDirty) this.$store.panel.dirty = true;
                this.analyzeSoon();
            });
            this.analyzeSoon();
        },

        destroy() {
            window.removeEventListener('km-editor-saved', this._onSaved);
            clearTimeout(this.analysisTimer);
        },

        get seoModules() {
            return this.$store.session.me?.seo?.modules || {};
        },

        /** Projede düzenleyicinin dil sekmesi; yazıda yazının dili. */
        get seoLocale() {
            return kind === 'post' ? this.item?.locale || defaultLocale() : localeCodes().includes(this.tab) ? this.tab : defaultLocale();
        },

        get seoFields() {
            return this.seo.locales[this.seoLocale];
        },

        get seoDirty() {
            return this.seoReady && JSON.stringify(this.seoPayload()) !== this.seoOriginal;
        },

        /** Boş alanların yerine geçen varsayılanlar (önizlemede ve analizde). */
        get seoDefaults() {
            if (!this.item) return { title: '', description: '', slug: '' };
            if (kind === 'post') {
                return { title: this.item.title || '', description: this.item.excerpt || strip(this.item.html).slice(0, 160), slug: this.item.slug || '' };
            }
            const l = this.item.i18n?.[this.seoLocale] || {};
            return { title: [l.title, l.tagline].filter(Boolean).join(' · '), description: l.summary || '', slug: l.slug || '' };
        },

        get seoTitle() {
            return this.seoFields.title || this.seoDefaults.title;
        },

        get seoDescription() {
            return this.seoFields.description || this.seoDefaults.description;
        },

        seoPayload() {
            const { locales, noindex, canonical, image_id: imageId } = this.seo;
            return { locales, noindex, canonical, image_id: imageId || 0 };
        },

        analyzeSoon() {
            if (!this.seoModules.analysis || !this.item) return;
            clearTimeout(this.analysisTimer);
            this.analysisTimer = setTimeout(() => this.analyze(), 700);
        },

        async analyze() {
            const item = this.item;
            const body = {
                kind,
                locale: this.seoLocale,
                title: this.seoTitle,
                description: this.seoDescription,
                focus: this.seoFields.focus,
                slug: this.seoDefaults.slug,
                cover: !!item.cover,
                noindex: this.seo.noindex,
            };
            if (kind === 'post') {
                body.html = item.html || '';
            } else {
                const l = item.i18n?.[this.seoLocale] || {};
                body.text = [l.summary, l.about, l.problem, l.solution, l.result, ...(l.features || []).map((f) => `${f.title || ''} ${f.text || ''}`)].filter(Boolean).join(' ');
            }
            try {
                this.analysis = await api.seoAnalyze(body);
            } catch {
                this.analysis = null;
            }
        },

        get scoreTone() {
            const s = this.analysis?.score ?? 0;
            return s >= 80 ? 'success' : s >= 50 ? 'warning' : 'danger';
        },

        lengthTone(n, min, max) {
            return n === 0 ? 'text-muted' : n < min || n > max ? 'text-warning-ink' : 'text-success-ink';
        },

        async pickSeoImage() {
            const picked = await this.$store.media.pick({ current: this.seo.image ? [this.seo.image] : null });
            if (picked?.[0]) {
                this.seo.image = picked[0];
                this.seo.image_id = picked[0].id;
            }
        },

        /** Düzenleyici kaydedince (yeni kayıtta kimlik o an belli olur). */
        async saveSeo(id) {
            if (!id || !this.seoDirty || this.seoSaving) return;
            this.seoSaving = true;
            try {
                this.seo = { ...blankSeo(), ...(await api.saveSeoMeta(kind, id, this.seoPayload())) };
                this.seoOriginal = JSON.stringify(this.seoPayload());
            } catch (error) {
                toast.error(error, { title: 'SEO bilgileri kaydedilemedi' });
            } finally {
                this.seoSaving = false;
            }
        },
    };
}
