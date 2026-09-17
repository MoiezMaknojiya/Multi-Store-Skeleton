# Testing Standards, Commands & Workflow

## Testing Standards
- All new features and modified business logic require tests.
- **Pest:** Write feature tests for endpoints/controllers, and focused unit tests where logic is isolated.
- Feature tests are isolated with `RefreshDatabase` (wired in `tests/Pest.php`) against in-memory SQLite (`phpunit.xml`) — do NOT switch them to `DatabaseTransactions`.

## Commands
- **Run Tests:** `php artisan test`
- **Code Style:** `vendor/bin/pint --dirty --format agent`
- **Development:** `php artisan serve`
- **Browser Tests:** start the Dusk server the way `.claude/launch.json` does — `php artisan serve --env=dusk --host=localhost --port=8001 --no-reload` (localhost and `--no-reload` matter: `phpunit.dusk.xml` points at `http://localhost:8001`, and `php artisan dusk` swaps `.env` during the run, which restarts a reloading server) — then `php artisan dusk`

## Workflow Guidelines
- **Formatting:** run the **Code Style** command listed above on changed files before finalizing.
- The general working rules — think before you leap, zero mistakes / double-check, and verify-with-search before trusting version-specific or uncertain API behavior — live once in `CLAUDE.md` → **Working Principles** (read first every session). They apply here too.
