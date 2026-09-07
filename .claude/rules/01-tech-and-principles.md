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
3. **Strict Types & Nullability:** Use scalar type declarations and strict types (`declare(strict_types=1);`) where applicable.
4. **API & Request Validation:** Utilize Form Requests for all non-trivial HTTP request validation rather than doing it inline in the controller.
