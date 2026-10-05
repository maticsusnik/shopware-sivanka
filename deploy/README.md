# Production deploy (NEOSERV cPanel)

Production runs on a NEOSERV "Turbo paket 1" cPanel account (`th19.neoserv.si`, user
`sivanka`, SSH port 5050). The account has no Docker, Python, Composer or Node. It runs
CloudLinux and LiteSpeed with PHP 8.4, MariaDB 11.4 and a per-account Redis at
`/tmp/redis.sock`.

```
feature/bug branch → develop → dev server (deploy-dev.yml, Docker)
                   → main    → production (deploy-prod.yml, needs approval)
```

## How a deploy works

1. A push to `main` starts `.github/workflows/deploy-prod.yml`.
2. **build** runs `composer install --no-dev` with PHP 8.4 and packs `shopware/` into
   `<sha>.tgz`. The custom plugin JS is committed, so there is no npm step.
3. **deploy** waits for approval on the `production` environment. It then uploads the
   tarball and `deploy/remote-deploy.sh`, and runs on the host:
   - unpack into `~/sivanka/releases/<sha>` and link the shared directories
   - `plugin:refresh`, core migrations, `plugin:update:all`
   - `system:update:finish`, only when the Shopware version changed
   - `assets:install`, `theme:compile`, `cache:clear` + `cache:warmup`
   - switch `~/sivanka/current` to the new release in one step, then `cache:clear:http`
   - keep the last 5 releases

   If any step fails before the switch, the unfinished release is deleted and the old
   one keeps serving.
4. The smoke test checks that `PROD_URL` answers HTTP 200.

Plugin migrations only run when the plugin's `composer.json` version goes up, because
`plugin:update:all` decides by version. New plugins are not installed automatically.
Install them once, by hand.

Migrations run while the old release is still live. Keep them backward compatible.

## Rollback

Actions → *Build & Deploy (production)* → *Run workflow* → `action: rollback`.

This points `current` at the previous live release and clears the caches. **The
database is not rolled back**, so restore it from JetBackup if a migration was the
problem.

## Server layout

```
~/sivanka/
  releases/<sha>/      one shopware/ tree per deploy
  shared/              .env.local, files/, public/{.well-known,media,thumbnail,sitemap,theme},
                       var/{log,optiweb-logs,optiweb-sync-locks}, config/jwt
  current -> releases/<sha>
  history              ids that went live (used by rollback)
  bin/remote-deploy.sh
~/public_html -> ~/sivanka/current/public
```

## One-time setup

Do these in this order. Each one changes production, so do them yourself or approve each
one explicitly.

1. **cPanel → MySQL Databases.** Create the database and user, and grant ALL on the
   database.
2. **Deploy key.** Generate the key locally:
   `ssh-keygen -t ed25519 -N '' -C github-deploy-sivanka -f sivanka_deploy`.
   Then go to cPanel → SSH Access → Import Key, paste `sivanka_deploy.pub`, and click
   *Authorize*.
3. **GitHub → Settings → Environments → `production`.** Add yourself as a required
   reviewer and restrict deployments to `main`. Then add:
   - secret `PROD_SSH_PRIVATE_KEY_B64` = `base64 -w0 sivanka_deploy`
   - secret `PROD_SSH_KNOWN_HOSTS` = `ssh-keyscan -p 5050 th19.neoserv.si`
     (compare the fingerprint with the one you get over your own SSH)
   - variables `PROD_HOST=th19.neoserv.si`, `PROD_SSH_PORT=5050`,
     `PROD_SSH_USER=sivanka`, `PROD_URL=https://sivanka.com` (leave `PROD_URL` empty
     until DNS points here)
4. **`.env.local`.** On the host, create `~/sivanka/shared/.env.local` from
   `deploy/env.local.prod.dist`, then `chmod 600` it.
5. **Database and files.** Import the database dump (cPanel → phpMyAdmin or
   `mysql < dump.sql`). Copy `public/media`, `public/thumbnail` and `files/` into
   `~/sivanka/shared/`. Then rewrite the sales channel domains to `https://sivanka.com`.
6. **First deploy.** Push to `main` (or *Run workflow*) and approve.
7. **Point the web root at the release.** This is done once and is reversible:
   ```bash
   cp -a ~/public_html/.well-known/. ~/sivanka/shared/public/.well-known/
   mv ~/public_html ~/public_html.orig
   ln -s ~/sivanka/current/public ~/public_html
   ```
   To undo it: `rm ~/public_html && mv ~/public_html.orig ~/public_html`.
8. **cPanel → Cron Jobs**, each once per minute:
   ```
   cd ~/sivanka/current && /usr/local/bin/php bin/console messenger:consume async low_priority --time-limit=55 --memory-limit=512M >/dev/null 2>&1
   cd ~/sivanka/current && /usr/local/bin/php bin/console scheduled-task:run --time-limit=55 --memory-limit=512M >/dev/null 2>&1
   ```
   Add the OptiwebSync/Minimax cron jobs only at go-live. Org 239849 is the live
   Minimax company.
9. **Go-live.** After the domain transfer: AutoSSL, Force HTTPS Redirect, and raise the
   mail limit to 1000/h in Moj NEOSERV. Set `PROD_URL`.
