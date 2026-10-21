/**
 * 404 / 410 sayfası (done-coklu-dil-raporu.md §4.7).
 *
 * - `available`: kayıt başka dillerde varsa render.php `#km-data`'ya yazar ([{ code, label, url }]);
 *   içerik deposu dil seçiciyi de bu adreslere bağlar (stores/content.js).
 * - `gone`: silinmiş içerik (410).
 * - `suggestions`: "Bunu mu demek istediniz?" — GET /suggest?path= (kodmob-api Site\Render\Suggestions).
 *   Başlığı olmayan sabit sayfaların adı arama dizininin sayfa listesinden.
 */
import { API } from '@/services/config.js';
import { pageInfo } from '@/i18n/client.js';

export function kmNotFound() {
    return {
        suggestions: [],

        get available() {
            return this.$store.km?.available || [];
        },

        /** Panelden seçilen "sık ziyaret edilenler" (Çoklu Dil > Ziyaretçi); boşsa sayfadaki varsayılanlar. */
        get popular() {
            return this.$store.km?.popular || [];
        },

        get gone() {
            return !!this.$store.km?.gone;
        },

        async init() {
            try {
                const query = new URLSearchParams({ path: window.location.pathname });
                const response = await fetch(`${API.base}${API.app}/suggest?${query}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const titles = Object.fromEntries((pageInfo().searchPages || []).map((p) => [p.url, p.title]));
                const taken = new Set(this.available.map((a) => a.url));
                this.suggestions = ((await response.json()).items || [])
                    .filter((item) => !taken.has(item.url))
                    .map((item) => ({ url: item.url, title: item.title || titles[item.url] || item.url }));
            } catch {
                /* öneri yoksa sayfa yine çalışır */
            }
        },
    };
}
