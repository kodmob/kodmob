/**
 * İkon başvurusu (`<use :href="iconHref('check')">`). Build'de ikon seti ayrı, önbelleklenen bir
 * dosyadadır ve adresi `<html data-icons>`'tadır (vite/plugins.js iconSprite); geliştirmede set
 * sayfaya gömülüdür, başvuru `#i-ad` kalır. Şablonlardaki `#i-…` yazımlarını build kendisi çevirir;
 * bu yardımcı yalnızca JS'in ürettiği başvurular içindir.
 */
const base = typeof document !== 'undefined' ? document.documentElement.dataset.icons || '' : '';

export const iconHref = (name) => `${base}#i-${name}`;
