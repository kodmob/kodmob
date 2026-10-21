<?php

namespace App\Core\Activity;

use App\Core\Events\EventCatalog;

/**
 * Panel işlemlerinin okunur adları (İşlem Geçmişi). Olay türü rotanın adıdır
 * ya da controller'ın `Activity::type()` ile verdiğidir. Burada olmayan tür
 * teknik adıyla görünür; kayıt yine tutulur.
 *
 * Modüllerin kendi olayları (talep geldi, iş bitti…) modül sınıfındadır.
 */
final class ActivityCatalog
{
    public static function register(): void
    {
        foreach (self::types() as $type => $def) {
            EventCatalog::define($type, $def[0], $def[1], $def[2] ?? null);
        }
    }

    /**
     * Tür → [başlık, ton, metin]. Sözleşme (Core\Contracts) rota adlarının başlığını buradan okur.
     *
     * @return array<string,array{0:string,1:string,2?:callable}>
     */
    public static function types(): array
    {
        $fields = static fn (array $p) => ! empty($p['fields']) ? 'Alanlar: '.implode(', ', (array) $p['fields']) : '';
        $changes = static fn (array $p) => implode(' · ', array_map(
            static fn ($c) => trim(($c['label'] ?? '').': '.self::show($c['from'] ?? '').' → '.self::show($c['to'] ?? '')),
            array_values((array) ($p['changes'] ?? []))
        ));
        $status = static fn (array $p) => isset($p['status']) ? 'Durum: '.(['publish' => 'yayında', 'draft' => 'taslak', 'future' => 'zamanlandı'][$p['status']] ?? $p['status']) : '';

        $types = [
            // İçerik
            'project.created' => ['Proje eklendi', 'success', $status],
            'project.updated' => ['Proje güncellendi', 'primary', $status],
            'project.deleted' => ['Proje silindi', 'danger'],
            'service.created' => ['Hizmet eklendi', 'success', $status],
            'service.updated' => ['Hizmet güncellendi', 'primary', $status],
            'service.deleted' => ['Hizmet silindi', 'danger'],
            'reference.created' => ['Referans eklendi', 'success', $status],
            'reference.updated' => ['Referans güncellendi', 'primary', $status],
            'reference.deleted' => ['Referans silindi', 'danger'],
            'post.created' => ['Yazı eklendi', 'success', $status],
            'post.updated' => ['Yazı güncellendi', 'primary', $status],
            'post.deleted' => ['Yazı silindi', 'danger'],
            'page.created' => ['Blok sayfası eklendi', 'success', $status],
            'page.updated' => ['Blok sayfası güncellendi', 'primary', $status],
            'page.deleted' => ['Blok sayfası silindi', 'danger'],
            'content.reordered' => ['Sıralama değişti', 'neutral', static fn ($p) => ($p['label'] ?? '').' · '.(int) ($p['count'] ?? 0).' kayıt'],
            'media.uploaded' => ['Görsel yüklendi', 'success'],
            'media.imported' => ['Görsel adresten eklendi', 'success', static fn ($p) => (string) ($p['url'] ?? '')],
            'media.alts_updated' => ['Görsel alt metni değişti', 'primary'],
            'records.restored' => ['Silinen kayıt geri yüklendi', 'success', static fn ($p) => (string) ($p['type_label'] ?? '')],

            // Site
            'texts.updated' => ['Sayfa metinleri değişti', 'primary', static fn ($p) => (int) ($p['count'] ?? 0).' metin'],
            'legal.customized' => ['Yasal belge özelleştirildi', 'primary', static fn ($p) => trim(($p['title'] ?? '').' · '.strtoupper((string) ($p['locale'] ?? '')), ' ·')],
            'legal.reset' => ['Yasal belge şablona döndü', 'warning', static fn ($p) => trim(($p['title'] ?? '').' · '.strtoupper((string) ($p['locale'] ?? '')), ' ·')],
            'layouts.published' => ['Sayfa düzeni yayınlandı', 'success', static fn ($p) => implode(', ', [...(array) ($p['zones'] ?? []), ...array_map(static fn ($t) => 'yayına alınan sayfa: '.$t, (array) ($p['pages'] ?? []))])],
            'layouts.discarded' => ['Sayfa düzeni taslağı atıldı', 'warning', static fn ($p) => (string) ($p['label'] ?? '')],
            'layouts.restored' => ['Sayfa düzeninin eski hali taslağa alındı', 'primary', static fn ($p) => (string) ($p['label'] ?? '')],
            'blocks.library' => ['Blok kitaplığı değişti', 'neutral', static fn ($p) => ($p['block'] ?? '').' · '.(! empty($p['added']) ? 'eklendi' : 'çıkarıldı')],
            'estimate.updated' => ['Teklif fiyatları değişti', 'primary'],
            'settings.updated' => ['Ayarlar değiştirildi', 'neutral', $changes],
            'settings.defaults' => ['Ayarlar varsayılana döndü', 'warning', static fn ($p) => ($p['keys'] ?? '') === 'all' ? 'Bütün ayarlar' : count((array) ($p['keys'] ?? [])).' ayar'],

            // SEO
            'seo.meta_updated' => ['SEO bilgileri değişti', 'primary', $changes],
            'seo.page_updated' => ['Sayfa SEO\'su değişti', 'primary', $fields],
            'seo.redirect_created' => ['Yönlendirme eklendi', 'success', static fn ($p) => trim(($p['source'] ?? '').' → '.($p['target'] ?: ($p['code'] ?? '')).' ('.($p['code'] ?? '').')')],
            'seo.redirect_updated' => ['Yönlendirme değişti', 'primary', static fn ($p) => trim(($p['source'] ?? '').' → '.($p['target'] ?: ($p['code'] ?? '')).' ('.($p['code'] ?? '').')')],
            'seo.redirect_deleted' => ['Yönlendirme silindi', 'danger', static fn ($p) => (string) ($p['source'] ?? '')],
            'seo.redirects_imported' => ['Yönlendirmeler içe aktarıldı', 'primary', static fn ($p) => sprintf('%d yeni · %d güncellendi · %d hata', $p['added'] ?? 0, $p['updated'] ?? 0, $p['errors'] ?? 0)],
            'seo.404_status' => ['404 kayıtları işaretlendi', 'neutral', static fn ($p) => (int) ($p['count'] ?? 0).' adres · '.(['ignored' => 'yoksayıldı', 'new' => 'yeni', 'redirected' => 'yönlendirildi'][$p['status'] ?? ''] ?? '')],
            'seo.404_deleted' => ['404 kayıtları silindi', 'warning', static fn ($p) => (int) ($p['count'] ?? 0).' adres'],
            'seo.links_fixed' => ['İçerikteki bağlantılar güncellendi', 'success', static fn ($p) => (int) ($p['count'] ?? 0).' kayıt'],
            'seo.robots_updated' => ['robots.txt kuralları değişti', 'warning'],
            'languages.created' => ['Dil eklendi', 'success'],
            'languages.visitor_updated' => ['404 bağlantıları değişti', 'primary'],
            'post.translation_created' => ['Çeviri oluşturuldu', 'success', static fn ($p) => strtoupper((string) ($p['locale'] ?? '')).(isset($p['mode']) ? ' · '.(['copy' => 'kopya', 'ai' => 'Jarvis çevirisi', 'empty' => 'boş'][$p['mode']] ?? $p['mode']) : '')],
            'post.translation_linked' => ['Çeviri bağlandı', 'primary'],
            'post.translation_unlinked' => ['Çeviri grubundan ayrıldı', 'warning'],
            'languages.updated' => ['Dil değişti', 'primary', $fields],
            'languages.deleted' => ['Dil silindi', 'danger'],
            'languages.reordered' => ['Dil sırası değişti', 'neutral'],

            // Talepler
            'briefs.status_changed' => ['Talep durumu değişti', 'neutral', static fn ($p) => ['read' => 'Okundu', 'archived' => 'Arşivlendi', 'reopen' => 'Arşivden alındı'][$p['status'] ?? ''] ?? ''],

            // Jarvis
            'jarvis.scan_started' => ['Domain taraması başladı', 'primary', static fn ($p) => (string) ($p['title'] ?? '')],
            'jarvis.job_controlled' => ['Jarvis işi', 'neutral', static fn ($p) => ['pause' => 'Duraklatıldı', 'resume' => 'Sürdürüldü', 'cancel' => 'İptal edildi', 'retry' => 'Belirsizler yeniden deneniyor', 'verify' => 'Boşlar yeniden doğrulanıyor'][$p['action'] ?? ''] ?? ''],
            'jarvis.job_deleted' => ['Jarvis işi silindi', 'danger'],
            'jarvis.domains_checked' => ['Domain kontrol edildi', 'neutral', static fn ($p) => implode(', ', array_slice((array) ($p['names'] ?? []), 0, 8))],
            'jarvis.ai_updated' => ['Yapay zekâ ayarları değişti', 'warning', $fields],
            'jarvis.conversation_created' => ['Jarvis sohbeti açıldı', 'neutral'],
            'jarvis.conversation_renamed' => ['Jarvis sohbetinin adı değişti', 'neutral'],
            'jarvis.conversation_deleted' => ['Jarvis sohbeti silindi', 'danger'],
            'jarvis.message_sent' => ['Jarvis\'e mesaj yazıldı', 'neutral'],
            'jarvis.skill_run' => ['Jarvis becerisi çalıştırıldı', 'neutral', static fn ($p) => (string) ($p['skill'] ?? '')],

            // Kullanıcılar ve roller
            'users.created' => ['Kullanıcı eklendi', 'success', static fn ($p) => (string) ($p['role'] ?? '')],
            'users.updated' => ['Kullanıcı güncellendi', 'primary', $changes],
            'users.deactivated' => ['Kullanıcı pasife alındı', 'warning'],
            'users.activated' => ['Kullanıcı aktifleştirildi', 'success'],
            'users.sessions_ended' => ['Kullanıcının oturumları kapatıldı', 'warning', static fn ($p) => (int) ($p['count'] ?? 0).' oturum'],
            'users.sms_sent' => ['Kullanıcılara SMS gönderildi', 'neutral', static fn ($p) => trim((int) ($p['count'] ?? 0).' kişi · '.(int) ($p['parts'] ?? 1).' boy · '.self::names($p), ' ·')],
            'users.mail_sent' => ['Kullanıcılara e-posta gönderildi', 'neutral', static fn ($p) => trim((int) ($p['count'] ?? 0).' kişi · '.($p['subject'] ?? '').' · '.self::names($p), ' ·')],
            'roles.created' => ['Rol eklendi', 'success'],
            'roles.updated' => ['Rol izinleri değişti', 'warning', static fn ($p) => trim((! empty($p['added']) ? '+ '.implode(', ', (array) $p['added']) : '').' '.(! empty($p['removed']) ? '− '.implode(', ', (array) $p['removed']) : ''))],
            'roles.renamed' => ['Rolün adı değişti', 'neutral'],
            'roles.menu_updated' => ['Rolün yan menüsü değişti', 'neutral'],
            'roles.deleted' => ['Rol silindi', 'danger'],

            // Güvenlik ve oturum
            'auth.login' => ['Panele giriş yaptı', 'neutral', static fn ($p) => (string) ($p['device'] ?? '')],
            'auth.handoff' => ['Ana projeden geçişle giriş yaptı', 'neutral', static fn ($p) => trim('Ana projede: '.($p['from'] ?? '').' · '.($p['device'] ?? ''), ' ·')],
            'auth.logout' => ['Panelden çıkış yaptı', 'neutral'],
            'profile.password_changed' => ['Şifresini değiştirdi', 'warning'],
            'profile.two_factor_enabled' => ['İki adımlı doğrulamayı açtı', 'success'],
            'profile.two_factor_disabled' => ['İki adımlı doğrulamayı kapattı', 'warning'],
            'profile.two_factor_recovery' => ['Yeni yedek kodlar aldı', 'neutral'],
            'users.two_factor_reset' => ['Kullanıcının iki adımlı doğrulaması sıfırlandı', 'warning', static fn ($p) => (int) ($p['sessions'] ?? 0).' oturum kapatıldı'],
            'profile.updated' => ['Profilini güncelledi', 'primary', $changes],
            'sessions.ended' => ['Oturum kapatıldı', 'warning', static fn ($p) => (string) ($p['device'] ?? '')],
            'sessions.ended_others' => ['Diğer oturumlar kapatıldı', 'warning', static fn ($p) => (int) ($p['count'] ?? 0).' oturum'],
            'sessions.ended_all' => ['Bütün oturumlar kapatıldı', 'danger', static fn ($p) => (int) ($p['count'] ?? 0).' oturum'],
            'security.key_rotated' => ['İmza anahtarı yenilendi (herkesin oturumu kapandı)', 'danger'],
            'security.ip_blocked' => ['IP engellendi', 'warning', static fn ($p) => trim(($p['ip'] ?? '').' '.($p['reason'] ?? ''))],
            'security.ip_unblocked' => ['IP engeli kaldırıldı', 'neutral', static fn ($p) => (string) ($p['ip'] ?? '')],
            'security.login_unlocked' => ['Giriş kilidi açıldı', 'neutral', static fn ($p) => trim(($p['username'] ?? '').' · '.($p['ip'] ?? ''), ' ·')],

            // Sistem
            'mail.updated' => ['E-posta ayarları değişti', 'neutral'],
            'mail.tested' => ['Deneme e-postası gönderildi', 'neutral'],
            'health.reported' => ['Sistem sağlığı raporu gönderildi', 'neutral'],
            'health.errors_cleared' => ['Hata kodları günlüğü temizlendi', 'warning'],
            'sms.updated' => ['SMS ayarları değişti', 'neutral'],
            'ai.updated' => ['Yapay zekâ ayarları değişti', 'neutral'],
            'sms.tested' => ['Deneme SMS\'i gönderildi', 'neutral'],
            'jobs.retried' => ['Başarısız iş yeniden denendi', 'neutral'],
            'jobs.forgotten' => ['Başarısız iş silindi', 'warning'],
            'backup.exported' => ['Yedek indirildi', 'neutral'],
            'backup.auto_updated' => ['Otomatik yedek ayarları değişti', 'neutral'],
            'backup.auto_run' => ['Otomatik yedek başlatıldı', 'neutral'],
            'tools.toggled' => ['Araç açıldı ya da kapatıldı', 'neutral', static fn ($p) => ($p['enabled'] ?? false) ? 'Açıldı' : 'Kapatıldı'],
            'tools.installed' => ['Araç eklendi ya da çıkarıldı', 'primary', static fn ($p) => (($p['installed'] ?? false) ? 'Eklendi' : 'Çıkarıldı').(! empty($p['with']) ? ' · birlikte: '.implode(', ', (array) $p['with']) : '')],
            'sites.handoff' => ['Başka projeye geçti', 'neutral', static fn ($p) => (string) ($p['key'] ?? '')],
            'sites.created' => ['Alt site oluşturuldu', 'success', static fn ($p) => trim(($p['name'] ?? '').' · '.implode(', ', (array) ($p['domains'] ?? [])), ' ·')],
            'sites.updated' => ['Alt site güncellendi', 'primary', static fn ($p) => (string) ($p['name'] ?? '')],
            'sites.migrated' => ['Alt sitelerin tabloları güncellendi', 'neutral', static fn ($p) => implode(', ', (array) ($p['keys'] ?? []))],
            'sites.removed' => ['Alt site kayıttan çıkarıldı', 'warning', static fn ($p) => (string) ($p['name'] ?? '')],
            'backup.restored' => ['Yedekten geri yüklendi', 'danger', static fn ($p) => implode(' · ', array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys((array) ($p['counts'] ?? [])), (array) ($p['counts'] ?? [])))],
        ];

        return $types;
    }

    /** Alıcı adları: "Ayşe, Mehmet ve 3 kişi daha". */
    private static function names(array $p): string
    {
        $names = implode(', ', array_map('strval', (array) ($p['names'] ?? [])));
        $more = (int) ($p['more'] ?? 0);

        return $more > 0 ? $names.' ve '.$more.' kişi daha' : $names;
    }

    private static function show(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'açık' : 'kapalı';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
