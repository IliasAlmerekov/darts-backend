#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

PATTERN='AKIA[0-9A-Z]{16}|ghp_[0-9A-Za-z]{36}|glpat-[0-9A-Za-z\-_]{20,}|-----BEGIN (RSA|OPENSSH|EC|DSA|PRIVATE) KEY-----|APP_SECRET=[^[:space:]]{8,}|API_KEY=[^[:space:]]{8,}'

echo "Scanning tracked files for possible secrets..."

found=0
while IFS= read -r file; do
    if rg -n "${PATTERN}" "${file}" >/dev/null; then
        rg -n "${PATTERN}" "${file}"
        echo "-- ${file}"
        found=1
    fi
done < <(git ls-files | rg -v '^(app/vendor/|app/public/bundles/|app/var/)')

if [ 0 -eq "${found}" ]; then
    echo "No obvious secrets found in tracked files."
    exit 0
fi

echo "Potential secrets detected. Review the lines above before pushing."
exit 1
