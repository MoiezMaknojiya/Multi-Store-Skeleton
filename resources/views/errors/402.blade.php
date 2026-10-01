@extends('errors.layout')

@section('code', '402')
@section('title', 'That did not work')
@section('message', 'Something about that request was not right. Go back and try again.')

@section('action')
    <button type="button" class="btn btn-secondary" onclick="history.back()" dusk="error-back">Go Back</button>
@endsection
