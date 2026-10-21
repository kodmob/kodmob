import { startPage } from '@/app.js';
import { http } from '@/services/http.js';

/**
 * Bülten sayfası (pages/newsletter/index.njk): `?confirm=<anahtar>` aboneliği onaylar (sayfa açılınca),
 * `?unsubscribe=<anahtar>` çıkmayı sorar (düğmeyle). Anahtar adres çubuğundan silinir (adres paylaşılırsa
 * başkası aboneliği değiştiremesin). `state`: '' (form) | loading | active | ask | unsubscribed | invalid.
 */
function newsletterPage() {
    return {
        state: '',
        token: '',
        busy: false,

        async init() {
            const query = new URLSearchParams(window.location.search);
            const confirm = query.get('confirm');
            const unsubscribe = query.get('unsubscribe');
            this.token = confirm || unsubscribe || '';
            if (!this.token) return;
            window.history.replaceState(null, '', window.location.pathname);
            if (unsubscribe) {
                this.state = 'ask';
                return;
            }
            this.state = 'loading';
            await this.call('confirm');
        },

        unsubscribe() {
            return this.call('unsubscribe');
        },

        async call(action) {
            this.busy = true;
            try {
                this.state = (await http.post(`/newsletter/${action}`, { token: this.token })).status;
            } catch {
                this.state = 'invalid';
            } finally {
                this.busy = false;
            }
        },
    };
}

startPage({ components: { newsletterPage } });
