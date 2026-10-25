<?php

namespace App\Modules\Commerce\Paytr;

use App\Core\Module;
use App\Core\Settings\SettingDefinition;
use App\Modules\Commerce\Payments\Payments;

/**
 * PayTR ile kartla ödeme (isteğe bağlı araç `paytr`, Mağaza'ya takılı: `requires: ['commerce']`). Müşteri
 * PayTR'nin güvenli ödeme sayfasına gider; kart bilgisi Kodmob'a hiç gelmez. Sonuç hash denetimli bildirimle
 * gelir: `POST /api/v1/shop/payments/paytr` (PayTR mağaza paneli > Ayarlar > Bildirim URL'si).
 *
 * Tümleştirme PayTR'nin herkese açık iFrame API belgelerine göre yazıldı (örnek kod kopyalanmadı). "PayTR",
 * sahibinin ticari markasıdır; Kodmob PayTR ile bağlantılı değildir.
 */
final class PaytrModule extends Module
{
    public const MERCHANT_ID = 'paytr.merchant_id';

    public const MERCHANT_KEY = 'paytr.merchant_key';

    public const MERCHANT_SALT = 'paytr.merchant_salt';

    public const TEST_MODE = 'paytr.test_mode';

    public const INSTALLMENTS = 'paytr.installments';

    public function settings(): array
    {
        $g = 'Mağaza: PayTR';

        return [
            new SettingDefinition(self::MERCHANT_ID, 'string', '', 'Mağaza no', $g, 'PayTR mağaza paneli > Bilgi: Mağaza No (merchant_id).', scope: 'project'),
            new SettingDefinition(self::MERCHANT_KEY, 'string', '', 'Mağaza parolası', $g, 'PayTR mağaza paneli > Bilgi: Mağaza Parola (merchant_key).', format: 'secret', scope: 'project'),
            new SettingDefinition(self::MERCHANT_SALT, 'string', '', 'Mağaza gizli anahtarı', $g, 'PayTR mağaza paneli > Bilgi: Mağaza Gizli Anahtar (merchant_salt). Bildirim URL\'si: https://<siteniz>/api/v1/shop/payments/paytr', format: 'secret', scope: 'project'),
            new SettingDefinition(self::TEST_MODE, 'bool', true, 'Deneme kipi', $g, 'Açıkken kartlardan para çekilmez (PayTR test kartlarıyla denenir). Canlıya geçerken kapatın.', scope: 'project'),
            new SettingDefinition(self::INSTALLMENTS, 'bool', false, 'Taksit', $g, 'Kapalıyken yalnızca tek çekim.', scope: 'project'),
        ];
    }

    public function boot(): void
    {
        Payments::register(new PaytrGateway);
    }
}
