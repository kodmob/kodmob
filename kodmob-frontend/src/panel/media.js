/**
 * Medya kitaplığı penceresi ($store.media) — görsel seçme, yükleme, adresten
 * alma ve alt metin (TR zorunlu, EN/DE isteğe bağlı) tek yerde.
 *
 *     const picked = await Alpine.store('media').pick({ multiple: true });
 *     if (picked) gallery.push(...picked);
 *
 * - `pick({ multiple, current, files })`: `current` önceden seçili görseller
 *   (alt metin düzenlemek için), `files` açılır açılmaz yüklenecek dosyalar
 *   (alana sürükle-bırak). Vazgeçilirse `null` döner.
 * - Seçilen her görselin varsayılan dildeki alt metni dolmadan "Seç" çalışmaz; değişen
 *   alt metinler seçimde kaydedilir.
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { localeCodes, languages, defaultLocale } from './meta.js';

/** Alt metin dilleri: dil kaydından (sunucu). */
const blankAlts = () => Object.fromEntries(localeCodes().map((code) => [code, '']));
const copy = (item) => ({ ...item, alts: { ...blankAlts(), ...(item.alts || {}) }, _orig: JSON.stringify(item.alts || {}) });

export function createMediaStore() {
    let resolver = null;

    return {
        open: false,
        multiple: false,
        items: [],
        page: 1,
        pages: 0,
        total: 0,
        search: '',
        loading: false,
        selected: [],
        focusId: null,
        uploading: 0,
        url: '',
        fetching: false,
        saving: false,
        error: '',
        over: false,
        // Getter: depo oturumdan önce kurulur; dil listesi /me gelince güncel olsun.
        get locales() {
            return localeCodes();
        },

        pick({ multiple = false, current = null, files = null } = {}) {
            Object.assign(this, { open: true, multiple, search: '', url: '', error: '', items: [], page: 1, pages: 0, saving: false });
            this.selected = (current || []).filter(Boolean).map(copy);
            this.focusId = this.selected[0]?.id ?? null;
            document.documentElement.classList.add('overflow-hidden');
            this.load();
            if (files?.length) this.uploadFiles(files);
            return new Promise((resolve) => {
                resolver = resolve;
            });
        },

        close(result = null) {
            this.open = false;
            document.documentElement.classList.remove('overflow-hidden');
            resolver?.(result);
            resolver = null;
        },

        async load(more = false) {
            this.loading = true;
            try {
                const page = more ? this.page + 1 : 1;
                const result = await api.media({ search: this.search.trim(), page });
                this.items = more ? [...this.items, ...result.items] : result.items;
                Object.assign(this, { page, pages: result.pages, total: result.total });
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        isSelected(id) {
            return this.selected.some((s) => s.id === id);
        },

        toggle(item) {
            if (this.isSelected(item.id)) {
                if (this.focusId !== item.id && this.multiple) {
                    this.focusId = item.id;
                    return;
                }
                this.selected = this.selected.filter((s) => s.id !== item.id);
                this.focusId = this.selected[0]?.id ?? null;
                return;
            }
            const picked = copy(item);
            this.selected = this.multiple ? [...this.selected, picked] : [picked];
            this.focusId = item.id;
            this.error = '';
        },

        get focused() {
            return this.selected.find((s) => s.id === this.focusId) || null;
        },

        /** Zorunlu alt metin dili: varsayılan dil (dil kaydı). */
        get altLocale() {
            return defaultLocale();
        },

        get altLabel() {
            return languages().find((l) => l.code === this.altLocale)?.name || this.altLocale.toUpperCase();
        },

        /** Alt metin alanları: kayıttaki bütün diller, varsayılan önce. */
        get altLanguages() {
            const list = languages();
            return [...list.filter((l) => l.code === this.altLocale), ...list.filter((l) => l.code !== this.altLocale)];
        },

        get missing() {
            return this.selected.filter((s) => !String(s.alts?.[this.altLocale] || '').trim());
        },

        missingAlt(item) {
            const selected = this.selected.find((s) => s.id === item.id);
            return !String((selected || item).alts?.[this.altLocale] || '').trim();
        },

        async uploadFiles(files) {
            for (const file of [...files]) {
                if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                    toast.error(`${file.name}: yalnızca JPG, PNG ya da WebP yüklenebilir.`);
                    continue;
                }
                this.uploading++;
                try {
                    const media = await api.upload(file);
                    this.items = [media, ...this.items.filter((i) => i.id !== media.id)];
                    this.total++;
                    if (!this.multiple) this.selected = [];
                    this.toggle(media);
                } catch (error) {
                    toast.error(error);
                } finally {
                    this.uploading--;
                }
            }
        },

        async importUrl() {
            if (!this.url.trim() || this.fetching) return;
            this.fetching = true;
            try {
                const media = await api.uploadUrl(this.url.trim());
                this.items = [media, ...this.items];
                this.url = '';
                if (!this.multiple) this.selected = [];
                this.toggle(media);
            } catch (error) {
                toast.error(error);
            } finally {
                this.fetching = false;
            }
        },

        /** Seç: eksik alt metin varsa uyarır; değişen alt metinleri kaydeder. */
        async confirm() {
            if (!this.selected.length || this.saving) return;
            if (this.missing.length) {
                this.focusId = this.missing[0].id;
                this.error = this.missing.length === 1 ? `Seçili görselin ${this.altLabel} alt metnini yazın.` : `${this.missing.length} görselin ${this.altLabel} alt metni eksik.`;
                return;
            }
            this.saving = true;
            try {
                const result = [];
                for (const item of this.selected) {
                    const { _orig, ...clean } = item;
                    if (JSON.stringify(item.alts) !== _orig) {
                        const saved = await api.saveAlts(item.id, item.alts);
                        this.items = this.items.map((i) => (i.id === saved.id ? saved : i));
                        result.push(saved);
                    } else {
                        result.push(clean);
                    }
                }
                this.close(result);
            } catch (error) {
                this.error = error.details?.fields?.[`alts.${this.altLocale}`] || error.message;
            } finally {
                this.saving = false;
            }
        },
    };
}
