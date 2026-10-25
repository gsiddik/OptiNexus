{!! __('operational_mail.greeting', ['name' => $name], $locale) !!}

@foreach ($paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach ($facts as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
@if ($actionUrl)

{!! __('operational_mail.action', [], $locale) !!}: {!! $actionUrl !!}
@endif

-- 
{!! __('operational_mail.footer', [], $locale) !!}
