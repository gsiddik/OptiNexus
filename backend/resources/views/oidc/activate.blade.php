@extends('oidc.layout')
@section('title', __('activation.activate.title'))
@section('language', '1')
@section('content')
    <h1>{{ __('activation.activate.title') }}</h1>
    <p class="lead">{{ __('activation.activate.lead') }}</p>
    <form method="POST" action="{{ route('oidc.activate.submit') }}" autocomplete="off">
        @csrf
        <label for="email">{{ __('activation.activate.email') }}</label>
        <input id="email" type="email" value="{{ $email }}" readonly tabindex="-1">

        <label for="temporary_password">{{ __('activation.activate.temporary_password') }}</label>
        <input id="temporary_password" name="temporary_password" type="password" autocomplete="off" required autofocus>
        <p class="hint">{{ __('activation.activate.temporary_hint') }}</p>
        @if(!empty($errors['temporary_password']))<div class="error" role="alert">{{ $errors['temporary_password'] }}</div>@endif

        <label for="password">{{ __('activation.activate.password') }}</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="{{ config('activation.password_min_length') }}" required>
        <p class="hint">{{ __('activation.activate.password_hint', ['min' => config('activation.password_min_length')]) }}</p>
        @if(!empty($errors['password']))<div class="error" role="alert">{{ $errors['password'] }}</div>@endif

        <label for="password_confirmation">{{ __('activation.activate.password_confirmation') }}</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <p class="hint" style="margin-top:14px">{{ __('activation.activate.note') }}</p>
        <button type="submit">{{ __('activation.activate.submit') }}</button>
    </form>
@endsection
