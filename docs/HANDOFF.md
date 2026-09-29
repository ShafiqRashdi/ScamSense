# Handoff — where ScamSense is

**Written 2026-09-29. Presentation is Wednesday 7 October 2026 — eight days.**

Read this first, then `AGENTS.md` for the standing rules. This file is *state*;
`AGENTS.md` is *law*. If they disagree, `AGENTS.md` wins and this file is stale.

---

## What the project is

An AI scam-verification platform for the Malaysian public. Somebody pastes a
suspicious SMS, WhatsApp message or URL and gets a risk verdict with confidence
and readable reasons in under three seconds. Final Year Project, FYP4112,
Universiti Poli-Tech Malaysia. The client is Dobi Eta, a laundry business in
Bangi.

**The audience is the whole design constraint.** Malaysian general public, low
digital literacy assumed, including older adults on mid-range Android phones.
Every interface decision follows from that.

Three services: Laravel 13 / PHP 8.4 (`backend/`), Flask + scikit-learn
(`ml-service/`), MySQL 8. The Flask service is **never** reachable from the
internet; Laravel calls it over a private hop authenticated with HMAC.

---

## Working agreements — these are not optional

These came from the user directly and have been enforced all the way through.
Breaking one is worse than doing nothing.

| Rule | Why it exists |
|---|---|
| **Never fabricate a metric.** Report the real number or say plainly that none exists | The whole project's credibility rests on this |
| **Report exact deltas.** Never soften to "equivalent" or "within noise" | They corrected this once already, over a 0.003 F1 gap |
| **Say which figure is canonical** when several similar numbers exist | Test-set F1, leak-free subset and probe accuracy are all different numbers |
| **Malay UI copy is plain everyday Malay** — no technical English loanwords | `alamat` not `domain`, `pelik` not `luar biasa`. "Engine" has no everyday Malay: `enjin` reads as a car engine |
| **Present drafts before building**, especially user-facing copy and design | They have asked for this repeatedly and it has saved rework every time |
| **Security review reports findings first**, never fixes in the same pass | |
| **No path may promote feedback to training without human review.** Not a bulk action, not a seeder, not a convenience method | "If one exists, the data-poisoning defence is theatre" |
| **No emoji in the UI.** Inline SVG only | Stated directly |
| **The verified-domain gate is a safety mitigation, never improved model accuracy** | |
| **All secrets via platform environment variables, never committed** | |
| **MySQL, not Postgres** — the brief commits to it and nine documents cite it | Switching to suit a host is "the tail wagging the dog" |

---

## Where things actually stand

### Repository

- Current branch: **`feat/result-page-touchup`** at `e12aace`
- `master` is at `a64adb2`, **two commits ahead of `origin/master`**
- **`origin/master` is at `cca3666` — five commits behind local.** The masthead
  redesign and everything after it is **not pushed**. Push before deploying.
- GitHub: `ShafiqRashdi/ScamSense`, **private**, deliberately. Serving model
  weights are committed (38 MB); the collected corpus is **not**, and that rule
  has a documented consent rationale — do not "helpfully" commit it.

### Tests

**395 backend (1 skipped), 241 ML (7 xfail), pint and ruff clean.** Both suites
were green on 2026-09-29. The xfails are known defects held as executable
assertions — they report XPASS when fixed, which is the point.

### Models

| | |
|---|---|
| URL | `rf-url-v2.0.0-pathnorm`, test F1 **0.8979** |
| Message | `tfidf-msg-v3.0.0-collected-my`, test F1 **0.9836** on 1,113 messages |
| Corpus | **1,113 messages / 459 URLs** — unchanged since the September merge |

Artefacts are `compress=3` joblib: the URL forest is 38 MB (was 131.5) and loads
in 2.1s (was 18.0). That was a hard blocker — uncompressed it exceeded GitHub's
100 MB file limit, and the training corpus is not committed either, so a clean
checkout could neither load a model nor train one.

