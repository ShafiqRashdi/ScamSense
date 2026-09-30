# Handoff — where ScamSense is

**Written 2026-09-29, corrected 2026-09-30 against the repo. Presentation is
Wednesday 7 October 2026.**

Read this first, then `AGENTS.md` for the standing rules. This file is *state*;
`AGENTS.md` is *law*. If they disagree, `AGENTS.md` wins and this file is stale.

---

## Do this next, in this order

1. ~~Get Shafiq's three Malay decisions and update the 4 tests.~~ Done
   2026-09-30: 420 tests, 419 pass, 1 skipped; pint clean.
2. **Commit** the redesign, the export fix and the test-key fix.
3. ~~**Fix `docs/deployment.md`** (the `railway run` defect) and **the AGENTS.md
   URL figure**.~~ Done 2026-09-30. (Described under "Known defects to fix before
   deploying", kept for the record.)
4. **Shafiq pushes.** Then delete the two `backup/*` branches.
5. **Railway:** set the $5 hard cap first; no public domain on the ML
   service; daily purge cron.
6. **Run the doctor inside Railway**, not from the local PC.
7. **Rotate the admin password.**
8. **Screenshots for Figures 4.9–4.15.**

**Skills:** `.claude/skills/` now holds 20 skills, including
`fyp-documentation`, `laravel-feature`, `ml-endpoint`, `security-review` and
`browser-qa`. Read `SKILLS.md` and use them where they apply; no session has
read the new ones yet.

---

## Malay wording: decided 2026-09-30

Shafiq's three decisions, now applied and tested:

- **(a) Footer:** *"Jika anda telah terkena scam, segera hubungi bank anda dan
  laporkan kepada polis."* The bank is back in.
- **(b) "Pautan Link"** is deliberate. Keep it.
- **(c) "scam" and "letakkan"** win across the whole site, replacing
  "penipuan" and "tampal". Exception: the phone's own menu words "Salin" /
  "Tampal" stay where the site names or draws that menu.

**Standing copy decisions, already made:**

- Malay copy uses "AI", not "kecerdasan buatan".
- About and How to use show no accuracy percentages.
- The verified-address list is always described as **not AI**.
- NSRC 997 hours are daily, 8am–8pm (confirmed by Shafiq).
- The tap-to-fill example buttons were removed on purpose. Do not bring them
  back.

---

## Known defects to fix before deploying

**`docs/deployment.md` uses `railway run` where it must not.** Five commands
(lines 204, 221, 224, 255, 363: admin seeder, `admin-password` twice, purge,
doctor) use `railway run`. That runs the command **on the local PC** with
Railway's variables injected, and the local PC cannot reach
`*.railway.internal`, so it cannot reach the ML service or MySQL. These
commands must run inside the deployed web service (`railway ssh`; confirm the
syntax with `railway --help`). **Not fixed yet. Propose the edit to Shafiq
before making it.**

**AGENTS.md §9 Phase 2 "CANONICAL FIGURE" is outdated.** It says URL F1
**0.8984** from `rf-url-v1.2.0-no-https`. The served model is
`rf-url-v2.0.0-pathnorm` and `docs/RESULTS.md` gives **0.8979**, a change of
−0.0005. `RESULTS.md` is the source of truth. Fix the line, and never quote
0.8984 in slides.

---

## Latest session — 2026-09-30: public UI redesign (uncommitted)

> **The design is FROZEN for deployment.** Shafiq called this the last design
> change before deploying. The next job is shipping (commit, push, Railway),
> not more visual work — push back if a session drifts into restyling.

Approved from a clickable mockup, then ported into the real code. Full
reasoning, contrast table and rejected ideas: **ADR 0012, amendment of
2026-09-30**. The redesign is not committed yet (the rest of `master` is
committed but not pushed; see "Repository"). The working tree also still holds
Shafiq's own uncommitted work from before (About / How to use pages, the removed
example buttons, and Malay wording edits in `lang/ms.json`).

**What changed**

