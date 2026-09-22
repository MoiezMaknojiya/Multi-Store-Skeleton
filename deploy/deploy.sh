#!/usr/bin/env bash
#
# Puts the last commit live (deploy/README.md). From the project folder, in Git Bash:
#
#   bash deploy/deploy.sh deploy@SERVER_IP https://app.example.com           # every release
#   bash deploy/deploy.sh deploy@SERVER_IP https://app.example.com --first   # the very first: makes the super admin
#
# Only committed work goes up, with the assets built from it here. The server installs PHP's packages without
# the development ones, so Dusk's sign-in route (/_dusk/login) cannot exist there — and the checks at the end
# prove that it does not, along with the rest of the site.
set -euo pipefail

USAGE="Usage: bash deploy/deploy.sh deploy@SERVER_IP https://app.example.com [--first]"
TARGET="${1:-}"
URL="${2:-}"
FIRST="${3:-}"
URL="${URL%/}"
[[ "$TARGET" =~ ^deploy@[A-Za-z0-9.:-]+$ ]] || { echo "$USAGE" >&2; exit 1; }
[[ "$URL" =~ ^https?://([a-z0-9.-]+)$ ]] || { echo "$USAGE" >&2; exit 1; }
HOST_NAME="${BASH_REMATCH[1]}"
[[ -z "$FIRST" || "$FIRST" == --first ]] || { echo "$USAGE" >&2; exit 1; }
SERVER="${TARGET#*@}"

KEY="${SIGNAGE_DEPLOY_KEY:-$HOME/.ssh/signage_deploy}"
[[ -f "$KEY" ]] || { echo "The deploy key is missing: $KEY" >&2; exit 1; }
SSH_OPTS=(-i "$KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)

cd "$(git rev-parse --show-toplevel)"
if [[ -n "$(git status --porcelain)" ]]; then
    echo "There are uncommitted changes. Only the last commit is deployed: commit (or stash) them first." >&2
    exit 1
fi

STAMP="$(date -u +%Y%m%d%H%M%S)"
COMMIT="$(git rev-parse --short HEAD)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "== Building the assets"
npm run build
[[ -f public/build/manifest.json ]] || { echo "The build left no public/build/manifest.json." >&2; exit 1; }

echo "== Packing $COMMIT"
git archive --format=tar -o "$WORK/$STAMP.tar" HEAD
tar -rf "$WORK/$STAMP.tar" public/build
gzip "$WORK/$STAMP.tar"
cp deploy/server/release.sh "$WORK/$STAMP.sh"

echo "== Uploading"
scp -q "${SSH_OPTS[@]}" "$WORK/$STAMP.tar.gz" "$WORK/$STAMP.sh" "$TARGET:/var/www/signage/releases/"

echo "== Installing it on the server"
ssh "${SSH_OPTS[@]}" "$TARGET" "bash /var/www/signage/releases/$STAMP.sh $STAMP $FIRST"

echo "== Checking $URL"
# Straight to the server's address, so the checks work even before the domain has reached every DNS.
RESOLVE=()
if [[ "$SERVER" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
    PORT=443
    [[ "$URL" == http://* ]] && PORT=80
    RESOLVE=(--resolve "$HOST_NAME:$PORT:$SERVER")
fi
failed=0
check() {
    local path="$1" expected="$2" code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${RESOLVE[@]}" "$URL$path" || true)"
    if [[ "$code" == "$expected" ]]; then
        echo "   ok    $path  $code"
    else
        echo "   FAIL  $path  $code (expected $expected)"
        failed=1
    fi
}
check /up 200
check /login 200
# Dusk signs anybody in with no password on this route wherever it exists: live, it must not.
check /_dusk/login/1 404
check /_dusk/user 404

if [[ $failed -ne 0 ]]; then
    echo "The release is live, but a check failed: look at it before anybody uses the site." >&2
    exit 1
fi
echo "Live: $URL ($COMMIT)"
