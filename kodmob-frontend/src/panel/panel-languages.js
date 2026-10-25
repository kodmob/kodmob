/**
 * Çok dilli panel (kodmob-core Modules\System\PanelLanguage):
 *
 * - `kmPanelLanguages` (Sistem Ayarları › Panel dili): kurulu diller ve ne kadarının çevrildiği; frontend-lang.json
 *   dışa aktarma (yeni dil ya da eksikleri tamamlamak için), doldurulan dosyayı içe aktarma, dili kaldırma.
 * - `kmPanelLanguagePicker` (Profilim): kişinin panel dili; seçince sayfa yenilenir (metinler o dille çizilir).
 */
import { api } from './api.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

export function kmPanelLanguages() {
    return {
        summary: { languages: [], total: 0, strings: 0, messages: 0 },
        form: { code: '', name: '' },
        busy: false,

        async init() {
            await this.load();
        },

        async load() {
            try {
                this.summary = await api.panelLanguages();
            } catch (error) {
                toast.error(error);
            }
        },

        percent(lang) {
            return lang.total ? Math.round((lang.translated / lang.total) * 100) : 0;
        },

        /** Dosyayı indirir (yeni dil: kod ve ad; var olan dil: eksikleri boş, çevrilmişler dolu). */
        async download(code = this.form.code, name = this.form.name) {
            this.busy = true;
            try {
                const response = await api.panelLanguageExport(code.trim().toLowerCase(), name.trim());
                const blob = await response.blob();
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `frontend-lang.${code.trim().toLowerCase()}.json`;
                link.click();
                URL.revokeObjectURL(link.href);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        async upload(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (!file) return;
            this.busy = true;
            try {
                const data = new FormData();
                data.append('file', file);
                const res = await api.panelLanguageImport(data);
                this.summary = res;
                toast.success(`${res.name}: ${res.strings} metin, ${res.messages} mesaj içe aktarıldı.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = false;
            }
        },

        async remove(lang) {
            if (!(await confirmAction({ title: `${lang.name} kaldırılsın mı?`, message: 'Bu dili seçmiş kişilerin paneli Türkçe olur. Dosyası varsa yeniden içe aktarılabilir.', confirmText: 'Kaldır', tone: 'danger' }))) return;
            try {
                this.summary = await api.panelLanguageDelete(lang.code);
                toast.success('Dil kaldırıldı.');
            } catch (error) {
                toast.error(error);
            }
        },
    };
}

export function kmPanelLanguagePicker() {
    return {
        get choices() {
            return this.$store.session.me?.panel_languages || [{ code: 'tr', name: 'Türkçe' }];
        },

        get current() {
            return this.$store.session.me?.panel_language || 'tr';
        },

        async choose(code) {
            if (code === this.current) return;
            try {
                await api.panelLanguageChoose(code);
                window.location.reload();
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