| Where | Change |
|---|---|
| Home | Form beside the heading on desktop (whole task in the first 1280x800 screen); eyebrow, three promises, paste tip; "How it works" as an `<ol>` with large numerals; privacy notice as a panel with icons |
| Result | Two-column verdict with a display-sized heading; "What to do" tinted in the band colour, one sentence per line (split in the view, lang files unchanged); feedback laid out side by side; outlined back button; "Warn your family" removed |
| All public pages | New footer (help box, About / How to use, project line); About and How to use get the entrance and the new numerals |
| Background | Static only: guilloche ribbon (`public/images/guilloche.svg`, from `backend/resources/svg/guilloche.mjs`), ghosted scam specimen in the home band. Nothing moves |
| Motion | Clip-path segment control (replaces the pill), press feedback, label crossfade, checklist entrance, linear loading rail, home entrance, once-only scroll reveal. All off under reduced motion |

**Files touched:** `backend/resources/css/app.scss`, `resources/js/app.js`,
`views/layouts/public.blade.php`, `views/verify/index.blade.php`,
`views/verify/result.blade.php`, `views/pages/about.blade.php`,
`views/pages/howto.blade.php`, `lang/ms.json`, `tests/Feature/VerifyPageTest.php`,
`tests/Feature/PaletteContrastTest.php`, `docs/adr/0012…`, `AGENTS.md`; new
`public/images/guilloche.svg` and `resources/svg/guilloche.mjs`.

