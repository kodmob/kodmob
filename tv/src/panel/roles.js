/**
 * Roller ve İzinler (/panel/roller, yalnızca Yönetici — `km_manage_roles`;
 * kodmob-api Modules/Users/RolesController):
 *
 * - Sol: roller (kullanıcı sayısı, yetki sayısı, "Değişti"). Yönetici kilitli (bütün yetkiler).
 *   "Yeni rol" ile ad, açıklama ve başlangıç yetkileri (bir rolden kopya) verilir; kullanıcısı
 *   olmayan eklenmiş rol silinir. Hazır rollerin adı değişir, kendisi silinmez.
 * - Sağ, üç görünüm:
 *   · Sayfalar: rolün göreceği sayfalar tek tek açılıp kapatılır. Sayfayı açmak sayfanın yetkisini
 *     ekler; kapatmak o sayfayı açan yetkileri kaldırır (aynı yetkiyi kullanan sayfalar satırda yazar).
 *   · İşlem yetkileri: bütün yetkiler gruplu (yayınlama, silme, kullanıcı yönetimi…); her yetkinin
 *     altında o yetkiyle açılan sayfalar.
 *   · Yan Menü: rolün sol menüsü (gruplar, sıra, menüde olmayan sayfalardan ekleme). Menüde olmak
 *     sayfayı açmaz; yetkisi olmayan öğe görünmez. Ayarı olmayan rol varsayılan menüyü görür.
 * - Kaydet rolün yetkilerini hemen değiştirir: o roldeki kişilerin paneli nabızla (`role`) tazelenir,
 *   sunucu her uçta yeni yetkiyle denetler. Varsayılana Dön koddaki tanıma döner.
 */
import { api } from './api.js';
import { PAGES, GROUPS, defaultMenu, pageByKey } from './meta.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const MENU_PAGES = PAGES.filter((p) => !p.hidden);
const GROUP_LABELS = Object.fromEntries(GROUPS.map((g) => [g.key, g.label]));
const clone = (v) => JSON.parse(JSON.stringify(v));
const fold = (s) => String(s || '').toLocaleLowerCase('tr-TR');
const capsOf = (page) => (page.can ? page.can.split('|') : []);

