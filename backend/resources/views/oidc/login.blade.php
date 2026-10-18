@extends('oidc.layout')
@section('title', __('oidc.login.title'))
@section('language', '1')
@section('content')
    <h1>{{ __('oidc.login.title') }}</h1>
    <p class="lead">{{ __('oidc.login.lead', ['application' => $applicationName ?? __('oidc.login.lead_default')]) }}</p>
    <form method="POST" action="{{ route('oidc.login.submit') }}">
        @csrf
        <label for="email">{{ __('oidc.login.email') }}</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">{{ __('oidc.login.password') }}</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        @error('email')<div class="error" role="alert">{{ $message }}</div>@enderror
        <p class="hint" style="margin-top:14px">{{ __('oidc.login.hint_temporary') }}</p>
        <button type="submit">{{ __('oidc.login.submit') }}</button>
    </form>
@endsection
