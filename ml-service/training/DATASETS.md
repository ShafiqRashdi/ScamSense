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

### This corpus is templated synthetic data

Generated from templates carrying authentic Malaysian context — LHDN, Touch 'n Go, Bantuan Tunai
Rahmah, SAPS. That makes it genuinely useful for a v1 baseline: it exercises the right
vocabulary, the right brands and the right code-switching, none of which the English public
corpora contain.

**It is not a valid final holdout.** Templated data means the test partition shares generator
structure with the training partition, so a model can score highly by learning the templates.
Any figure measured on it describes performance on the template set, not on real Malaysian scam
messages.

Consequence for Phase 3, and this is not negotiable in the write-up: a metric from this corpus
must be reported as *"F1 on a synthetic template corpus"*, never as ScamSense's message-detection
accuracy. The real number requires the corpus below.

### Real Malaysian messages — *collection in progress, separate effort*

The genuine holdout. Local-language coverage over authentic messages is what distinguishes
ScamSense from ScamShield, and a defensible Chapter 4 figure for the message classifier depends
on it.

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
