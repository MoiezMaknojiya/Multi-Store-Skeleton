{{-- Active/Inactive status badge pill --}}
@props(['activeExpression'])

<span x-show="{{ $activeExpression }}" class="badge-success">Active</span>
<span x-show="!{{ $activeExpression }}" class="badge-neutral">Inactive</span>