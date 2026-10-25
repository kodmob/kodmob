/**
 * Bülten aboneliği (kodmob-core Modules\Newsletter; POST /newsletter). Çift onay: gönderilince onay e-postası gider,
 * abone bağlantıya tıklayınca listeye girer. Spam koruması talep formuyla aynı: görünmez tuzak alan (`website`) ve
 * açılış zamanı (`started_at`). Hata metinleri ziyaretçinin dilinde (client.newsletter).
 */
import { http } from '@/services/http.js';
import { t, lang } from '@/i18n/client.js';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function init(el) {
    const form = el.querySelector('[data-newsletter-form]');
    const done = el.querySelector('[data-newsletter-done]');
    if (!form || !done) return null;
    const startedAt = Date.now();
    const field = (name) => form.elements.namedItem(name);
    const show = (key, message = '') => {
        const p = form.querySelector(`[data-error="${key}"]`);
        p.textContent = message;
        p.hidden = !message;
        field(key)?.setAttribute('aria-invalid', message ? 'true' : 'false');
    };

    const submit = async (event) => {
        event.preventDefault();
        const email = field('email').value.trim();
        const consent = field('consent').checked;
        show('email', EMAIL.test(email) ? '' : t('newsletter.emailInvalid'));
        show('consent', consent ? '' : t('newsletter.consentRequired'));
        if (!EMAIL.test(email) || !consent) return;
        const button = form.querySelector('button[type=submit]');
        button.disabled = true;
        try {
            await http.post('/newsletter', {
                email,
                name: field('name')?.value.trim() || '',
                consent,
                locale: lang(),
                // Bülten sayfasındaki blok ayrı sayılır (panelde kaynak).
                source: el.closest('[data-newsletter-page]') ? 'page' : 'block',
                website: field('website').value,
                started_at: startedAt,
            });
            form.hidden = true;
            done.hidden = false;
            done.focus();
        } catch (error) {
            const fields = error?.details?.fields || {};
            if (fields.email) show('email', t('newsletter.emailInvalid'));
            else if (fields.consent) show('consent', t('newsletter.consentRequired'));
            else show('email', t(error?.status === 429 ? 'newsletter.rateLimited' : 'newsletter.error'));
        } finally {
            button.disabled = false;
        }
    };

    form.addEventListener('submit', submit);
    return () => form.removeEventListener('submit', submit);
}
