@extends('invitations.layout')
@section('title', __("invitations.states.$state.title"))
@section('content')
    <h1>{{ __("invitations.states.$state.title") }}</h1>
    <p class="lead">{{ __("invitations.states.$state.text") }}</p>
    <p class="action">{{ __("invitations.states.$state.action", ['minutes' => $minutes ?? 15]) }}</p>
    @if(!empty($continueUrl))
        <a class="button" href="{{ $continueUrl }}" rel="noopener noreferrer">{{ __('invitations.done.continue', ['application' => $application]) }}</a>
    @endif
@endsection
