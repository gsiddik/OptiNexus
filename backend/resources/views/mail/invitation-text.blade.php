{{ __('invitations.mail.greeting', ['name' => $name]) }}

{{ $inviter ? __('invitations.mail.intro', ['inviter' => $inviter, 'application' => $application, 'tenant' => $tenant]) : __('invitations.mail.intro_unknown', ['application' => $application, 'tenant' => $tenant]) }}

{{ __('invitations.mail.action') }}
{{ $link }}

{{ __('invitations.mail.validity', ['expires' => $expiresAt]) }}
{{ __('invitations.mail.ignore') }}

-- 
{{ __('invitations.mail.footer') }}
