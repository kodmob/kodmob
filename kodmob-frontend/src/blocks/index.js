/**
 * Blokların sitedeki davranışı. Sayfadaki her blok `<div data-km-block="<ad>" data-km-id="…">` içindedir (sunucu
 * ve derleme sarar); davranışı olan bloğun modülü (blocks/<ad>/block.js, `init(el)`) yalnızca sayfada o blok varsa
 * iner. Sayfa düzenleyici önizlemede bloğu değiştirince `km:blocks` olayıyla yeniden başlatır.
 */
const byName = (modules) => Object.fromEntries(Object.entries(modules).map(([file, load]) => [file.split('/').slice(-2, -1)[0], load]));
// Profilin aynı adlı bloğu çatınınkinin yerine geçer (vite/blocks.js ile aynı kural).
const MODULES = { ...byName(import.meta.glob('./*/block.js')), ...byName(import.meta.glob('@profile/blocks/*/block.js')) };

/** Başlatılan bloklar ve bırakma işlevleri (yeniden çizilen blok önce bırakılır). */
const started = new WeakMap();

async function start(el) {
    if (started.has(el)) return;
    const load = MODULES[el.dataset.kmBlock];
    started.set(el, null);
    if (!load) return;
    try {
        const { init } = await load();
        started.set(el, (await init?.(el)) || null);
    } catch (error) {
        console.error(`[blok] ${el.dataset.kmBlock}:`, error);
    }
}

export function initBlocks(root = document) {
    root.querySelectorAll('[data-km-block]').forEach(start);
}

/** Düzenleyici: değişen bloğu bırakıp yenisini başlatır (`detail.root`: değişen kök; yoksa bütün sayfa). */
export function watchBlocks() {
    document.addEventListener('km:blocks', (event) => {
        for (const el of event.detail?.removed || []) {
            const stop = started.get(el);
            if (typeof stop === 'function') stop();
            started.delete(el);
        }
        initBlocks(event.detail?.root || document);
    });
}
