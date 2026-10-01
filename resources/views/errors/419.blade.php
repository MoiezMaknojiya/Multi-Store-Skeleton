@extends('errors.layout')

@section('code', '419')
@section('title', 'This page expired')
@section('message', 'It was open too long, so what you sent did not go through. Go back, reload the page and try again.')

@section('action')
    <button type="button" class="btn btn-secondary" onclick="history.back()" dusk="error-back">Go Back</button>
@endsection
