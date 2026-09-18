# Registration Desk M1A.2 Qualification Design

## Scope

M1A.2 changes only the Registration Desk qualification runner, its host-side guards, newly introduced formatting diagnostics, and qualification documentation. The five M1A.1 backend corrections remain unchanged. Production access, deployment, later milestones, and payment behavior remain out of scope.

## Designated-project ownership

The runner will use `/home/rocco/projects/oras-wp-env` as the wp-env source project. That directory supplies the effective `.wp-env.json`, installed wp-env 10.39.0 code, generated install path, base Compose file, and Compose project identity. The feature worktree supplies only the plugin and test-harness files mounted into the disposable test services.

The runner will not infer ownership from the CLI executable path. It will resolve the install path through wp-env while its process current directory is the designated project, derive the Compose project from that resolved path, and verify the resulting container labels and configuration paths.

## Test-only mount overlay

The designated project maps the primary ORAS Tickets checkout into both development and test services. M1A.2 must leave the development mapping and database untouched. A temporary Docker Compose overlay will replace/add mounts only on `tests-wordpress` and `tests-cli`:

- feature-worktree `oras-tickets/` to `wp-content/plugins/oras-tickets`;
- feature-worktree `scripts/` to `wp-content/oras-qbo-tests`;
- the Registration Desk and Intuit guards to their test-only must-use-plugin destinations.

The runner will combine the designated project's generated base Compose file with that temporary overlay and start only `tests-mysql`, `tests-wordpress`, and `tests-cli`. It will not call `wp-env start`, because that command manages both ordinary development and test services.

Before starting test services, the runner will snapshot ordinary development container identity, running state, mounts, and database volume identity. It will also snapshot the full test-service configuration and refuse pre-existing overrides it cannot reproduce. After qualification it will verify both snapshots are restored.

## Fail-closed verification

Before any WordPress mutation, the runner will verify:

- designated source project and effective configuration path;
- wp-env version and resolved install/Compose paths;
- unique Compose project and test service identities;
- `tests-wordpress` database name and host;
- the disposable marker, with an explicit one-time initialization permitted only after every other isolation check passes;
- exact feature plugin, scripts, and guard mounts;
- mounted plugin Git identity matching the committed feature worktree;
- external HTTP, Intuit, and mail guards.

A mismatched marker or unsafe database stops the run. Every WordPress command before marker verification skips ordinary plugins and themes and proves ORAS Tickets did not bootstrap. If the verified designated test database has no marker, an explicit one-time initialization mode may add the project-bound marker only after project, database volume, URL, guards, mounts, and code identity have passed. Ordinary verification and qualification never create a marker implicitly.

## Execution and cleanup

The existing legacy and authoritative HPOS phases remain unchanged after the environment boundary. Original test HPOS, compatibility-sync, ORAS settings, and plugin activation values will be restored on every exit. Conflicting pre-existing ORAS Tickets copies may be temporarily deactivated after their original activation state is captured. Test-only fault injection and sessions retain their existing cleanup behavior.

On exit, the runner will restore the designated test services to the base Compose mounts and their original running or stopped state. It will remove only its temporary Compose overlay and Docker client directory. It will not delete containers, volumes, databases, or the separate worktree-derived runtime left by M1A.1.

## Alternatives rejected

Changing the designated source project's `.wp-env.override.json` and running `wp-env start` was rejected because wp-env may reconfigure both development and test services. Copying the feature plugin into an existing container was rejected because it would not establish the required host mount and code identity. A separate worktree-derived wp-env project was rejected because it is the acceptance defect being corrected.
