#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT HUP INT TERM
mkdir -p "$tmp/repo/scripts"
cp "$root/scripts/check-secrets.sh" "$tmp/repo/scripts/"
cd "$tmp/repo"
git init -q
printf 'API_KEY=%s\n' 'abcdefghijklmnopqrstuvwxyz123456' > secret.txt
git add secret.txt
if bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan accepted a tracked secret" >&2; exit 1
fi
printf 'ordinary content\n' > secret.txt
if ! bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan rejected a clean repository" >&2; exit 1
fi
mkdir -p app scripts
printf 'APP_''SECRET=CHANGE_ME_32_CHARS_MIN\n' > app/.env.prod.example
git add app/.env.prod.example
if ! bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan rejected the documented placeholder" >&2; exit 1
fi
printf 'APP_''SECRET=CHANGE_ME_32_CHARS_MIN_extra\n' > app/.env.prod.example
if bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan accepted a changed placeholder" >&2; exit 1
fi
printf 'ordinary content\n' > app/.env.prod.example
printf '%s\n' '    -e APP_''SECRET=check-runtime-image-secret \' > scripts/check-runtime-image.sh
git add scripts/check-runtime-image.sh
if ! bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan rejected the runtime test placeholder" >&2; exit 1
fi
printf '%s\n' '    -e APP_''SECRET=check-runtime-image-secret-extra \' > scripts/check-runtime-image.sh
if bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan accepted a changed runtime placeholder" >&2; exit 1
fi
rm -rf .git
if bash scripts/check-secrets.sh > /dev/null 2>&1; then
    echo "secret scan accepted a directory outside Git" >&2; exit 1
fi
cd "$tmp"
mkdir -p app
printf 'FROM scratch\n' > app/Dockerfile
printf '%s\n' '  - docker build -f app/Dockerfile --target runtime .' > .gitlab-ci.yml
if ! sh "$root/scripts/check-gitlab-dockerfiles.sh"; then
    echo "Dockerfile check rejected a valid file" >&2; exit 1
fi
printf '%s\n' '  - |' '    docker build \' '      -f app/Dockerfile --target runtime .' > .gitlab-ci.yml
rm app/Dockerfile
if sh "$root/scripts/check-gitlab-dockerfiles.sh" > /dev/null 2>&1; then
    echo "Dockerfile check accepted a missing file" >&2; exit 1
fi
printf '%s\n' '  - >' '    docker build' '    -f app/Dockerfile --target runtime .' > .gitlab-ci.yml
if sh "$root/scripts/check-gitlab-dockerfiles.sh" > /dev/null 2>&1; then
    echo "Dockerfile check accepted a missing file in a folded command" >&2; exit 1
fi
