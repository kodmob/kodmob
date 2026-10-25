import { defineConfig, loadEnv } from 'vite';
import fs from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { PROFILE, PROFILE_DIR } from './profile.js';
import { frameworkRoutes } from './vite/framework-routes.js';
import { readLegal } from './vite/legal.js';
import { i18nPages } from './vite/i18n-pages.js';
import { htmlMinifier, iconSprite } from './vite/plugins.js';
import { EXTENSIONS_DIR, extensionBlockDirs, extensionPanels, kmExtensions, loadExtensionPanels } from './vite/extensions.js';
import { panelTypes, projectPages, toolPages } from './src/panel/extension.js';
import { usePages, useTypes } from './src/panel/routes.js';

const SRC = resolve(__dirname, 'src');

/** Sitenin profilinden (profile.js): rotalar, site bilgileri, diller. Çatının rotaları sona eklenir. */
const fromProfile = async (file) => import(pathToFileURL(resolve(PROFILE_DIR, file)).href);
const { ROUTES: SITE_ROUTES } = await fromProfile('routes.config.js');
const { SITE: MAIN_SITE } = await fromProfile('site.config.js');
const { LOCALES, DEFAULT_LOCALE, LANG_COOKIE } = await fromProfile('locales.js');
// Yasal belgeler (vite/legal.js): çatınınkiler, profil aynı adla değiştirir ya da yenisini ekler.
const LEGAL = readLegal([{ dir: resolve(SRC, 'legal'), source: 'framework' }, { dir: resolve(PROFILE_DIR, 'legal'), source: 'profile' }]);
// Çatının araç sayfaları (Mağaza, Rezervasyon, Oteller, Bülten) profil yazmadıysa kapalı olarak girer (toolPages: false almaz).
const ROUTES = [...SITE_ROUTES, ...frameworkRoutes(Object.keys(LOCALES), SITE_ROUTES, { tools: MAIN_SITE.toolPages !== false, legal: LEGAL })];
// Projenin panel uzantısı (profilin panel.js'i): içerik türleri ve özel sayfalar km-manifest `panel`'e (kodmob-core
// PanelUrl); özel sayfaların şablonları panelin kabuğuna (i18n-pages `panelPages`). Araç paketlerinin sayfaları da
// (kodmob-core/extensions/<anahtar>/panel; vite/extensions.js).
const PANEL = await fromProfile('panel.js');
const TOOLS = extensionPanels();
const PANEL_PAGES = [...projectPages(PANEL.PAGES), ...toolPages(await loadExtensionPanels(TOOLS))];
useTypes(panelTypes(PANEL.TYPES));
usePages(PANEL_PAGES);

/**
 * Çok site: KM_SITE=<anahtar> alt sitenin kendi derlemesini üretir (scripts/build-sites.mjs çağırır):
 * katman kodmob-frontend/sites/<anahtar>/ (site.config.js, i18n/, templates/; bkz. vite/i18n-pages.js), çıktı
 * dist-sites/<anahtar>; betik sayfaları dist/_sites/<anahtar>/'a, varlıkları dist/assets/'a taşır.
 */
const SITE_KEY = process.env.KM_SITE || '';
const OVERLAY = SITE_KEY ? resolve(__dirname, 'sites', SITE_KEY) : null;
if (OVERLAY && !fs.existsSync(OVERLAY)) throw new Error(`KM_SITE=${SITE_KEY}: ${OVERLAY} yok.`);

async function siteProfile() {
    const file = OVERLAY && resolve(OVERLAY, 'site.config.js');
    if (!file || !fs.existsSync(file)) return MAIN_SITE;
    const { SITE: own = {} } = await import(pathToFileURL(file).href);
    return { ...MAIN_SITE, ...own, organization: { ...MAIN_SITE.organization, ...own.organization } };
}

