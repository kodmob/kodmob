{{-- Bülten e-postası (App\Modules\Newsletter\NewsletterMail). Çerçeve sitenin e-posta şablonuyla aynı; gövde temizlenmiş HTML. --}}
<!doctype html>
<html lang="{{ $lang }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;border:1px solid #e5e7eb;">
<tr><td style="padding:24px 28px 8px;font-size:18px;font-weight:700;color:#111827;">{{ $siteName }}</td></tr>
<tr><td style="padding:8px 28px 12px;font-size:15px;line-height:1.6;">{!! $html !!}</td></tr>
<tr><td style="padding:16px 28px 22px;border-top:1px solid #f0f1f3;font-size:12px;line-height:1.5;color:#9ca3af;">
{{ $reason }}<br>
<a href="{{ $unsubscribeUrl }}" style="color:#6b7280;">{{ $unsubscribeLabel }}</a> · <a href="{{ $siteUrl }}" style="color:#6b7280;text-decoration:none;">{{ $siteName }}</a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
