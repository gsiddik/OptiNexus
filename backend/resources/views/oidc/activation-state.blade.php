@extends('oidc.layout')
@section('title', __("activation.states.$state.title"))
@section('language', '1')
@section('content')
    <h1>{{ __("activation.states.$state.title") }}</h1>
    <p class="lead">{{ __("activation.states.$state.text") }}</p>
    <p class="action">{{ __("activation.states.$state.action", ['minutes' => $minutes ?? 15]) }}</p>
@endsection