export default defineConfig(async ({ command, mode }) => {
    const env = loadEnv(mode, __dirname, '');
    const SITE = await siteProfile();
    // Alt sitenin adresi kendi profilinden (sunucu yine kayıttaki adresi kullanır: Router).
    const siteUrl = ((OVERLAY ? SITE.url : env.SITE_URL) || (command === 'serve' ? 'http://localhost:5173' : SITE.url)).replace(/\/$/, '');

    return {
        // Sayfalar src altında sanal HTML olarak üretilir: src/tr/index.html → dist/tr/index.html.
        root: SRC,
        envDir: __dirname,
        // Alt sitenin derlemesinde de (yazı tipleri çözülsün); build-sites.mjs yalnızca sayfaları ve assets'i alır.
        publicDir: resolve(__dirname, 'public'),
        base: '/',

        server: {
            host: true,
            port: 5173,
            strictPort: true,
            // /api/… ve /media/… yerel siteye gider (kodmob-core/dev/serve.cmd: php -S
            // 127.0.0.1:8090); canlıdaki gibi aynı adres. Başka yer için API_PROXY.
            // Araç paketlerinin panel dosyaları kodmob-frontend'in dışında (kodmob-core/extensions).
            fs: { allow: [__dirname, EXTENSIONS_DIR] },
            proxy: {
                '/api': { target: env.API_PROXY || 'http://127.0.0.1:8090', changeOrigin: true },
                '/media': { target: env.API_PROXY || 'http://127.0.0.1:8090', changeOrigin: true },
            },
        },

        plugins: [
            i18nPages({
                srcDir: SRC,
                routes: ROUTES,
                site: SITE,
                locales: LOCALES,
                defaultLocale: DEFAULT_LOCALE,
                cookie: LANG_COOKIE,
                siteUrl,
                // Yayına çıkarken .env.production'da SITE_INDEXABLE=true yapılır.
                indexable: env.SITE_INDEXABLE === 'true',
                overlay: OVERLAY,
                profile: PROFILE,
                profileDir: PROFILE_DIR,
                panelPages: PANEL_PAGES.map((p) => ({ key: p.key, template: p.template })),
                extensionsDir: EXTENSIONS_DIR,
                // Sayfa düzenleyicinin blokları (vite/blocks.js): çatınınkiler, profilinkiler, araç paketlerininkiler.
                blockSources: [
                    { dir: resolve(SRC, 'blocks'), source: 'framework' },
                    { dir: resolve(PROFILE_DIR, 'blocks'), source: 'profile' },
                    ...extensionBlockDirs(),
                ],
                iconsFile: resolve(SRC, 'shell/icons.html'),
                legal: LEGAL,
            }),
            kmExtensions(TOOLS),
            iconSprite({ file: resolve(SRC, 'shell/icons.html') }),
            htmlMinifier(),
        ],

        build: {
            outDir: OVERLAY ? resolve(__dirname, 'dist-sites', SITE_KEY) : resolve(__dirname, 'dist'),
            emptyOutDir: true,
            assetsDir: 'assets',
            rollupOptions: {
                output: {
                    entryFileNames: 'assets/js/[name]-[hash].js',
                    chunkFileNames: 'assets/js/[name]-[hash].js',
                    assetFileNames: (asset) => {
                        const name = asset.names?.[0] || asset.name || '';
                        if (/\.css$/i.test(name)) return 'assets/css/[name]-[hash][extname]';
                        if (/\.(woff2?|ttf|otf)$/i.test(name)) return 'assets/fonts/[name]-[hash][extname]';
                        if (/\.(png|jpe?g|gif|svg|webp|avif|ico)$/i.test(name)) return 'assets/img/[name]-[hash][extname]';
                        return 'assets/[name]-[hash][extname]';
                    },
                },
            },
        },

        resolve: {
            // @profile: etkin site profili (tarayıcı kodu dilleri buradan alır: @profile/locales.js).
            alias: { '@': SRC, '@profile': PROFILE_DIR },
        },
    };
});
