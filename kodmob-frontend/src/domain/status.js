/**
 * Durum sözlüğü: sunucudan gelen durum kodu → ekranda etiket,
 * ton, ikon. Renk tek başına anlam taşımaz: rozet her zaman metin ve ikonla
 * basılır. Tonlar `badge-<ton>` sınıflarına karşılık gelir.
 *
 *     <span class="badge" :class="status('domain', row.status).cls"> …
 */
import { iconHref } from '../ui/icon.js';
const S = (label, tone, icon) => ({ label, tone, icon });

export const STATUS = {
    // Jarvis domain kontrolü (CheckResult)
    domain: {
        available: S('Boş', 'success', 'check-circle'),
        expiring: S('Düşüyor', 'warning', 'clock'),
        taken: S('Kayıtlı', 'neutral', 'lock'),
        unknown: S('Belirsiz', 'info', 'help'),
        invalid: S('Geçersiz', 'danger', 'ban'),
    },
    // Jarvis işleri
    job: {
        queued: S('Sırada', 'info', 'clock'),
        running: S('Çalışıyor', 'primary', 'loader'),
        paused: S('Duraklatıldı', 'warning', 'clock'),
        done: S('Bitti', 'success', 'check-circle'),
        failed: S('Hata', 'danger', 'alert-circle'),
        cancelled: S('Durduruldu', 'neutral', 'ban'),
    },
    // İçerik (proje, hizmet, referans, blog)
    content: {
        publish: S('Yayında', 'success', 'check-circle'),
        future: S('Zamanlandı', 'primary', 'clock'),
        draft: S('Taslak', 'neutral', 'pencil'),
    },
    // Panel kullanıcıları
    user: {
        active: S('Aktif', 'success', 'check-circle'),
        passive: S('Pasif', 'neutral', 'ban'),
    },
    // Güvenlik kayıtları (kodmob-core Security\SecurityLog)
    security: {
        login_failed: S('Hatalı giriş', 'warning', 'alert-circle'),
        login_locked: S('Giriş kilitlendi', 'danger', 'lock'),
        ip_login_limited: S('IP girişi sınırlandı', 'danger', 'ban'),
        login_disabled: S('Pasif hesapla deneme', 'warning', 'user'),
        waf_blocked: S('Saldırı kalıbı', 'danger', 'shield-check'),
        ip_denied: S('Engelli IP isteği', 'neutral', 'ban'),
        ip_auto_blocked: S('IP otomatik engellendi', 'danger', 'ban'),
        token_reused: S('Kullanılmış jeton', 'warning', 'alert-circle'),
        key_rotated: S('Anahtar yenilendi', 'warning', 'refresh'),
    },
    // IP engeli kaynağı
    block: {
        manual: S('Elle', 'primary', 'user'),
        auto: S('Otomatik', 'danger', 'zap'),
    },
    // SEO
    redirect: {
        301: S('301 Kalıcı', 'primary', 'arrow-right'),
        302: S('302 Geçici', 'info', 'arrow-right'),
        307: S('307 Geçici', 'info', 'arrow-right'),
        410: S('410 Kaldırıldı', 'danger', 'ban'),
    },
    notFound: {
        new: S('Yeni', 'warning', 'alert-circle'),
        redirected: S('Yönlendirildi', 'success', 'check-circle'),
        ignored: S('Yoksayıldı', 'neutral', 'eye-off'),
    },
    seoGrade: {
        good: S('İyi', 'success', 'check-circle'),
        ok: S('Orta', 'warning', 'alert-circle'),
        bad: S('Zayıf', 'danger', 'alert'),
    },
    // Mağaza siparişleri (kodmob-core Modules\Commerce\Orders::FLOW)
    order: {
        pending: S('Ödeme bekliyor', 'warning', 'clock'),
        paid: S('Ödendi', 'success', 'check-circle'),
        processing: S('Hazırlanıyor', 'info', 'package'),
        shipped: S('Kargoda', 'primary', 'truck'),
        completed: S('Tamamlandı', 'success', 'check'),
        cancelled: S('İptal', 'neutral', 'ban'),
        refunded: S('İade', 'danger', 'rotate-ccw'),
    },
    // Bülten aboneleri ve bültenler (kodmob-core Modules\Newsletter)
    subscriber: {
        pending: S('Onay bekliyor', 'warning', 'clock'),
        active: S('Abone', 'success', 'check-circle'),
        unsubscribed: S('Ayrıldı', 'neutral', 'ban'),
    },
    campaign: {
        draft: S('Taslak', 'neutral', 'pencil'),
        sending: S('Gönderiliyor', 'primary', 'send'),
        paused: S('Duraklatıldı', 'warning', 'alert-circle'),
        sent: S('Gönderildi', 'success', 'check'),
    },
    // Proje talepleri
    brief: {
        new: S('Yeni', 'primary', 'mail'),
        read: S('Okundu', 'neutral', 'check'),
        replied: S('Yanıtlandı', 'success', 'send'),
        archived: S('Arşiv', 'neutral', 'inbox'),
    },
};

const UNKNOWN = S('Bilinmiyor', 'neutral', 'help');

/** @returns {{label:string, tone:string, icon:string, cls:string, href:string}} */
export function status(kind, value) {
    const def = STATUS[kind]?.[value] || (value ? { ...UNKNOWN, label: String(value) } : UNKNOWN);
    return { ...def, cls: `badge-${def.tone}`, href: iconHref(def.icon) };
}

/** Sekme ve filtre seçenekleri: [{ value, label }] */
export function statusOptions(kind) {
    return Object.entries(STATUS[kind] || {}).map(([value, def]) => ({ value, label: def.label }));
}
