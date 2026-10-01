{{-- The panel's own error page (owner, 2026-09-30): what happened, in words, and the way back — in the panel's look,
     its dark mode too. Self-contained: its styles are written here, and it asks nothing of the database, the session or
     the built files, because it must still be drawn when the thing that broke is one of those. Each code's page (404,
     403, 419, 429, 500, 503) gives its title, its words and, when it has one, a button besides the dashboard's. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@100..900&display=swap" rel="stylesheet">
    <script>
        try {
            if (localStorage.getItem('darkMode') === 'true') document.documentElement.classList.add('dark');
        } catch (e) { /* Storage refused: the light page. */ }
    </script>
    <style>
        :root { color-scheme: light; --page: #f3f4f6; --card: #ffffff; --text: #101828; --muted: #475467; --line: #e4e7ec; --brand: #2563eb; --brand-hover: #1d4ed8; }
        .dark { color-scheme: dark; --page: #0c111d; --card: #1d2939; --text: #ffffff; --muted: #d0d5dd; --line: #344054; }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
            background: var(--page); color: var(--text); font-family: Outfit, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            -webkit-font-smoothing: antialiased; }
        main { width: 100%; max-width: 28rem; text-align: center; }
        .brand { display: inline-flex; align-items: center; gap: .5rem; font-weight: 600; color: var(--text); text-decoration: none; }
        .brand:focus-visible, .btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .tile { display: inline-flex; width: 2rem; height: 2rem; align-items: center; justify-content: center; border-radius: .125rem; background: var(--brand); }
        .card { margin-top: 1.5rem; padding: 2.5rem 1.5rem; background: var(--card); border: 1px solid var(--line); border-radius: .75rem;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .05); }
        .code { margin: 0; font-size: .875rem; font-weight: 600; letter-spacing: .05em; color: var(--brand); }
        .dark .code { color: #60a5fa; }
        h1 { margin: .5rem 0 0; font-size: 1.5rem; line-height: 2rem; font-weight: 700; }
        .words { margin: .75rem 0 0; font-size: .9375rem; line-height: 1.5rem; color: var(--muted); overflow-wrap: anywhere; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; margin-top: 1.75rem; }
        .btn { display: inline-flex; height: 2.5rem; align-items: center; justify-content: center; padding: 0 1.5rem; border: 1px solid transparent;
            border-radius: .375rem; font: inherit; font-size: .875rem; font-weight: 500; white-space: nowrap; text-decoration: none; cursor: pointer; }
        .btn-primary { background: var(--brand); color: #ffffff; }
        .btn-primary:hover { background: var(--brand-hover); }
        .btn-secondary { background: var(--card); color: var(--text); border-color: var(--line); }
    </style>
</head>
<body>
    <main>
        <a class="brand" href="{{ url('/dashboard') }}">
            <span class="tile" aria-hidden="true">
                <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#ffffff"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
            </span>
            {{ config('app.name', 'Laravel') }}
        </a>

        <div class="card">
            <p class="code">Error @yield('code')</p>
            <h1>@yield('title')</h1>
            <p class="words">@yield('message')</p>
            <div class="actions">
                @yield('action')
                <a class="btn btn-primary" href="{{ url('/dashboard') }}" dusk="error-dashboard">Go to the Dashboard</a>
            </div>
        </div>
    </main>
</body>
</html>