**Tests:** 420 backend. Before the Malay decisions, 4 failed; they were
exactly the Malay-wording tests: they assert the old wording ("Adakah ini
penipuan?", "tampal", the footer's "segera hubungi bank anda…") that Shafiq's
uncommitted `ms.json` edits changed. **Resolved 2026-09-30** (see "Malay wording:
decided"): the suite is now 419 pass, 1 skipped, 0 fail.

**Open for Shafiq** (the footer, "Scam" and "Pautan Link" questions are now
decisions (a)–(c) near the top of this file)

1. Review the seven new Malay strings (end of the promise block in `ms.json`).
   The paste tip deliberately says "Salin" / "Tampal" because those are the
   phone's own menu words.
2. **Content bug, not design:** a high-band verdict with no located reasons
   shows *"did not clearly match either a scam or an ordinary message"*
   (`verdict.indicators_none`), which contradicts "Likely a scam". The copy was
   written for the medium band; it needs band-specific wording.
3. Check on a real phone. Headless Edge cannot render below 496px wide.
4. The new "Salin" / "Tampal" strings in `ms.json` (the drawn phone menu on
   How to use) are part of the same Malay review.

**Also done, after approval the same day:**
- **How to use is drawn**: each step beside a miniature of the real control,
  both example addresses drawn with the result page's marks, "lost money" as
  three cards with 997 set large. Built from existing strings, so only
  "Copy"/"Paste" are new.
- **"Warn your family" removed** (Shafiq: not needed): view, clipboard JS,
  styles, strings in both locales.
- **Dead-code pass**: every `sc-` class, keyframe and palette variable is now
  used; four rules that matched nothing were deleted (`.btn-check`,
  `.btn[aria-busy]`, `.btn-outline-primary` hover, a `.sc-card` grey-box
  rule); `.sc-bar` merged; the unused "STEP" string removed.
- `defangs urls embedded inside a message` was passing only because the
  removed bubble repeated the link; it now checks the echo's visible text.

**Bug fix (not design), same day: admin "Download everything" gave a blank
page.** Cause: `CsvExporter` called `flush()` after each chunk, which sends the
HTTP response headers even when the rows are going into a file for the zip, so
the zip response then failed with "headers already sent" (in
`storage/logs/laravel.log`). Single downloads were fine because their headers
were already out. Fix in `app/Services/Admin/CsvExporter.php`: flush the
response only when writing to `php://output`, otherwise `fflush` the file.
Also found: the zip was sent `Cache-Control: public`; it is now
`no-store, private`, like the single downloads. Both pinned in
`AdminExportTest` (28 pass). Tests cannot see the headers bug, so it was
verified by fetching the zip from a real PHP web server: 200,
`application/zip`, six files, nothing left in `storage/app/exports`.
**Re-check by clicking "Download everything" in the admin area after
restarting `php artisan serve`.**

**Fixed the same day:** in Malay the home heading ran under the form and the
example messages were cropped. Home heading and form are now one grid in
`<main>` (`.sc-home-top`) instead of a fixed-distance pull from the header.
Also: the card now sets its own text colour (inside the green band it had
inherited white labels).

**If the page still looks old:** restart `php artisan serve` (see the Vite
manifest trap below), then hard-refresh.

**Tests no longer call VirusTotal or Safe Browsing (fixed 2026-09-30,
uncommitted).** The suite used to read the real keys from `.env`. About 27 URL
tests fake only `*/predict/*`, so their threat-intel calls went out for real,
and `RefreshDatabase` wiped the 24-hour cache every run. That used up the
500-a-day VirusTotal quota on 29 September (it resets at 00:00 UTC, 8:00 a.m.
Malaysia time). `phpunit.xml` now blanks `VIRUSTOTAL_KEY` and
`GOOGLE_SAFE_BROWSING_KEY` with `force="true"`, and
`tests/Unit/ThreatIntelKeysTest.php` guards that.

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

- **`master` is at `f6c604c`, 8 commits ahead of `origin/master`, not
  pushed.** Push before deploying. `feat/result-page-touchup` now equals
  `master`.
- **On 29 September the unpushed history was rewritten** so that report drafts
  (`.docx`) never reach GitHub. The old history is on
  `backup/before-docx-cleanup` and `backup/before-docs-cleanup`. They are
  local only: **never push them**, and delete them after the push.
- GitHub: `ShafiqRashdi/ScamSense`, **private**, deliberately. Serving model
  weights are committed (38 MB). The collected corpus,
  `scamsense_corpus_collection.xlsx`, stays in the repo root because the
  training scripts read it there, and it is gitignored for consent reasons.
  **Never commit it**, not even "helpfully".
- **Report material lives outside the repo**, in `C:\dev\ScamSense-Report\`:
  the chapters, reference PDF, figure PNG, word-prompts, `KICKOFF_PROMPT.md`
  and `ID Pass.txt`. `.gitignore` excludes `*.docx`, `*.pdf` and `~$*`.

### Tests

**Backend: 420 tests, 419 pass, 1 skipped** (2026-09-30). **ML: 241 (7 xfail)**, ruff clean. The xfails are known defects held as executable
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
out of it; a dark footer bookends the page. Since 2026-09-30 the home form sits
beside the heading on desktop, and a static security-print pattern runs under
the band. Full reasoning, the contrast table and the list of rejected ideas are
in **`docs/adr/0012`** — read it before changing anything visual. `PaletteContrastTest` enforces the palette by reading
the Sass variables and recomputing every pair, including the dark band.

Two colours have now been chosen by eye in this project and **failed the
arithmetic**. Compute, do not estimate.

---

## What is left, and who owns it

### Blocking — the user must do these

1. **Push to GitHub.** `origin/master` is 8 commits behind. Do not push the
   `backup/*` branches.
2. **Railway.** Not deployed yet. Hobby plan, ~$5/month.
   - **Set a hard usage limit at $5 first**, before creating the project. That
     turns "I might be billed more" into "I cannot be."
   - Target was **1 October**, to keep spend down while leaving margin.
   - Follow `docs/deployment.md`, **after** fixing its `railway run` defect
     (see "Known defects to fix before deploying").
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
- **The VirusTotal free tier is 4 lookups a minute and 500 a day, and the
  deployed site shares the key.** In a live demo, checking more than 4 new
  links in a minute gives partial verdicts. Repeated links are cached for 24
  hours, so rehearse with the links you will show.
- **The doctor's URL probe shows a false positive.** `scamsense:doctor` checks
  `https://www.utm.my/about/`, which comes back "scam at 0.3179" (threshold
  0.3105). That is the ADR 0006 false-positive limit. `utm.my` is not
  `.edu.my`, so the verified-address gate does not catch it. The doctor proves
  the chain works, not that the verdict is right. Explain this if the panel
  sees it; do not change the probe unasked.
- **XML comments in `phpunit.xml` must not contain `--`**, or PHPUnit refuses
  to start.
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
