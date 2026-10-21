/**
 * WhatsApp düğmesi: çalışma saatine göre "çevrimiçi / mesai dışı" durumu.
 * Saatler Türkiye saatiyle hesaplanır (ziyaretçinin saat diliminden bağımsız).
 *
 *     <div x-data="kmWhatsapp({ start: 9, end: 18, days: [1,2,3,4,5], timezone: 'Europe/Istanbul' })">
 *       <span x-show="online">…</span>
 */
const WEEKDAYS = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };

export function isOpen({ start = 9, end = 18, days = [1, 2, 3, 4, 5], timezone = 'Europe/Istanbul' } = {}, now = new Date()) {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: timezone, weekday: 'short', hour: 'numeric', hourCycle: 'h23' })
        .formatToParts(now)
        .map((p) => [p.type, p.value]));
    const hour = Number(parts.hour);
    return days.includes(WEEKDAYS[parts.weekday]) && hour >= start && hour < end;
}

export function kmWhatsapp(hours = {}) {
    return {
        online: false,
        init() {
            this.online = isOpen(hours);
        },
    };
}
