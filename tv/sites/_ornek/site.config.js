/**
 * Örnek alt site profili (tv/sites/README.md). Klasör kayıttaki anahtarla yeniden adlandırılınca derlenir.
 * Yalnızca değişenler yazılır; gerisi ana site.config.js'ten gelir.
 */
export const SITE = {
    url: 'https://ornek.kodmob.com',
    organization: { type: 'LocalBusiness' },
    // Bu sitede derlenmeyecek sayfalar (routes.config.js'teki adlar).
    exclude: ['estimate'],
};
