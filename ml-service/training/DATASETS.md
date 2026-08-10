# Datasets

Provenance for every corpus used to train a ScamSense model. The panel will ask where the data
came from, and "a Kaggle CSV" is not an answer.

Raw files live in `ml-service/training/data/`, which is gitignored. This file is how they are
traced.

---

## URL corpus — `data/urls.csv` — **in use, model trained**

| Field | Value |
|---|---|
| Rows | 298,272, no exact duplicates |
| Balance | Exactly 50/50 — 149,136 phishing, 149,136 legitimate |
| Labels | `phishing` / `legitimate` |
| Unique registrable domains | 136,154 |
| Date obtained | 2026-08-10 |
| Consent basis | Public research datasets; no personal data |
| Preprocessing | Deduplicated on exact URL before splitting |

### Composition by source

| Source | Legitimate | Phishing | Total |
|---|---:|---:|---:|
| PhiUSIIL | 63,777 | 96,721 | 160,498 |
| Kaggle set 2 | 58,759 | 47,160 | 105,919 |
| Tranco | 23,936 | 0 | 23,936 |
| Kaggle phishing set | 2,664 | 5,255 | 7,919 |

### The finding that shaped the methodology

**56.7% of rows sit on a registrable domain that appears more than once**, and the most frequent
domains are free hosting and CDN platforms:

```
web.app 6,869    firebaseapp.com 6,682   weeblysite.com 6,189   google.com 4,789
cf-ipfs.com 3,882   repl.co 3,592   workers.dev 3,172   r2.dev 2,390
```

These platforms are abused at scale for phishing, so thousands of malicious URLs share a handful
of domains. Under a random split those domains land in **both** train and test, and the model can
score well by memorising "web.app is phishing" without learning anything about phishing
structure.

Measured directly: a random split leaves **2,979 registrable domains in both train and test**.
The reported F1 then partly measures how concentrated the corpus is rather than how well the
classifier generalises to a domain it has never seen.

Training therefore defaults to `--split-strategy domain`, which keeps every registrable domain
inside one partition. See `docs/adr/0004` for the measured difference.

377 domains carry both labels. That is not necessarily noise — legitimate hosting platforms
genuinely serve both — but it is a further reason not to let a domain straddle the split.

---

## Message corpus — `data/messages.csv` — **held for Phase 3, with a caveat that limits its use**

| Field | Value |
|---|---|
| Rows | 1,732 unique Malay messages |
| Balance | Imbalanced: 1,215 scam / 517 legitimate — use class weights, not resampling |
| Labels | `scam` / `legitimate` |
| Extra columns | `language`, `split`, `source`, `scam_type` |
| Consent basis | Synthetic; no real person's message is included |

### Measured: this corpus cannot support an evaluation at all

It was supplied as templated synthetic data with authentic Malaysian context, flagged as "good
for a v1 baseline but not a valid final holdout". Measurement showed the limitation is more
severe: it cannot support a baseline either.

**1,732 rows are generated from 56 templates**, and 99.1% of rows sit on a repeated template.
Three templates account for 65% of the corpus, and one legitimate template covers 394 of the 517
legitimate rows — 76% of the entire negative class.

| Split strategy | Test F1 | What it actually measures |
|---|---:|---|
| Random | **1.0000** | Template memorisation — every test template was seen in training |
| Template-grouped | **0.0000** | Nothing — the test partition drew 3 templates, all one class |

The supplied `split` column shares 32 templates between train and test, so it has the same defect
as a random split.

**No figure from this corpus may be reported as a performance result.** See `docs/adr/0005`.
The effective sample size is 56, not 1,732 — `train_message_model.py` prints the template count
on every run so this cannot be misjudged again.

The corpus is retained as a smoke-test fixture proving the pipeline runs end to end. That is its
only remaining use.

### Real Malaysian messages — *collection in progress, and now the blocker for Phase 3*

The genuine corpus. Local-language coverage over authentic messages is what distinguishes
ScamSense from ScamShield, and **the entire message-classifier objective now depends on it** —
there is no interim figure, because the synthetic corpus cannot produce one.

What it needs to contain, from ADR 0005:

1. **300+ distinct scam messages and 300+ distinct legitimate messages** as a floor, ideally
   1,000+ total. Distinct *messages*, not rows: the template count is what matters.
2. **A legitimate class as varied as the scam class.** The synthetic corpus failed partly
   because its negative class was effectively one template. Real legitimate messages — bank TAC
   codes, delivery notifications, appointment reminders, telco top-ups, government announcements
   — must come from as many distinct sources as the scam examples.
3. **Real messages**, not generated ones, however authentic the vocabulary.

| Field | Value |
|---|---|
| Collection method | |
| Date range | |
| Rows | |
| Consent basis | |

Record for every message where it came from, the date, and the basis on which it was collected.
Run each one through the redaction rules in `backend/app/Services/RedactorService.php` before it
enters the dataset — a training corpus is a stored copy of personal data like any other.

---

## Rejected sources

Recording rejections matters as much as recording inclusions: it demonstrates the corpus was
audited rather than accepted.

### Malay `emails_cleaned.csv` — **rejected, label contamination**

Two independent defects found on inspection:

- **70 clause fragments appeared under both labels.** Identical text labelled both scam and
  legitimate makes those rows pure noise, and worse, guarantees leakage across any split.
- **140 texts labelled legitimate contained clear phishing markers.** Mislabelled positives in
  the negative class teach the model that phishing language is benign — the most damaging
  possible label error for this task.

Training on it would have depressed real performance while potentially *raising* the reported
score, since the same contaminated rows would appear in the test set. Excluded entirely.

---

## Rules for all corpora

1. **Deduplicate before splitting.** Enforced in `load_dataset`.
2. **Group by registrable domain when splitting URLs.** Enforced by `--split-strategy domain`,
   which is the default, and asserted by a test.
3. **Never resample before splitting.** Prefer class weights.
4. **Split once, commit the indices.** `split_indices.json` ships beside every artefact.
5. **The test set is opened once per model version.** Thresholds are chosen on validation.
6. **Nothing over 50 MB is committed.** Raw corpora and model weights are gitignored; the JSON
   metadata beside each artefact is tracked, because it is the evidence for Chapter 4.
