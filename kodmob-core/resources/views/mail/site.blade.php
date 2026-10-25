{{-- Sitenin e-posta şablonu (App\Core\Mail\SiteMail). Satır içi stil: e-posta istemcileri <style>'ı çoğunlukla yok sayar. --}}
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
<tr><td style="padding:8px 28px 4px;font-size:15px;line-height:1.6;">
@foreach ($lines as $line)
<p style="margin:0 0 14px;">{!! nl2br(e($line)) !!}</p>
@endforeach
</td></tr>
@if ($facts)
<tr><td style="padding:0 28px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;line-height:1.5;">
@foreach ($facts as $label => $value)
<tr>
<td style="padding:6px 12px 6px 0;color:#6b7280;white-space:nowrap;vertical-align:top;border-top:1px solid #f0f1f3;">{{ $label }}</td>
<td style="padding:6px 0;color:#111827;border-top:1px solid #f0f1f3;">{!! nl2br(e($value)) !!}</td>
</tr>
@endforeach
</table>
</td></tr>
@endif
@if ($action)
<tr><td style="padding:8px 28px 20px;">
<a href="{{ $action['url'] }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 20px;border-radius:8px;">{{ $action['label'] }}</a>
</td></tr>
@endif
<tr><td style="padding:16px 28px 22px;border-top:1px solid #f0f1f3;font-size:12px;color:#9ca3af;">
<a href="{{ $siteUrl }}" style="color:#6b7280;text-decoration:none;">{{ $siteName }}</a>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
