#!/usr/bin/env bash
# Tests scripts/worktree.sh in temporary repositories. gh, npm and docker are stubs:
# gh answers from JSON fixtures, docker logs its arguments.
set -euo pipefail
# A git hook (pre-push in a worktree) exports GIT_DIR and friends; unset them so every git
# call below works on the temporary repositories, never on the repository under test.
unset $(git rev-parse --local-env-vars)

root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

fail() { echo "test-worktree: $*" >&2; exit 1; }

export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

mkdir -p "$tmp/bin" "$tmp/prs"
cat > "$tmp/bin/gh" <<'EOF'
#!/usr/bin/env bash
# gh pr list -R <slug> --head <branch> ... -q <jq>: answers from $PRS/<branch>.json.
head="" query="."
while [ $# -gt 0 ]; do
  case $1 in
    --head) head=$2; shift ;;
    -q) query=$2; shift ;;
  esac
  shift
done
file="$PRS/${head//\//_}.json"
# A <branch>.hook fixture runs while prune is between reading the tip and deleting it.
if [ -f "${file%.json}.hook" ]; then bash "${file%.json}.hook"; fi
[ -f "$file" ] || file=/dev/null
{ [ "$file" = /dev/null ] && echo '[]' || cat "$file"; } | jq -r "$query"
EOF
# docker logs its arguments and the worktrees that still exist at that moment.
printf '#!/bin/sh\necho "$* | worktrees: $(ls "%s/ws/worktrees" | tr "\\n" " ")" >> "%s/docker.log"\n' \
  "$tmp" "$tmp" > "$tmp/bin/docker"
printf '#!/bin/sh\nexit 1\n' > "$tmp/bin/npm"
chmod +x "$tmp/bin/"*
export PATH="$tmp/bin:$PATH" PRS="$tmp/prs"

# pr <branch> <state> <sha> [owner]: one PR in the branch's fixture.
pr() {
  local f="$tmp/prs/${1//\//_}.json"
  [ -f "$f" ] || echo '[]' > "$f"
  jq --arg s "$2" --arg o "$3" --arg w "${4:-acme}" \
    '. + [{state: $s, headRefOid: $o, headRepositoryOwner: {login: $w}}]' "$f" > "$f.new"
  mv "$f.new" "$f"
}

# A bare "GitHub" repo, and a main checkout whose push URL names github.com.
git init -q --bare -b main "$tmp/origin.git"
git init -q -b main "$tmp/ws/app"
cd "$tmp/ws/app"
mkdir -p scripts app
cp "$root/scripts/worktree.sh" scripts/
printf 'DATABASE_URL="mysql://root:pw@mysql:3306/shop_db?serverVersion=8.0.32"\n' > app/.env.test
git add -A
git commit -qm init
git remote add github "$tmp/origin.git"
git remote set-url --push github https://github.com/acme/app.git
git push -q "$tmp/origin.git" main
git fetch -q github
# Untracked, so only the main checkout counts as a backend: worktrees skip the PHP setup,
# and prune drops their test databases.
touch scripts/php.sh

wt() { scripts/worktree.sh "$@"; }

# new: the branch starts at the remote main, even when the local tracking ref is stale.
git clone -q "$tmp/origin.git" "$tmp/other"
(cd "$tmp/other" && git commit -q --allow-empty -m newer && git push -q origin main)
newer=$(git --git-dir="$tmp/origin.git" rev-parse main)
dir=$(wt new feat/1-fresh 2>/dev/null)
[ "$dir" = "$tmp/ws/worktrees/app-1" ] || fail "new printed '$dir'"
[ "$(git -C "$dir" rev-parse HEAD)" = "$newer" ] || fail "new did not start from the remote main"
git worktree remove "$dir" && git branch -D -q feat/1-fresh

# new: a failed setup removes the worktree and branch again.
(cd "$tmp/other" && touch package-lock.json && git add -A && git commit -qm lock && git push -q origin main)
if wt new feat/2-broken >/dev/null 2>&1; then fail "new succeeded although npm ci failed"; fi
[ ! -e "$tmp/ws/worktrees/app-2" ] || fail "new left the worktree of a failed setup"
! git rev-parse -q --verify refs/heads/feat/2-broken >/dev/null || fail "new left the branch of a failed setup"
: > "$tmp/docker.log"

# Worktrees for prune: <branch> <issue>, cut from main with one commit each.
mk() {
  git worktree add -q -b "$1" "$tmp/ws/worktrees/app-$2" main
  git -C "$tmp/ws/worktrees/app-$2" commit -q --allow-empty -m "$1"
  git rev-parse "refs/heads/$1"
}
merged_tip=$(mk feat/10-merged 10);   pr feat/10-merged MERGED "$merged_tip"
old_tip=$(mk feat/11-after 11);       pr feat/11-after MERGED "$old_tip"
git -C "$tmp/ws/worktrees/app-11" commit -q --allow-empty -m 'after the merge'
open_tip=$(mk feat/12-open 12);       pr feat/12-open MERGED "$open_tip"; pr feat/12-open OPEN "$open_tip"
dirty_tip=$(mk feat/13-dirty 13);     pr feat/13-dirty MERGED "$dirty_tip"
echo wip > "$tmp/ws/worktrees/app-13/wip.txt"
fork_tip=$(mk feat/14-fork 14);       pr feat/14-fork MERGED "$fork_tip" someone
mk feat/15-nopr 15 >/dev/null
moving_tip=$(mk feat/18-moving 18); pr feat/18-moving MERGED "$moving_tip"
echo "git -C '$tmp/ws/worktrees/app-18' commit -q --allow-empty -m 'during prune'" > "$tmp/prs/feat_18-moving.hook"
git branch -q feat/16-loose main;     pr feat/16-loose MERGED "$(git rev-parse feat/16-loose)"
git branch -q feat/17-loose-open main; pr feat/17-loose-open OPEN "$(git rev-parse feat/17-loose-open)"

# prune runs from inside the worktree it removes.
out=$(cd "$tmp/ws/worktrees/app-10" && "$tmp/ws/app/scripts/worktree.sh" prune 2>&1) ||
  fail "prune failed: $out"

gone() { [ ! -e "$tmp/ws/worktrees/app-$1" ] || fail "prune kept worktree app-$1: $out"; }
kept() { [ -d "$tmp/ws/worktrees/app-$1" ] || fail "prune removed worktree app-$1: $out"; }
branch_gone() { ! git rev-parse -q --verify "refs/heads/$1" >/dev/null || fail "prune kept branch $1: $out"; }
branch_kept() { git rev-parse -q --verify "refs/heads/$1" >/dev/null || fail "prune removed branch $1: $out"; }

gone 10;  branch_gone feat/10-merged
kept 11;  branch_kept feat/11-after
kept 12;  branch_kept feat/12-open
kept 13;  branch_kept feat/13-dirty
kept 14;  branch_kept feat/14-fork
kept 15;  branch_kept feat/15-nopr
branch_kept feat/18-moving
branch_gone feat/16-loose
branch_kept feat/17-loose-open
grep -q 'DROP DATABASE IF EXISTS `shop_db_test_wt10`' "$tmp/docker.log" ||
  fail "prune did not drop the test database of app-10: $(cat "$tmp/docker.log")"
! grep 'shop_db_test_wt10' "$tmp/docker.log" | grep -q 'app-10' ||
  fail "prune dropped the test database of app-10 before removing the worktree"
[ "$(grep -c 'DROP DATABASE' "$tmp/docker.log")" = 2 ] ||
  fail "prune dropped a database of a kept worktree: $(cat "$tmp/docker.log")"

echo "worktree.sh tests passed"
