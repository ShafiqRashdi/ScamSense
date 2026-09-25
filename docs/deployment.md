# Deployment guide

ScamSense on Railway — Laravel, MySQL and the Flask ML service in one project,
with the ML service on the private network only.

---

## Topology, and why it is this one

```
                    internet
                       │
                       ▼  HTTPS, public domain
        ┌──────────────────────────────┐
        │  scamsense-web  (Laravel)    │
        └───────┬──────────────┬───────┘
                │              │
   private IPv6 │              │ HTTPS to third parties
   HTTP + HMAC  │              ▼
                │      Google Safe Browsing, VirusTotal
                ▼
   ┌────────────────────────────┐      ┌──────────────────┐
   │ scamsense-ml  (Flask)      │      │  MySQL           │
   │ NO PUBLIC DOMAIN           │      │  private only    │
   └────────────────────────────┘      └──────────────────┘
```

Three services, one Railway project, one private network. **Only
`scamsense-web` has a public domain.**

Hugging Face Spaces was considered for the ML service and rejected. It serves on
a public hostname and requires binding a public interface, which would have
meant editing the `127.0.0.1` binding in `wsgi.py` to fit the platform — and
AGENTS.md §2 rule 1 states the service is never exposed to the internet. Needing
to edit that line to satisfy a host is the architecture objecting. HMAC is a
compensating control, not a replacement for the primary one.

Railway over Render for the database: the project brief commits to MySQL, and
Render offers no managed MySQL. Swapping to Postgres to suit a host would be the
tail wagging the dog.

---

## Before you start

- A Railway account with a paid plan or credits — see **Cost** below, this
  matters more than it sounds.
- The repository pushed to GitHub.
- A trained URL model. Weights are gitignored, so a clean checkout has none:

  ```bash
  cd ml-service
  python training/train_url_model.py --data training/data/urls_combined.csv \
      --version 2.0.0-pathnorm --split-strategy domain \
      --n-estimators 200 --max-depth 18 --drop-feature uses_https
  ```

  The 130 MB artefact must reach the container. Either commit it to a private
  deployment branch, or add a Railway build step that trains it. Do **not**
  commit weights to a public repository.

---

## 1. MySQL, with a scoped user

Add a MySQL service in Railway. It stays on the private network — never
generate a public domain for it.

Railway provisions a root user. **Do not use it for the application.** A SQL
injection or an application compromise under root inherits full server
privileges, including `DROP DATABASE` and every other schema on the instance
(security review finding 7).

Connect with Railway's MySQL console and create a scoped user:

```sql
CREATE DATABASE IF NOT EXISTS scamsense
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'scamsense_app'@'%' IDENTIFIED BY '<a long random password>';

-- Exactly the rights the running application needs. No DROP, no ALTER,
-- no GRANT, no access to any other schema.
GRANT SELECT, INSERT, UPDATE, DELETE ON scamsense.* TO 'scamsense_app'@'%';

-- Migrations change schema, so they need more. Keep them separate and use this
-- user only when running migrations, never for serving traffic.
CREATE USER 'scamsense_migrate'@'%' IDENTIFIED BY '<a different long password>';
GRANT ALL PRIVILEGES ON scamsense.* TO 'scamsense_migrate'@'%';

FLUSH PRIVILEGES;
```

Two users is a small amount of extra work and a strong viva answer: the
application literally cannot drop a table, and you can say so precisely.

---

## 2. The ML service — private, no public domain

Create a service from the repository, root directory `ml-service`.

**Start command**

```
gunicorn --bind [::]:$PORT --workers 1 --timeout 30 wsgi:app
```

Binding `[::]` rather than `127.0.0.1` is what makes it reachable on Railway's
private IPv6 network. That is still a private network and still satisfies rule 1
— **but only while no public domain exists for this service.**

> **Do not click "Generate Domain" on `scamsense-ml`.** That single click puts
> the prediction endpoint on the internet and breaks the architectural rule the
> whole design rests on. If you ever create one by accident, delete it and
> rotate `ML_SERVICE_SECRET`.

**One worker, deliberately.** The nonce cache that prevents HMAC replay is
per-process (`ml-service/app/security.py`). With multiple workers a replayed
request could land on a different worker and be accepted. One worker is
sufficient for this traffic; more would need shared state such as Redis.

**Environment variables**

| Variable | Value |
|---|---|
| `ML_SERVICE_SECRET` | 64 hex chars, generated below. Must match the web service exactly |
| `FLASK_RUN_HOST` | `::` |

Generate the secret once:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

**Verify it is private.** After deploying, from your own machine:

```bash
curl https://scamsense-ml.up.railway.app/health   # must fail to resolve or connect
```

If that returns a health payload, a public domain exists. Remove it.

---

## 3. The Laravel service

Create a second service from the repository, root directory `backend`.

**Build command**

```
composer install --no-dev --optimize-autoloader && npm ci && npm run build
```

**Start command**

```
php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan serve --host 0.0.0.0 --port $PORT
```

