<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0; padding:24px 12px; background:#f4f6fa; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color:#1d2433; line-height:1.5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; margin:0 auto; background:#ffffff; border:1px solid #dfe3eb; border-radius:8px;">
<tr><td style="padding:20px 24px; border-bottom:1px solid #dfe3eb; font-weight:600; font-size:16px;">{{ __('operational_mail.brand', [], $locale) }}</td></tr>
<tr><td style="padding:24px;">
<p style="margin:0 0 16px;">{{ __('operational_mail.greeting', ['name' => $name], $locale) }}</p>
@foreach ($paragraphs as $paragraph)
<p style="margin:0 0 16px;">{{ $paragraph }}</p>
@endforeach
@if ($facts !== [])
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; margin:8px 0 20px; border-collapse:collapse; font-size:14px;">
@foreach ($facts as $label => $value)
<tr><td style="padding:6px 12px 6px 0; color:#5b6475; white-space:nowrap; vertical-align:top;">{{ $label }}</td><td style="padding:6px 0; vertical-align:top;">{{ $value }}</td></tr>
@endforeach
</table>
@endif
@if ($actionUrl)
<p style="margin:0 0 16px;"><a href="{{ $actionUrl }}" style="display:inline-block; padding:10px 18px; background:#2457d6; color:#ffffff; text-decoration:none; border-radius:6px;">{{ __('operational_mail.action', [], $locale) }}</a></p>
<p style="margin:0 0 16px; font-size:13px; color:#5b6475; word-break:break-all;">{{ $actionUrl }}</p>
@endif
</td></tr>
<tr><td style="padding:16px 24px; border-top:1px solid #dfe3eb; color:#5b6475; font-size:12px;">{{ __('operational_mail.footer', [], $locale) }}</td></tr>
</table>
</body>
</html>
