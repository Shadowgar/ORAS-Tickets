# QuickBooks Intuit Security Compliance Notes

Last updated: 2026-02-28

## Scope
This document maps ORAS Tickets QuickBooks integration controls to Intuit security requirements for OAuth and token handling.

Primary source:
- https://developer.intuit.com/app/developer/qbo/docs/go-live/publish-app/security-requirements

Supporting source:
- https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-2.0
- https://developer.intuit.com/app/developer/qbo/docs/develop/authentication-and-authorization/oauth-openid-discovery-doc

## Requirement Mapping

1. OAuth state parameter and CSRF protection
- Requirement: OAuth authorization requests must include and validate `state`.
- Implementation:
  - `Module::handle_oauth_start()` generates random state and stores transient ownership.
  - `Module::handle_oauth_callback()` validates state, deletes transient, and rejects mismatch.

2. Sensitive endpoint behavior for URL token/code parameters
- Requirement: endpoints receiving sensitive URL params should avoid rendering sensitive data and should redirect.
- Implementation:
  - OAuth callback endpoint uses server-side exchange and redirects back to settings notices.
  - No token/code values are rendered in callback responses.

3. Encrypt refresh token and realm ID in persistent storage
- Requirement: encrypt tokens and store securely.
- Implementation:
  - `Settings::prepare_for_storage()` encrypts sensitive fields before persistence.
  - `Settings::hydrate_from_storage()` decrypts on read for runtime use.
  - Encrypted fields: `client_secret`, `access_token`, `refresh_token`, `realm_id`.

4. Encryption key separation
- Requirement: encryption key must be separate from encrypted data.
- Implementation:
  - Production guardrail requires explicit `ORAS_TICKETS_QBO_AES_KEY` constant in `wp-config.php` before production OAuth connect.
  - `Module::get_production_security_error()` blocks production connect when missing.
  - Sandbox/dev can use fallback keying from WordPress auth constants for local development continuity.

5. Avoid logging sensitive customer data/tokens
- Requirement: do not expose credentials/tokens in logs.
- Implementation:
  - `QuickBooks_Logger::redact()` masks token/secret/authorization/realm keys.
  - Removed raw QuickBooks API/token response body logging from error paths.

6. HTTPS in production OAuth flow
- Requirement: secure transport and HTTPS callback endpoints.
- Implementation:
  - Production guardrail checks redirect URI scheme is `https://` before OAuth start.
  - Sandbox mode remains available for local non-HTTPS testing.

7. Authentication error scenario handling (audit prompts)
- Requirement: app should handle key auth failure scenarios deterministically.
- Implementation:
  - Access token failures:
    - API 401 triggers refresh attempt; on failure, emits labeled `Auth Error Access`.
  - Refresh token failures:
    - token endpoint refresh failures (`invalid_grant`/401 on refresh grant) emit labeled `Auth Error Refresh` and clear stale tokens.
  - Invalid grant failures:
    - authorization-code token exchange `invalid_grant` emits labeled `Auth Error Grant`.
  - CSRF failures:
    - callback state missing/mismatch emits labeled `CSRF Error` and blocks exchange.
  - Deterministic callback guard verification script covers missing-state/missing-grant/state-failed/state-owner-mismatch branches.

8. API error and troubleshooting observability
- Requirement: app should handle API fault classes and preserve support trace identifiers.
- Implementation:
  - Validation/syntax/API fault responses (`Fault.Error`) are surfaced as deterministic WP_Error objects with HTTP status code-specific error IDs.
  - Retriable classification is explicit:
    - retriable: transport errors, `429`, `5xx`
    - non-retriable: `400` validation/syntax faults, invalid JSON, auth grant failures.
  - `intuit_tid` response header is captured in API metadata and stored in order sync metadata/audit contexts for support troubleshooting.
  - Deterministic runtime evidence script:
    - `wp eval-file scripts/qbo-api-error-matrix-tests.php`

9. OAuth/OpenID discovery requirements alignment
- Requirement: OAuth/OpenID metadata and endpoint behavior should align with Intuit discovery documentation.
- Implementation:
  - Current flow uses Intuit-documented OAuth2 authorize/token endpoints and accounting scope (`com.intuit.quickbooks.accounting`) only.
  - No OpenID user identity scopes are requested.
  - Callback behavior follows strict state validation and redirect-only handling.
  - For audit packets, include a snapshot of configured authorize/token endpoints and callback URL from runtime settings.

## Operational Requirements (Non-Code)
These must be managed by deployment/ops:
- Define strong `ORAS_TICKETS_QBO_AES_KEY` in production `wp-config.php`.
- Rotate encryption key only with planned token re-authentication procedure.
- Restrict admin capability (`oras_tickets_manage_settings`) to trusted operators.
- Ensure production site uses HTTPS end-to-end.
- Review server/application log retention and access policy.

## Validation Commands
- `wp oras-tickets qbo test-connection`
- `wp oras-tickets qbo preview-order <order_id> --format=json`
- `wp oras-tickets qbo sync-order <order_id>`
- `wp oras-tickets qbo reconcile-report --from=<YYYY-MM-DD> --to=<YYYY-MM-DD> --format=json`
- `wp eval-file scripts/qbo-split-calculator-tests.php`
- `wp eval-file scripts/qbo-safety-controls-tests.php`
- `wp eval-file scripts/qbo-reconciliation-tests.php`
- `wp eval-file scripts/qbo-api-error-matrix-tests.php`
- `wp eval-file scripts/qbo-oauth-callback-tests.php`