Run the migration under `scamsense_migrate`, then switch `DB_USERNAME` back to
`scamsense_app` for normal operation. On Railway the simplest safe pattern is to
run migrations once from the shell with the migrate credentials, then keep the
service configured with the app user and drop `migrate --force` from the start
command.

**Environment variables**

| Variable | Value | Why |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | **`false`** | Stack traces leak paths, config and query fragments (finding 6) |
| `APP_KEY` | `base64:…` from `php artisan key:generate --show` | |
| `APP_URL` | your public Railway URL | |
| `SESSION_SECURE_COOKIE` | **`true`** | The cookie is otherwise sent in the clear |
| `SESSION_SAME_SITE` | `lax` | |
| `DB_CONNECTION` | `mysql` | |
| `DB_HOST` | `mysql.railway.internal` | Private hostname, not the public proxy |
| `DB_DATABASE` | `scamsense` | |
| `DB_USERNAME` | `scamsense_app` | **Never root** (finding 7) |
| `DB_PASSWORD` | the scoped user's password | |
| `ML_SERVICE_URL` | `http://scamsense-ml.railway.internal:8080` | Private DNS |
| `ML_SERVICE_SECRET` | identical to the ML service | |
| `GOOGLE_SAFE_BROWSING_KEY` | your key | Optional; absent degrades gracefully |
| `VIRUSTOTAL_KEY` | your key | Optional |
| `RETENTION_SUBMISSION_DAYS` | `90` | Must match the privacy notice |
| `ADMIN_EMAIL` | your address | |
| `ADMIN_PASSWORD` | a strong password, set here not in a file | See below |

**Never commit any of these.** `.env` is gitignored and `git ls-files` confirms
none is tracked; the platform's variable store is the only place they belong.

**HTTPS.** Railway terminates TLS and issues a certificate automatically. Set
`SESSION_SECURE_COOKIE=true` so the cookie requires it, and the application
sends HSTS automatically once requests arrive over HTTPS
(`SecurityHeaders` middleware).

---

## 4. Seed the administrator, then rotate

```bash
railway run php artisan db:seed --class=AdminUserSeeder
```

The seeder never ships a fixed password. With `ADMIN_PASSWORD` set it uses that;
without it, it generates a random one and prints it **once**.

**The seeder only ever creates.** If the account already exists it prints
`already exists — left unchanged` and returns, which is deliberate: a seeder
that silently reset a live password would be worse than the problem it solves.
Re-running it after changing `ADMIN_PASSWORD` therefore does nothing, and the
account keeps its old password.

**Rotate with the dedicated command.** Platform build logs capture stdout, so a
printed password may persist in the deploy history (finding 10).

```bash
# Preferred: set ADMIN_PASSWORD in the platform's secret store, then
railway run php artisan scamsense:admin-password --from-env

# Or interactively — reads without echo, nothing enters the log
railway run php artisan scamsense:admin-password
```

A password you supply is never printed. `--generate` is available and prints
once, but avoid it on a platform runner for exactly the reason above; the
command says so when you use it.

This is also the answer when someone is locked out: there is no self-service
reset and no password-change screen, by design — the admin area has two routes
and neither is a profile page.

Do **not** run `db:seed` without a class in production — that would also load
the sample submissions and the demo moderation queue.

---

## 5. Schedule the retention purge

The purge is scheduled in `routes/console.php`, but Laravel's scheduler needs
something to run it every minute. Add a **cron service** in the same Railway
project, from the same repository and root directory:

```
php artisan schedule:run
```

with a cron expression of `* * * * *`.

Verify it works before relying on it:

```bash
railway run php artisan scamsense:purge --dry-run
```

That reports what would be deleted and deletes nothing. The submission form
tells users their data is kept for `RETENTION_SUBMISSION_DAYS` days and then
deleted; without this cron, that is a promise the system does not keep
(finding 5).

---

## 6. Verify the deployment

Work through all of these before calling it done.

> **A long-running PHP process caches the Vite manifest.** After any
> `npm run build`, a server that was already running keeps emitting the previous
> hashed asset filenames — files the build has just deleted — so the site
> renders with stale or missing CSS while the manifest on disk is correct.
> `php artisan optimize:clear` does **not** fix it, which makes the obvious
> remedy look broken. Restart the process.
>
> Locally that means restarting `php artisan serve`; on Railway it means
> redeploying rather than rebuilding assets in place. It cost an afternoon in
> development, and it is invisible to the test suite because feature tests build
> a fresh application per test and re-read the manifest each time. See
> `docs/deployment-boundary-defects.md` §3.

```bash
# 1. The site is up and served over HTTPS
curl -sI https://<your-app>.up.railway.app | head -1

# 2. Security headers are present
curl -sI https://<your-app>.up.railway.app | grep -iE "content-security|x-content-type|strict-transport|x-frame"

# 3. Debug mode is OFF — this must NOT return a stack trace
curl -s https://<your-app>.up.railway.app/no-such-page | grep -ci "vendor/laravel" # expect 0

# 4. The ML service is NOT reachable publicly
curl -s --max-time 5 https://scamsense-ml.up.railway.app/health   # expect failure
```

