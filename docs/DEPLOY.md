# SparkBooks — Production deployment (VPS via Laravel Forge)

Goal: **books.sparkingasia.com**, always-on, with the queue worker, nightly
backups, and monitoring running — so an accountant can log in and review, and
SparkingAsia can eventually keep its real books here.

This runbook uses **Laravel Forge** (server management) on a **VPS**. Forge does
the heavy lifting: it installs PHP 8.3 + MySQL + Nginx, deploys from GitHub,
runs the scheduler cron and the queue worker, and issues free SSL. The manual
alternative (raw VPS, no Forge) is sketched at the bottom.

> **Split of work.** Steps marked 👤 need *your* account/credentials/money —
> you do them (I'll guide each click). Steps marked 🤖 are code/config I've
> already prepared or can run. I never create accounts or enter your passwords.

---

## What the server must have (Forge sets all of this up for you)
- PHP **8.3** with extensions: `zip` (accountant package), `bcmath` (money
  math), `intl`, `gd`, `pdo_mysql`, `mbstring`, `openssl`, `curl` — Forge's
  default PHP build includes these.
- **MySQL 8** (Forge installs it; `mysqldump`/`mysql` end up on PATH → backups work).
- A **queue worker** daemon: `php artisan queue:work --sleep=1 --tries=3 --max-time=3600`
  (Forge → Daemons). The app uses the database queue (no Redis needed).
- The **scheduler** cron: `php artisan schedule:run` every minute (Forge adds this
  automatically for Laravel sites). It drives the three nightly jobs:
  `sparkbooks:backup` (02:00), `ledger:verify` (03:00), `sparkbooks:queue-heartbeat` (04:00).

---

## Phase A — Get it online (accountant can log in)

### A1. 👤 Accounts you need first
1. **A VPS** — DigitalOcean (easiest with Forge) or Hetzner (cheapest). A basic
   2 GB / 1 vCPU droplet (~$6–12/mo) is plenty to start.
2. **Laravel Forge** — <https://forge.laravel.com> (~$12/mo). Connect your
   DigitalOcean/Hetzner account and your GitHub inside Forge.
3. The **GitHub repo already exists and is current**: `jawadislam92/SparkBooks`
   at the latest commit. **Confirm it is PRIVATE** (financial source must not be
   public) — GitHub → repo → Settings → Danger Zone → “Change visibility”.

### A2. 👤 Provision the server (in Forge)
- Forge → **Create Server** → pick your provider + region (closest to Pakistan,
  e.g. Bengaluru/Singapore) → PHP 8.3 → create. Wait ~10 min.

### A3. 👤 Create the site
- Forge → your server → **New Site** → domain `books.sparkingasia.com` →
  project type **Laravel** → web directory `/public`.
- **Git repository** → `jawadislam92/SparkBooks`, branch `main` → install.
- Forge auto-creates a MySQL database + user for the site (note the password).

### A4. 🤖 The deploy script (paste into Forge → Site → Deploy Script)
```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

# PHP deps (production, optimized)
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Front-end build (Inertia/React) — Node must be installed on the server
npm ci
npm run build

# Database + caches
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link || true
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan event:cache
$FORGE_PHP artisan view:cache

# Restart the queue worker so it runs the new code
$FORGE_PHP artisan queue:restart
```
> Node/npm on the server: Forge servers include Node. If `npm` isn't found, run
> `nvm install --lts` once in Forge → Server → “Run Command”, or build assets in
> CI and commit `public/build` (we currently build on the server).

### A5. 👤 Environment
- Forge → Site → **Environment** → paste from `.env.production.example` (in the
  repo), filling the DB password from A3 and leaving the (later) blocks for now.
- Then Forge → Site → “Run Command”: `php artisan key:generate --force` (sets APP_KEY).

### A6. 👤 First deploy + SSL
- Forge → Site → **Deploy Now**.
- Forge → Site → **SSL** → **Let’s Encrypt** → issue for `books.sparkingasia.com`.
- 👤 **DNS**: at your domain registrar / Cloudflare, add an **A record**
  `books` → the server’s IP (shown in Forge). Wait for it to resolve, then issue SSL.

### A7. 👤 Queue worker daemon
- Forge → Server → **Daemons** → New Daemon:
  `php8.3 /home/forge/books.sparkingasia.com/artisan queue:work --sleep=1 --tries=3 --max-time=3600`
  (directory = the site path). This keeps invoice emails + the queue heartbeat alive.

### A8. 🤖 Create the company for review
- Forge → Site → “Run Command” (or `ssh` in): the production DB starts **empty**.
  Register the first user through the app’s sign-up, which runs onboarding and
  seeds the 25-account chart of accounts for a fresh **SparkingAsia** company.
  (Do *not* copy the local dev/test data — production starts clean.)
- Create a login for the accountant (Settings → Users), role **Accountant**.

✅ **At this point the accountant can open `https://books.sparkingasia.com` and review.**

---

## Phase B — Make it safe for real books (before actual bookkeeping)

- **B1. Off-site backups** 👤 — create an S3-compatible bucket (DigitalOcean
  Spaces / Backblaze B2 / AWS S3), fill the `BACKUP_S3_*` env, redeploy. Then
  Forge → Run Command `php artisan sparkbooks:backup` once and confirm the
  artifact lands in the bucket. Rehearse a restore per [RESTORE.md](RESTORE.md).
- **B2. Monitoring** 👤 — Sentry DSN (`SENTRY_LARAVEL_DSN`), an alert email
  (`MONITORING_ALERT_EMAIL`), and three healthchecks.io URLs
  (`*_HEARTBEAT_URL`) so a dead cron or a failed backup pages you. Send a test
  alert on each channel.
- **B3. Mail** 👤 — a real sending provider (Postmark/Mailgun/Resend/SES) for
  invoice emails. (Invoices still post without it — email is decoupled, §17.6.)
- **B4. Accountant sign-off** 👤 — hand over the accountant package
  (Reports → Accountant package) for the review month; fold any corrections back.
- The full pre-go-live list is in [PRE_TRIAL_CHECKLIST.md](PRE_TRIAL_CHECKLIST.md).

---

## Security notes (already handled in code; confirm on the server)
- `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`.
- The DB password is only ever in Forge’s env editor and the server `.env` —
  never in git, and the backup command redacts it from logs/alerts (SecretScrubber).
- Attachments are on the **private** disk, served only through authed routes.
- Keep the GitHub repo **private**.

## Manual VPS (no Forge) — the short version
Provision Ubuntu 22.04 → install PHP 8.3 (+extensions above), MySQL 8, Nginx,
Node, Composer, Certbot → clone the repo → `.env` from the template → the A4
commands by hand → an Nginx server block to `/public` → a systemd service for
`queue:work` → a crontab line `* * * * * php /path/artisan schedule:run` →
Certbot for SSL. Forge just automates all of this.
