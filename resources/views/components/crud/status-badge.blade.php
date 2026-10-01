{{-- Active/Inactive status badge pill; `inactive` names the other state where a page has a word of its own for it. --}}
@props(['activeExpression', 'inactive' => 'Inactive'])

<span x-show="{{ $activeExpression }}" class="badge-success">Active</span>
<span x-show="!{{ $activeExpression }}" class="badge-neutral">{{ $inactive }}</span>