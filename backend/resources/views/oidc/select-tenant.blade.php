@extends('oidc.layout')
@section('title', __('oidc.tenant.title'))
@section('language', '1')
@section('content')
    <h1>{{ __('oidc.tenant.heading') }}</h1>
    <p class="lead">{{ __('oidc.tenant.lead', ['application' => $applicationName]) }}</p>
    <form method="POST" action="{{ route('oidc.tenant.select') }}">
        @csrf
        @foreach ($tenants as $tenant)
            <button class="tenant" type="submit" name="tenant_id" value="{{ $tenant->id }}">
                {{ $tenant->name }}<small>{{ $tenant->tenant_code }}</small>
            </button>
        @endforeach
    </form>
@endsection
