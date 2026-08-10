# ScamSense

AI-Based Scam Verification Platform for Online Fraud Prevention.
Final Year Project (FYP4112), Universiti Poli-Tech Malaysia.

A Malaysian user pastes a suspicious SMS/WhatsApp message or a URL and gets back, in under
three seconds, a risk verdict with a confidence score and human-readable indicators explaining
why.

Read [AGENTS.md](AGENTS.md) before changing anything — it holds the architectural and security
rules this project is graded on.

---

## Architecture in one line

The browser talks only to Laravel. Laravel talks to a Flask ML service over an HMAC-signed
internal hop, and to Google Safe Browsing and VirusTotal for reputation. **The Flask service is
never exposed to the internet, and ScamSense never visits a submitted URL.**

```
scamsense/
├── AGENTS.md          # operating rules — read first
├── SKILLS.md          # index of the agent skills in .claude/skills/
├── docs/              # ADRs, API contract, FYP chapter source
├── backend/           # Laravel 13 (PHP 8.4) + MySQL + Bootstrap 5
└── ml-service/        # Python 3.11 + Flask, DistilBERT + Random Forest
```

---

## Prerequisites

| Tool | Version | Note |
|------|---------|------|
| PHP | 8.3+ | 8.4 in development |
| Composer | 2.x | |
| MySQL | 8.0 | must be running |
| Node.js | 20+ | for the Vite/Bootstrap build |
| Python | **3.11 exactly** | not 3.12+, see below |

Python 3.11 is a hard requirement, not a preference: the `torch` and `transformers` wheels the
message classifier needs are not published for newer releases yet. If `python --version` shows
something else, use the launcher — `py -3.11` on Windows.

---

## Running it

Two terminals. Start the ML service first so Laravel's health check has something to reach.

### 1. ML service

```bash
cd ml-service
py -3.11 -m venv .venv            # Windows;  python3.11 -m venv .venv elsewhere
.venv\Scripts\activate            # Windows;  source .venv/bin/activate elsewhere
pip install -r requirements.txt -r requirements-dev.txt
cp .env.example .env              # then set ML_SERVICE_SECRET
python wsgi.py                    # http://127.0.0.1:5001
```

Check it: `curl http://127.0.0.1:5001/health` → `200`.

### 2. Laravel backend

```bash
cd backend
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create the database and set your credentials in `.env`:

```sql
CREATE DATABASE scamsense CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`ML_SERVICE_SECRET` must be **byte-identical** in `backend/.env` and `ml-service/.env` — it is
the shared HMAC key for the internal hop. Generate one with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Then:

```bash
php artisan migrate --seed         # prints the generated admin password once
npm run build                      # or: npm run dev
php artisan serve                  # http://127.0.0.1:8000
```

The seeder creates one administrator. There are no default credentials: leave `ADMIN_PASSWORD`
empty in `.env` and the seeder generates a random password and prints it to the console a single
time. Save it then — it is not stored anywhere.

---

## Tests and linting

Both suites must be green before anything is reported as done (AGENTS.md §7).

```bash
# Laravel
cd backend
./vendor/bin/pint          # --test to check without rewriting
php artisan test

# ML service
cd ml-service
ruff check .
black --check .
pytest
```

---

## Current status

Phase 0 (scaffold) is complete: both services boot, `/health` returns 200, and both suites pass.
No models have been trained yet, so `/health` honestly reports `null` for both model versions,
and the two web pages are labelled placeholders. See AGENTS.md §9 for the full phase checklist.
