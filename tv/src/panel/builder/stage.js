/**
 * Sayfa düzenleyicinin sahnesi: sitenin gerçek sayfası çerçevede (aynı köken, `?km-preview=` anahtarıyla taslak
 * bloklar; kodmob-api render.php). Panel çerçevenin belgesine doğrudan erişir:
 *
 * - Bölgeler `<div data-km-zone>`, bloklar `<div data-km-block data-km-id>`; düzenlenebilir metinler `data-km-field`.
 * - Düzenleme kipinde tıklama bağlantıyı açmaz, bloğu seçer; çift tık metni yerinde düzenler.
 * - Çerçeveler ve araç çubuğu panelin katmanında çizilir (sitenin stiline ve CSP'sine dokunulmaz); boş bölgeye
 *   yalnızca CSSOM ile en az yükseklik verilir.
 * - Blok değişince yalnızca o bloğun HTML'i değişir; site betiği `km:blocks` olayıyla bloğu yeniden başlatır.
 */
const ZONE = '[data-km-zone]';
const BLOCK = ':scope > [data-km-block]';

/** Yerinde yazılan metin: satır sonları korunur, bölünmez boşluk düz boşluk. */
function readText(el) {
    const copy = el.cloneNode(true);
    copy.querySelectorAll('br').forEach((br) => br.replaceWith('\n'));
    return copy.textContent.replace(/ /g, ' ');
}

