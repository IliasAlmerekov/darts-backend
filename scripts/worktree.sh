#!/usr/bin/env bash
# Git worktrees for issue branches, so parallel sessions never switch the main checkout.
#
#   scripts/worktree.sh new <type>/<issue>-<topic>
#       Cuts the branch from the latest main into ../worktrees/<repo>-<issue>, copies the
#       local env files from the main checkout and installs dependencies: npm packages, or
#       for the backend Composer packages plus a migrated test database of its own.
#       A failed setup removes the half-made worktree and branch again. Prints the path.
#   scripts/worktree.sh prune
#       Removes every worktree and local branch whose tip is the head commit of a merged
#       pull request from this repository and that no open PR uses. Keeps a worktree with
#       uncommitted changes, a branch with commits after the merge, and anything whose PR
#       is open, closed or missing. A removed backend worktree's test database is dropped.
#
# Tests: scripts/test-worktree.sh
set -euo pipefail

die() { echo "worktree.sh: $*" >&2; exit 1; }

common_dir=$(git rev-parse --path-format=absolute --git-common-dir)
main_dir=$(dirname "$common_dir")
repo=$(basename "$main_dir")
wt_root=$(dirname "$main_dir")/worktrees
remote=$(git remote -v | awk '/github\.com/ && /\(push\)/ { print $1; exit }')
[ -n "$remote" ] || die "no GitHub remote"
slug=$(git remote get-url --push "$remote" | sed -E 's#^.*github\.com[:/]##; s#\.git$##')
owner=${slug%%/*}
# Every git call runs from the main checkout, so prune can remove the worktree it was started in.
cd "$main_dir"

# Prints "merged" when the branch tip is the head commit of a merged PR opened from this
# repository and no open PR from it uses the branch; a commit added after a squash merge,
# a reused branch, or a fork PR with the same branch name never counts.
pr_verdict() {
  local branch=$1 tip=$2
  gh pr list -R "$slug" --head "$branch" --state all --json state,headRefOid,headRepositoryOwner \
    -q "[.[] | select(.headRepositoryOwner.login == \"$owner\")]
        | if any(.state == \"OPEN\") then \"open\"
          elif any(.state == \"MERGED\" and .headRefOid == \"$tip\") then \"merged\"
          else \"keep\" end" </dev/null
}

# Deletes the branch only if its tip is still the commit that was checked, so a commit
# made while prune runs survives.
delete_branch() {
  git update-ref -d "refs/heads/$1" "$2"
}

# The backend gives each worktree the test database <name>_test_wt<issue> (scripts/php.sh).
drop_test_db() {
  local issue=$1 base
  [ -f "$main_dir/scripts/php.sh" ] || return 0
  base=$(sed -nE 's#^DATABASE_URL="?[^"]*/([^/?"]+)[?"].*#\1#p' "$main_dir/app/.env.test" | tail -1)
  [ -n "$base" ] || { echo "no DATABASE_URL in app/.env.test" >&2; return 1; }
  docker exec darts-mysql-1 sh -c \
    "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`${base}_test_wt${issue}\`'" \
    </dev/null 2>&1 | grep -v 'Using a password' >&2 || true
}

setup() {
  local dir=$1 f
  for f in .env .env.local app/.env.local app/.env.test.local; do
    if [ -f "$main_dir/$f" ]; then cp "$main_dir/$f" "$dir/$f"; fi
  done
  if [ -f "$dir/package-lock.json" ]; then
    (cd "$dir" && npm ci --no-audit --no-fund) || return 1
  fi
  if [ -f "$dir/scripts/php.sh" ]; then
    (
      cd "$dir"
      scripts/php.sh composer install --no-interaction --no-progress &&
        scripts/php.sh php bin/console doctrine:database:create --env=test --if-not-exists &&
        scripts/php.sh php bin/console doctrine:migrations:migrate --env=test --no-interaction
    ) || return 1
  fi
}

new() {
  local branch=$1 issue dir
  issue=$(sed -nE 's#^[a-z]+/([0-9]+)-.+$#\1#p' <<<"$branch")
  [ -n "$issue" ] || die "branch must look like <type>/<issue>-<topic>, got: $branch"
  dir=$wt_root/$repo-$issue
  [ ! -e "$dir" ] || die "$dir already exists"
  git fetch -q "$remote" main
  git worktree add --no-track -b "$branch" "$dir" "$remote/main" >&2
  if ! setup "$dir" >&2; then
    git worktree remove --force "$dir"
    git branch -D "$branch" >/dev/null
    drop_test_db "$issue"
    die "setup failed; removed $dir and branch $branch, fix the cause and run new again"
  fi
  echo "$dir"
}

prune() {
  local dir="" branch line tip verdict issue
  git fetch -q --prune "$remote"
  while read -r line; do
    case $line in
      "worktree "*) dir=${line#worktree } ;;
      "branch "*)
        branch=${line#branch refs/heads/}
        [ "$dir" != "$main_dir" ] || continue
        tip=$(git rev-parse "refs/heads/$branch")
        verdict=$(pr_verdict "$branch" "$tip") || verdict="gh failed"
        if [ "$verdict" != merged ]; then
          echo "keep $dir ($branch): ${verdict}"
        elif [ -n "$(git -C "$dir" status --porcelain)" ]; then
          echo "keep $dir ($branch): uncommitted changes"
        elif ! git worktree remove "$dir"; then
          echo "keep $dir ($branch): git worktree remove failed"
        else
          issue=$(sed -nE 's#.*-([0-9]+)$#\1#p' <<<"$dir")
          if [ -n "$issue" ]; then drop_test_db "$issue"; fi
          if delete_branch "$branch" "$tip"; then
            echo "removed $dir and branch $branch"
          else
            echo "removed $dir; kept branch $branch: it moved during prune"
          fi
        fi
        ;;
    esac
  done < <(git worktree list --porcelain)
  git worktree prune

  # Local branches without a worktree. A branch checked out anywhere is skipped.
  git for-each-ref --format='%(refname:short) %(worktreepath)' refs/heads |
    while read -r branch dir; do
      [ "$branch" != main ] && [ -z "$dir" ] || continue
      tip=$(git rev-parse "refs/heads/$branch")
      if [ "$(pr_verdict "$branch" "$tip")" = merged ] && delete_branch "$branch" "$tip"; then
        echo "removed branch $branch"
      fi
    done
}

case ${1:-} in
  new) [ $# -eq 2 ] || die "usage: worktree.sh new <type>/<issue>-<topic>"; new "$2" ;;
  prune) prune ;;
  *) die "usage: worktree.sh new <type>/<issue>-<topic> | prune" ;;
esac
