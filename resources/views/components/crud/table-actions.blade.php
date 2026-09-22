{{-- Edit and Delete action buttons for table rows.
     editCan / deleteCan take a permission name; when given, the button only renders
     for users holding that permission (the backend enforces it regardless).
     dusk takes a name for the row's thing ("permission"), which becomes
     edit-{dusk}-{id} / delete-{dusk}-{id} — without it a browser test cannot reach
     these buttons at all, which is how the Permissions page went untested. --}}
@props([
    'editClick' => '',
    'deleteClick' => '',
    'editCan' => null,
    'deleteCan' => null,
    'dusk' => null,
])

<div class="flex items-center justify-end gap-2">
    @if($editClick && (! $editCan || auth()->user()->can($editCan)))
    <button @click="{{ $editClick }}" class="btn-row-neutral"
            @if($dusk) x-bind:dusk="'edit-{{ $dusk }}-' + item.id" @endif>Edit</button>
    @endif

    @if($deleteClick && (! $deleteCan || auth()->user()->can($deleteCan)))
    <button @click="{{ $deleteClick }}" class="btn-row-danger"
            @if($dusk) x-bind:dusk="'delete-{{ $dusk }}-' + item.id" @endif>Delete</button>
    @endif
</div>