Then in a browser:

| Check | Expected |
|---|---|
| Paste `https://www.maybank2u.com.my/home/m2u/common/login.do` | Verified-address panel naming Maybank |
| Paste `https://maybank2u.com.my.secure-verify.xyz/login` | **Likely a scam** |
| Paste a Malay scam message | A verdict, not an error |
| Switch to **BM** | Interface in Malay |
| `/admin` while signed out | Redirect to login |
| Sign in, visit `/admin` | Dashboard with model metrics |

---

## Cost — read this before the demo period

Railway bills by resource-hours across **every** service. This project runs
three: web, ML and MySQL, plus a cron service. That is materially more than a
single-service project, and a small monthly credit will not cover it running
continuously for weeks.

Verify current pricing yourself — it changes, and this document will go stale.

**For a presentation in early October, pick one:**

1. **Run continuously and pay for it.** Simplest, and removes all risk on the
   day. Budget from the moment you deploy, not from the presentation date.
2. **Deploy, verify, tear down, redeploy.** Deploy now and work through §6 so
   you know it works. Then delete the services and redeploy about a week before
   the presentation, leaving time to fix anything that broke. Keep this guide
   and your environment variables somewhere safe — that is what makes the
   redeploy quick.
3. **Sleep the services between sessions** if the platform supports it, waking
   them for supervisor demos.

**Check your usage in the first week rather than the last.** Discovering the
credit is exhausted during your presentation window is the failure mode to
avoid, and it is entirely avoidable by looking early.

---

## Warming up before a demo

Free and low tiers idle containers out. A cold start means the first visitor
waits while the container boots, PHP warms, and the ML service loads a 130 MB
model — comfortably past the three-second budget in objective O2, and a poor
first impression in front of a panel.

**Fifteen minutes before you present:**

```bash
# 1. Wake the web service
curl -s -o /dev/null -w "web:   %{http_code} in %{time_total}s\n" https://<your-app>.up.railway.app/

# 2. Wake the ML service through a real submission, which loads the model
curl -s -o /dev/null -w "first: %{http_code} in %{time_total}s\n" https://<your-app>.up.railway.app/

# 3. Submit one URL through the browser so the model is resident
#    Then repeat step 1 — the second timing is what your audience will see.
curl -s -o /dev/null -w "warm:  %{http_code} in %{time_total}s\n" https://<your-app>.up.railway.app/
```

Then submit one URL and one message through the interface. Expect the first
submission to be slow and every one after it to be fast.

**Before that, confirm the chain actually answers:**

```bash
railway run php artisan scamsense:doctor
```

It sends a real signed request for both a link and a message. A pass means a
submission will produce a genuine verdict; anything else names the broken link
and what to do about it.

This is not the same check as `/health`, and the difference matters. `/health`
is unauthenticated, so it never exercises the HMAC. A service started with the
wrong `ML_SERVICE_SECRET` reports `status: ok` and `models_loaded: true` while
rejecting every real request with a 401 — and Laravel correctly degrades, so
every verdict on the site reads "We could not check this properly" with nothing
anywhere saying why. Run the doctor, not the health check.

**Do this again if you are idle for more than about twenty minutes** — during a
long Q&A, for instance. Keeping the tab open does not keep the container awake.

---

## Local fallback for demo day

Deployments fail at inconvenient moments. Have the local stack ready as a
fallback, tested the day before, not discovered on the day.

**Preparation, the day before:**

```powershell
# Ensure MySQL starts on boot so it is not a surprise
Set-Service MYSQL80 -StartupType Automatic   # admin PowerShell, once

cd C:\dev\scamsense
git pull
cd backend; composer install; npm ci; npm run build

# WARNING: migrate:fresh DROPS every table. Fine for a demo machine, never
# against anything whose data you want.
php artisan migrate:fresh --seed
php artisan scamsense:admin-password --from-env
```

**On `--from-env`.** `migrate:fresh --seed` runs `AdminUserSeeder`, which uses
`ADMIN_PASSWORD` when it is set and prints nothing — it only prints a password
when it had to generate one. Running the command afterwards is harmless if the
seeder already applied the value, and it is the difference between being able to
sign in on the day and not.

**On the day:** double-click `start.bat`. It checks that MySQL is running, that
ports 5001 and 8000 are free, reads the shared secret from `backend/.env`, and
opens both services in labelled windows.

Then, before you show anyone anything:

```powershell
cd backend
php artisan scamsense:doctor
```

Five passing lines means a submission will produce a real verdict. `/health`
answering is not the same check and will report a healthy service that rejects
every signed request — see `docs/deployment-boundary-defects.md`.

Then visit `http://127.0.0.1:8000`.

**If the ML service will not start**, the application still works. Verdicts come
back marked *"We could not check this properly"* — the graceful-degradation path
from AGENTS.md §2 rule 4. That is worth demonstrating deliberately rather than
apologising for: it shows the system fails safely.

**Have ready as a final fallback:** screenshots of the deployed site, the
verified-address panel, the moderation queue and the dashboard. If the network
in the room fails, you can still show what the system does.
