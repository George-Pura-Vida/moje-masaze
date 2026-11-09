#!/usr/bin/env bash
# Moje masaze: safe and CI-gated GitHub auto deploy via WebKitty Scheduled Tasks.
# The Git checkout MUST be the public document root. No banking or user data touched.
set -Eeuo pipefail
umask 077
export PATH="/usr/local/cpanel/3rdparty/bin:/usr/local/bin:/usr/bin:/bin:$PATH"

ROOT="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
PRIVATE="$(dirname "$ROOT")/moje-masaze-private"
if printenv MASSAGE_PRIVATE_DIR >/dev/null; then PRIVATE="$(printenv MASSAGE_PRIVATE_DIR)"; fi
REMOTE_REPO="https://github.com/George-Pura-Vida/moje-masaze.git"
GITHUB_API="https://api.github.com/repos/George-Pura-Vida/moje-masaze/actions/workflows/test.yml/runs?branch=main&per_page=30"

say() { printf '[%s] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*"; }
fail() { say "ERROR: $*" >&2; exit 1; }

# Prevent accidental writes to public webroot and concurrent cron invocations.
[[ "$PRIVATE" == /* ]] || fail "MASSAGE_PRIVATE_DIR must be absolute"
[[ "$PRIVATE" != "$ROOT" && "$PRIVATE" != "$ROOT/"* ]] || fail "Private data directory is inside the website"
if [[ ! -d "$PRIVATE" ]]; then
  mkdir -p -- "$PRIVATE" || fail "Cannot create private directory"
  chmod 700 -- "$PRIVATE" || fail "Cannot secure private directory"
fi
[[ -w "$PRIVATE" ]] || fail "Private directory is not writable"
command -v flock >/dev/null || fail "flock is unavailable"
exec 9>"$PRIVATE/auto-deploy.lock"
flock -n 9 || exit 0

[[ -d "$ROOT/.git" ]] || fail "Public directory is not a Git checkout: do not use this cron job"
command -v git >/dev/null || fail "Git binary is unavailable"
command -v curl >/dev/null || fail "cURL binary is unavailable"
PHP_BIN=""
for candidate in /usr/bin/php84 /usr/bin/php83 /usr/bin/php82; do
  if [[ -x "$candidate" ]]; then PHP_BIN="$candidate"; break; fi
done
if [[ -z "$PHP_BIN" ]]; then PHP_BIN="$(command -v php || true)"; fi
[[ -n "$PHP_BIN" ]] || fail "PHP CLI is unavailable"

origin="$(git -C "$ROOT" remote get-url origin 2>/dev/null || true)"
case "$origin" in
  "$REMOTE_REPO"|"https://github.com/George-Pura-Vida/moje-masaze"|"git@github.com:George-Pura-Vida/moje-masaze.git") ;;
  *) fail "Unexpected Git origin; refusing to pull" ;;
esac
branch="$(git -C "$ROOT" symbolic-ref --quiet --short HEAD 2>/dev/null || true)"
[[ "$branch" == "main" ]] || fail "Checked-out branch is not main"

# Never overwrite manual fixes made on hosting.
if ! git -C "$ROOT" diff --quiet || ! git -C "$ROOT" diff --cached --quiet; then
  fail "Locally modified tracked files require manual review"
fi

git -C "$ROOT" fetch --quiet --no-tags origin refs/heads/main || fail "GitHub fetch failed"
remote_sha="$(git -C "$ROOT" rev-parse FETCH_HEAD)"
current_sha="$(git -C "$ROOT" rev-parse HEAD)"
if [[ "$remote_sha" == "$current_sha" ]]; then exit 0; fi
git -C "$ROOT" merge-base --is-ancestor "$current_sha" "$remote_sha" \
  || fail "Update is not a fast-forward; manual review required"

# Only deploy a commit after its precise GitHub Actions test run succeeds.
api_result="$(curl --fail --silent --show-error --location \
  --connect-timeout 8 --max-time 18 \
  -H 'Accept: application/vnd.github+json' \
  -H 'User-Agent: Moje-Masaze-WebKitty-Cron' \
  "$GITHUB_API")" || fail "Cannot verify GitHub CI"

if ! printf '%s' "$api_result" | "$PHP_BIN" -r '
  $data = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($data) || !is_array($data["workflow_runs"] ?? null)) exit(2);
  foreach ($data["workflow_runs"] as $run) {
    if (($run["head_sha"] ?? "") === ($argv[1] ?? "")) {
      exit(($run["status"] ?? "") === "completed" && ($run["conclusion"] ?? "") === "success" ? 0 : 1);
    }
  }
  exit(3);
' "$remote_sha"; then
  say "SKIPPED: GitHub CI not green for $(printf '%.12s' "$remote_sha")"
  exit 0
fi

# Works only if the checkout itself serves the site, as configured for this subdomain.
git -C "$ROOT" merge --ff-only --quiet "$remote_sha" || fail "Fast-forward merge failed"
new_sha="$(git -C "$ROOT" rev-parse HEAD)"
[[ "$new_sha" == "$remote_sha" ]] || fail "Unexpected deployed commit"
say "DEPLOYED $(printf '%.12s' "$current_sha") -> $(printf '%.12s' "$new_sha")"
