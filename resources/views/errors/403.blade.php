@extends('errors.layout')

{{-- A refusal of our own says why ("You are not a member of this store."); Laravel's own words for a permission the
     person does not hold are said in plainer ones. --}}
@php($__said = trim((string) (($exception ?? null)?->getMessage() ?? '')))

@section('code', '403')
@section('title', 'You cannot open this')
@section('message', in_array($__said, ['', 'Forbidden', 'This action is unauthorized.'], true)
    ? 'Your role does not allow it. If you need it, ask whoever manages your team.'
    : $__said)
