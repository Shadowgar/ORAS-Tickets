# QBO runner portability design

## Scope

Make the existing disposable QuickBooks integration runner prove the same
environment identity in a local checkout and in GitHub Actions. This change is
limited to the runner, its real-guard regression tests, the fixture registry's
portable marker assertion, and the Phase5 workflow. QuickBooks runtime PHP is
unchanged.

## Trust model

The runner derives its checkout from its own canonical path, then requires that
path to be the Git top level for the canonical ORAS Tickets remote and to contain
the immutable pre-QuickBooks baseline commit. Repository-owned `.wp-env.json`,
Docker configuration, Intuit HTTP blocker, `package.json`, `package-lock.json`,
and repo-local `wp-env` entry point are pinned by content and identity.

The Node executable may live at a platform-specific path, but its canonical
binary, version, and content hash must match the approved Node release. The
account home is obtained from the operating-system account record rather than
trusted from ambient `HOME`. The wp-env path uses its exact compatibility rule:
an existing canonical legacy MD5 directory or, otherwise, the tool's
descriptive directory derived from the checkout and the MD5 prefix. The Docker
Compose project name is normalized from that exact directory by Compose's
naming rule. Generated Compose content is normalized only for those derived
host-specific values before it is compared to an approved hash.

Container labels, names, mounts, database name/host/volume, loopback URLs,
checkout-specific database marker, QuickBooks sandbox/dry-run/disabled/empty
credential state, and the Intuit HTTP interception probe remain runtime gates.
The runner injects the already-verified marker into fixture scripts as a
required constant; arbitrary process environment does not select it.

## Environment handling

`ORAS_WP_ENV_DIR` is optional and otherwise may name only `.` or the exact
canonical checkout. `ORAS_WP_ENV_CMD` is optional and otherwise may name only
the checkout's npm-created `node_modules/.bin/wp-env` link, whose exact link
target, realpath, package version, and entry-point hash are verified. Traversal,
alternate paths, and redirected symlinks fail closed.

The three exact variables injected by the PHP setup action are accepted only as
`COMPOSER_PROCESS_TIMEOUT=0`, `COMPOSER_NO_INTERACTION=1`, and
`COMPOSER_NO_AUDIT=1`; all other `COMPOSER_*` variables and any other values are
rejected. The local non-interactive `GIT_PAGER=cat` value is also accepted, but
not copied to Git children. Shell, PHP, Node/npm, WP-CLI, Git, and Docker
startup/configuration overrides remain rejected.

Every wp-env, Docker, guard-PHP, and test-PHP child is launched through a
minimal explicit environment. No operator-provided command string is evaluated.

## Verification

The guard regression suite runs the real runner's static checks from the local
checkout and from a second Git worktree shaped like a hosted workspace. It also
exercises rejected path, repository, Compose, project/container, database,
marker, URL, QuickBooks credential, startup-variable, and HTTP-fall-through
cases. The normal environment-only and complete test modes retain all live
disposable-container checks.
