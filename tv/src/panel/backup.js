/**
 * Yedekleme (Ayarlar > Yedekleme, `/panel/ayarlar/yedekleme`): tam yedeği indir, dosyadan geri
 * yükle; yedek listesi (geri yükleme öncesi sunucunun otomatik aldığı anlık yedekler) — indir
 * ya da o hale geri dön.
 *
 * Yedek bütün içeriği, ayarları ve görsellerin kaydını (adres + alt metin)
 * taşır; görsel dosyaları sunucuda kalır. Geri yükleme mevcut içeriği
 * yedektekiyle değiştirir; öncesinde sunucu bir anlık yedek alır.
 *
 * Kişisel kayıtlar (siparişler, talepler, aboneler) indirilen yedeğe yalnızca kutu işaretliyse girer;
 * geri yüklemede ayrıca seçilir (seçilmezse sitedeki kayıtlar kalır). Otomatik yedeğin zip'i ve uzak
 * depodaki şifreli kopyası (.kmbak, yedek şifresiyle) da buradan yüklenebilir.
 */
import { api } from './api.js';
import { TYPES } from './types.js';
import { relative } from './meta.js';
import { fmt } from '../domain/format.js';
import { downloadJson, readJson, today } from './files.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const LABELS = { ...Object.fromEntries(Object.entries(TYPES).map(([name, t]) => [name, t.singular.toLocaleLowerCase('tr')])), settings: 'ayar', texts: 'sayfa metni', languages: 'dil', seo: 'yönlendirme', slugs: 'eski adres' };
const summary = (counts) => Object.entries(counts || {}).map(([key, n]) => `${n} ${LABELS[key] || key}`).join(', ');
/** Dosya adındaki neden (20261015-171428-geri-yukleme-oncesi.json) okunur hale gelir. */
const REASONS = { 'geri-yukleme-oncesi': 'Geri yükleme öncesi (otomatik)' };

/** Yedekteki kişisel kayıtların adları (records; eski yedekte yok). */
export const personalIn = (backup) => Object.values(backup?.records || {}).filter((r) => r.personal).map((r) => `${r.label} (${r.count})`);

/** Geri yüklemede "kişisel kayıtlar da" kutusu; işaretsiz başlar (sitedeki yeni siparişler silinmesin). */
export const personalOption = (labels) =>
    labels.length ? { label: 'Kişisel kayıtları da geri yükle', hint: `${labels.join(', ')}: sitedekilerin yerine yedektekiler yazılır.`, checked: false } : null;

/** Geri yükleme raporundaki kişisel kayıt notu. */
export const recordsNote = (records) =>
    records?.restored?.length ? ` Kişisel kayıtlar: ${records.restored.join(', ')}.` : records?.skipped?.length ? ' Kişisel kayıtlar değişmedi.' : '';

export function kmBackup() {
    return {
        relative,
        fmt,
        busy: '',
        snapshots: [],
        personal: [],
        withPersonal: false,
        loadingList: true,
        report: null,

        async init() {
            if (this.$store.session.can('km_manage_backups')) await this.loadSnapshots();
            this.loadingList = false;
        },

        async loadSnapshots() {
            try {
                const list = await api.snapshots();
                this.snapshots = list.items;
                this.personal = list.personal || [];
            } catch {
                this.snapshots = [];
            }
        },

        label(name) {
            const reason = String(name).replace(/^\d{8}-\d{6}-?/, '').replace(/\.json$/, '');
            return REASONS[reason] || reason.replace(/-/g, ' ') || 'Yedek';
        },

        async download() {
            this.busy = 'download';
            try {
                const backup = await api.backup(this.withPersonal);
                downloadJson(backup, `kodmob-yedek-${today()}.json`);
                toast.success(`Yedek indirildi: ${summary(backup.counts)}.`);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async restore(files) {
            const file = files?.[0];
            if (!file) return;
            if (/\.(zip|kmbak)$/i.test(file.name)) {
                await this.restoreArchive(file);
                return;
            }
            let backup;
            try {
                backup = await readJson(file);
            } catch (error) {
                toast.error(error.message);
                return;
            }
            await this.restoreBackup(backup, file.name);
        },

        /** Listedeki anlık yedeğe geri döner (dosya sunucudan alınıp aynı geri yükleme yolundan gider). */
        async restoreSnapshot(name) {
            this.busy = `load:${name}`;
            let backup;
            try {
                backup = await api.snapshot(name);
            } catch (error) {
                toast.error(error);
                return;
            } finally {
                this.busy = '';
            }
            await this.restoreBackup(backup, name);
        },

        /** Otomatik yedeğin zip'i ya da şifreli kopyası (.kmbak): içi sunucuda açılır. */
        async restoreArchive(file) {
            const encrypted = /\.kmbak$/i.test(file.name);
            const option = personalOption(this.personal);
            const field = encrypted ? { label: 'Yedek şifresi', type: 'password', value: '' } : null;
            const ok = await confirmAction({
                title: 'Yedekten geri yüklensin mi?',
                message: 'Sitedeki bütün içerik ve ayarlar yedektekilerle değişir; görseller de yedekten gelir. Öncesinde mevcut durum yedek listesine kaydedilir.',
                summary: [{ label: 'Dosya', value: file.name }, { label: 'Boyut', value: this.size(file.size) }],
                confirmText: 'Geri yükle',
                tone: 'danger',
                option,
                field,
            });
            if (!ok) return;
            const form = new FormData();
            form.append('file', file);
            if (field) form.append('passphrase', field.value);
            if (option?.checked) form.append('personal', '1');
            this.busy = 'restore';
            try {
                this.report = await api.restoreFile(form);
                toast.success(`Geri yüklendi: ${summary(this.report.counts)}.${recordsNote(this.report.records)}`);
                await this.loadSnapshots();
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async restoreBackup(backup, fileName) {
            if (backup.format !== 'kodmob-backup') {
                toast.error('Bu dosya bir Kodmob yedeği değil.');
                return;
            }
            const option = personalOption(personalIn(backup));
            const ok = await confirmAction({
                title: 'Yedekten geri yüklensin mi?',
                message: 'Sitedeki bütün projeler, hizmetler, referanslar, yazılar ve ayarlar yedektekilerle değiştirilir. Öncesinde mevcut durum yedek listesine kaydedilir; oradan geri dönebilirsiniz.',
                summary: [
                    { label: 'Dosya', value: fileName },
                    { label: 'Tarih', value: backup.created ? new Date(backup.created).toLocaleString('tr-TR') : '—' },
                    { label: 'İçerik', value: summary(backup.counts) || '—' },
                ],
                confirmText: 'Geri yükle',
                tone: 'danger',
                option,
            });
            if (!ok) return;
            this.busy = 'restore';
            try {
                this.report = await api.restore(backup, !!option?.checked);
                const failed = this.report.media.failed.length;
                toast[failed ? 'warning' : 'success'](`Geri yüklendi: ${summary(this.report.counts)}.${failed ? ` ${failed} görsel bulunamadı.` : ''}${recordsNote(this.report.records)}`);
                await this.loadSnapshots();
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async downloadSnapshot(name) {
            this.busy = name;
            try {
                downloadJson(await api.snapshot(name), name);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        size(bytes) {
            return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
        },

        summary,
    };
}
