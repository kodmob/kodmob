/**
 * Panelin içerik türleri: tek kaynak. Menü (meta.js PAGES), adresler (routes.js TYPE_SLUGS:
 * /panel/projeler), yedek özeti ve düzenleyici buradan okur. Anahtar API'deki addır
 * (kodmob-api Types: /admin/content/projects); alanlar ve liste sunucudan (şema) gelir.
 *
 * Projenin türleri profilin panel.js'inde (kodmob:module oraya yazar), blog çatıda (extension.js);
 * birleştiren project.js. Tanım alanları:
 *
 * - `slug`: paneldeki adres parçası · `record`: kayıt adı (işlem kaydı, canlı şerit)
 * - `can`: düzenleme yetkisi · `orderable`: listede sürükle-bırak sıralama
 * - `featured`: listede "Öne çıkan" sütunu · `site: { route, anchor? }`: sayfası olmayan türün sitedeki yeri
 * - `guide`: Kullanım Kılavuzu turundaki tanıtım (yoksa genel metin)
 */
import { TYPES } from './project.js';
import { typeSlugs } from './routes.js';

export { TYPES };

/** İçerik türü (API adı) → paneldeki adres parçası. */
export const TYPE_SLUGS = typeSlugs(TYPES);
