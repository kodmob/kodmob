<?php

namespace App\Core\Sms;

/** NetGSM (çatıyla gelir): abone numarası ya da alt kullanıcı, API şifresi, onaylı gönderici başlığı, İYS filtresi. */
final class NetgsmProvider implements SmsProvider
{
    /** NetGSM `iysfilter`: bilgilendirme İYS'ye takılmaz; ticari ileti İYS onayı olan alıcıya gider. */
    public const IYS_FILTERS = [
        '0' => 'Bilgilendirme (İYS kontrolü yok)',
        '11' => 'Ticari ileti · bireysel alıcı (İYS kontrollü)',
        '12' => 'Ticari ileti · tacir alıcı (İYS kontrollü)',
    ];

    public function key(): string
    {
        return 'netgsm';
    }

    public function label(): string
    {
        return 'NetGSM';
    }

    public function help(): string
    {
        return 'Abone numaranız (ya da API izni verilmiş alt kullanıcı), API şifresi ve NetGSM\'de onaylı gönderici başlığı. NetGSM panelinde IP kısıtlaması açıksa sitenin sunucu IP\'sini izinli listeye ekleyin.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'username', 'label' => 'Kullanıcı adı', 'type' => 'text', 'required' => true, 'max' => 100, 'placeholder' => '850xxxxxxx',
                'message' => 'NetGSM kullanıcı adını (abone numarası ya da alt kullanıcı) yazın.'],
            ['key' => 'password', 'label' => 'API şifresi', 'type' => 'secret', 'required' => true],
            ['key' => 'sender', 'label' => 'Gönderici başlığı', 'type' => 'text', 'required' => true, 'max' => 20, 'placeholder' => 'FIRMAADI', 'sender' => true,
                'message' => 'Onaylı gönderici başlığını yazın.', 'help' => 'NetGSM\'de onaylandığı gibi (büyük-küçük harf ve boşluk dahil). "Bağlantıyı dene" onaylı başlıkları getirir.'],
            ['key' => 'iys_filter', 'label' => 'İleti türü (İYS)', 'type' => 'select', 'options' => self::IYS_FILTERS, 'default' => '0',
                'help' => 'Ekibe giden duyuru ve bildirimler bilgilendirmedir. Ticari ileti yalnızca İYS\'de onayı olan alıcıya gider.'],
            ['key' => 'app_name', 'label' => 'Uygulama adı (isteğe bağlı)', 'type' => 'text', 'max' => 100,
                'help' => 'NetGSM geliştirici hesabındaki uygulama adı (appname); yoksa boş bırakın.'],
        ];
    }

    public function gateway(array $values): SmsGateway
    {
        return new NetgsmGateway($values['username'] ?? '', $values['password'] ?? '', $values['sender'] ?? '', $values['iys_filter'] ?? '0', $values['app_name'] ?? '');
    }
}
