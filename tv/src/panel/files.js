/**
 * Panelde dosya alışverişi: JSON indirme ve seçilen dosyayı okuma.
 *
 *     downloadJson(data, 'kodmob-yedek-2026-10-05.json');
 *     const data = await readJsonFile(input.files[0]);
 */

/** Bugünün tarihi dosya adı için: 2026-10-05 */
export const today = () => new Date().toISOString().slice(0, 10);

export function downloadJson(data, filename) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = Object.assign(document.createElement('a'), { href: url, download: filename });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Dosyayı ya da yapıştırılan metni JSON olarak okur; okunamazsa açıklayıcı hata fırlatır. */
export async function readJson(source) {
    const text = typeof source === 'string' ? source : await source.text();
    try {
        return JSON.parse(text);
    } catch {
        throw new Error('Geçerli bir JSON değil. Dosyanın ya da yapıştırdığınız metnin tamamını kontrol edin.');
    }
}