export function kmRoles() {
    return {
        loading: true,
        saving: false,
        matrix: null,
        slug: '',
        draft: [],
        q: '',
        /** 'pages' | 'caps' | 'menu' */
        view: 'pages',
        menuDraft: [],
        addTarget: 0,
        creating: null,
        renaming: null,
        groupLabels: GROUP_LABELS,

        async init() {
            await this.load();
        },

        async load(keep = '') {
            this.loading = !this.matrix;
            try {
                this.matrix = await api.roles();
                this.pick(keep || this.slug || this.matrix.roles.find((r) => !r.locked)?.slug || '', true);
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        get role() {
            return this.matrix?.roles.find((r) => r.slug === this.slug) || null;
        },

        get allCaps() {
            return (this.matrix?.capabilities || []).flatMap((g) => g.items.map((c) => c.key));
        },

        /** Menüye konabilen sayfalar: yetkisi sunucunun kataloğunda olanlar (kapalı modülün sayfası yok; kodmob-api Core\Modules). */
        get menuPages() {
            const known = new Set(this.allCaps);
            return MENU_PAGES.filter((p) => !capsOf(p).length || capsOf(p).some((c) => known.has(c)));
        },

        get pageGroups() {
            return GROUPS.map((g) => ({ key: g.key, name: g.label, pages: this.menuPages.filter((p) => p.group === g.key) })).filter((g) => g.pages.length);
        },

        async pick(slug, force = false) {
            if (!force && slug === this.slug) return;
            if (!force && (this.dirty || this.menuDirty)) {
                const ok = await confirmAction({ title: 'Kaydedilmemiş değişiklik', message: `${this.role?.label} rolündeki değişiklikler kaydedilmedi. Bırakılsın mı?`, confirmText: 'Bırak', tone: 'danger' });
                if (!ok) return;
            }
            this.slug = slug;
            this.draft = [...(this.role?.caps || [])];
            this.menuDraft = clone(this.menuBase);
            this.addTarget = 0;
        },

        // ------------------------------------------------------------ yetkiler
        has(cap) {
            return this.role?.locked || this.draft.includes(cap);
        },

        isLocked(cap) {
            return (this.matrix?.capabilities || []).some((g) => g.items.some((c) => c.key === cap && c.locked));
        },

        toggle(cap) {
            if (this.role?.locked || this.isLocked(cap)) return;
            this.draft = this.draft.includes(cap) ? this.draft.filter((c) => c !== cap) : [...this.draft, cap];
        },

        toggleGroup(g) {
            if (this.role?.locked) return;
            const keys = g.items.map((c) => c.key).filter((k) => !this.isLocked(k));
            const all = keys.every((k) => this.has(k));
            this.draft = all ? this.draft.filter((c) => !keys.includes(c)) : [...new Set([...this.draft, ...keys])];
        },

        groupState(g) {
            const keys = g.items.map((c) => c.key);
            const n = keys.filter((k) => this.has(k)).length;
            return n === 0 ? 'none' : n === keys.length ? 'all' : 'some';
        },

        changed(cap) {
            return !this.role?.locked && this.role?.caps.includes(cap) !== this.draft.includes(cap);
        },

        differsFromDefault(cap) {
            return this.role?.system && !this.role.locked && this.role.default_caps.includes(cap) !== this.has(cap);
        },

        get changeCount() {
            if (!this.role || this.role.locked) return 0;
            const a = new Set(this.role.caps);
            const b = new Set(this.draft);
            return [...a].filter((c) => !b.has(c)).length + [...b].filter((c) => !a.has(c)).length;
        },

        get dirty() {
            return this.changeCount > 0;
        },

        // ------------------------------------------------------------ sayfalar
        /** Sayfa, yetkisi (ya da `a|b` biçiminde herhangi biri) varsa açılır; yetkisizse herkese açık. */
        opens(page, caps) {
            const need = capsOf(page);
            return !need.length || need.some((c) => caps.includes(c));
        },

        pagesFor(cap) {
            return this.menuPages.filter((p) => capsOf(p).includes(cap));
        },

        get visiblePages() {
            return this.menuPages.filter((p) => this.seesPage(p));
        },

        seesPage(page) {
            return this.role?.locked || this.opens(page, this.draft);
        },

        isOpenToAll(page) {
            return !capsOf(page).length;
        },

        /** Sayfayı aç / kapat: açarken sayfanın (ilk) yetkisi eklenir, kapatırken onu açan bütün yetkiler kalkar. */
        togglePage(page) {
            if (this.role?.locked || this.isOpenToAll(page)) return;
            const caps = capsOf(page);
            if (this.seesPage(page)) {
                this.draft = this.draft.filter((c) => !caps.includes(c));
            } else {
                this.draft = [...new Set([...this.draft, caps[0]])];
            }
        },

        /** Aynı yetkiyle açılan öbür sayfalar (kapatınca onlar da kapanır). */
        siblingsOf(page) {
            const caps = capsOf(page);
            return this.menuPages.filter((p) => p.key !== page.key && capsOf(p).some((c) => caps.includes(c))).map((p) => p.label);
        },

        pageChanged(page) {
            return !this.role?.locked && this.opens(page, this.role?.caps || []) !== this.opens(page, this.draft);
        },

        capLabel(cap) {
            for (const g of this.matrix?.capabilities || []) {
                const c = g.items.find((x) => x.key === cap);
                if (c) return c.label;
            }
            return cap;
        },

        get filteredPageGroups() {
            const q = fold(this.q.trim());
            if (!q) return this.pageGroups;
            return this.pageGroups.map((g) => ({ ...g, pages: g.pages.filter((p) => fold(`${p.label} ${p.href}`).includes(q)) })).filter((g) => g.pages.length);
        },

        get filteredGroups() {
            const q = fold(this.q.trim());
            const groups = this.matrix?.capabilities || [];
            if (!q) return groups;
            return groups
                .map((g) => ({ ...g, items: g.items.filter((c) => fold(`${c.label} ${c.key} ${this.pagesFor(c.key).map((p) => p.label).join(' ')}`).includes(q)) }))
                .filter((g) => g.items.length);
        },

        undo() {
            this.draft = [...(this.role?.caps || [])];
        },

        async save() {
            if (!this.dirty || this.saving) return;
            this.saving = true;
            try {
                const label = this.role.label;
                await api.saveRoleCaps(this.slug, this.draft);
                await this.load(this.slug);
                toast.success(`${label} rolünün izinleri kaydedildi. O roldeki kişilerin paneli hemen güncellenir.`);
                this.refreshOwn();
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        async resetRole() {
            const ok = await confirmAction({ title: 'Varsayılana dön', message: `${this.role.label} rolünün izinleri varsayılana dönsün mü?`, confirmText: 'Varsayılana Dön', tone: 'danger' });
            if (!ok) return;
            this.saving = true;
            try {
                const label = this.role.label;
                await api.saveRoleCaps(this.slug, null);
                await this.load(this.slug);
                toast.success(`${label} rolü varsayılan izinlere döndü.`);
                this.refreshOwn();
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        // ------------------------------------------------------------ yan menü
        get menuCustom() {
            return !!this.role?.menu;
        },

        get menuBase() {
            return this.role?.menu ? clone(this.role.menu) : defaultMenu().filter((g) => g.items.length);
        },

        get menuDirty() {
            const norm = (m) => JSON.stringify(m.map((g) => ({ label: String(g.label).trim(), items: g.items })));
            return !!this.slug && norm(this.menuDraft) !== norm(this.menuBase);
        },

        pageOf(key) {
            return pageByKey(key);
        },

        seesItem(key) {
            const page = this.pageOf(key);
            return !!page && this.seesPage(page);
        },

        get unusedPageGroups() {
            const used = new Set(this.menuDraft.flatMap((g) => g.items));
            const q = fold(this.q.trim());
            const list = this.menuPages.filter((p) => !used.has(p.key) && (!q || fold(`${p.label} ${p.href}`).includes(q)));
            return GROUPS.map((g) => ({ key: g.key, name: g.label, pages: list.filter((p) => p.group === g.key) })).filter((g) => g.pages.length);
        },

        addGroup() {
            this.menuDraft.push({ key: `g-${Date.now().toString(36)}`, label: 'Yeni grup', items: [] });
            this.addTarget = this.menuDraft.length - 1;
            this.$nextTick(() => this.$root.querySelector(`[data-menu-group="${this.menuDraft.length - 1}"]`)?.select());
        },

        removeGroup(gi) {
            this.menuDraft.splice(gi, 1);
            this.addTarget = Math.min(this.addTarget, Math.max(0, this.menuDraft.length - 1));
        },

        moveGroup(gi, step) {
            const to = gi + step;
            if (to < 0 || to >= this.menuDraft.length) return;
            this.menuDraft.splice(to, 0, this.menuDraft.splice(gi, 1)[0]);
        },

        /** Sayfayı grupta kaydırır; grubun başından yukarı ya da sonundan aşağı komşu gruba geçer. */
        moveItem(gi, ii, step) {
            const group = this.menuDraft[gi];
            const to = ii + step;
            if (to >= 0 && to < group.items.length) {
                group.items.splice(to, 0, group.items.splice(ii, 1)[0]);
                return;
            }
            const next = this.menuDraft[gi + step];
            if (!next) return;
            const [key] = group.items.splice(ii, 1);
            if (step < 0) next.items.push(key);
            else next.items.unshift(key);
        },

        removeItem(gi, ii) {
            this.menuDraft[gi].items.splice(ii, 1);
        },

        addItem(key) {
            if (!this.menuDraft.length) this.menuDraft.push({ key: `g-${Date.now().toString(36)}`, label: 'Menü', items: [] });
            const group = this.menuDraft[Math.min(this.addTarget, this.menuDraft.length - 1)];
            if (!group.items.includes(key)) group.items.push(key);
        },

        undoMenu() {
            this.menuDraft = clone(this.menuBase);
        },

        async saveMenu() {
            if (!this.menuDirty || this.saving) return;
            const groups = this.menuDraft.map((g) => ({ ...g, label: String(g.label).trim() })).filter((g) => g.items.length);
            if (!groups.length) {
                toast.warning('Menüde en az bir sayfa olmalı.');
                return;
            }
            this.saving = true;
            try {
                const label = this.role.label;
                await api.saveRoleMenu(this.slug, groups);
                await this.load(this.slug);
                toast.success(`${label} rolünün yan menüsü kaydedildi.`);
                this.refreshOwn();
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        async resetMenu() {
            const ok = await confirmAction({ title: 'Varsayılan menüye dön', message: `${this.role.label} rolü varsayılan yan menüyü görsün mü?`, confirmText: 'Varsayılana Dön', tone: 'danger' });
            if (!ok) return;
            this.saving = true;
            try {
                await api.saveRoleMenu(this.slug, null);
                await this.load(this.slug);
                toast.success(`${this.role.label} rolü varsayılan yan menüye döndü.`);
                this.refreshOwn();
            } catch (error) {
                toast.error(error);
            } finally {
                this.saving = false;
            }
        },

        /** Kendi rolü değiştiyse menü ve yetkiler hemen tazelenir. */
        refreshOwn() {
            if (this.$store.session.role.slug === this.slug) this.$store.session.load().catch(() => {});
        },

        // ------------------------------------------------------------ rol ekle, adlandır, sil
        startCreate() {
            this.creating = { label: '', description: '', copy_from: this.role && !this.role.locked ? this.slug : 'author', errors: {} };
        },

        async create() {
            const form = this.creating;
            form.errors = {};
            try {
                const role = await api.createRole({ label: form.label, description: form.description, copy_from: form.copy_from || undefined });
                this.creating = null;
                await this.load(role.slug);
                toast.success(`${role.label} rolü eklendi. Sayfalarını ve işlem yetkilerini ayarlayın.`);
            } catch (error) {
                form.errors = error.details?.fields || {};
                toast.error(error);
            }
        },

        startRename() {
            this.renaming = { label: this.role.label, description: this.role.description, errors: {} };
        },

        async rename() {
            const form = this.renaming;
            form.errors = {};
            try {
                await api.renameRole(this.slug, { label: form.label, description: form.description });
                this.renaming = null;
                await this.load(this.slug);
                toast.success('Rol bilgileri kaydedildi.');
            } catch (error) {
                form.errors = error.details?.fields || {};
                toast.error(error);
            }
        },

        async remove() {
            const role = this.role;
            const ok = await confirmAction({ title: `${role.label} rolü silinsin mi?`, message: 'Rol kalıcı olarak silinir. Bu rolde kullanıcı varsa önce başka rol verin.', confirmText: 'Rolü sil', tone: 'danger' });
            if (!ok) return;
            try {
                await api.deleteRole(role.slug);
                this.slug = '';
                await this.load();
                toast.success(`${role.label} rolü silindi.`);
            } catch (error) {
                toast.error(error);
            }
        },
    };
}
