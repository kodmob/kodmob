<?php

namespace App\Core\Mail;

use App\Core\Content\Locales;
use App\Core\Support\SiteManifest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sitenin bütün e-postaları için tek şablon (resources/views/mail/site*.blade.php): site adıyla
 * başlık, paragraflar, isteğe bağlı bilgi tablosu ve düğme; HTML ve düz metin birlikte gider.
 * Metinler çağıranın dilindedir (ziyaretçiye giden: sitenin sözlüğünden; panele giden: Türkçe).
 *
 *   new SiteMail('Talebinizi aldık', ["Merhaba Ayşe,", "…"], locale: 'en')
 *   new SiteMail('Yeni talep', [$mesaj], facts: ['E-posta' => 'a@b.c'], action: ['label' => 'Panelde aç', 'url' => $url])
 */
final class SiteMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $lines  paragraflar (düz metin; satır sonları korunur)
     * @param  array<string,string>  $facts  etiket → değer
     * @param  array{label:string,url:string}|null  $action
     */
    public function __construct(
        public string $subjectLine,
        public array $lines,
        public array $facts = [],
        public ?array $action = null,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
        public ?string $mailLocale = null,
    ) {}

    /** Düz metni boş satırlardan paragraflara böler. */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/u', $text) ?: []), static fn ($p) => $p !== ''));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->replyToName)] : [],
        );
    }

    public function content(): Content
    {
        $locale = $this->mailLocale ?? Locales::default();

        return new Content(
            view: 'mail.site',
            text: 'mail.site-text',
            with: ['siteName' => SiteManifest::siteName($locale), 'siteUrl' => rtrim((string) config('app.url'), '/'), 'lang' => $locale],
        );
    }
}
