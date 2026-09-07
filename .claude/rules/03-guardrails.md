# Guardrails (Non-Negotiable)

- Never run destructive database commands outside a local, throwaway dev database — this includes `migrate:fresh`, `migrate:reset`, `db:wipe`, and `migrate:rollback`. They drop or truncate tables. If a schema reset seems necessary, stop and ask first.
- Assume any database you are connected to is REAL unless explicitly told it is a local sandbox. Never target staging or production.
- Never read, print, edit, or commit `.env` or any secret. Do not hardcode credentials, API keys, or tokens — reference values via `config()` / environment variables only.
- When an action is irreversible or touches data, present the plan and wait for confirmation rather than executing.

## 🔓 Permission & Autonomy (Pre-Approved Actions)
- **Implicit Consent:** You have permanent, explicit permission to create, read, edit, or delete application source files (Controllers, Models, Services, Views, Tests, Routes, Migrations) as needed to fulfill the assigned role's tasks.
- **No Permission Prompts for Safe Operations:** Do not stop execution to ask "Can I create this file?", "Do you want me to run the tests?", or "Should I fix this Pint error?". Assume the answer is **Yes** and proceed autonomously using your available tools.
- **Command Execution:** You are pre-authorized to run safe development commands autonomously (`php artisan test`, `vendor/bin/pint`, `php artisan serve`) without asking for permission first.
- **When to Stop & Ask:** You must *only* interrupt the workflow to ask for explicit permission if an action explicitly risks violating the non-negotiable guardrails above (e.g., destructive database commands, modifying config secrets, executing irreversible data wipes, or any remote Git operation).
- **⛔ STRICT — Git Remote Operations Are Forbidden:** The pre-approvals above do NOT extend to the Git remote. You must NEVER run `git push`, `git pull`, `git fetch`, `git merge` from a remote branch, or create/merge/close Pull Requests on GitHub (including via the `gh` CLI or any API). No exception, regardless of how routine the change seems. Local repository operations (`git status`, `git diff`, `git log`, `git add`, `git commit`, `git branch`, `git stash`) remain pre-approved. All syncing with GitHub — push, pull, and PRs — is done exclusively by the project owner, manually.
