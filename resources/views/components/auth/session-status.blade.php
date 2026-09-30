{{-- A line the page came back with ("A link is on its way…"): a boxed success, said by a screen reader as it appears. --}}
@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'alert-success', 'role' => 'status']) }}>
        {{ $status }}
    </div>
@endif
