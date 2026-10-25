<?php

namespace App\Modules\Newsletter\Http;

use App\Core\Activity\Activity;
use App\Core\Content\Locales;
use App\Core\Exceptions\DomainException;
use App\Core\Http\Controller;
use App\Core\Jobs\Background;
use App\Core\Mail\MailLog;
use App\Modules\Newsletter\Campaigns;
use App\Modules\Newsletter\SendQuota;
use App\Modules\Newsletter\Subscribers;
use Illuminate\Http\Request;
use Throwable;

/**
 * Panel › Bülten (`module:newsletter`, `cap:km_manage_newsletter`).
 *
 *   GET    /admin/newsletter/subscribers           liste + durum sayıları
 *   POST   /admin/newsletter/subscribers           { email, name?, locale } ekle (onay e-postası gider)
 *   DELETE /admin/newsletter/subscribers/{id}      kalıcı sil (KVKK silme isteği)
 *   GET    /admin/newsletter/campaigns             bültenler + dil başına onaylı abone, saatlik sınır, cron
 *   POST   /admin/newsletter/campaigns             taslak; PUT …/{id} güncelle (yalnız taslak); DELETE …/{id}
 *   POST   /admin/newsletter/campaigns/{id}/test   kendi adresime deneme
 *   POST   /admin/newsletter/campaigns/{id}/send   gönderimi başlat / sürdür;  …/pause  duraklat
 *   POST   /admin/newsletter/campaigns/{id}/continue  bir parça gönder (cron yokken sayfa açıkken; işlem geçmişine yazılmaz)
 */
final class NewsletterAdminController extends Controller
{
    public function subscribers(): array
    {
        return (new Subscribers)->list();
    }

    public function addSubscriber(Request $request): array
    {
        $subscriber = (new Subscribers)->add($this->input($request));
        Activity::subject('newsletter_subscriber', $subscriber['id'], $subscriber['email']);
        Activity::with(['email' => $subscriber['email']]);

        return $subscriber;
    }

    public function deleteSubscriber(int $id): array
    {
        $email = (new Subscribers)->delete($id);
        Activity::subject('newsletter_subscriber', $id, $email);
        Activity::with(['email' => $email]);

        return ['deleted' => true];
    }

    public function campaigns(): array
    {
        $campaigns = new Campaigns;
        $audience = ['' => $campaigns->audience(null)];
        foreach (Locales::active() as $code) {
            $audience[$code] = $campaigns->audience($code);
        }

        return [
            'items' => $campaigns->all(),
            'audience' => $audience,
            'limit' => SendQuota::limit(),
            'available' => SendQuota::available(),
            'cron' => Background::cronAlive(),
        ];
    }

    public function saveCampaign(Request $request, ?int $id = null): array
    {
        $campaign = (new Campaigns)->save($id, $this->input($request), $request->user()?->id);
        $this->log($campaign);

        return $campaign;
    }

    public function deleteCampaign(int $id): array
    {
        $subject = (new Campaigns)->delete($id);
        Activity::subject('newsletter_campaign', $id, $subject);
        Activity::with(['subject' => $subject]);

        return ['deleted' => true];
    }

    public function test(Request $request, int $id): array
    {
        $campaigns = new Campaigns;
        $campaign = $campaigns->find($id);
        $to = (string) $request->user()->email;
        try {
            $campaigns->test($id, $to);
        } catch (Throwable $e) {
            MailLog::record($to, $campaign['subject'], 'newsletter.test', false, $e->getMessage());
            throw new DomainException('km_mail_failed', 'Gönderilemedi: '.mb_substr($e->getMessage(), 0, 300), 422);
        }
        $this->log($campaign, ['email' => $to]);

        return ['sent' => true, 'to' => $to];
    }

    public function send(int $id): array
    {
        $campaign = (new Campaigns)->start($id);
        $this->log($campaign, ['total' => $campaign['total']]);

        return $campaign;
    }

    public function pause(int $id): array
    {
        $campaign = (new Campaigns)->pause($id);
        $this->log($campaign);

        return $campaign;
    }

    public function continue(int $id): array
    {
        $campaigns = new Campaigns;
        $campaigns->tick(10, 20, $id);

        return $campaigns->find($id) + ['available' => SendQuota::available()];
    }

    private function log(array $campaign, array $extra = []): void
    {
        Activity::subject('newsletter_campaign', $campaign['id'], $campaign['subject']);
        Activity::with(['subject' => $campaign['subject']] + $extra);
    }
}
