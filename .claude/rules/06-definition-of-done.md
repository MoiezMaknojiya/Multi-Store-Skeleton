# Definition of Done & Verification Loops

## Definition of Done
1. Relevant tests pass (`php artisan test`) — and for changes touching UI or user flows, the Dusk browser suite passes too (`php artisan dusk`, per the Testing convention).
2. `vendor/bin/pint --dirty --format agent` reports no remaining issues.
3. No debug leftovers (`dd()`, `dump()`, `ray()`, `var_dump()`, stray `Log::debug`).
4. Any new migration is reversible (`down()` implemented) and runs cleanly on a local DB.
5. New env keys are added to `.env.example`.
6. Live/localhost verification passes (see **Post-Tooling Live Verification** below).

## Remediation Loop (Fix → Verify Cycle)

Work is not finished when the first implementation pass is done — it is finished when **every** item in the Definition of Done passes. If any check fails, the process loops back into development rather than stopping.

**Routing — failures go back to the role that owns them:**
- A **frontend failure** (broken component, layout/CSS, client-side logic, accessibility, prop/contract mismatch on the client) goes back to the 🎨 **Frontend Engineer**, who re-enters its working phase and fixes it.
- A **backend failure** (controller/service/action logic, validation, migration, model, server-side API contract) goes back to the ⚙️ **Backend Engineer**, who re-enters its working phase and fixes it.
- A failure that spans both (e.g. the API shape and the client's expectation disagree) goes to both, with the 🏛️ Architect arbitrating which side is correct when it is ambiguous.

**Handoff note — every failure is passed back with a written bug report containing:**
- File name and line reference
- What was expected vs. what actually happened (failing assertion, error message, or screenshot/description)
- Which Definition of Done item failed
- Reproduction steps, if any

**The cycle:**
1. The QA Tester runs the Definition of Done checks.
2. On any failure, QA writes the handoff note and routes it to the correct Engineer (frontend or backend).
3. That Engineer fixes **only** the reported issue, stays within its role's scope, and re-runs `vendor/bin/pint --dirty --format agent` on the changed files.
4. QA re-verifies the **full** Definition of Done.
5. Repeat until every item passes. Only then is the work done.

Never silence a failing test, delete an assertion, or weaken a check to make the cycle pass — fix the underlying cause.

## Post-Tooling Live Verification

Passing tools is not the same as passing reality — always confirm the feature works where it will actually run.

- After running Laravel Pint or any other testing tool/package (`php artisan test`, static analysis, etc.) and having it report success, perform live or localhost testing of the affected feature (e.g. `php artisan serve` + manual/browser verification of the actual endpoints, pages, or flows touched).
- If live/localhost testing surfaces any issue, do **not** stop or report success. Route the issue back to whichever Engineer owns it — 🎨 **Frontend Engineer** or ⚙️ **Backend Engineer** — using the same handoff-note format as the Remediation Loop (file, line, expected vs. actual, repro steps).
- The responsible Engineer fixes the issue, re-runs Pint and the test suite, and live/localhost testing is performed again.
- Continue this loop — tools pass → live test → fix if needed → tools pass again → live test again — until live/localhost testing succeeds with no remaining issues. Only then is the work done.
