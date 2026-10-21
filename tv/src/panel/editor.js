/**
 * Kayıt düzenleyici — bütün içerik türleri için tek bileşen. Form, sunucunun
 * şemasından çizilir (`GET /admin/schema/<tür>`, kodmob-api Core\Content\Fields):
 * ortak alanlar sağ sütunda, dil alanları solda kayda eklenmiş dillerin sekmelerinde
 * (dil ekleme/kaldırma, dil başına yayın, çeviriler: panel/editor-languages.js).
 *
 * - Görseller formda önizlemeli nesnedir ({ id, src… }); kayıtta kimliğe döner.
 * - Galeri açıklamaları her dilde galeri sırasıyla hizalıdır (sil/taşı birlikte).
 * - Kaydedilmemiş değişiklik `$store.panel.dirty` ile izlenir (sayfadan çıkışta sorulur).
 * - Ctrl+S kaydeder. Alan hataları (`i18n.en.title`) ilgili alana ve sekmeye düşer.
 */
import { href, navigate, replaceUrl } from './routes.js';
import { api } from './api.js';
import { TYPES, STATUS, relative, localeLabels, localeCodes, defaultLocale } from './meta.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { deleteRecord } from '../ui/records/records.js';
import { fieldErrors } from '../domain/errors.js';
import { pageInfo, recordPatterns } from '../i18n/client.js';
import { downloadJson, readJson } from './files.js';
import { editorLanguages, TEXT_TYPES } from './editor-languages.js';
import { fromMinor, toMinor } from '../domain/money.js';

/** Sağ sütundaki ortak bölümler; diğerleri sol sütunda. */
const SIDE_SECTIONS = ['Genel', 'Görseller', 'Yayın'];
const isEmpty = (value) => value === '' || value === null || value === undefined || (Array.isArray(value) && (!value.length || value.every((v) => v === '')));

/** Seçim kutusu seçenekleri: yazı türü → API türü. */
/** Bağlı kayıt alanının (`postType`: km_project) seçenekleri hangi listeden: kayıt adı → API adı. */
const OPTION_TYPES = Object.fromEntries(Object.entries(TYPES).map(([name, t]) => [`km_${t.record}`, name]));

const blank = (field) => {
    switch (field.type) {
        case 'bool': return false;
        case 'int': case 'money': case 'post': return 0;
        case 'image': return null;
        case 'multiselect': case 'gallery': case 'list': case 'rows': return [];
        case 'icon': return field.default || 'layers';
        default: return '';
    }
};

