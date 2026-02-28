#!/usr/bin/env bash
set -euo pipefail

REPO=""
BRANCH="main"
APPLY="false"
STRICT="true"

CHECKS=("Phase5 Verification / verify")

print_usage() {
	cat <<'USAGE'
Usage:
  bash scripts/configure-branch-protection-required-checks.sh [options]

Options:
  --repo <owner/repo>     GitHub repository. Defaults to origin remote.
  --branch <branch>       Branch to configure (default: main).
  --check <context>       Required status check context. Can be repeated.
  --strict <true|false>   Require branch to be up-to-date before merge (default: true).
  --apply                 Apply changes to GitHub (default is dry-run).
  --help                  Show this help.

Examples:
  bash scripts/configure-branch-protection-required-checks.sh
  bash scripts/configure-branch-protection-required-checks.sh --check "Phase5 Verification / verify" --apply
  GITHUB_TOKEN=... bash scripts/configure-branch-protection-required-checks.sh --repo Shadowgar/ORAS-Tickets --branch main --apply
USAGE
}

parse_repo_from_origin() {
	local remote_url
	remote_url="$(git config --get remote.origin.url 2>/dev/null || true)"
	if [[ -z "$remote_url" ]]; then
		return 1
	fi

	if [[ "$remote_url" =~ github\.com[:/]([^/]+)/([^/.]+)(\.git)?$ ]]; then
		echo "${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
		return 0
	fi

	return 1
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--repo)
			REPO="${2:-}"
			shift 2
			;;
		--branch)
			BRANCH="${2:-}"
			shift 2
			;;
		--check)
			CHECKS+=("${2:-}")
			shift 2
			;;
		--strict)
			STRICT="${2:-}"
			shift 2
			;;
		--apply)
			APPLY="true"
			shift
			;;
		--help|-h)
			print_usage
			exit 0
			;;
		*)
			echo "Unknown option: $1" >&2
			print_usage
			exit 1
			;;
	esac
done

if ! command -v jq >/dev/null 2>&1; then
	echo "jq is required." >&2
	exit 1
fi

if [[ -z "$REPO" ]]; then
	if ! REPO="$(parse_repo_from_origin)"; then
		echo "Unable to infer --repo from git remote.origin.url. Pass --repo owner/repo." >&2
		exit 1
	fi
fi

if [[ "$STRICT" != "true" && "$STRICT" != "false" ]]; then
	echo "--strict must be true or false." >&2
	exit 1
fi

# Deduplicate and drop empty checks.
declare -A seen=()
unique_checks=()
for check in "${CHECKS[@]}"; do
	check="$(printf '%s' "$check" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
	if [[ -z "$check" ]]; then
		continue
	fi
	if [[ -z "${seen[$check]:-}" ]]; then
		seen[$check]=1
		unique_checks+=("$check")
	fi
done

if [[ ${#unique_checks[@]} -eq 0 ]]; then
	echo "At least one required check must be provided." >&2
	exit 1
fi

checks_json="$(printf '%s\n' "${unique_checks[@]}" | jq -R . | jq -s .)"
payload="$(jq -n --argjson strict "$STRICT" --argjson contexts "$checks_json" '{strict:$strict,contexts:$contexts}')"

base_url="https://api.github.com/repos/${REPO}/branches/${BRANCH}"
status_checks_url="${base_url}/protection/required_status_checks"
protection_url="${base_url}/protection"

echo "Repo:    $REPO"
echo "Branch:  $BRANCH"
echo "Strict:  $STRICT"
echo "Checks:"
for check in "${unique_checks[@]}"; do
	echo "  - $check"
done

if [[ "$APPLY" != "true" ]]; then
	echo
echo "DRY RUN: no API calls made."
	echo "PATCH $status_checks_url"
	echo "Payload:"
	echo "$payload" | jq .
	exit 0
fi

if [[ -z "${GITHUB_TOKEN:-}" ]]; then
	echo "GITHUB_TOKEN must be set when using --apply." >&2
	exit 1
fi

headers=(
	-H "Accept: application/vnd.github+json"
	-H "Authorization: Bearer ${GITHUB_TOKEN}"
	-H "X-GitHub-Api-Version: 2022-11-28"
)

# Ensure branch protection exists before patching required checks.
tmp_protection="$(mktemp)"
http_code="$(curl -sS -o "$tmp_protection" -w "%{http_code}" "${headers[@]}" "$protection_url")"
if [[ "$http_code" != "200" ]]; then
	echo "Branch protection is not available on ${REPO}:${BRANCH} (HTTP $http_code)." >&2
	echo "Create baseline branch protection in GitHub UI first, then re-run this script with --apply." >&2
	echo "Response:" >&2
	cat "$tmp_protection" >&2
	rm -f "$tmp_protection"
	exit 1
fi
rm -f "$tmp_protection"

tmp_response="$(mktemp)"
http_code="$(curl -sS -o "$tmp_response" -w "%{http_code}" -X PATCH "${headers[@]}" "$status_checks_url" -d "$payload")"
if [[ "$http_code" != "200" ]]; then
	echo "Failed to update required status checks (HTTP $http_code)." >&2
	echo "Response:" >&2
	cat "$tmp_response" >&2
	rm -f "$tmp_response"
	exit 1
fi

echo "Updated required status checks successfully."
echo "API response:"
cat "$tmp_response" | jq '{strict,contexts}'
rm -f "$tmp_response"
