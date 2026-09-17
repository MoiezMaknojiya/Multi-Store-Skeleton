# Tech Stack & Core Principles

## Tech Stack
- **Framework:** Laravel 13.x (`laravel/framework: ^13.0`)
- **PHP Version:** 8.3+
- **Testing:** Pest (backend) + Laravel Dusk (browser)
- **Frontend:** Blade + Alpine.js + Tailwind CSS, built with Vite — NO Vue/Inertia/Livewire; never introduce them
- **Database:** MySQL (local dev) / SQLite in-memory (test suites) / `dusk.sqlite` (browser tests)

## Core Principles
1. **Laravel Idioms:** Always favor built-in Laravel helper methods, facades, and Eloquent relationships over raw SQL or verbose PHP logic.
2. **Fat Models, Skinny Controllers:** Business logic should live in Services, Actions, or Model methods. Keep controllers responsible for handling requests and returning responses.
3. **Types & Nullability:** every method, property and closure parameter carries a scalar or class type declaration and an honest nullability — that half is not optional. `declare(strict_types=1);` is deliberately NOT used across this project (owner's decision, 2026-09-17: the rule now says what the code does): request input arrives as strings and ids arrive from route parameters, so switching it on everywhere would trade today's working coercion for TypeErrors in code that has been tested for months. Three files carry it from before this rule and may keep it. Add it to a new file only when that file never reads raw request input; never as a sweep.
4. **Request Validation:** a **Form Request** when the rule set is long, shared between endpoints, or carries its own messages or authorization (`app/Http/Requests/{Signage,Advertising,Auth}`); **`$request->validate()` in the action** when an endpoint has a handful of rules that nothing else needs — which is how most of this app's validations are written, on purpose, and is why the rules are easy to read beside the code they guard. Either way: one place per endpoint, never hand-rolled `if` checks in the controller body, and every rule list whose later rules assume a type (a closure, `current_password`, `exists`) begins with **`bail`** — see the validation gotcha in `02-project-conventions.md`, which is a 500 waiting to happen otherwise.
