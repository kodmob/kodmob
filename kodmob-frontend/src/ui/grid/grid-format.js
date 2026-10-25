/**
 * dfGrid ortak biçimleme (grid.js ve özellik modülleri): hücre değeri, sayısal biçimler.
 */
import { fmt } from '../../domain/format.js';

export const NUMERIC = ['number', 'tl', 'percent'];
export const NOWRAP = [...NUMERIC, 'date', 'time', 'dateTime', 'relative'];

/** Sütun biçimine göre metin; boş değer "—". */
export function formatValue(format, value) {
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'code') return String(value);
    if (format === 'image') return '';
    const fn = fmt[format];
    return fn ? fn(value) : String(value);
}

/**
 * Özellik nesnelerini birleştirir; getter ve setter'lar korunur (nesne yayma `...` onları
 * değere çevirirdi). Sonraki parça aynı adlı alanı ezer.
 */
export function compose(...parts) {
    const out = {};
    for (const part of parts) Object.defineProperties(out, Object.getOwnPropertyDescriptors(part));
    return out;
}
