/**
 * Sayfa düzenleyicide sürükle-bırak: işaretçi olaylarıyla (fare, dokunmatik, kalem aynı). Kütüphanedeki kart ya
 * da katmanlardaki blok sürüklenir; hedefi düzenleyici bulur (`onMove` → hedef ya da null). 5 px kıpırdamadan
 * sürükleme başlamaz (tıklama tıklama kalır). Esc vazgeçer. Sürüklerken gövdede `bd-dragging` sınıfı: önizleme
 * çerçevesinin üstüne olayları yakalayan katman gelir (çerçeve işaretçiyi yutmasın).
 */
import { iconHref } from '../../ui/icon.js';

function ghostOf(label, icon) {
    const ghost = document.createElement('div');
    ghost.className = 'bd-ghost';
    ghost.setAttribute('aria-hidden', 'true');
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon icon-sm');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', iconHref(icon || 'layers'));
    svg.append(use);
    const text = document.createElement('span');
    text.textContent = label;
    ghost.append(svg, text);
    document.body.append(ghost);
    return ghost;
}

/**
 * @param {PointerEvent} event   pointerdown
 * @param {{ label: string, icon?: string, onStart?: Function, onMove: (x: number, y: number) => void,
 *           onDrop: (x: number, y: number) => void, onCancel?: Function, onClick?: Function }} options
 */
export function startDrag(event, { label, icon, onStart, onMove, onDrop, onCancel, onClick }) {
    if (event.button !== undefined && event.button !== 0) return;
    const startX = event.clientX;
    const startY = event.clientY;
    let active = false;
    let ghost = null;

    const cleanup = () => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', cancel);
        window.removeEventListener('keydown', key, true);
        ghost?.remove();
        document.body.classList.remove('bd-dragging');
    };
    const move = (e) => {
        if (!active) {
            if (Math.hypot(e.clientX - startX, e.clientY - startY) < 5) return;
            active = true;
            ghost = ghostOf(label, icon);
            document.body.classList.add('bd-dragging');
            onStart?.();
        }
        e.preventDefault();
        ghost.style.transform = `translate3d(${e.clientX + 14}px, ${e.clientY + 10}px, 0)`;
        onMove(e.clientX, e.clientY);
    };
    const up = (e) => {
        cleanup();
        if (active) onDrop(e.clientX, e.clientY);
        else onClick?.();
    };
    const cancel = () => {
        cleanup();
        if (active) onCancel?.();
    };
    const key = (e) => {
        if (e.key !== 'Escape') return;
        e.preventDefault();
        e.stopPropagation();
        cancel();
    };
    window.addEventListener('pointermove', move, { passive: false });
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', cancel);
    window.addEventListener('keydown', key, true);
}
