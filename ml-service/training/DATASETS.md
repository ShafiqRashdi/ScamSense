# Datasets

Provenance for every corpus used to train a ScamSense model. The panel will ask where the data
came from, and "a Kaggle CSV" is not an answer. Every source gets a row before a single row of it
is trained on.

> **Status as of the last update to this file: no dataset has been obtained.**
> No URL or message model has been trained, and no metric for either appears anywhere in this
> repository. The training scripts run and are covered by plumbing tests, but they have never
> been executed against real data. Any number quoted before this notice is removed did not come
> from ScamSense.

---

## Required for each source

| Field | Why |
|---|---|
| Name and URL | Traceability |
| Licence | Whether it may be used and redistributed at all |
| Date obtained | Phishing corpora rot fast; a 2019 set describes 2019 attacks |
| Rows, and class balance | Determines whether class weighting is needed |
| Consent basis | Required for anything collected from real people |
| Preprocessing applied | Deduplication, redaction, normalisation |

---

## URL corpora — Phase 2

### Source 1 — *not yet obtained*

| Field | Value |
|---|---|
| Name | |
| URL | |
| Licence | |
| Date obtained | |
| Rows / balance | |
| Consent basis | Public dataset, no personal data |
| Preprocessing | Deduplicated on exact URL before splitting |

Candidates worth evaluating, in rough order of usefulness:

- **PhishTank** — community-verified phishing URLs, refreshed continuously. Registration is now
  required for bulk access. Strongest source for *current* attacks; heavily English and
  US/EU-weighted.
- **OpenPhish** — a free community feed alongside a commercial one. Check the licence terms for
  the free tier before use.
- **Kaggle phishing URL datasets** — convenient and pre-labelled, but frequently undated and of
  unclear provenance. If one is used, record which one exactly; several circulate with the same
  name and different contents.
- **Tranco / Majestic top sites** — a source of *legitimate* URLs. Needed because a phishing-only
  corpus cannot train a classifier, and because the negative class must be varied. Sampling
  legitimate URLs from a handful of domains teaches the model those domains rather than
  legitimacy.

**A caution that matters for this project.** These sources are English- and Western-heavy.
A model trained only on them will underperform on precisely the Malaysian campaigns ScamSense
exists to catch — `lhdn-refund.site`, `kwsp-semakan.top`, `tng-ewallet-reload.xyz`. Supplement
with locally collected phishing URLs and record them below.

### Source 2 — Malaysian phishing URLs, locally collected — *not yet started*

| Field | Value |
|---|---|
| Collection method | |
| Date range | |
| Rows | |
| Consent basis | |

Collection routes worth pursuing: URLs shared in Malaysian scam-awareness Facebook groups,
links reported to Semak Mule, campaigns circulated in family and community WhatsApp groups.

---

## Message corpora — Phase 3

### Source 1 — UCI SMS Spam Collection — *not yet obtained*

| Field | Value |
|---|---|
| URL | https://archive.ics.uci.edu/dataset/228/sms+spam+collection |
| Licence | CC BY 4.0 |
| Rows / balance | ~5,574 messages, ~13% spam |
| Consent basis | Public research dataset |

Dated (2012) and entirely English. Useful as a baseline and for establishing the pipeline, but
**it does not represent the target domain**: ScamSense's users receive Malay/English
code-switched messages. Treat it as scaffolding, not as the corpus.

### Source 2 — Malaysian scam messages, locally collected — *not yet started*

**This is the differentiator, and it is the long pole of the whole project.** Local-language
coverage is what distinguishes ScamSense from ScamShield, and it only exists if the corpus is
built. Start collecting in parallel with the earlier phases; it takes calendar time, not
working hours.

| Field | Value |
|---|---|
| Collection method | |
| Date range | |
| Rows | |
| Consent basis | |

Record for **every message**: where it came from, the date, and the basis on which it was
collected. For messages contributed by individuals, that means asking, and noting that they
agreed. A corpus that cannot answer "how did you get this" is a corpus that cannot be defended.

Run every collected message through the redaction rules before it enters the dataset — the same
Malaysian phone, IC, email and bank account patterns implemented in
`backend/app/Services/RedactorService.php`. A training corpus is a stored copy of the data like
any other.

---

## Rules for all corpora

1. **Deduplicate before splitting.** The same URL or message in both train and test inflates
   every reported metric. `train_url_model.py` enforces this for URLs.
2. **Never resample before splitting.** Oversampling the minority class first leaks copies of
   test rows into training. Prefer class weights.
3. **Split once, commit the indices.** `split_indices.json` is written beside every artefact so a
   reader can verify the test set was never trained on.
4. **The test set is opened once per model version.** Thresholds are chosen on validation.
5. **Nothing over 50 MB is committed** (AGENTS.md §5). Raw corpora live in
   `ml-service/training/data/`, which is gitignored. This file is how they are traced.
