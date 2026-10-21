/**
 * Para: sunucuda en küçük birimde (kuruş) tam sayı, formda ondalıklı metin ("149,90"). kodmob-api
 * Core\Content\Fields `money` alanı aynı kuralla anlar.
 */

/** "1.234,56" / "1234.56" / "149,9" → kuruş; geçersiz ya da negatifse 0. */
export function toMinor(value) {
    const text = String(value ?? '').trim().replace(/[^\d.,]/g, '');
    if (!text) return 0;
    const normal = text.includes(',') ? text.replace(/\./g, '').replace(',', '.') : text;
    const number = Number(normal);
    return Number.isFinite(number) && number > 0 ? Math.round(number * 100) : 0;
}

/** Kuruş → formdaki metin ("149,90"); 0 boş. */
export function fromMinor(minor) {
    const n = Number(minor) || 0;
    return n ? (n / 100).toFixed(2).replace('.', ',') : '';
}
