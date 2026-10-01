{{-- The toasts, filled by window.toast() from any component (resources/js/app.js), and the flash message a
     redirect brought ("Welcome to Alpha Mart!", "Switched to …") shown as a success toast. Shared by both
     shells a signed-in person sees — the app layout and the focused layout (the organization picker), so a flash
     that reaches the picker is shown there too. The Profile and Settings forms show their own status keys
     themselves, so those never become a toast. --}}
@php($__flash = session('status'))

{{-- A live region: a screen reader says each toast as it appears, without moving the focus — "Done:" or "Error:"
     first, as the colour and the icon say it to the eye. `top` moves them below a bar that would hide them (the
     Ad Builder's). --}}
@props(['top' => 'top-4'])

<div x-data role="status" aria-live="polite" class="fixed {{ $top }} left-1/2 -translate-x-1/2 z-[100] w-80 max-w-[calc(100vw-2rem)] space-y-2 pointer-events-none">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition.opacity.duration.300ms
            @mouseenter="$store.toasts.hold(toast.id)" @mouseleave="$store.toasts.release(toast.id)"
            @focusin="$store.toasts.hold(toast.id)" @focusout="$store.toasts.release(toast.id)"
            class="pointer-events-auto rounded-md px-4 py-3 text-sm text-white shadow-lg flex items-start gap-2"
            :class="toast.type === 'success' ? 'bg-green-700' : 'bg-red-600'">
            <svg x-show="toast.type === 'success'" class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <svg x-show="toast.type !== 'success'" class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <span class="flex-1"><span class="sr-only" x-text="toast.type === 'success' ? 'Done: ' : 'Error: '"></span><span x-text="toast.message"></span></span>
            <button type="button" class="-m-1 rounded p-1 opacity-80 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-white" aria-label="Dismiss notification"
                @click="$store.toasts.dismiss(toast.id)"><span aria-hidden="true">✕</span></button>
        </div>
    </template>
</div>

{{-- A saved form says so as a toast in words — a key is never shown raw — and the email's own keys stay beside the
     email field on the profile, which says them there. --}}
@php($__said = ['profile-updated' => 'Your profile is saved.', 'password-updated' => 'Your password is changed.', 'organization-updated' => 'Organization details saved.'])
@php($__toast = is_string($__flash) && ! in_array($__flash, ['email-pending', 'email-link-resent', 'email-changed', 'email-change-cancelled', 'verification-link-sent'], true) ? ($__said[$__flash] ?? $__flash) : null)
@if ($__toast)
    <script>
        document.addEventListener('alpine:initialized', () => window.toast({{ Js::from($__toast) }}, 'success'));
    </script>
@endif
