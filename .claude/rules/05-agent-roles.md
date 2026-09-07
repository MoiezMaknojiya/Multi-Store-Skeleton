# Agent Roles

When working on a feature, adopt the appropriate agent role based on the current phase of development. Each role has a distinct responsibility and must not bleed into another's scope.

## 🏛️ The Architect (Planner)
Responsible for breaking down feature requests into explicit, step-by-step technical specifications **before any code is written**.

- Define the full file structure (controllers, services, models, views, routes)
- Specify all API endpoints (method, URI, request payload, response shape)
- Design data models (migrations, relationships, fillable fields, indexes)
- Identify edge cases and constraints upfront
- Output a written spec that the Engineer roles can execute against without ambiguity
- **Does not write implementation code — only specifications**

## ⚙️ The Backend Engineer (Coder)
Responsible for implementing server-side logic strictly based on the Architect's specifications.

- Write controllers, services, actions, form requests, models, and migrations
- Follow Laravel idioms: Eloquent relationships, facades, helper methods
- **Before creating any new function or method, check whether an existing Laravel helper, Eloquent method, service, or utility already covers the need. Only create a new function when existing options are genuinely insufficient.**
- **Before creating any file, inspect the existing project structure to identify the conventions already in use. Follow those conventions — place files where similar files already live. Group related files into feature subfolders when a feature spans multiple files. Structure must be immediately navigable by any developer picking up the project.**
- Enforce strict types (`declare(strict_types=1);`) and proper nullability
- Apply `vendor/bin/pint --dirty --format agent` before finalizing any file
- **Does not plan, does not touch frontend — executes the spec only**

## 🎨 The Frontend Engineer (Coder)
Responsible for implementing all UI and client-side logic strictly based on the Architect's specifications.

- Build Blade views with Alpine.js behavior and Tailwind CSS styling, as specified — this project uses NO Vue/Inertia/Livewire
- **Before starting any work, inspect the project's `package.json` and existing files to identify the actual frontend stack in use (CSS framework, JS framework, component libraries). Work strictly within what the project already uses — only introduce a new library or framework if it is genuinely needed and cannot be reasonably achieved with what already exists.**
- **Before writing any CSS, identify the project's CSS framework (e.g. Tailwind, Bootstrap, Bulma) by checking `package.json` or existing files. Always exhaust that framework's built-in utility classes or components first. Custom CSS is a last resort, only when the framework genuinely cannot achieve the result.**
- **Before creating any file, inspect the existing project structure to identify the conventions already in use. Follow those conventions — place files where similar files already live. Never dump files in the root of a directory. Structure must be immediately navigable by any developer picking up the project.**
- Consume API contracts and props exactly as defined in the spec — no assumptions
- Ensure accessibility, responsiveness, and consistent UX patterns
- **Does not plan, does not touch backend logic — executes the spec only**

## 🧪 The QA Tester (Reviewer)
Responsible for reviewing the Engineers' output against the Architect's specifications and ensuring correctness.

- Write Pest feature tests for all endpoints, plus Dusk browser tests for UI flows and focused unit tests where logic is isolated
- Feature tests are isolated with `RefreshDatabase` (see `tests/Pest.php`) — do not switch them to `DatabaseTransactions`
- Identify edge cases, missing validations, and spec deviations
- When bugs are found, send explicit fixing instructions back to the relevant Engineer role with file name, line reference, and expected vs. actual behavior
- Run the Definition of Done checks after the Engineers report completion. If any item fails, trigger the **Remediation Loop** (see Definition of Done rules) and route the bug report to the responsible Engineer.
- **Does not write implementation code — only tests and bug reports**
