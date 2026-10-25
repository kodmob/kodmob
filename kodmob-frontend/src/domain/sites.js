/**
 * Siteler sayfasının (panel/sites.js) saf yardımcıları; kurallar kodmob-core Core\Sites ile aynı:
 * anahtar SiteContext::KEY_PATTERN, alan adı SiteContext::host() (küçük harf, port ve "www." yok).
 */

const TR = { ç: 'c', ğ: 'g', ı: 'i', ö: 'o', ş: 's', ü: 'u' };

/** Ad → anahtar önerisi: küçük harf, rakam ve tire; en çok 32 karakter, başta ve sonda tire yok. */
export function siteKey(name) {
    return String(name || '')
        .toLocaleLowerCase('tr')
        .replace(/[çğıöşü]/g, (c) => TR[c])
        .normalize('NFKD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 32)
        .replace(/-+$/, '');
}

/** Panel bu bilgisayarda mı açık (localhost, 127.0.0.1, ::1, *.localhost): yeni siteye yerel deneme adresi önerilir. */
export function isLocalHost(hostname) {
    const host = String(hostname || '').toLowerCase().replace(/^\[|\]$/g, '');
    return host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.endsWith('.localhost');
}

/**
 * Yerel deneme adresi: `anahtar.localhost`. Chrome, Edge ve Firefox `*.localhost`'u ek ayar istemeden bu bilgisayara
 * yönlendirir (hosts dosyası gerekmez); IP adresinin alt adı (deneme.127.0.0.1) açılmaz.
 */
export const localDomain = (key) => (key ? `${key}.localhost` : '');

/** Sitenin bu panelden açılan adresi: yerelde `.localhost` alan adı (bu sunucunun bağlantı noktasıyla), değilse kayıttaki adres. */
export function siteOrigin(site, location) {
    const local = isLocalHost(location?.hostname) && (site?.domains || []).find((d) => d.endsWith('.localhost'));
    if (local) return `${location.protocol}//${local}${location.port ? `:${location.port}` : ''}`;
    return site?.url || (site?.domains?.[0] ? `https://${site.domains[0]}` : '');
}

/** Metin kutusundaki alan adları: satır, virgül ya da boşlukla ayrılmış; şema, yol ve port atılır, tekrarlar bir kez. */
export function parseDomains(text) {
    return [...new Set(String(text || '').split(/[\s,]+/)
        .map((d) => d.trim().toLowerCase().replace(/^[a-z]+:\/\//, '').replace(/[/?#].*$/, '').replace(/:\d+$/, '').replace(/\.$/, '').replace(/^www\./, ''))
        .filter(Boolean))];
}
