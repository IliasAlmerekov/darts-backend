# Workspace paths

Read this before crossing repositories or using the shared Compose stack from a worktree.
Run from the current repository:

```bash
rtk git rev-parse --path-format=absolute --git-common-dir
```

The parent of that repository's common `.git` directory is its main checkout. The parent
of the main checkout is the shared workspace. Resolve `dartsapp` and `dartsapp-backend`
there, and verify each exists. If a paired feature worktree is supplied, use that explicit
path for branch-specific contracts instead. Do not infer siblings from the current worktree.

For a frontend contract refresh, set `frontend_checkout` to the absolute current frontend
worktree and `workspace_dir` to the resolved shared workspace. Verify both paths, then run:

```bash
rtk proxy docker compose --project-directory "$workspace_dir" exec -T -w /var/www/html php php bin/console nelmio:apidoc:dump > "$frontend_checkout/docs/backend-api-contract.json"
```

`rtk proxy` preserves exact JSON stdout. The shared PHP service serves the main backend
checkout; verify that its code matches the intended contract before refreshing. For a
backend feature worktree, first change into that backend checkout, then run
`rtk proxy scripts/php.sh php bin/console nelmio:apidoc:dump` and redirect stdout into
the explicit frontend checkout.