export function kmEditor(type, id) {
    // Dil kaydından (sunucu): görünüm her açılışta güncel listeyle kurulur.
    const LOCALE_LABELS = localeLabels();
    const LOCALES = localeCodes();
    const DEFAULT = defaultLocale();
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        destroyed: false,
        type,
        id,
        meta: TYPES[type],
        status: STATUS,
        locales: LOCALE_LABELS,
        relative,
        schema: null,
        item: null,
        snapshot: '',
        loading: true,
        saving: false,
        errors: {},
        tab: DEFAULT,
        options: {},
        busy: {},
        /** JSON'dan gelen, kayıtta yazılacak alt metinler: görsel kimliği → { tr, en, de }. */
        pendingAlts: {},
        importer: { open: false, text: '', locale: DEFAULT, overwrite: false, error: '' },
        ...editorLanguages(),

        async init() {
            try {
                const [schema, item] = await Promise.all([api.schema(type), id ? api.get(type, id) : api.blank(type)]);
                for (const field of schema.shared.filter((f) => f.type === 'post')) {
                    this.options[field.postType] = (await api.options(OPTION_TYPES[field.postType])).items;
                }
                this.schema = schema;
                this.item = this.normalize(item);
                // Dil sekmesi adreste (?dil=en); yoksa kaynak dil.
                const wanted = new URLSearchParams(window.location.search).get('dil');
                if (this.hasLanguages()) this.tab = this.item.locales[wanted] ? wanted : this.sourceLocale();
                this.snapshot = this.serialize();
            } catch (error) {
                toast.error(error);
                if (error.status === 404) navigate(href('list', { type }), { replace: true });
            } finally {
                this.loading = false;
            }
            // İşlev biçimi: görünüm kaldırılırken çalışan bir yeniden hesap `item is not defined` atmasın.
            this.$watch(() => this.item, () => {
                if (this.item && !this.destroyed) this.$store.panel.dirty = this.serialize() !== this.snapshot;
            });
            this.$watch(() => this.tab, () => !this.destroyed && this.id && replaceUrl(this.editorUrl()));
        },

        destroy() {
            this.destroyed = true;
            this.$store.panel.dirty = false;
        },

        // ------------------------------------------------------------ veri
        /** Eksik dil/alan değerlerini tamamlar; galeri açıklamalarını hizalar. */
        normalize(item) {
            const out = { ...item, i18n: { ...(item.i18n || {}) } };
            for (const field of this.schemaOf(item).shared) {
                if (out[field.name] === undefined || out[field.name] === null) out[field.name] = blank(field);
            }
            if (this.schemaLocalized().length) {
                for (const locale of LOCALES) {
                    const l = { ...(out.i18n[locale] || {}) };
                    for (const field of this.schemaLocalized()) {
                        if (l[field.name] === undefined || l[field.name] === null) l[field.name] = blank(field);
                    }
                    out.i18n[locale] = l;
                }
                for (const gallery of this.galleries()) {
                    for (const locale of LOCALES) {
                        const captions = [...(out.i18n[locale][gallery.captions] || [])];
                        while (captions.length < out[gallery.name].length) captions.push('');
                        out.i18n[locale][gallery.captions] = captions.slice(0, out[gallery.name].length);
                    }
                }
            }
            return out;
        },

        schemaOf() {
            return this.schema || { shared: [], localized: [] };
        },

        schemaLocalized() {
            return this.schemaOf().localized || [];
        },

        galleries() {
            return this.schemaOf().shared.filter((f) => f.type === 'gallery' && f.captions);
        },

        /** Sunucuya giden biçim: görseller kimlik, sırasız alanlar olduğu gibi. */
        payload() {
            const toApi = (field, value) => {
                if (field.type === 'image') return value?.id || 0;
                if (field.type === 'gallery') return (value || []).map((v) => v.id);
                return value;
            };
            const out = { status: this.item.status };
            if (this.hasLanguages()) out.locales = this.localesPayload();
            if (this.item.translation_current) out.translation_current = true;
            for (const field of this.schema.shared) out[field.name] = toApi(field, this.item[field.name]);
            if (this.schema.localized.length) {
                out.i18n = {};
                for (const locale of LOCALES) {
                    out.i18n[locale] = {};
                    for (const field of this.schema.localized) out.i18n[locale][field.name] = toApi(field, this.item.i18n[locale][field.name]);
                }
            }
            return out;
        },

        serialize() {
            return this.schema && this.item ? JSON.stringify([this.payload(), this.pendingAlts]) : '';
        },

        get dirty() {
            return this.$store.panel.dirty;
        },

        // ------------------------------------------------------------ yerleşim
        /** Alanları bölümlerine ayırır (sıra korunur). */
        groups(fields, side) {
            const groups = [];
            for (const field of fields) {
                if (field.hidden) continue;
                const name = field.section || 'Genel';
                if (side !== undefined && SIDE_SECTIONS.includes(name) !== side) continue;
                let group = groups.find((g) => g.name === name);
                if (!group) groups.push((group = { name, fields: [] }));
                group.fields.push(field);
            }
            return groups;
        },

        get mainGroups() {
            return this.groups(this.schemaOf().shared, false);
        },

        get sideGroups() {
            return this.groups(this.schemaOf().shared, true);
        },

        get localizedGroups() {
            return this.groups(this.schemaLocalized());
        },

        get title() {
            if (!this.item) return '';
            const key = this.schema.localeKey;
            if (key) return [this.sourceLocale(), ...LOCALES].map((l) => this.item.i18n[l]?.[key]).find(Boolean) || '';
            return this.item.title || this.item.name || '';
        },

        get published() {
            return this.item?.status !== 'draft';
        },

        set published(value) {
            this.item.status = value ? 'publish' : 'draft';
        },

        localeFilled(locale) {
            const key = this.schema?.localeKey;
            return !!(key && this.item?.i18n?.[locale]?.[key]);
        },

        localeHasError(locale) {
            return Object.keys(this.errors).some((path) => path.startsWith(`i18n.${locale}.`));
        },

        err(path) {
            return this.errors[path] || '';
        },

        fieldId(field, scope = '') {
            return `f-${scope}${field.name}`;
        },

        /** Para alanı (`money`): kuruş ↔ "149,90". */
        moneyText(minor) {
            return fromMinor(minor);
        },

        moneyValue(text) {
            return toMinor(text);
        },

        /** Sitede kendi sayfası var mı (rotası `dynamic: <kayıt adı>`): SEO kutusu, adres öneki. */
        get hasPage() {
            return !!recordPatterns(this.meta?.record);
        },

        /**
         * Sitedeki adresi (yayındaysa): "Sitede gör". Kendi sayfası olan kayıt o sayfaya; olmayan tür
         * tanımındaki `site` bölümüne (panel.js: `{ route, anchor? }`, ör. hizmetler sayfasında #anahtar).
         */
        /** Blok sayfası (`blocks`): içeriği Sayfa düzenleyicide. */
        get builderUrl() {
            return this.meta.blocks && this.item?.id ? href('builder', {}, { sayfa: `page@${this.item.id}` }) : '';
        },

        get publicUrl() {
            if (!this.item?.id || !this.published) return '';
            const patterns = recordPatterns(this.meta.record);
            const fill = (locale, slug) => (patterns?.[locale] || '').replace(':slug', encodeURIComponent(slug));
            if (type === 'posts') return this.item.slug && patterns ? fill(this.item.locale, this.item.slug) : '';
            if (patterns) {
                const locale = this.addedLocales().find((l) => this.localePublished(l) && this.item.i18n[l]?.title && this.item.i18n[l]?.slug);
                return locale ? fill(locale, this.item.i18n[locale].slug) : '';
            }
            const site = this.meta.site;
            const base = site && pageInfo().routes?.[site.route];
            return base ? `${base}${site.anchor && this.item[site.anchor] ? `#${this.item[site.anchor]}` : ''}` : '';
        },

        /** Adres alanının önüne yazılan yol: /projeler/, /en/projects/ */
        slugPrefix(locale) {
            return (recordPatterns(this.meta?.record)?.[locale] || '').replace(':slug/', '');
        },

        /** Düzenleyici adresi; kaynak dışındaki dil sekmesi sorguda (?dil=en). */
        editorUrl() {
            const dil = this.hasLanguages() && this.tab !== this.sourceLocale() ? { dil: this.tab } : {};
            return href('editor', { type, id: this.id }, dil);
        },

        // ------------------------------------------------------------ kayıt
        async save() {
            if (this.saving || !this.item) return;
            this.saving = true;
            this.errors = {};
            try {
                for (const [mediaId, alts] of Object.entries(this.pendingAlts)) {
                    await api.saveAlts(mediaId, alts);
                }
                this.pendingAlts = {};
                const saved = id ? await api.update(type, id, this.payload()) : await api.create(type, this.payload());
                this.item = this.normalize(saved);
                if (this.hasLanguages() && !this.item.locales[this.tab]) this.tab = this.sourceLocale();
                this.snapshot = this.serialize();
                this.$store.panel.dirty = false;
                toast.success(this.published ? 'Kaydedildi ve yayında.' : 'Taslak kaydedildi.');
                if (!id) {
                    this.id = id = saved.id;
                    replaceUrl(this.editorUrl());
                }
                // SEO kutusu kendi bilgilerini aynı anda kaydeder (panel/seo-box.js).
                window.dispatchEvent(new CustomEvent('km-editor-saved', { detail: { type, id } }));
            } catch (error) {
                this.errors = fieldErrors(error);
                const paths = Object.keys(this.errors);
                if (paths.length) {
                    const locale = LOCALES.find((l) => this.localeHasError(l));
                    if (this.errors.locales) toast.error(this.errors.locales);
                    if (locale) this.tab = locale;
                    toast.error(paths.length === 1 ? Object.values(this.errors)[0] : 'Formdaki işaretli alanları düzeltin.');
                    this.$nextTick(() => this.$root.querySelector('[aria-invalid="true"]')?.focus());
                } else {
                    toast.error(error);
                }
            } finally {
                this.saving = false;
            }
        },

        /** Genel Sil (ui/records): onay penceresi sunucudan, Silinenler'den geri yüklenir. */
        async remove() {
            if (await deleteRecord({ type: this.meta.record, id, label: this.title })) {
                this.$store.panel.dirty = false;
                navigate(href('list', { type }));
            }
        },

        // ------------------------------------------------------------ görseller
        /**
         * Görsel alanı: medya penceresi açılır (`files`: alana sürüklenen dosyalar
         * yüklenerek gelir). Seçilen görselin Türkçe alt metni pencerede istenir.
         */
        async pickImage(target, name, files = null) {
            const current = target[name] ? [target[name]] : null;
            const picked = await this.$store.media.pick({ current: files ? null : current, files });
            if (picked?.[0]) target[name] = picked[0];
        },

        async addToGallery(field, files = null) {
            const picked = await this.$store.media.pick({ multiple: true, files });
            if (!picked?.length) return;
            for (const media of picked) {
                if (this.item[field.name].some((m) => m.id === media.id)) continue;
                this.item[field.name].push(media);
                for (const locale of LOCALES) this.item.i18n[locale][field.captions].push('');
            }
        },

        /** Galerideki bir görselin alt metnini düzenle (yerinde güncellenir). */
        async editGalleryAlt(field, index) {
            const picked = await this.$store.media.pick({ current: [this.item[field.name][index]] });
            if (picked?.[0]) this.item[field.name].splice(index, 1, picked[0]);
        },

        missingAlt(media) {
            return !!media && !String(media.alts?.[DEFAULT] || '').trim();
        },

        removeFromGallery(field, index) {
            this.item[field.name].splice(index, 1);
            for (const locale of LOCALES) this.item.i18n[locale][field.captions].splice(index, 1);
        },

        moveInGallery(field, index, step) {
            const to = index + step;
            const move = (list) => {
                if (to < 0 || to >= list.length) return;
                const [x] = list.splice(index, 1);
                list.splice(to, 0, x);
            };
            move(this.item[field.name]);
            for (const locale of LOCALES) move(this.item.i18n[locale][field.captions]);
        },

        // ------------------------------------------------------------ kayıt JSON'u
        /** Dil alanları (yazıda: ortak metin alanları); galeri açıklamaları dahil. */
        recordFields() {
            const localized = this.schema.localized.length > 0;
            const list = localized ? this.schema.localized : this.schema.shared;
            return list.filter((f) => TEXT_TYPES.includes(f.type));
        },

        imageRefs() {
            const refs = [];
            for (const field of this.schema.shared) {
                if (field.type === 'image' && this.item[field.name]) refs.push({ field: field.name, media: this.item[field.name] });
                if (field.type === 'gallery') this.item[field.name].forEach((media, i) => refs.push({ field: `${field.name}.${i + 1}`, media }));
            }
            return refs;
        },

        /** Seçili dilin metinleri ve görsel alt metinleri: başka yerde çevrilip geri aktarılır. */
        exportJson() {
            const localized = this.schema.localized.length > 0;
            const locale = localized ? this.tab : this.item.locale;
            const source = localized ? this.item.i18n[locale] : this.item;
            const fields = {};
            for (const field of this.recordFields()) fields[field.name] = JSON.parse(JSON.stringify(source[field.name] ?? ''));
            const record = {
                format: 'kodmob-record',
                version: 1,
                type,
                id: this.item.id || null,
                locale,
                title: this.title,
                exported: new Date().toISOString(),
                note: 'fields ve images[].alt değerlerini hedef dile çevirip panelde "JSON içe aktar" ile ekleyin. Anahtarları değiştirmeyin.',
                fields,
                images: this.imageRefs().map(({ field, media }) => ({ id: media.id, field, alt: media.alts?.[locale] || '' })),
            };
            const slug = (localized ? this.item.i18n[this.sourceLocale()]?.slug : this.item.slug) || `${type}-${this.item.id || 'yeni'}`;
            downloadJson(record, `${slug}-${locale}.json`);
            navigator.clipboard?.writeText(JSON.stringify(record, null, 2)).catch(() => {});
            toast.success(`${LOCALE_LABELS[locale] || locale} içeriği JSON olarak indirildi (panoya da kopyalandı).`);
        },

        openImport() {
            const localized = this.schema.localized.length > 0;
            Object.assign(this.importer, {
                open: true,
                text: '',
                error: '',
                overwrite: false,
                locale: localized ? (this.tab === this.sourceLocale() ? this.addedLocales().find((l) => l !== this.tab) || this.tab : this.tab) : this.item.locale,
            });
        },

        async importFile(files) {
            if (files?.[0]) this.importer.text = await files[0].text();
        },

        /** JSON'u hedef dile uygular: boş alanlar dolar (istenirse doluların üzerine yazılır). Kaydetmez. */
        async applyImport() {
            this.importer.error = '';
            let record;
            try {
                record = await readJson(this.importer.text.trim());
            } catch (error) {
                this.importer.error = error.message;
                return;
            }
            if (record?.format !== 'kodmob-record' || !record.fields || typeof record.fields !== 'object') {
                this.importer.error = "Bu bir Kodmob kayıt JSON'u değil (format: kodmob-record bekleniyor).";
                return;
            }
            if (record.type && record.type !== type) {
                this.importer.error = `Bu JSON başka bir içerik türüne ait (${record.type}); burası ${type}.`;
                return;
            }
            const localized = this.schema.localized.length > 0;
            const locale = localized ? this.importer.locale : this.item.locale;
            const target = localized ? this.item.i18n[locale] : this.item;
            const overwrite = this.importer.overwrite;
            let filled = 0;
            let skipped = 0;

            for (const field of this.recordFields()) {
                if (!(field.name in record.fields)) continue;
                const value = record.fields[field.name];
                if (isEmpty(value)) continue;
                if (!overwrite && !isEmpty(target[field.name])) {
                    skipped++;
                    continue;
                }
                let next = JSON.parse(JSON.stringify(value));
                const gallery = field.hidden ? this.galleries().find((g) => g.captions === field.name) : null;
                if (gallery) {
                    // Galeri açıklamaları galeri uzunluğunda kalır.
                    next = Array.from({ length: this.item[gallery.name].length }, (_, i) => String(next[i] ?? ''));
                }
                target[field.name] = next;
                filled++;
            }

            let alts = 0;
            const refs = this.imageRefs();
            for (const image of Array.isArray(record.images) ? record.images : []) {
                const ref = refs.find((r) => r.media.id === image.id) || refs.find((r) => r.field === image.field);
                const alt = String(image.alt || '').trim().slice(0, 200);
                if (!ref || !alt) continue;
                if (!overwrite && ref.media.alts?.[locale]?.trim()) continue;
                ref.media.alts = { ...Object.fromEntries(LOCALES.map((code) => [code, ''])), ...(ref.media.alts || {}), [locale]: alt };
                this.pendingAlts = { ...this.pendingAlts, [ref.media.id]: { ...ref.media.alts } };
                alts++;
            }

            if (localized) this.tab = locale;
            this.importer.open = false;
            if (!filled && !alts) {
                toast.info(skipped ? `Doldurulacak boş alan yok; ${skipped} dolu alan atlandı. Üzerine yazmak için "Dolu alanların da üzerine yaz" kutusunu işaretleyin.` : 'JSON\'da bu kayda uyan dolu alan bulunamadı.');
                return;
            }
            const parts = [`${filled} alan dolduruldu`];
            if (alts) parts.push(`${alts} görsel alt metni eklendi`);
            if (skipped) parts.push(`${skipped} dolu alan atlandı`);
            toast.success(`${parts.join(', ')}. Kaydet'e basınca yazılır.`);
        },

        // ------------------------------------------------------------ listeler
        toggleIn(list, value) {
            const i = list.indexOf(value);
            i === -1 ? list.push(value) : list.splice(i, 1);
        },

        /** Etiket alanı: taslağı listeye ekler (boş, tekrar ve sınır dışı eklenmez); yeni taslağı döner. */
        pushTag(list, draft, max) {
            const value = String(draft || '').trim().replace(/,+$/, '').trim();
            if (value && !list.includes(value) && (!max || list.length < max)) list.push(value);
            return '';
        },

        addRow(list, field) {
            const row = {};
            for (const column of field.columns) row[column.name] = column.type === 'icon' ? column.default || 'check' : '';
            list.push(row);
        },

        moveRow(list, index, step) {
            const to = index + step;
            if (to < 0 || to >= list.length) return;
            const [x] = list.splice(index, 1);
            list.splice(to, 0, x);
        },

        /** Başka dilden kopyala (boş alanları doldurmak için hızlı başlangıç). Adres kopyalanmaz. */
        async copyFrom(source) {
            const target = this.tab;
            if (source === target) return;
            const ok = await confirmAction({
                title: `${LOCALE_LABELS[source]} içeriği kopyalansın mı?`,
                message: `${LOCALE_LABELS[target]} sekmesindeki alanlar ${LOCALE_LABELS[source]} metinleriyle değiştirilir; sonra çevirirsiniz. Adres (slug) kopyalanmaz.`,
                confirmText: 'Kopyala',
            });
            if (!ok) return;
            const slug = this.item.i18n[target]?.slug ?? '';
            this.fillLocale(target, source);
            if ('slug' in this.item.i18n[target]) this.item.i18n[target].slug = slug;
        },
    };
}
