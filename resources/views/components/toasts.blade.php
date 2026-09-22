{{-- The toasts, filled by window.toast() from any component (resources/js/app.js), and the flash message a
     redirect brought ("Welcome to Alpha Mart!", "Switched to …") shown as a success toast. Shared by both
     shells a signed-in person sees — the app layout and the focused layout (the store picker), so a flash
     that reaches the picker is shown there too. The Profile and Settings forms show their own status keys
     themselves, so those never become a toast. --}}
@php($__flash = session('status'))

<div x-data class="fixed top-4 left-1/2 -translate-x-1/2 z-[100] w-80 max-w-[calc(100vw-2rem)] space-y-2 pointer-events-none">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition.opacity.duration.300ms
            class="pointer-events-auto rounded-md px-4 py-3 text-sm text-white shadow-lg flex items-start gap-2"
            :class="toast.type === 'success' ? 'bg-green-600' : 'bg-red-600'">
            <span class="flex-1" x-text="toast.message"></span>
            <button type="button" class="opacity-70 hover:opacity-100" aria-label="Dismiss notification"
                @click="$store.toasts.dismiss(toast.id)">✕</button>
        </div>
    </template>
</div>

@if (is_string($__flash) && ! in_array($__flash, ['profile-updated', 'password-updated', 'store-updated'], true))
    <script>
        document.addEventListener('alpine:initialized', () => window.toast({{ Js::from($__flash) }}, 'success'));
    </script>
@endif
