/**
 * Geliştirici belgeleri sitesi (VitePress): docs/'daki Markdown dosyaları, GitHub Pages'te kodmob.github.io/kodmob.
 *
 *   cd docs && npm ci && npm run dev      yerelde (http://localhost:5173/kodmob/)
 *   npm run build                         .vitepress/dist (.github/workflows/docs.yml yayımlar)
 *
 * Belgeler kodun dosyalarına göreli bağlantı verir (../../kodmob-api/app/…): sitede olmayan her hedef GitHub'daki
 * dosyaya gider. İç raporlar (*-raporu.md) siteye girmez.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitepress';

const REPO = 'https://github.com/kodmob/kodmob';
const SITE = 'https://kodmob.github.io/kodmob/';
const DOCS = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ROOT = path.resolve(DOCS, '..');
const EXCLUDE = ['**/*-raporu.md', 'done-*.md', 'prompt.md', 'tanitim/**', 'node_modules/**'];

const excluded = (file) => /-raporu\.md$|[\\/]done-[^\\/]*\.md$|[\\/]prompt\.md$|[\\/]tanitim[\\/]/.test(file);
const onSite = (file) => file.startsWith(DOCS + path.sep) && file.endsWith('.md') && fs.existsSync(file) && !excluded(file);
const github = (file, hash = '') => {
    const kind = fs.existsSync(file) && fs.statSync(file).isDirectory() ? 'tree' : 'blob';
    return `${REPO}/${kind}/main/${path.relative(ROOT, file).split(path.sep).join('/')}${hash ? `#${hash}` : ''}`;
};

/** Sitede olmayan göreli bağlantı (kod dosyası, klasör, kökteki belge) GitHub'a. */
function githubLinks(md) {
    const render = md.renderer.rules.link_open || ((tokens, i, options, env, self) => self.renderToken(tokens, i, options));
    md.renderer.rules.link_open = (tokens, i, options, env, self) => {
        const href = tokens[i].attrGet('href') || '';
        if (href && !/^([a-z][a-z0-9+.-]*:|#|\/)/i.test(href) && env.relativePath) {
            const [file, hash] = href.split('#');
            const target = path.resolve(path.dirname(path.join(DOCS, env.relativePath)), decodeURI(file));
            if (!onSite(target)) tokens[i].attrSet('href', github(target, hash));
        }
        return render(tokens, i, options, env, self);
    };
}

/**
 * Belgelerde düz metin ve satır içi koddaki `{{…}}` (blok ve yasal belge şablon dili) Vue'nun ara değeri sayılmasın.
 * Kod blokları VitePress'te zaten v-pre.
 */
function plainMustache(md) {
    const escape = (html) => html.replace(/\{\{/g, '&#123;&#123;').replace(/\}\}/g, '&#125;&#125;');
    for (const rule of ['text', 'code_inline']) {
        const render = md.renderer.rules[rule];
        md.renderer.rules[rule] = (tokens, i, options, env, self) => escape(render(tokens, i, options, env, self));
    }
}

/** llms.txt siteye: belge bağlantıları sitedeki sayfaya, diğerleri GitHub'a. */
function copyLlms(outDir) {
    const source = path.join(DOCS, 'llms.txt');
    if (!fs.existsSync(source)) return;
    const text = fs.readFileSync(source, 'utf-8').replace(/\]\(([^)#\s]+)\)/g, (all, href) => {
        if (/^[a-z]+:/i.test(href)) return all;
        const target = path.resolve(DOCS, href);
        if (!onSite(target)) return `](${github(target)})`;
        const page = path.relative(DOCS, target).split(path.sep).join('/').replace(/(^|\/)README\.md$/, '$1').replace(/\.md$/, '');
        return `](${SITE}${page})`;
    });
    fs.writeFileSync(path.join(outDir, 'llms.txt'), text);
}

export default defineConfig({
    lang: 'tr-TR',
    title: 'Kodmob',
    description: 'Kurumsal site ve çok kullanıcılı yönetim paneli çatısı: Laravel 12 + Vite, Alpine, Tailwind. Geliştirici belgeleri.',
    base: '/kodmob/',
    cleanUrls: true,
    lastUpdated: true,
    srcExclude: EXCLUDE,
    rewrites: { 'gelistirici/README.md': 'gelistirici/index.md' },
    head: [['link', { rel: 'icon', type: 'image/svg+xml', href: '/kodmob/favicon.svg' }]],
    sitemap: { hostname: SITE },
    // Belgelerdeki `<ad>` gibi yer tutucular HTML etiketi değil, metin.
    markdown: { html: false, languageAlias: { njk: 'jinja' }, config: (md) => md.use(githubLinks).use(plainMustache) },
    buildEnd: ({ outDir }) => copyLlms(outDir),
    themeConfig: {
        logo: '/favicon.svg',
        nav: [
            { text: 'Belgeler', link: '/gelistirici/', activeMatch: '^/gelistirici/' },
            { text: 'Araç paketi', link: '/arac-paketi' },
            { text: 'Değişiklikler', link: `${REPO}/blob/main/CHANGELOG.md` },
        ],
        sidebar: [
            {
                text: 'Başlarken',
                items: [
                    { text: 'Belgeler dizini', link: '/gelistirici/' },
                    { text: '01 Kurulum', link: '/gelistirici/01-kurulum' },
                    { text: '02 Mimari', link: '/gelistirici/02-mimari' },
                ],
            },
            {
                text: 'Geliştirme',
                items: [
                    { text: '03 Modül ve içerik türü', link: '/gelistirici/03-modul' },
                    { text: '04 Sayfalar', link: '/gelistirici/04-sayfalar' },
                    { text: '05 Ayar, yetki, uç', link: '/gelistirici/05-ayar-yetki' },
                    { text: '08 Bloklar', link: '/gelistirici/08-bloklar' },
                    { text: '09 Yasal belgeler', link: '/gelistirici/09-yasal-belgeler' },
                    { text: 'Araç paketi', link: '/arac-paketi' },
                ],
            },
            {
                text: 'Yayın ve bakım',
                items: [
                    { text: '06 Yayın', link: '/gelistirici/06-yayin' },
                    { text: '07 Güncelleme', link: '/gelistirici/07-guncelleme' },
                    { text: 'Paket ayrıntısı', link: '/build' },
                    { text: 'Bilinen sınırlar', link: '/dikkat' },
                ],
            },
            {
                text: 'Yapay zekâ ile geliştirme',
                items: [
                    { text: 'İstem kalıpları', link: '/ai/istemler' },
                    { text: 'Proje rehberi şablonu', link: '/sablonlar/CLAUDE.proje' },
                    { text: 'Etki analizi şablonu', link: '/sablonlar/etki-analizi' },
                ],
            },
        ],
        socialLinks: [{ icon: 'github', link: REPO }],
        editLink: { pattern: `${REPO}/edit/main/docs/:path`, text: 'Bu sayfayı GitHub\'da düzenleyin' },
        search: {
            provider: 'local',
            options: {
                translations: {
                    button: { buttonText: 'Ara', buttonAriaLabel: 'Ara' },
                    modal: {
                        displayDetails: 'Ayrıntıları göster',
                        resetButtonTitle: 'Temizle',
                        backButtonTitle: 'Kapat',
                        noResultsText: 'Sonuç yok',
                        footer: { selectText: 'seç', navigateText: 'gezin', closeText: 'kapat' },
                    },
                },
            },
        },
        outline: { level: [2, 3], label: 'Bu sayfada' },
        docFooter: { prev: 'Önceki', next: 'Sonraki' },
        lastUpdated: { text: 'Son güncelleme' },
        darkModeSwitchLabel: 'Görünüm',
        lightModeSwitchTitle: 'Açık temaya geç',
        darkModeSwitchTitle: 'Koyu temaya geç',
        sidebarMenuLabel: 'Menü',
        returnToTopLabel: 'Başa dön',
        langMenuLabel: 'Dil',
        notFound: { title: 'Sayfa bulunamadı', quote: 'Aradığınız belge taşınmış ya da silinmiş olabilir.', linkText: 'Ana sayfaya dön' },
        footer: { message: 'MIT lisansıyla yayımlanır. "Kodmob" adı ve logosu sahibinin markasıdır.', copyright: '© 2026 Hakkı Cengiz' },
    },
});
