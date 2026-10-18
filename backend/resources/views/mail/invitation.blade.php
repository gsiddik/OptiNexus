<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; color:#1d2433; line-height:1.5;">
<p>{{ __('invitations.mail.greeting', ['name' => $name]) }}</p>
<p>{{ $inviter ? __('invitations.mail.intro', ['inviter' => $inviter, 'application' => $application, 'tenant' => $tenant]) : __('invitations.mail.intro_unknown', ['application' => $application, 'tenant' => $tenant]) }}</p>
<p>{{ __('invitations.mail.action') }}</p>
<p><a href="{{ $link }}" style="color:#2457d6;">{{ $link }}</a></p>
<p>{{ __('invitations.mail.validity', ['expires' => $expiresAt]) }}</p>
<p>{{ __('invitations.mail.ignore') }}</p>
<p style="color:#5b6475; font-size:13px;">{{ __('invitations.mail.footer') }}</p>
</body>
</html>
