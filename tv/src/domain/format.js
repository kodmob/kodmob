/**
 * Sayı ve tarih biçimleri (sayfanın dilinde: tr-TR, en-GB, de-DE). Ekranlar değerleri
 * yalnızca bunlarla basar, böylece her yerde aynı görünür: 1.250,5.
 */
import { intlLocale } from '../i18n/client.js';

const LOCALE = intlLocale();
const nf = (min, max) => new Intl.NumberFormat(LOCALE, { minimumFractionDigits: min, maximumFractionDigits: max });
const NF0 = nf(0, 0);
const NF1 = nf(0, 1);
const NF2 = nf(0, 2);
const MONEY = nf(2, 2);

const isNum = (v) => v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
const DASH = '—';

export const number = (v, digits = 0) => (isNum(v) ? (digits === 0 ? NF0 : digits === 1 ? NF1 : NF2).format(Number(v)) : DASH);


/** 1250.5 → "1.250,50 TL" */
export const tl = (v) => (isNum(v) ? `${MONEY.format(Number(v))} TL` : DASH);

export const percent = (v, digits = 0) => (isNum(v) ? `%${number(v, digits)}` : DASH);

function toDate(value) {
    if (!value) return null;
    if (value instanceof Date) return value;
    // MySQL "YYYY-MM-DD HH:MM:SS" → yerel saat
    const d = new Date(typeof value === 'string' && !value.includes('T') ? value.replace(' ', 'T') : value);
    return Number.isNaN(d.getTime()) ? null : d;
}

export const date = (v) => {
    const d = toDate(v);
    return d ? d.toLocaleDateString(LOCALE, { day: '2-digit', month: '2-digit', year: 'numeric' }) : DASH;
};

export const time = (v) => {
    const d = toDate(v);
    return d ? d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) : DASH;
};

export const dateTime = (v) => {
    const d = toDate(v);
    return d ? `${date(d)} ${time(d)}` : DASH;
};

/** Üst bar: "23 Eylül 2026" */
export const longDate = (v = new Date()) => toDate(v)?.toLocaleDateString(LOCALE, { day: 'numeric', month: 'long', year: 'numeric' }) ?? DASH;

/** "3 dk önce", "2 sa önce", "dün", sonra tarih */
export function relative(v, now = Date.now()) {
    const d = toDate(v);
    if (!d) return DASH;
    const sec = Math.round((now - d.getTime()) / 1000);
    if (sec < 45) return 'az önce';
    const min = Math.round(sec / 60);
    if (min < 60) return `${min} dk önce`;
    const hr = Math.round(min / 60);
    if (hr < 24) return `${hr} sa önce`;
    const day = Math.round(hr / 24);
    if (day === 1) return 'dün';
    if (day < 7) return `${day} gün önce`;
    return date(d);
}


/** 22080 → "6 sa 8 dk"; 540 → "9 dk"; 40 → "1 dk altı" */
export function duration(seconds) {
    if (!isNum(seconds)) return DASH;
    const min = Math.floor(Number(seconds) / 60);
    if (min < 1) return '1 dk altı';
    const h = Math.floor(min / 60);
    const d = Math.floor(h / 24);
    if (d >= 1) return `${d} gün ${h % 24} sa`;
    return h ? `${h} sa ${min % 60} dk` : `${min} dk`;
}

/** "Ahmet Yılmaz" → "AY" */
export function initials(name = '') {
    const parts = String(name).trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? parts[0][0] + parts[parts.length - 1][0] : (parts[0] || '?').slice(0, 2);
    return letters.toLocaleUpperCase(LOCALE);
}

export const fmt = { number, tl, percent, date, time, dateTime, longDate, relative, duration, initials };
