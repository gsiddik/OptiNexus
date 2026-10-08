@extends('oidc.layout')
@section('title', 'Sign in')
@section('content')
    <h1>Sign in</h1>
    <p class="lead">Continue to {{ $applicationName ?? 'your application' }}. One sign-in opens every OptiNexus application your organization subscribes to.</p>
    <form method="POST" action="{{ route('oidc.login.submit') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        @error('email')<div class="error">{{ $message }}</div>@enderror
        <button type="submit">Sign in</button>
    </form>
@endsection
