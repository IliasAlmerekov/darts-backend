#!/usr/bin/env sh
# Fails when a shell script, a Dockerfile, or a file under app/docker/ would be
# checked out with CRLF. The runtime image copies these files as they are, and a
# CRLF start-container.sh makes the container exit with "not found" (#57).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT_DIR}"

files="$(git ls-files 'app/docker/*' '*.sh' '*Dockerfile*')"
if [ -z "${files}" ]; then
    echo "No image files found. Run this script inside the git checkout."
    exit 1
fi

# check-attr prints "<path>: eol: <value>"; every file needs eol=lf so that
# core.autocrlf=true cannot add CR on checkout.
not_lf="$(printf '%s\n' "${files}" | git check-attr --stdin eol | sed -n 's/^\(.*\): eol: \(.*\)$/\1 \2/p' | grep -v ' lf$' || true)"
# A file committed with CRLF keeps it no matter what .gitattributes says.
crlf_in_index="$(printf '%s\n' "${files}" | xargs git ls-files --eol -- | grep '^i/crlf' || true)"

if [ -n "${not_lf}" ]; then
    echo "These files need 'eol=lf' in .gitattributes (path, current eol):"
    printf '%s\n' "${not_lf}"
fi
if [ -n "${crlf_in_index}" ]; then
    echo "These files are committed with CRLF. Run 'git add --renormalize .' and commit:"
    printf '%s\n' "${crlf_in_index}"
fi
if [ -n "${not_lf}" ] || [ -n "${crlf_in_index}" ]; then
    exit 1
fi

echo "Image files are checked out with LF."
