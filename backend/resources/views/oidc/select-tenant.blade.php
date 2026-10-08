@extends('oidc.layout')
@section('title', 'Choose organization')
@section('content')
    <h1>Choose an organization</h1>
    <p class="lead">You have access to {{ $applicationName }} in more than one organization.</p>
    <form method="POST" action="{{ route('oidc.tenant.select') }}">
        @csrf
        @foreach ($tenants as $tenant)
            <button class="tenant" type="submit" name="tenant_id" value="{{ $tenant->id }}">
                {{ $tenant->name }}<small>{{ $tenant->tenant_code }}</small>
            </button>
        @endforeach
    </form>
@endsection
