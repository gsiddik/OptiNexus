@extends('invitations.layout')
@section('title', __('invitations.done.title'))
@section('content')
    <h1 class="ok">{{ __('invitations.done.title') }}</h1>
    <p class="lead">{{ __('invitations.done.lead', ['application' => $invitation->application->name, 'tenant' => $invitation->tenant->name]) }}</p>
    @if($continueUrl)
        <p class="action">{{ __('invitations.done.next', ['application' => $invitation->application->name, 'email' => $invitation->email]) }}</p>
        <a class="button" href="{{ $continueUrl }}" rel="noopener noreferrer">{{ __('invitations.done.continue', ['application' => $invitation->application->name]) }}</a>
    @else
        <p class="action">{{ __('invitations.done.no_link', ['application' => $invitation->application->name]) }}</p>
    @endif
@endsection
