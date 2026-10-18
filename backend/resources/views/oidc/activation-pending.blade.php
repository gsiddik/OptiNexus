@extends('oidc.layout')
@section('title', __('activation.pending.title'))
@section('language', '1')
@section('content')
    <h1>{{ __('activation.pending.title') }}</h1>
    <p class="lead">{{ __('activation.pending.text') }}</p>
    <p class="action">{{ __('activation.pending.action') }}</p>
@endsection