**DistilBERT has been measured twice and is still not shipped.** On 1,113 rows:
0.9959 against 0.9836, difference +0.0122, 95% CI **[-0.0043, +0.0317]** — still
spans zero. Always quote the interval, never the point estimate alone. Both
models now miss the *same single scam* of 121; the whole difference is false
positives, which is the cheaper error under this project's own asymmetry.

### Design

The public pages hang from a deep green masthead with the content card breaking
out of it; a dark footer bookends the page. Full reasoning, the contrast table
and the list of rejected ideas are in **`docs/adr/0012`** — read it before
changing anything visual. `PaletteContrastTest` enforces the palette by reading
the Sass variables and recomputing every pair, including the dark band.

Two colours have now been chosen by eye in this project and **failed the
arithmetic**. Compute, do not estimate.

---

## What is left, and who owns it

### Blocking — the user must do these

1. **Push to GitHub.** `origin/master` is five commits behind.
2. **Railway.** Not deployed yet. Hobby plan, ~$5/month.
   - **Set a hard usage limit at $5 first**, before creating the project. That
     turns "I might be billed more" into "I cannot be."
   - Target was **1 October**, to keep spend down while leaving margin.
   - Follow `docs/deployment.md`. It is thorough and current.
   - **Do not click Generate Domain on the ML service.** One click puts the
     prediction endpoint on the internet and breaks the architecture.
   - Use a **daily** cron (`15 3 * * *`, `php artisan scamsense:purge`), not the
     per-minute `schedule:run` the guide originally specified — the scheduler
     has exactly one daily job, and a 24/7 service booting Laravel 1,440 times a
     day would eat the credit for nothing.
3. **Rotate the admin password** on the deployed instance. `admin_2002` is in
   their shell history.
4. **UAT participants.** `docs/uat-questionnaire.md` and `docs/uat-form.html`
   exist; nobody has been recruited. The citation index says plainly there is
   nothing to cite.
5. **Screenshots for Figures 4.9–4.15.** Needs a running instance.

### Known gaps, already recorded honestly

`docs/citation-index.md` has a "Claims with no source yet" section. It is the
first place to look before writing any sentence in the report. Currently: no UAT
participants, no adversarial testing, contrast measured but not full WCAG
conformance, feedback loop not validated at scale, latency measured locally
only, corpus labelled by one person.

**Do not quietly close these by asserting something.** They are deliberate.

---

## Traps that have already cost time

- **`php artisan serve` caches the Vite manifest.** After `npm run build` the
  running server keeps serving deleted asset filenames. `optimize:clear` does
  **not** fix it. Restart the process.
- **Run `scamsense:doctor`, never `/health`.** `/health` is unauthenticated, so
  a service holding the wrong `ML_SERVICE_SECRET` reports `ok, models_loaded:
  true` while every signed request 401s and every verdict reads "We could not
  check this properly". That cost three debugging sessions.
- **Batch files must be pure ASCII.** An em dash makes the file UTF-8 and
  `start.bat` breaks. Use `ping -n` rather than `timeout /t`, which fails under
  redirected stdin.
- **CSS sibling combinators only reach *later* siblings.** A pill written before
  its inputs silently never matches — that shipped once and made both segment
  labels invisible.
- **`git` is not a backup for a file `git` has never tracked.** Model weights
  and corpus were gitignored; a backup directory exists at `.backups/` for
  exactly this reason.

---

## Useful commands

```bash
# Everything up, then verify the chain end to end
start.bat

# The check that actually proves a verdict will work
cd backend && php artisan scamsense:doctor

cd backend && php artisan test && ./vendor/bin/pint --test
cd ml-service && .venv/Scripts/python.exe -m pytest -q
```

---

## How to work on this

The user is technical, reads carefully, and will catch a softened claim. They
respond well to being told plainly when something is wrong — including when the
thing that is wrong is something you built an hour earlier. Several of the best
outcomes here came from measuring a claim instead of repeating it.

Show them a draft before building anything user-facing. Say what you would
refuse to build and why. Give exact numbers.
