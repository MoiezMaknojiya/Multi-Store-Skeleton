{{-- Edit and Delete action buttons for table rows.
     editCan / deleteCan take a permission name; when given, the button only renders
     for users holding that permission (the backend enforces it regardless). --}}
@props(['editClick' => '', 'deleteClick' => '', 'showEdit' => true, 'showDelete' => true, 'editCan' => null, 'deleteCan' => null, 'rowShow' => null])

<div class="flex items-center justify-end gap-2">
    @if($showEdit && $editClick && (! $editCan || auth()->user()->can($editCan)))
    <button @click="{{ $editClick }}" @if($rowShow) x-show="{{ $rowShow }}" @endif class="btn-row-neutral">Edit</button>
    @endif

    @if($showDelete && $deleteClick && (! $deleteCan || auth()->user()->can($deleteCan)))
    <button @click="{{ $deleteClick }}" @if($rowShow) x-show="{{ $rowShow }}" @endif class="btn-row-danger">Delete</button>
    @endif

    {{-- Extra buttons slot (e.g. "Stores" button on users page) --}}
    {{ $slot }}
</div>