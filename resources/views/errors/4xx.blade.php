@extends('errors.layout')

{{-- Any other refusal of a request (a method the address does not take, text that is not text, a body too large):
     the code, and the way back. --}}
@section('code', (string) (($exception ?? null)?->getStatusCode() ?? 400))
@section('title', 'That did not work')
@section('message', 'Something about that request was not right. Go back and try again.')

@section('action')
    <button type="button" class="btn btn-secondary" onclick="history.back()" dusk="error-back">Go Back</button>
@endsection
