# Registration Desk Walk-In Finalization Bugfix Plan

## Scope

Fix the reproducible kiosk finalization failure without weakening canonical offering validation, station context validation, idempotency, transactional writes, or the Registration Desk's nonfinancial boundary. Work directly on `main`, preserve the owner's `.gitignore` edit, and qualify only in the disposable `~/projects/oras-wp-env` instance.

## Root cause to address

The desk offering endpoint exposes a canonical ticket as selectable after the event's admission date has passed. The server correctly rejects the eventual write with `oras_desk_wrong_date`, but the browser presents that expected eligibility rejection as a persistence failure and leaves the original final-submit controls actionable beneath the recovery card.

## Implementation sequence

1. Add failing checks for desk-only admission eligibility, full-screen recovery rendering, recovery restoration, and duplicate-submit prevention.
2. Add a desk presentation overlay that marks canonical offerings nonselectable outside the event's admission dates while retaining the canonical ticket identity and fingerprint.
3. Keep final server validation authoritative for stale screens and date rollover.
4. Refactor finalization recovery to replace the active screen, preserve the bound payload/request UUID, restore the same failure count after refresh, and expose only retry/manager actions until repeated failure permits confirmed abandonment.
5. Exercise successful Card, Cash, Check, and Unpaid writes plus family/one-day variants and forced transactional failures through the REST controller rather than service-only calls.
6. Verify rollback, idempotent lost-response retry, no financial/account side effects, and both Woo storage modes.
7. Capture the required browser screenshots, commit and push the exact tested tree, then run Phase5 and CodeQL against that commit.

## Completion evidence

- Focused static/PHP/JS checks pass.
- Guarded legacy and HPOS integration suites pass in `oras-wp-env`.
- Browser proof covers normal success, first/repeated failure, restored recovery, and successful same-request retry.
- Database evidence proves one operational record set on success and zero partial rows after injected rollback failures.
- Phase5 and CodeQL pass for the final pushed SHA.
