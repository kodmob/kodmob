/**
 * Ayarlar > Yedekleme > Otomatik yedek (`kmAutoBackup`; kodmob-core Content\AutoBackup).
 *
 * İçerik yedeği ve görsellerin asılları her gün (ya da haftada bir) tek zip'te sunucuda tutulur
 * (son N), isteğe bağlı olarak S3 uyumlu bir depoya da gönderilir (Cloudflare R2, Backblaze B2,
 * AWS S3, Wasabi…). Gizli anahtar sunucuda şifreli, panele geri gelmez. "Şimdi yedekle" arka
 * planda çalışır; sonuç gelene kadar birkaç saniyede bir sorulur. Zamanlama cron ister.
 *
 * Uzak depoya giden kopya yedek şifresiyle şifrelenir (.kmbak; yedekte siparişler, talepler var):
 * şifre de panele geri gelmez; yeni sunucuda dosya listeye konup ya da yüklenip bu şifreyle açılır.
 */
import { api } from './api.js';
import { http } from '../services/http.js';
import { relative } from './meta.js';
import { fmt } from '../domain/format.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';
import { personalOption, recordsNote } from './backup.js';

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const FIELDS = ['enabled', 'frequency', 'keep', 'media', 'remote', 'endpoint', 'region', 'bucket', 'prefix', 'key', 'path_style'];

export function kmAutoBackup() {
    return {
        loading: true,
        view: null,
        form: {},
        secret: '',
        passphrase: '',
        errors: {},
        busy: '',
        relative,
        fmt,

        async init() {
            try {
                this.apply(await api.autoBackup());
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },

        apply(view) {
            this.view = view;
            this.form = Object.fromEntries(FIELDS.map((key) => [key, view[key]]));
            this.secret = '';
            this.passphrase = '';
        },

        /** Son çalışmanın özeti. */
        get lastText() {
            const v = this.view;
            if (!v) return '';
            if (!v.last_at) return 'Henüz otomatik yedek alınmadı.';
            const remote = v.last_remote === 'uploaded' ? ' · uzak depoya gönderildi' : v.last_remote === 'failed' ? ' · uzak depoya gönderilemedi' : '';
            return v.last_status === 'ok' ? `Son yedek ${relative(v.last_at)}${remote}.` : `Son deneme ${relative(v.last_at)} başarısız: ${v.last_error || 'bilinmeyen hata'}`;
        },

        async save() {
            this.busy = 'save';
            this.errors = {};
            try {
                this.apply(await api.saveAutoBackup({ ...this.form, ...(this.secret ? { secret: this.secret } : {}), ...(this.passphrase ? { passphrase: this.passphrase } : {}) }));
                toast.success('Otomatik yedek ayarları kaydedildi.');
            } catch (error) {
                this.errors = error.details?.fields || {};
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        /** Kayıtlı ayarlarla uzak depoya deneme dosyası yazılıp silinir. */
        async test() {
            this.busy = 'test';
            try {
                await api.testAutoBackupRemote();
                toast.success('Uzak depoya bağlanıldı: deneme dosyası yazılıp silindi.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async runNow() {
            this.busy = 'run';
            const before = this.view?.last_at;
            try {
                this.apply(await api.runAutoBackup());
                // Arka planda: sonuç gelene kadar (en çok ~3 dk) sorulur.
                for (let i = 0; i < 60 && this.view?.last_at === before; i++) {
                    await wait(3000);
                    this.apply(await api.autoBackup());
                }
                if (this.view?.last_at === before) toast.info('Yedek hâlâ alınıyor; birazdan listede görünür.');
                else if (this.view.last_status === 'ok') toast.success('Yedek alındı.');
                else toast.error(this.view.last_error || 'Yedek alınamadı.');
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async download(name) {
            this.busy = name;
            try {
                const response = await http.download(`/admin/backups/auto/${encodeURIComponent(name)}`, undefined, { timeout: 600000 });
                const url = URL.createObjectURL(await response.blob());
                const link = Object.assign(document.createElement('a'), { href: url, download: name });
                document.body.append(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(url), 1000);
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        async restore(item) {
            const option = personalOption(this.view?.personal || []);
            const field = item.encrypted ? { label: 'Yedek şifresi', type: 'password', value: '' } : null;
            const ok = await confirmAction({
                title: 'Bu yedeğe geri dönülsün mü?',
                message: 'Sitedeki bütün içerik ve ayarlar yedektekilerle değişir; görseller de yedekten geri gelir. Öncesinde mevcut durum "Yedek listesi"ne kaydedilir.',
                summary: [{ label: 'Yedek', value: item.name }, { label: 'Tarih', value: fmt.dateTime(item.created) }],
                confirmText: 'Geri yükle',
                tone: 'danger',
                option,
                field,
            });
            if (!ok) return;
            this.busy = `restore:${item.name}`;
            try {
                const result = await api.restoreAutoBackup(item.name, { personal: !!option?.checked, ...(field ? { passphrase: field.value } : {}) });
                const media = result.media ? ` Görseller: ${result.media.reused + result.media.downloaded}.` : '';
                toast.success(`Geri yüklendi.${media}${recordsNote(result.records)}`);
                window.dispatchEvent(new CustomEvent('km-backup-restored'));
            } catch (error) {
                toast.error(error);
            } finally {
                this.busy = '';
            }
        },

        size(bytes) {
            return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
        },
    };
}
