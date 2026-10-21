{{-- The privacy policy (owner, 2026-10-21, for the Amazon Appstore). Every sentence says what the code does: a change to
     what the dashboard or the TV app keeps, sends or deletes changes this page and its date in the same commit. --}}
<x-document-layout title="Privacy Policy">
    <h1 class="text-3xl font-bold text-gray-800 dark:text-white">Privacy Policy</h1>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" dusk="privacy-updated">
        Last updated <time datetime="2026-10-21">October 21, 2026</time>
    </p>

    <p class="mt-6 leading-relaxed text-gray-700 dark:text-gray-300">
        This policy explains what {{ config('app.name') }} collects when you use the dashboard at
        {{ parse_url(config('app.url'), PHP_URL_HOST) }} and the {{ config('app.name') }} TV app, why we collect it, who
        else handles it, how long we keep it and what you can do about it. We do not sell personal information, and we
        do not use it to profile you or to show you advertising.
    </p>

    <nav aria-labelledby="privacy-contents" class="mt-8 rounded-lg border border-gray-200 p-5 dark:border-gray-700">
        <h2 id="privacy-contents" class="text-base font-semibold text-gray-800 dark:text-white">On this page</h2>
        <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm">
            <li><a href="#what-we-collect" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">What we collect</a></li>
            <li><a href="#tv-app" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">The TV app</a></li>
            <li><a href="#how-we-use-it" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">How we use it</a></li>
            <li><a href="#who-else" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Who else handles it</a></li>
            <li><a href="#cookies" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Cookies and storage on your device</a></li>
            <li><a href="#how-long" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">How long we keep it</a></li>
            <li><a href="#your-choices" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Your choices</a></li>
            <li><a href="#security" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Security</a></li>
            <li><a href="#children" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Children</a></li>
            <li><a href="#changes" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Changes to this policy</a></li>
            @if (filled(config('signage.privacy_contact_email') ?: config('signage.billing_contact_email')))
                <li><a href="#contact" class="text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400">Contact us</a></li>
            @endif
        </ol>
    </nav>

    <x-legal.section id="what-we-collect" title="What we collect">
        <ul>
            <li><strong>Your account:</strong> your first and last name, email address, phone number and password. We keep
                the password only as a one-way hash, so nobody can read it, us included.</li>
            <li><strong>Your organization:</strong> its name and its address (street, suite, city, state and ZIP code).</li>
            <li><strong>What you upload or make:</strong> pictures, videos, the ads you design, playlists, schedules and
                channels. Files reach your screens through long, random web addresses, and anyone who has such an address
                can open the file, so upload only what you are happy to show on a screen. A picture keeps the details its
                camera wrote into the file, such as when and, on some phones, where it was taken, unless it was made
                smaller or turned when it was added; remove them before uploading if that matters to you.</li>
            <li><strong>The people you invite:</strong> the email address you invite and the role you give them.</li>
            <li><strong>What changes in your organization:</strong> an activity log of what was done (for example a file
                deleted or a person invited), with the name and email address of the person who did it.</li>
            <li><strong>When you are signed in:</strong> your IP address and your browser's description (its user agent),
                kept with your sign-in session while it lasts.</li>
        </ul>
    </x-legal.section>

    <x-legal.section id="tv-app" title="The TV app">
        <p>The TV app shows your organization's content on a TV. To do that it:</p>
        <ul>
            <li>gets a random device ID and a pairing code from our server. Once the TV is paired, a secret token takes
                their place; we keep only a one-way hash of it;</li>
            <li>downloads the playlist and the files your organization chose for that screen, and keeps copies on the TV
                so it can keep playing without the internet;</li>
            <li>tells our server once a minute that it is on, which the dashboard shows as when the screen was last seen;</li>
            <li>asks our server, when it opens and once a day, whether a newer version of the app is ready. A copy
                installed from the Amazon Appstore never asks: Amazon keeps it up to date.</li>
        </ul>
        <p>The TV app does not collect names, contacts, location, advertising IDs, or anything from a microphone or a
            camera. Like any request on the internet, its requests reach our server with the TV's IP address, which our
            web server logs.</p>
    </x-legal.section>

    <x-legal.section id="how-we-use-it" title="How we use it">
        <ul>
            <li>To run the service: signing you in, keeping your organization's content, pairing screens and playing on
                them what your organization scheduled.</li>
            <li>To send you the emails the service needs: confirming your email address, invitations, password resets,
                approving a change of email address, and warnings to our own team when the server runs low on space.</li>
            <li>To keep the service safe: limiting how often a form or a screen may ask, and stopping abuse such as
                automated sign-ups.</li>
            <li>To help you when you ask us for support.</li>
        </ul>
        <p>We use no analytics or advertising trackers.</p>
    </x-legal.section>

    <x-legal.section id="who-else" title="Who else handles it">
        <ul>
            <li><strong>Hetzner Online</strong> hosts our servers and their backups.</li>
            <li><strong>Brevo</strong> sends our emails, so it receives each recipient's address and the email itself.</li>
            <li><strong>Google Fonts</strong> serves the font the dashboard is written in, and the font previews of the Ad
                Builder, so Google receives your IP address and your browser's description when a page asks for them.</li>
            <li><strong>Your organization:</strong> its members see your name, email address and role, and what you change
                appears in its activity log.</li>
            <li><strong>Our own team</strong> sees accounts and organizations so it can run and support the service, and may
                view the dashboard as you do to help you.</li>
            <li><strong>The authorities,</strong> only when the law requires it.</li>
        </ul>
    </x-legal.section>

    <x-legal.section id="cookies" title="Cookies and storage on your device">
        <ul>
            <li>A sign-in cookie and a security cookie that protects your forms, and a "remember me" cookie only if you
                tick it when you sign in.</li>
            <li>Your browser keeps a few settings of yours: light or dark, whether the side menu is open, and the Ad
                Builder's settings and copied items.</li>
            <li>The TV app keeps its device ID, its token and copies of the content it plays.</li>
        </ul>
        <p>There are no advertising or analytics cookies.</p>
    </x-legal.section>

    <x-legal.section id="how-long" title="How long we keep it">
        <ul>
            <li>Your account and your organization's details and files: until you delete them.</li>
            <li>An account whose email address is never confirmed: deleted after 7 days, together with the empty
                organization it made.</li>
            <li>A sign-in session: it ends after 2 hours without use, or when you sign out. "Remember me" keeps that
                browser signed in until you sign out.</li>
            <li>An invitation's link works for 7 days, a password reset link for 60 minutes, and a TV's pairing code for 15
                minutes.</li>
            <li>The activity log: up to two years. Each year is removed once it is older than the year before this one.</li>
            <li>Our web server's and application's logs, and the backups of our database: 14 days.</li>
        </ul>
    </x-legal.section>

    <x-legal.section id="your-choices" title="Your choices">
        <ul>
            <li>Correct your name, phone number and email address in the dashboard, under Settings, Profile.</li>
            <li><strong>Delete your account:</strong> sign in at {{ parse_url(config('app.url'), PHP_URL_HOST) }}, open
                Settings, Profile, choose Delete Account and confirm with your password. What you made stays with your
                organization, and past entries of its activity log keep the name and email address they were written with.</li>
            <li><strong>Delete some of your information</strong> and keep the account: delete the files, ads, screens or
                people in the dashboard. An organization's owner can delete the whole organization, with every screen, file
                and ad in it.</li>
            <li>Or ask us for a copy of your information, to correct or delete it, or any question about this policy.</li>
        </ul>
    </x-legal.section>

    <x-legal.section id="security" title="Security">
        <p>Every page and every TV talks to our server over an encrypted connection (HTTPS). Passwords, TV tokens and
            invitation links are kept only as one-way hashes, and what each person may see or change in an organization is
            decided by the role it gives them.</p>
    </x-legal.section>

    <x-legal.section id="children" title="Children">
        <p>{{ config('app.name') }} is made for businesses and other organizations. It is not meant for children under 13,
            and we do not knowingly collect information from them.</p>
    </x-legal.section>

    <x-legal.section id="changes" title="Changes to this policy">
        <p>When this policy changes, the date at the top of this page changes with it.</p>
    </x-legal.section>

    @if (filled($contact = config('signage.privacy_contact_email') ?: config('signage.billing_contact_email')))
        <x-legal.section id="contact" title="Contact us">
            <p>For a question about this policy or a request about your information, write to
                <a href="mailto:{{ $contact }}" class="break-all text-blue-600 underline underline-offset-2 hover:decoration-2 dark:text-blue-400" dusk="privacy-contact">{{ $contact }}</a>.</p>
        </x-legal.section>
    @endif
</x-document-layout>
