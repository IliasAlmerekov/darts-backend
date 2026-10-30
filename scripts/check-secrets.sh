#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

PATTERN='AKIA[0-9A-Z]{16}|ghp_[0-9A-Za-z]{36}|glpat-[0-9A-Za-z\-_]{20,}|-----BEGIN (RSA|OPENSSH|EC|DSA|PRIVATE) KEY-----|APP_SECRET=[^[:space:]]{8,}|API_KEY=[^[:space:]]{8,}'

echo "Scanning tracked files for possible secrets..."

git ls-files > /dev/null

found=0
while IFS= read -r file; do
    matches=$(grep -En "${PATTERN}" "${file}" || true)
    while IFS= read -r match; do
        [ -n "${match}" ] || continue
        match=${match%$'\r'}
        content=${match#*:}
        if [[ ${file} == app/.env.prod.example && ${content} == "APP_""SECRET=CHANGE_ME_32_CHARS_MIN" ]] ||
           [[ ${file} == scripts/check-runtime-image.sh && ${content} == '    -e APP_'"SECRET=check-runtime-image-secret "'\' ]] ||
           [[ ${file} == scripts/check-secrets.sh && ${content} == "PATTERN='"* ]]; then
            continue
        fi
        printf '%s\n-- %s\n' "${match}" "${file}"
        found=1
    done <<< "${matches}"
done < <(git ls-files | grep -Ev '^(app/vendor/|app/public/bundles/|app/var/)')

if [ 0 -eq "${found}" ]; then
    echo "No obvious secrets found in tracked files."
    exit 0
fi

echo "Potential secrets detected. Review the lines above before pushing."
exit 1
