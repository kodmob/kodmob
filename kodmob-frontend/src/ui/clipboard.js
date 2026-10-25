/**
 * Panoya kopyala. Güvenli bağlamda (HTTPS / localhost) Clipboard API; düz
 * HTTP adresinde gizli metin alanı + `execCommand('copy')`.
 * @returns {Promise<boolean>} kopyalandıysa true
 */
export async function copyText(text) {
    const value = String(text ?? '');
    if (!value) return false;
    try {
        if (window.isSecureContext && navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
            return true;
        }
    } catch {
        // Tarayıcı izin vermediyse eski yönteme düş.
    }
    const area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.className = 'fixed -left-[9999px] top-0 opacity-0';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try {
        ok = document.execCommand('copy');
    } catch {
        ok = false;
    }
    area.remove();
    return ok;
}
