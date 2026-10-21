/**
 * Sayfadaki bir ayarı bulup gösterir: ekranın ortasına kaydırır, kısa süre vurgular, içindeki
 * alana odaklanır. Ayar şablonda `data-setting="<anahtar>"` ile işaretlidir (panel araması,
 * `#ayar=<anahtar>` bağlantıları).
 *
 * Sayfa ve sekme yeni açılıyorsa alan birkaç yüz milisaniye sonra çizilir: 5 sn'ye kadar beklenir.
 * Alan yoksa (ör. SMTP seçili değilken SMTP sunucusu) bir üst bölüm denenir: `mail.host` → `mail`.
 *
 * Önce `km-spotlight` olayı gider: alan kapalı bir pencerede ise görünüm onu açar
 * (ör. Jarvis, `ai.` ile başlayan anahtarda Claude ayarlarını).
 */
const FLASH_MS = 2400;
const WAIT_MS = 5000;

let pending = null;

/** Az önce istenen anahtar (5 sn): tembel yüklenen görünüm açılışta okur (olay ondan önce gitmiş olabilir). */
export function pendingSpotlight() {
    return pending && Date.now() - pending.at < WAIT_MS ? pending.key : '';
}

export function spotlight(key) {
    if (!key) return;
    pending = { key, at: Date.now() };
    window.dispatchEvent(new CustomEvent('km-spotlight', { detail: { key } }));
    const candidates = key.split('.').map((_, i, parts) => parts.slice(0, parts.length - i).join('.'));
    const started = Date.now();
    const tick = () => {
        const waited = Date.now() - started;
        // İlk 1,5 sn yalnızca tam anahtar; sonra üst bölümler de kabul.
        const pool = waited < 1500 ? candidates.slice(0, 1) : candidates;
        for (const candidate of pool) {
            const el = [...document.querySelectorAll(`[data-setting="${CSS.escape(candidate)}"]`)].find((node) => node.offsetParent !== null);
            if (el) return reveal(el);
        }
        if (waited < WAIT_MS) setTimeout(tick, 120);
    };
    tick();
}

function reveal(el) {
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
    el.classList.remove('pn-spotlight');
    void el.offsetWidth; // animasyon yeniden başlasın
    el.classList.add('pn-spotlight');
    setTimeout(() => el.classList.remove('pn-spotlight'), FLASH_MS);
    const input = el.matches('input, select, textarea, button') ? el : el.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
    input?.focus({ preventScroll: true });
}

/** `#ayar=<anahtar>` → anahtar (yoksa boş). */
export function spotlightFromHash(hash) {
    return String(hash || '').startsWith('#ayar=') ? decodeURIComponent(hash.slice(6)) : '';
}
