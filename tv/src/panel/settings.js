/**
 * Ayarlar iki sayfa, tek bileşen (`kmSettings(r, kapsam)`):
 *
 * - Proje Ayarları (/panel/proje-ayarlari; `project`): kimlik, iletişim, sosyal medya, yasal bilgiler,
 *   görseller, talepler ve araçların ayarları (kayıttaki `scope: project`).
 * - Sistem Ayarları (/panel/sistem-ayarlari; `system`, rota adı `settings`): Genel (sistem kapsamlı
 *   ayarlar: sistem sağlığı raporu), E-posta, SMS, Yapay zekâ, Arka plan (panel/system.js), Yedekleme
 *   (panel/backup.js). Sekmeler adreste (`/panel/sistem-ayarlari/yedekleme`). E-posta, SMS ve Yapay zekâ
 *   (`shared`) kurulumun tamamınındır: alt projede görünmez, ana projenin ayarları kullanılır (Options::SHARED).
 *
 * Kayıttaki ayarların tanımları sunucudan gelir (tür, sınır, açıklama, kapsam; sunucu yalnızca kullanıcının
 * değiştirebildiği grupları verir); yalnızca değişenler gönderilir. Güvenlik, SEO ve çoklu dil ayarları kendi
 * sayfalarında.
 */
import { href, replaceUrl } from './routes.js';
import { api } from './api.js';
import { toast } from '../ui/toast.js';

/** Grupların paneldeki sırası (listede olmayanlar sona). */
const ORDER = ['Kimlik', 'İletişim', 'Sosyal medya', 'Yasal', 'Talepler', 'Görseller', 'Sistem sağlığı'];

/** Kendi sayfası olan gruplar (form burada çizilmez). */
const ELSEWHERE = ['Güvenlik', 'SEO', 'Çoklu dil'];

/** Sistem Ayarları sekmeleri; Proje Ayarları tek bölümdür (araçlar sekmesi Araç Pazarı'yla gelir). */
const TABS = [
    { key: 'general', label: 'Genel', icon: 'cog', can: 'km_manage_settings' },
    { key: 'mail', label: 'E-posta', icon: 'mail', can: 'km_manage_settings', shared: true },
    { key: 'sms', label: 'SMS', icon: 'message', can: 'km_manage_sms', shared: true },
    { key: 'ai', label: 'Yapay zekâ', icon: 'sparkles', can: 'km_manage_ai', shared: true },
    { key: 'jobs', label: 'Arka plan', icon: 'activity', can: 'km_manage_settings' },
    { key: 'backup', label: 'Yedekleme', icon: 'download', can: 'km_manage_backups' },
];

const PROJECT_TABS = [{ key: 'general', label: 'Proje', icon: 'sliders', can: 'km_manage_settings|km_manage_ai' }];

export function kmSettings(route = {}, scope = 'system') {
    const tabs = scope === 'project' ? PROJECT_TABS : TABS;
    const routeName = scope === 'project' ? 'projectSettings' : 'settings';
    const mine = (items) => items.filter((i) => (i.scope || 'project') === scope && !ELSEWHERE.includes(i.group));
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _onRoute: null,
        tabs,
        scope,
        tab: null,
        items: [],
        values: {},
        original: {},
        errors: {},
        loading: true,
        saving: false,

        async init() {
            this.show(route.tab);
            this._onRoute = (e) => this.show(e.detail?.tab);
            window.addEventListener('km-route', this._onRoute);
            // Yalnızca yedek yetkisi olan, ayar formunu görmez.
            if (!this.visible(tabs[0])) {
                this.loading = false;
                return;
            }
            try {
                this.apply(mine((await api.settings()).items));
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        visible(t) {
            return this.$store.session.can(t.can) && !(t.shared && this.child);
        },

        /** Alt projede miyiz (paylaşılan sekmeler ana projede). */
        get child() {
            return this.$store.session.me?.project?.main === false;
        },

        /** Sekmeyi açar; yetkisi olmayan ya da bilinmeyen sekme ilk görünür sekmeye düşer. */
        show(key) {
            const tab = tabs.find((t) => t.key === key && this.visible(t)) || tabs.find((t) => this.visible(t)) || tabs[0];
            this.tab = tab.key;
            replaceUrl(href(routeName, { tab: tab.key }));
        },

        apply(items) {
            this.items = items;
            this.values = Object.fromEntries(items.map((i) => [i.key, i.value]));
            this.original = { ...this.values };
        },

        get groups() {
            const groups = {};
            for (const item of this.items) (groups[item.group] ||= []).push(item);
            const rank = (name) => (ORDER.indexOf(name) === -1 ? 99 : ORDER.indexOf(name));
            return Object.entries(groups).sort(([a], [b]) => rank(a) - rank(b)).map(([name, list]) => ({ name, items: list }));
        },

        get changed() {
            return Object.keys(this.values).filter((key) => this.values[key] !== this.original[key]);
        },

        hasOptions(item) {
            return item.options && Object.keys(item.options).length > 0;
        },

        async save() {
            if (this.tab !== 'general' || !this.changed.length || this.saving) return;
            this.saving = true;
            this.errors = {};
            try {
                const values = Object.fromEntries(this.changed.map((key) => [key, this.values[key]]));
                const result = await api.saveSettings(values);
                this.errors = result.errors || {};
                const failed = Object.keys(this.errors);
                const keep = Object.fromEntries(failed.map((key) => [key, this.values[key]]));
                this.apply(mine(result.items));
                Object.assign(this.values, keep);
                failed.length ? toast.error('Bazı ayarlar kaydedilemedi; işaretli alanları düzeltin.') : toast.success('Ayarlar kaydedildi.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        reset() {
            this.values = { ...this.original };
            this.errors = {};
        },
    };
}
