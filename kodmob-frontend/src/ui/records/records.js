/**
 * Genel "Sil" ve "Silinenler": paneldeki tek silme akışı
 * (kodmob-core Modules/Records). Ortak tablo (ui/grid) her satırda bunu kullanır; düzenleme
 * formundaki "Sil" düğmesi de aynısını çağırır:
 *
 *     if (await deleteRecord({ type: 'post', id: post.id, label: post.title })) navigate(href('list', { type: 'posts' }));
 *
 * Pencerenin içeriği sunucudan gelir (`/records/plan`): engeller, notlar ("Sitede yayından
 * kalkar") ve kaydın kalıcı silinip silinmeyeceği (talepler kalıcı silinir, KVKK). İçerik
 * kalıcı silinmez: Silinenler'den geri yüklenir; bildirimde "Geri Al" düğmesi çıkar.
 */
import { http } from '../../services/http.js';
import { fmt } from '../../domain/format.js';

const store = (name) => window.Alpine.store(name);

/**
 * @param {{ type: string, id: number|string, label?: string, onRestored?: Function }} record
 * @returns {Promise<object|null>} silindiyse sunucu yanıtı; vazgeçildiyse, engel ya da hata varsa null
 */
export async function deleteRecord({ type, id, label = '', onRestored = null }) {
    const toast = store('toast');
    try {
        const plan = await http.get('/records/plan', { type, id });
        const name = plan.title || label || `#${id}`;
        if (plan.blockers?.length) {
            toast.error(plan.blockers[0], { title: `${name} silinemez` });
            return null;
        }
        const notes = (plan.notes || []).slice(0, 3).join(' ');
        const ok = await store('confirm').ask({
            title: `${name} silinsin mi?`,
            message: plan.permanent
                ? `Bu kayıt kalıcı silinir; geri yüklenemez.${notes ? ` ${notes}` : ''}`
                : `Kayıt listelerden ve siteden kalkar.${notes ? ` ${notes}` : ''} Kalıcı silinmez; "Silinenler"den geri yüklenebilir.`,
            summary: (plan.groups || []).map((g) => ({ label: g.label, value: `${fmt.number(g.count)} kayıt` })),
            confirmText: plan.permanent ? 'Kalıcı olarak sil' : 'Sil',
            tone: 'danger',
        });
        if (!ok) return null;
        const result = await http.post('/records/hide', { type, id, label: label || name });
        toast.success(result.message || `${name} silindi.`, result.permanent ? {} : {
            action: { label: 'Geri Al', run: async () => (await restoreRecord({ type, id, label: name })) && onRestored?.() },
        });
        return result;
    } catch (error) {
        toast.error(error);
        return null;
    }
}

/**
 * Toplu "Sil": seçilen kayıtlar tek onayla silinir. Her kayıt sunucuda kendi kuralından
 * geçer; silinemeyenler nedeniyle bildirilir ve yerinde kalır.
 *
 * @param {Array<{ type: string, id: number|string, label?: string }>} items
 * @returns {Promise<{ done: number, failed: Array<{ label: string, message: string }> } | null>} vazgeçildiyse null
 */
export async function deleteRecords(items) {
    const toast = store('toast');
    if (!items.length) return null;
    const ok = await store('confirm').ask({
        title: `${fmt.number(items.length)} kayıt silinsin mi?`,
        message: 'Kayıtlar listelerden ve siteden kalkar. Silinemeyen kayıt nedeniyle bildirilir ve yerinde kalır. İçerik kalıcı silinmez; "Silinenler"den geri yüklenir (talepler kalıcı silinir).',
        confirmText: 'Hepsini Sil',
        tone: 'danger',
    });
    if (!ok) return null;
    let done = 0;
    const failed = [];
    for (const item of items) {
        try {
            await http.post('/records/hide', { type: item.type, id: item.id, label: item.label || `#${item.id}` });
            done += 1;
        } catch (error) {
            failed.push({ label: item.label || `#${item.id}`, message: error?.message || 'silinemedi' });
        }
    }
    if (done) toast.success(`${fmt.number(done)} kayıt silindi.`);
    if (failed.length) {
        const first = failed.slice(0, 3).map((f) => `${f.label}: ${f.message}`).join(' · ');
        toast.error(`${fmt.number(failed.length)} kayıt silinemedi. ${first}${failed.length > 3 ? ' …' : ''}`);
    }
    return { done, failed };
}

/** Silinen kaydı geri yükler. @returns {Promise<boolean>} */
export async function restoreRecord({ type, id, label = '' }) {
    try {
        await http.post('/records/restore', { type, id });
        store('toast').success(`${label || `#${id}`} geri yüklendi.`);
        return true;
    } catch (error) {
        store('toast').error(error);
        return false;
    }
}

/** Bu türün silinen kayıtları (Silinenler). */
export async function hiddenRecords(type) {
    try {
        return (await http.get('/records/hidden', { type })).items || [];
    } catch (error) {
        store('toast').error(error);
        return [];
    }
}
