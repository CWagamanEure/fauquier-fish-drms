# Deploying to SiteGround

A push to `main` publishes the site to SiteGround with `.github/workflows/deploy.yml`, which runs
`scripts/deploy-siteground.sh` (rsync over SSH). This page is the setup and the rules.

## What a deploy does and does not do

- It makes `public_html` match the tracked files at the tip of `main`, minus the development-only paths
  listed in `scripts/deploy-siteground.filter` (`sql/`, `tests/`, `scripts/`, `docs/`, `.github/`, ...).
- It **deletes** server files that are not in the repository, and **overwrites** server copies of tracked
  files, including the tracked samples inside `uploads/` and `reportGeneration/reports/`.
- It never uploads and never deletes: the root `.htaccess` (database credentials), any `.api_env`, any
  `php_errorlog`, `.well-known/`, and anything inside `uploads/`, `reportGeneration/reports/` and
  `python/exports/` that the repository does not carry. Files users upload through the app are safe.
- It does **not** touch the database. It does not create the root `.htaccess` or `.api_env`; those are
  edited on the server only.
- The server's root `.htaccess` must keep denying dot-files, `.ini`/`.txt`/`.sql` files and
  `php_errorlog` (the rules mirrored from `docker/apache.conf`). The deploy checks this from outside
  before and after it runs (`scripts/deploy-probe.sh`). A failed check before the sync stops the deploy.
  A failed check after the sync means a private path is being served now: fix the server `.htaccess`
  (or revert the commit and deploy again) straight away; there is no automatic rollback. The probe
  of `python/exports/` is weak (a directory with no index is usually refused anyway), and the probes do
  not check the database.
- A file removed from `uploads/`, `reportGeneration/reports/` or `python/exports/` in the repository stays
  on the server (those directories are never cleaned); delete it there by hand. If it is one of the files
  named in `scripts/deploy-probe.sh`, replace that probe in the same pull request.
- Re-running an old deploy run cannot publish an older commit: a real deploy checks again that it is the
  tip of `main` and that deploys are enabled.
- SiteGround caching can delay a visible change; purge the cache in Site Tools if a page looks stale.

Teammates need no `make dev`, `make migrate` or re-login for this change.

## First-time setup (repository owner)

The deploy key gives shell access to the hosting account, and so to the database credentials in
`.htaccess`. Treat it like a password.

1. GitHub, Settings, Environments: create `production`. Under "Deployment branches and tags" choose
   "Selected branches and tags" and add `main` only. Adding yourself as a required reviewer is
   recommended. Confirm the branch rule before storing the key:
   `gh api repos/OWNER/REPO/environments/production/deployment-branch-policies`.
2. Create a key used only for deploys: `ssh-keygen -t ed25519 -N '' -C github-deploy -f sg_deploy_key`.
   Import `sg_deploy_key.pub` in SiteGround, Site Tools, Devs, SSH Keys Manager.
3. Get the server's host key line from a machine that already connects to it:
   `ssh-keygen -F '[HOST]:18765'` (copy the line that starts with `[HOST]:18765`).
4. Add these **secrets to the `production` environment** (not repository secrets):
   `SG_SSH_KEY` (contents of `sg_deploy_key`), `SG_HOST`, `SG_USER`, `SG_PORT` (`18765`),
   `SG_PATH` (`www/SITE/public_html/`, must end in `/public_html/`), `SG_KNOWN_HOSTS` (the line from
   step 3). Then delete the local `sg_deploy_key` file.
5. Add the **repository variable** `SG_SITE_URL` (`https://...`, no trailing path).
6. Merge to `main`. The push run only prints "Deploy is not enabled".
7. Actions, deploy, "Run workflow" on `main` with "Dry run" ticked. Read the rsync list: every
   `*deleting` line and every transfer should be expected.
8. Confirm `sql/production-applied.txt` matches the live schema, then set the repository variable
   `SG_DEPLOY_ENABLED` to `true`. From then on every push to `main` deploys. To deploy without a new
   push, run the workflow with "Dry run" unticked.

To stop deploys, set `SG_DEPLOY_ENABLED` to anything else. If the key may have leaked: remove it in the
SSH Keys Manager, change the database password (SiteGround and the server `.htaccess`), create a new key.

## Releasing a migration

Production runs MySQL 8; the files in `sql/migrations/` are MariaDB syntax and are not applied by the
deploy. `scripts/deploy-schema-guard.sh` refuses a real deploy while `sql/migrations/` has a file that
`sql/production-applied.txt` does not list. The pull request check and dry runs show the same thing as a
warning.

1. The owner ports the migration to MySQL 8 and applies it on SiteGround (phpMyAdmin). Keep the ported
   SQL in the pull request description.
2. The owner adds the filename to `sql/production-applied.txt` in a pull request to `dev`. Only the owner
   edits that file. The guard trusts it: listing a file without applying it defeats the check.
3. Merge `dev` to `main`.

A migration applied ahead of the code must work with the code that is live at that moment. Apply a
destructive step (dropping or renaming something the live code uses) immediately before the release
merge. If the deploy fails after the schema was applied, fix forward and re-run the workflow; there is no
automatic rollback.

## Dry run from your machine

The script refuses a real deploy outside GitHub Actions. A dry run needs GNU rsync 3 (macOS ships
openrsync, which is refused), your own SSH key imported in SiteGround, and the host key in a
known_hosts file:

```
SG_HOST=... SG_USER=... SG_PORT=18765 SG_PATH=www/SITE/public_html/ \
SG_KEY_FILE=~/.ssh/id_ed25519 SG_KNOWN_HOSTS_FILE=~/.ssh/known_hosts scripts/deploy-siteground.sh
```

It publishes the committed tree at `HEAD`; uncommitted and ignored files are never sent.

## Tests

`make test-deploy` runs `tests/deploy/deploy_test.sh` (no network): it executes the deploy script against
a fake `public_html` and checks the filter, the guards and the schema guard. On a Mac use
`make test-deploy-docker`. The pull request check and every deploy run it. The workflow's own logic
(enable switch, missing-secret message) can only be observed in Actions.
