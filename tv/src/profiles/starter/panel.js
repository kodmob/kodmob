/**
 * Projenin panel uzantısı. Çatının panel dosyalarına dokunmadan eklenir (tv/src/panel/project.js okur;
 * biçim tv/src/panel/extension.js).
 *
 * `TYPES`: içerik türleri; anahtar API'deki addır (kodmob-api config/project.php → /admin/content/products).
 * - `slug`: paneldeki adres parçası (/panel/urunler) · `record`: kayıt adı (işlem kaydı, canlı şerit)
 * - `can`: düzenleme yetkisi · `orderable`: listede sürükle-bırak sıralama
 * - `featured`: listede "Öne çıkan" sütunu · `guide`: Kullanım Kılavuzu turundaki tanıtım
 * - `site: { route, anchor? }`: kendi sayfası olmayan türün sitedeki yeri ("Sitede gör")
 *
 * Yeni tür: `php artisan kodmob:module courses --label=Kurslar --singular=Kurs` satırı aşağıdaki
 * işaretin üstüne ekler.
 */
export const TYPES = {
    // kodmob:module
};

/**
 * `PAGES`: projenin özel ekranları. Menüye (bölümünün sekmesi; varsayılan İçerik), Tüm İşlemler'e ("Siteye özel" kartı),
 * panel aramasına, tura ve panel adreslerine kendiliğinden girer; bileşenleri ilk açılışta iner.
 *
 *     {
 *         key: 'reports', path: 'raporlar/:tab?', label: 'Raporlar', icon: 'chart', group: 'project',
 *         can: 'km_view_reports',                       // projenin modülü verir: Module::capabilities()
 *         guide: 'Turdaki kısa tanıtım.',
 *         load: () => import('./panel/reports.js'),     // export function kmReports() { return { … } }
 *         template: 'panel/_reports.njk',               // bu klasörde: <div x-data="kmReports">…</div>
 *     }
 */
export const PAGES = [];
