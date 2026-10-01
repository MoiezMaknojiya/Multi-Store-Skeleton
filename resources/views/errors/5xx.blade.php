@extends('errors.layout')

@section('code', (string) (($exception ?? null)?->getStatusCode() ?? 500))
@section('title', 'Something went wrong')
@section('message', 'It is on our side, not yours. Please try again in a moment.')
