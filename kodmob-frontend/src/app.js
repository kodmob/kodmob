/**
 * Her sayfanın tek giriş noktası.
 *
 *     // pages/iletisim/main.js
 *     import { startPage } from '@/app.js';
 *     startPage({ components: { contactPage } });
 *
 * İçerik sayfalarında Alpine, sayfanın verisi ($store.km, bkz.
 * stores/content.js) hazır olduktan sonra başlar: x-for ile çizilen içerik
 * ilk çizimde yerindedir. Veri sayfaya gömülü gelir; gelmezse istenir.
 */
import { applyStoredTheme } from './ui/theme/theme.js';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import './styles/app.css';

import { createToastStore } from './stores/toast.js';
import { createContentStore, loadPageData } from './stores/content.js';
import { registerUi } from './ui/index.js';
import { initEffects } from './ui/effects/index.js';
import { initBlocks, watchBlocks } from './blocks/index.js';
import { fmt } from './domain/format.js';
import { initAnalytics } from './services/analytics.js';

// Seçilmiş tema ilk çizime en yakın anda (CSP, head'de satır içi betiğe izin vermiyor).
applyStoredTheme();

let started = false;

export async function startPage({ components = {} } = {}) {
    if (started) return;
    started = true;

    const data = await loadPageData();
    if (data) Alpine.store('km', createContentStore(data));

    Alpine.plugin(collapse);
    Alpine.store('toast', createToastStore());
    Alpine.magic('fmt', () => fmt);
    registerUi(Alpine);
    Object.entries(components).forEach(([name, factory]) => Alpine.data(name, factory));

    window.Alpine = Alpine;
    // Sunucunun JavaScript'siz okuyucular için yazdığı liste kopyaları (kodmob-core ListSsr): Alpine
    // listeleri kendisi çizer, çift içerik olmasın.
    document.querySelectorAll('[data-km-ssr-item]').forEach((node) => node.remove());
    Alpine.start();

    // Kaydırınca belirme, parallax, özel imleç (x-for içerikleri çizildikten sonra).
    requestAnimationFrame(initEffects);
    // Sayfadaki blokların davranışı (kayan etiketler, harf animasyonu…): yalnızca sayfadakiler iner.
    initBlocks();
    watchBlocks();
    // Çapa (/hizmetler/#web): bölüm içerikle birlikte çizildi, tarayıcının ilk kaydırması onu kaçırır.
    if (data && window.location.hash) {
        requestAnimationFrame(() => document.getElementById(decodeURIComponent(window.location.hash.slice(1)))?.scrollIntoView());
    }
    initAnalytics();
}
