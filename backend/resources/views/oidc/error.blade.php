@extends('oidc.layout')
@section('title', 'Sign-in problem')
@section('content')
    <h1>We could not sign you in</h1>
    <p class="lead">{{ $message }}</p>
@endsection
