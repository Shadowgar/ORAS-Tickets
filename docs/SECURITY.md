# Security Policy

Last updated: 2026-02-25

## Supported Versions

| Version | Supported |
| --- | --- |
| `main` (current internal deployment line) | Yes |
| older snapshots/branches | No |

## Reporting a Vulnerability

This is an internal ORAS project.

1. Report vulnerabilities privately to the ORAS maintainers through internal channels.
2. Include:
- affected component/file
- reproduction steps
- impact assessment
- proposed mitigation if available
3. Do not open public issues with exploit details.

## Security Requirements for Code Changes

- Enforce capability checks for admin actions.
- Verify nonces for privileged form/AJAX/admin-post flows.
- Sanitize input and escape output for all user-derived content.
- Keep deterministic data models and avoid hidden side effects.
- Run `composer phpcs` and `composer phpstan` before merge.

## Disclosure and Fix Handling

- Target initial triage within 2 business days.
- Prioritize fixes by impact (RCE/data leak > privilege escalation > XSS/CSRF > integrity bugs).
- Backport only when an older internal deployment line is still active.
