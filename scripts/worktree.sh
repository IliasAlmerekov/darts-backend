#!/usr/bin/env bash
# Git worktrees for issue branches, so parallel sessions never switch the main checkout.
#
#   scripts/worktree.sh new <type>/<issue>-<topic>
#       Cuts the branch from the latest main into ../worktrees/<repo>-<issue>, copies the
#       local env files from the main checkout and installs dependencies: npm packages, or
#       for the backend Composer packages plus a migrated test database of its own.
#       Prints the path.
#   scripts/worktree.sh prune
#       Removes every worktree and local branch whose tip is the head commit of a merged
#       pull request from this repository. Keeps a worktree with uncommitted changes, a
#       branch with commits after the merge, and anything whose PR is open, closed or missing.
#       The backend worktree's test database is dropped with it.
set -euo pipefail

die() { echo "worktree.sh: $*" >&2; exit 1; }

common_dir=$(git rev-parse --path-format=absolute --git-common-dir)
main_dir=$(dirname "$common_dir")
repo=$(basename "$main_dir")
wt_root=$(dirname "$main_dir")/worktrees
remote=$(git remote -v | awk '/github\.com/ && /\(push\)/ { print $1; exit }')
[ -n "$remote" ] || die "no GitHub remote"
slug=$(git remote get-url "$remote" | sed -E 's#^.*github\.com[:/]##; s#\.git$##')
owner=${slug%%/*}
# Every git call runs from the main checkout, so prune can remove the worktree it was started in.
cd "$main_dir"

# True when the branch tip is the head commit of a merged PR opened from this repository,
# so a commit added after a squash merge, or a fork PR with the same branch name, never counts.
merged() {
  local tip
  tip=$(git rev-parse "refs/heads/$1")
  gh pr list -R "$slug" --head "$1" --state merged --json headRefOid,headRepositoryOwner \
    -q ".[] | select(.headRepositoryOwner.login == \"$owner\") | .headRefOid" </dev/null | grep -qx "$tip"
}

new() {
  local branch=$1 issue dir f
  issue=$(sed -nE 's#^[a-z]+/([0-9]+)-.+$#\1#p' <<<"$branch")
  [ -n "$issue" ] || die "branch must look like <type>/<issue>-<topic>, got: $branch"
  dir=$wt_root/$repo-$issue
  [ ! -e "$dir" ] || die "$dir already exists"
  git fetch -q "$remote" main
  git worktree add --no-track -b "$branch" "$dir" "$remote/main" >&2
  echo "created $dir" >&2
  for f in .env .env.local app/.env.local app/.env.test.local; do
    if [ -f "$main_dir/$f" ]; then cp "$main_dir/$f" "$dir/$f"; fi
  done
  if [ -f "$dir/package-lock.json" ]; then
    (cd "$dir" && npm ci --no-audit --no-fund >&2)
  fi
  if [ -f "$dir/scripts/php.sh" ]; then
    (
      cd "$dir"
      scripts/php.sh composer install --no-interaction --no-progress
      scripts/php.sh php bin/console doctrine:database:create --env=test --if-not-exists
      scripts/php.sh php bin/console doctrine:migrations:migrate --env=test --no-interaction
    ) >&2
  fi
  echo "$dir"
}

# The backend worktree's own test database (see scripts/php.sh) goes with the worktree.
drop_test_db() {
  [ -f "$1/scripts/php.sh" ] && [ -d "$1/app/vendor" ] || return 0
  (cd "$1" && scripts/php.sh php bin/console doctrine:database:drop --env=test --force --if-exists >&2)
}

prune() {
  local dir="" branch line
  git fetch -q --prune "$remote"
  while read -r line; do
    case $line in
      "worktree "*) dir=${line#worktree } ;;
      "branch "*)
        branch=${line#branch refs/heads/}
        [ "$dir" != "$main_dir" ] || continue
        if ! merged "$branch"; then
          echo "keep $dir ($branch): no merged PR at this commit"
        elif [ -n "$(git -C "$dir" status --porcelain)" ]; then
          echo "keep $dir ($branch): uncommitted changes"
        elif drop_test_db "$dir" && git worktree remove "$dir"; then
          git branch -D "$branch" >/dev/null
          echo "removed $dir and branch $branch"
        else
          echo "keep $dir ($branch): dropping its test database or removing it failed"
        fi
        ;;
    esac
  done < <(git worktree list --porcelain)
  git worktree prune

  # Local branches without a worktree. A branch checked out anywhere is skipped.
  git for-each-ref --format='%(refname:short) %(worktreepath)' refs/heads |
    while read -r branch dir; do
      [ "$branch" != main ] && [ -z "$dir" ] || continue
      if merged "$branch"; then
        git branch -D "$branch" >/dev/null
        echo "removed branch $branch"
      fi
    done
}

case ${1:-} in
  new) [ $# -eq 2 ] || die "usage: worktree.sh new <type>/<issue>-<topic>"; new "$2" ;;
  prune) prune ;;
  *) die "usage: worktree.sh new <type>/<issue>-<topic> | prune" ;;
esac
