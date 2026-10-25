<?php

namespace App\Modules\Newsletter;

use App\Core\Content\Locales;
use App\Core\Support\SiteManifest;
use DOMDocument;
use DOMElement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Bir bülten e-postası (resources/views/mail/newsletter*.blade.php): sitenin e-posta çerçevesinde bültenin
 * gövdesi (temizlenmiş HTML; kök-göreli adresler mutlak), altta neden geldiği ve abonelikten çıkma bağlantısı.
 * Başlıkta List-Unsubscribe ve tek tık çıkma (RFC 8058; Gmail ve Yahoo toplu gönderende ister). Deneme
 * gönderiminde abone anahtarı yoktur: çıkma bağlantısı sayfaya gider, başlık eklenmez.
 */
final class NewsletterMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public string $mailLocale,
        public ?string $token = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function headers(): Headers
    {
        if ($this->token === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<'.self::siteUrl().'/api/v1/newsletter/unsubscribe?token='.$this->token.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        $locale = Locales::valid($this->mailLocale) ? $this->mailLocale : Locales::default();
        $site = SiteManifest::siteName($locale);
        $html = self::prepare($this->bodyHtml);

        return new Content(
            view: 'mail.newsletter',
            text: 'mail.newsletter-text',
            with: [
                'siteName' => $site,
                'siteUrl' => self::siteUrl(),
                'lang' => $locale,
                'html' => $html,
                'text' => self::plain($html),
                'reason' => NewsletterText::get('footer.reason', $locale, ['site' => $site]),
                'unsubscribeLabel' => NewsletterText::get('footer.unsubscribe', $locale),
                'unsubscribeUrl' => Subscribers::pageUrl($locale).($this->token !== null ? '?unsubscribe='.$this->token : ''),
            ],
        );
    }

    private static function siteUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** E-posta istemcileri için: kök-göreli adresler mutlak, görseller taşmasın, bağlantı rengi. */
    public static function prepare(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="km-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('km-root');
        if (! $root) {
            return $html;
        }
        $absolute = static fn (string $url) => str_starts_with($url, '/') && ! str_starts_with($url, '//') ? self::siteUrl().$url : $url;
        foreach ($root->getElementsByTagName('img') as $img) {
            /** @var DOMElement $img */
            $img->setAttribute('src', $absolute($img->getAttribute('src')));
            $img->removeAttribute('srcset');
            $img->removeAttribute('loading');
            $img->setAttribute('style', 'max-width:100%;height:auto;border:0;border-radius:8px;');
        }
        foreach ($root->getElementsByTagName('a') as $a) {
            /** @var DOMElement $a */
            $a->setAttribute('href', $absolute($a->getAttribute('href')));
            $a->setAttribute('style', 'color:#2563eb;');
        }
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** Düz metin sürümü: paragraflar boş satırla, bağlantılar "metin (adres)". */
    public static function plain(string $html): string
    {
        $html = preg_replace_callback('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', static fn ($m) => strip_tags($m[2]).($m[1] !== '' && $m[1] !== strip_tags($m[2]) ? ' ('.$m[1].')' : ''), $html) ?? $html;
        $html = preg_replace(['/<br\s*\/?>/i', '/<\/(p|h[1-6]|li|blockquote|tr|figure)>/i', '/<li[^>]*>/i'], ["\n", "\n\n", '- '], $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+\n/', "\n", $text) ?? $text) ?? $text);
    }
}