export function createStage(hooks) {
    let frame = null;
    let doc = null;
    let win = null;
    let scale = 1;
    let mode = 'edit';
    let editing = null;
    let observer = null;
    let raf = 0;

    const zoneEl = (key) => doc?.querySelector(`${ZONE}[data-km-zone="${CSS.escape(key)}"]`) || null;
    const blockEl = (zone, id) => zoneEl(zone)?.querySelector(`:scope > [data-km-id="${CSS.escape(id)}"]`) || null;
    const blocksOf = (zone) => [...(zoneEl(zone)?.querySelectorAll(BLOCK) || [])];

    /** Çerçevedeki öğenin, sahne kabının (katmanın) koordinatlarında kutusu. */
    function rect(el, container) {
        if (!el || !frame) return null;
        const r = el.getBoundingClientRect();
        const f = frame.getBoundingClientRect();
        const c = container.getBoundingClientRect();
        return { top: f.top - c.top + r.top * scale, left: f.left - c.left + r.left * scale, width: r.width * scale, height: r.height * scale };
    }

    /** Ekran noktası → çerçeve içi nokta. */
    function inFrame(x, y) {
        const f = frame.getBoundingClientRect();
        return { x: (x - f.left) / scale, y: (y - f.top) / scale, inside: x >= f.left && x <= f.right && y >= f.top && y <= f.bottom };
    }

    const where = (el) => {
        const block = el?.closest?.('[data-km-block]');
        const zone = block?.parentElement?.closest(ZONE);
        return block && zone && block.parentElement === zone ? { zone: zone.dataset.kmZone, id: block.dataset.kmId, el: block } : null;
    };

    function schedule() {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(() => hooks.onLayout?.());
    }

    /** Boş bölgeye bırakılacak yer; gizli blok soluk (yalnızca düzenleme kipinde). */
    function decorate() {
        if (!doc) return;
        for (const zone of doc.querySelectorAll(ZONE)) {
            zone.style.minHeight = mode === 'edit' && !zone.querySelector(BLOCK) ? '48px' : '';
            for (const block of zone.querySelectorAll(BLOCK)) {
                const hidden = block.hasAttribute('data-km-hidden');
                block.style.opacity = hidden && mode === 'edit' ? '0.35' : '';
                block.style.display = hidden && mode !== 'edit' ? 'none' : '';
            }
        }
        schedule();
    }

    // ------------------------------------------------------------ olaylar
    function onClick(event) {
        if (editing && editing.el.contains(event.target)) return;
        const link = event.target.closest?.('a[href]');
        if (mode === 'interact') {
            // Gezinme: sitenin kendi sayfası düzenleyicide açılır (anahtar korunur).
            if (link && link.origin === win.location.origin && !link.target && !link.hasAttribute('download') && !/^\/(api|panel|media|assets)\//.test(link.pathname)) {
                event.preventDefault();
                hooks.onNavigate?.(link.pathname, link.hash);
            }
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const hit = where(event.target);
        hooks.onSelect?.(hit ? { zone: hit.zone, id: hit.id } : null);
    }

    function onDblClick(event) {
        if (mode !== 'edit') return;
        const field = event.target.closest?.('[data-km-field]');
        const hit = field && where(field);
        if (!hit) return;
        event.preventDefault();
        hooks.onInlineStart?.({ zone: hit.zone, id: hit.id, path: field.dataset.kmField, el: field });
    }

    function onMove(event) {
        if (mode !== 'edit' || editing) return;
        const hit = where(event.target);
        hooks.onHover?.(hit ? { zone: hit.zone, id: hit.id } : null);
    }

    function onKey(event) {
        if (editing) return;
        const tag = event.target?.tagName;
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || event.target?.isContentEditable) return;
        hooks.onKey?.(event);
    }

    function attach() {
        win = frame.contentWindow;
        doc = frame.contentDocument;
        if (!doc) return;
        doc.addEventListener('click', onClick, true);
        doc.addEventListener('submit', (e) => mode === 'edit' && e.preventDefault(), true);
        doc.addEventListener('dblclick', onDblClick, true);
        doc.addEventListener('mousemove', onMove, { passive: true });
        doc.addEventListener('mouseleave', () => hooks.onHover?.(null));
        doc.addEventListener('keydown', onKey);
        win.addEventListener('scroll', schedule, { passive: true });
        win.addEventListener('resize', schedule, { passive: true });
        observer?.disconnect();
        observer = new win.ResizeObserver(schedule);
        observer.observe(doc.documentElement);
        decorate();
        hooks.onReady?.({ zones: [...doc.querySelectorAll(ZONE)].map((z) => z.dataset.kmZone), path: win.location.pathname });
    }

    /** Değişen bloklar sitenin betiğiyle yeniden başlar (kayan etiketler, harf animasyonu…). */
    function restart(root, removed = []) {
        try {
            doc.dispatchEvent(new win.CustomEvent('km:blocks', { detail: { root, removed } }));
        } catch {
            // Sayfa betiği henüz yüklenmedi: ilk açılışta zaten başlar.
        }
        decorate();
    }

    const fragment = (html) => {
        const template = doc.createElement('template');
        template.innerHTML = html;
        return template.content.firstElementChild;
    };

    return {
        mount(el) {
            frame = el;
            frame.addEventListener('load', () => {
                editing = null;
                attach();
            });
        },
        load(url) {
            editing = null;
            if (frame) frame.src = url;
        },
        get ready() {
            return !!doc?.body;
        },
        get scale() {
            return scale;
        },
        setScale(value) {
            scale = value || 1;
            schedule();
        },
        setMode(value) {
            mode = value;
            hooks.onHover?.(null);
            decorate();
        },
        rect,
        zoneRect: (key, container) => rect(zoneEl(key), container),
        blockRect: (zone, id, container) => rect(blockEl(zone, id), container),
        hasZone: (key) => !!zoneEl(key),
        scrollY: () => win?.scrollY || 0,
        scrollTo(y) {
            win?.scrollTo({ top: y });
        },

        /** Bloğu görünür alana kaydırır (üstte biraz pay). */
        reveal(zone, id) {
            const el = id ? blockEl(zone, id) : zoneEl(zone);
            if (!el) return;
            const r = el.getBoundingClientRect();
            if (r.top < 0 || r.top > win.innerHeight - 80) win.scrollBy({ top: r.top - 96, behavior: 'smooth' });
        },

        // ------------------------------------------------------------ DOM güncellemeleri
        replaceBlock(zone, id, html) {
            const old = blockEl(zone, id);
            const next = html && fragment(html);
            if (!old || !next) return;
            old.replaceWith(next);
            restart(next, [old]);
        },
        insertBlock(zone, index, html) {
            const parent = zoneEl(zone);
            const next = html && fragment(html);
            if (!parent || !next) return;
            parent.insertBefore(next, blocksOf(zone)[index] || null);
            restart(next);
        },
        removeBlock(zone, id) {
            const old = blockEl(zone, id);
            old?.remove();
            if (old) restart(doc.body, [old]);
        },
        moveBlock(fromZone, id, toZone, index) {
            const el = blockEl(fromZone, id);
            const parent = zoneEl(toZone);
            if (!el || !parent) return;
            const siblings = blocksOf(toZone).filter((b) => b !== el);
            parent.insertBefore(el, siblings[index] || null);
            decorate();
        },
        /** Bölgenin bütün blokları (geri al, yapıştır, eski hal): [{ id, html }]. */
        replaceZone(zone, items) {
            const parent = zoneEl(zone);
            if (!parent) return;
            const removed = blocksOf(zone);
            parent.replaceChildren(...items.map((item) => fragment(item.html)).filter(Boolean));
            restart(parent, removed);
        },
        setHidden(zone, id, hidden) {
            const el = blockEl(zone, id);
            if (!el) return;
            el.toggleAttribute('data-km-hidden', hidden);
            decorate();
        },

        /**
         * Bırakma noktası (sürükle-bırak): ekran noktasına en yakın bölge ve sıra; `line` katmandaki çizgi.
         * `allowed`: bırakılabilecek bölgeler (sayfanın bölgeleri).
         */
        dropAt(x, y, container, allowed) {
            if (!doc || !frame) return null;
            const p = inFrame(x, y);
            if (!p.inside) return null;
            let best = null;
            for (const zone of doc.querySelectorAll(ZONE)) {
                const key = zone.dataset.kmZone;
                if (allowed && !allowed.includes(key)) continue;
                const r = zone.getBoundingClientRect();
                const distance = p.y < r.top ? r.top - p.y : p.y > r.bottom ? p.y - r.bottom : 0;
                if (!best || distance < best.distance) best = { key, zone, distance };
            }
            if (!best || best.distance > 160) return null;
            const blocks = blocksOf(best.key);
            let index = blocks.findIndex((b) => {
                const r = b.getBoundingClientRect();
                return p.y < r.top + r.height / 2;
            });
            if (index === -1) index = blocks.length;
            const zr = rect(best.zone, container);
            const ref = blocks[index] ? rect(blocks[index], container) : blocks.length ? rect(blocks[blocks.length - 1], container) : zr;
            const top = blocks[index] ? ref.top : blocks.length ? ref.top + ref.height : zr.top + zr.height / 2;
            return { zone: best.key, index, line: { top, left: zr.left, width: zr.width } };
        },

        /** Sürüklerken kenara yaklaşınca sayfa kayar. */
        autoscroll(y) {
            if (!frame || !win) return;
            const f = frame.getBoundingClientRect();
            const edge = 64;
            if (y < f.top + edge) win.scrollBy(0, -Math.ceil((f.top + edge - y) / 3));
            else if (y > f.bottom - edge) win.scrollBy(0, Math.ceil((y - (f.bottom - edge)) / 3));
        },

        // ------------------------------------------------------------ yerinde düzenleme
        /** `text`: alanın şimdiki değeri (harf animasyonu gibi bölünmüş metin yerine düz metin). */
        beginInline(el, text, { multiline = false, onInput, onDone }) {
            this.endInline(true);
            editing = { el, before: text, onDone };
            el.textContent = text;
            try {
                el.contentEditable = 'plaintext-only';
            } catch {
                el.contentEditable = 'true';
            }
            if (el.contentEditable !== 'plaintext-only') el.contentEditable = 'true';
            el.spellcheck = true;
            el.style.outline = '2px solid #2563EB';
            el.style.outlineOffset = '3px';
            el.style.cursor = 'text';
            el.focus();
            doc.getSelection()?.selectAllChildren(el);
            // innerText değil: CSS'teki büyük harf dönüşümü (text-transform) metne geçmesin.
            editing.input = () => onInput(readText(el));
            editing.key = (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    el.textContent = editing.before;
                    onInput(editing.before);
                    this.endInline(true);
                } else if (event.key === 'Enter' && (!multiline || !event.shiftKey)) {
                    event.preventDefault();
                    this.endInline(true);
                }
            };
            editing.blur = () => this.endInline(true);
            el.addEventListener('input', editing.input);
            el.addEventListener('keydown', editing.key);
            el.addEventListener('blur', editing.blur);
        },
        endInline(commit = true) {
            if (!editing) return;
            const { el, onDone } = editing;
            el.removeEventListener('input', editing.input);
            el.removeEventListener('keydown', editing.key);
            el.removeEventListener('blur', editing.blur);
            el.contentEditable = 'false';
            el.removeAttribute('contenteditable');
            el.style.outline = '';
            el.style.outlineOffset = '';
            el.style.cursor = '';
            editing = null;
            if (commit) onDone?.();
        },
        get editing() {
            return !!editing;
        },
    };
}
