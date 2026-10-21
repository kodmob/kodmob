/**
 * km-filter — istemci tarafı kategori süzgeci (proje listesi, blog).
 *
 *     <div x-data="kmFilter()">
 *       <div class="pills" role="group" aria-label="Kategori">
 *         <button class="pill" @click="set('all')" :aria-pressed="is('all')">Tümü</button>
 *         <button class="pill" @click="set('web')" :aria-pressed="is('web')">Web</button>
 *       </div>
 *       <article x-show="shows('web mobil')">…</article>
 *     </div>
 *
 * Bütün öğeler HTML'de durur; süzgeç yalnızca görünürlüğü değiştirir.
 */
export function kmFilter({ initial = 'all' } = {}) {
    return {
        active: initial,

        set(key) {
            this.active = key;
        },

        is(key) {
            return this.active === key;
        },

        /** `cats`: boşlukla ayrılmış kategori anahtarları. */
        shows(cats) {
            return this.active === 'all' || String(cats).split(/\s+/).includes(this.active);
        },
    };
}
