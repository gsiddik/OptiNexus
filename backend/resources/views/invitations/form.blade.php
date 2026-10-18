@extends('invitations.layout')
@section('title', __('invitations.form.title'))
@section('content')
    <h1>{{ __('invitations.form.title') }}</h1>
    <p class="lead">{{ __('invitations.form.lead', ['application' => $invitation->application->name, 'tenant' => $invitation->tenant->name]) }}</p>
    <form method="POST" action="{{ url('/invitations/accept') }}" autocomplete="off">
        @csrf
        <label for="email">{{ __('invitations.form.email') }}</label>
        <input id="email" type="email" value="{{ $invitation->email }}" readonly tabindex="-1">

        @if($existing)
            <p class="notice">{{ __('invitations.form.existing_notice') }}</p>
            <label for="current_password">{{ __('invitations.form.current_password') }}</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required autofocus>
            @if(!empty($errors['current_password']))<div class="error" role="alert">{{ $errors['current_password'] }}</div>@endif
        @else
            <label for="name">{{ __('invitations.form.name') }}</label>
            <input id="name" name="name" type="text" value="{{ old('name', $invitation->name) }}" autocomplete="name" maxlength="255" required>
            @if(!empty($errors['name']))<div class="error" role="alert">{{ $errors['name'] }}</div>@endif

            <label for="password">{{ __('invitations.form.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="{{ config('invitations.password_min_length') }}" required autofocus>
            <p class="hint">{{ __('invitations.form.password_hint', ['min' => config('invitations.password_min_length')]) }}</p>
            @if(!empty($errors['password']))<div class="error" role="alert">{{ $errors['password'] }}</div>@endif

            <label for="password_confirmation">{{ __('invitations.form.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        @endif

        <p class="hint" style="margin-top:14px">{{ __('invitations.form.verify_note') }}</p>
        <button type="submit">{{ $existing ? __('invitations.form.submit_existing') : __('invitations.form.submit_new') }}</button>
    </form>
@endsection
