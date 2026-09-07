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

### Real Malaysian messages — `data/messages_my.csv` — **in use, model trained**

The genuine corpus. Local-language coverage over authentic messages is what distinguishes
ScamSense from ScamShield, and the message-classifier objective rests entirely on it.

| Field | Value |
|---|---|
| Rows | **1,113** — 609 scam, 504 legitimate |
| Distinct templates | 940 (84%) |
| Language | predominantly Malay, with code-switched and English rows |
| Split | 726 train / 164 validation / 223 test, shipped with the corpus |
| Source tag | `collected_my` throughout |
| Collected in | two rounds: 528 rows to August 2026, a further 585 in September |
| Serving artefact | `tfidf-msg-v3.0.0-collected-my`, test F1 0.9836 |

**Collected by one person** from their own phone, family members' phones, friends, classmates,
WhatsApp groups and published scam alerts. Per-row provenance is recorded in
`messages_my_with_metadata.csv` under `channel` and `received_from`, so any row can be traced
back to how it was obtained. That is a stronger provenance record than most corpora of this size
carry, and it is also the honest limit: **one annotator, no inter-annotator agreement**. Describe
it as read end to end, never as verified.

Every message is redacted before it enters the corpus. Phone numbers, identification numbers,
account numbers and names are replaced with `[PHONE]`, `[IC]`, `[ACCOUNT]` and `[NAME]`.
`training/validate_corpus.py` checks this and will not let unmasked personal data through; the
masking costs nothing at training time because `normalise()` substitutes `[NUM]` for every number
before the vectoriser sees it.

#### The second round was targeted, not uniform

The generalisation probe on the 528-row corpus showed recall tracking category support: every
missed scam sat in a category holding fewer than 21 rows, every category above 39 was caught.
Collection was weighted accordingly.

| Category | Round 1 | Round 2 | Total |
|---|---:|---:|---:|
| Parcel scam | 18 | +93 | **111** |
| Lucky draw / prize | 20 | +86 | **106** |
| Macau scam / impersonation | 19 | +83 | **102** |
| Investment scam | 78 | +10 | 88 |
| Job offer scam | 76 | +8 | 84 |
| Phishing link | 40 | +37 | 77 |
| Loan scam | 39 | +6 | 45 |

Out-of-corpus probe accuracy moved from 70.8% to 91.7% and missed scams from 6 of 12 to 2 of 12.
See `docs/RESULTS.md`.

#### Collection workflow

`scamsense_corpus_collection.xlsx` is the collection sheet — **deliberately gitignored**, because
it holds real messages sent to real people, which is the same reason `data/` is excluded.

```bash
python training/validate_corpus.py     # what is wrong, before anything is written
python training/merge_corpus.py --dry-run
python training/merge_corpus.py        # writes the three corpus files
```

The merge assigns splits **by template, never by row**, so a template group is indivisible and
cannot straddle a partition. Existing rows keep their partition, which is what allows a figure
measured after a merge to be compared with one measured before it.

### Collected Malaysian URLs — `data/legit_urls_my.csv`

459 rows, all legitimate, **76% carrying a real path** (up from 50%). Sectors: banking,
e-wallet, telco, government, delivery, utility.

**These are evidence, not training signal, and the report must not claim otherwise.** At 459 rows
they are 0.15% of a 298,000-row corpus and cannot have moved the model's weights. Their value was
diagnostic: they surfaced the trailing-slash defect where a single `/` moved `shopee.com.my` from
0.0391 to 0.8814. Path-bearing URLs are collected specifically because ADR 0006 found the model's
remaining false positives are deep paths on genuine institutional sites — a bare domain exercises
none of that.

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
