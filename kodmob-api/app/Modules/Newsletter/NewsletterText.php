<?php

namespace App\Modules\Newsletter;

use App\Core\Content\Locales;

/**
 * Bültenin ziyaretçiye giden, sunucunun ürettiği metinleri (onay e-postası, bültenin alt bilgisi). Sitedeki form ve
 * sonuç sayfasının metinleri çatının sözlüğünde (tv/src/i18n → newsletter). Türkçe, İngilizce, Almanca; başka dilde
 * İngilizce.
 */
final class NewsletterText
{
    private const TEXT = [
        'tr' => [
            'confirm.subject' => 'Bülten aboneliğinizi onaylayın',
            'confirm.hello' => 'Merhaba {name},',
            'confirm.helloAnon' => 'Merhaba,',
            'confirm.body' => '{site} bültenine abone olmak için aşağıdaki düğmeye tıklayın.',
            'confirm.ignore' => 'Bu isteği siz yapmadıysanız e-postayı yok sayın: adresiniz listeye eklenmez.',
            'confirm.action' => 'Aboneliği onayla',
            'footer.reason' => 'Bu e-postayı {site} bültenine abone olduğunuz için aldınız.',
            'footer.unsubscribe' => 'Abonelikten çık',
        ],
        'en' => [
            'confirm.subject' => 'Please confirm your newsletter subscription',
            'confirm.hello' => 'Hello {name},',
            'confirm.helloAnon' => 'Hello,',
            'confirm.body' => 'To subscribe to the {site} newsletter, click the button below.',
            'confirm.ignore' => 'If you did not ask for this, ignore this e-mail: your address will not be added.',
            'confirm.action' => 'Confirm subscription',
            'footer.reason' => 'You received this e-mail because you subscribed to the {site} newsletter.',
            'footer.unsubscribe' => 'Unsubscribe',
        ],
        'de' => [
            'confirm.subject' => 'Bitte bestätigen Sie Ihr Newsletter-Abonnement',
            'confirm.hello' => 'Hallo {name},',
            'confirm.helloAnon' => 'Hallo,',
            'confirm.body' => 'Um den Newsletter von {site} zu abonnieren, klicken Sie auf die Schaltfläche unten.',
            'confirm.ignore' => 'Wenn Sie das nicht angefordert haben, ignorieren Sie diese E-Mail: Ihre Adresse wird nicht eingetragen.',
            'confirm.action' => 'Abonnement bestätigen',
            'footer.reason' => 'Sie erhalten diese E-Mail, weil Sie den Newsletter von {site} abonniert haben.',
            'footer.unsubscribe' => 'Abmelden',
        ],
    ];

    /** @param array<string,string> $vars */
    public static function get(string $key, ?string $locale = null, array $vars = []): string
    {
        $locale ??= Locales::default();
        $text = self::TEXT[$locale][$key] ?? self::TEXT['en'][$key] ?? $key;

        return strtr($text, array_combine(array_map(static fn ($k) => '{'.$k.'}', array_keys($vars)), array_values($vars)) ?: []);
    }
}
