{{-- Where an organization asks to unlock or about billing (docs/BILLING-SPEC.md §3, BillingSummary::contact): the email and phone the
     owner gives in SIGNAGE_BILLING_EMAIL and SIGNAGE_BILLING_PHONE, or "Contact us" alone until then. `lead` says what the
     contact is for there (unlocking, a paused organization); `alwaysLead` says it above the email and phone too. --}}
@props(['contact', 'organization', 'lead' => 'Contact us, and we unlock it for you.', 'alwaysLead' => false])

<div {{ $attributes->merge(['class' => 'space-y-1 text-sm text-gray-700 dark:text-gray-200']) }}>
    @if ($contact['email'] === null && $contact['phone'] === null)
        <p dusk="billing-contact-us">{{ $lead }}</p>
    @else
        @if ($alwaysLead)
            <p>{{ $lead }}</p>
        @endif
        @if ($contact['email'] !== null)
            <p><span class="text-gray-500 dark:text-gray-400">Email:</span>
                <a href="mailto:{{ $contact['email'] }}" class="font-medium text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400" dusk="billing-contact-email">{{ $contact['email'] }}</a></p>
        @endif
        @if ($contact['phone'] !== null)
            <p><span class="text-gray-500 dark:text-gray-400">Phone:</span>
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}" class="font-medium text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400"
                   dusk="billing-contact-phone">{{ $contact['phone'] }}</a></p>
        @endif
    @endif
    <p class="pt-2 text-xs text-gray-500 dark:text-gray-400">Tell us your organization's name, {{ $organization }}.</p>
</div>
