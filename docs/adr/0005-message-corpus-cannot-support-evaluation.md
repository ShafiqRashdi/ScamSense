# ADR 0005 — The synthetic message corpus cannot support a valid evaluation

- **Status:** Accepted
- **Date:** 2026-08-10
- **Phase:** 3 (message classifier) — **blocked**
- **Outcome:** No message classifier is shipped. DistilBERT fine-tuning is deliberately deferred.

---

## Summary

> **There is no valid message-classifier metric, and none can be produced from the current
> corpus by any split strategy.**
>
> `messages.csv` contains 1,732 rows generated from **56 templates**. A random split reports
> **F1 = 1.0000** by memorising templates. A template-grouped split produces a degenerate,
> single-class test partition and reports **F1 = 0.0000**. Neither number describes the ability
> to detect scam messages, and no figure from this corpus may appear in Chapter 4 as a
> performance result.
>
> Phase 3 is blocked on the collection of real Malaysian messages, which was already in progress
> separately.

## Context

The corpus was supplied as templated synthetic data with authentic Malaysian context — LHDN,
Touch 'n Go, Bantuan Tunai Rahmah, SAPS — and was correctly flagged on delivery as "good for a
v1 baseline but not a valid final holdout". Measurement showed the limitation is more severe
than that: it cannot support a baseline either.

## What the corpus actually contains

Templates were identified by normalising each message to its skeleton — substituting URLs and
numbers, stripping punctuation and case — which is the same transformation
`text_preprocess.normalise` applies before classification.

| Property | Value |
|---|---:|
| Rows | 1,732 |
| Exact duplicate texts | 0 |
| **Unique templates** | **56** |
| Rows sitting on a repeated template | 1,717 (99.1%) |
| Scam templates / legitimate templates | 31 / 25 |
| Median rows per legitimate template | 1 |
| Median rows per scam template | 4 |

Three templates account for 65% of the corpus:

```
394 rows  legitimate  "resit rasmi bagi transaksi txn [NUM] berjaya diproses..."
392 rows  scam        "notis lhdn anda mempunyai tunggakan cukai rm [NUM]..."
342 rows  scam        "bantuan tunai rahmah semak status kelayakan rm [NUM]..."
```

**The effective sample size is 56, not 1,732.** Row count measures how many times the generator
ran, not how many independent examples exist.

Two further distortions compound this:

- **One legitimate template covers 394 of 517 legitimate rows — 76% of the entire negative
  class.** The other 24 legitimate templates have a median of one row each.
- **Six of the scam templates are the same message with the brand swapped** — "Tahniah! Anda
  terpilih memenangi hadiah RM[NUM] dari X" for Touch 'n Go, J&T Express, Lazada, Shopee, Pos
  Laju and Grab, together 389 rows. Counted as distinct templates, they represent one rhetorical
  pattern. Genuine pattern diversity is therefore below 56.

## What was measured

TF-IDF (character n-grams, 2–5, `char_wb`) with Logistic Regression and balanced class weights.
The `ml-endpoint` skill requires this baseline before any transformer fine-tune.

### Random split — F1 = 1.0000

| | Accuracy | F1 (scam) | Precision | Recall | ROC AUC |
|---|---:|---:|---:|---:|---:|
| Validation | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |
| Test | 1.0000 | 1.0000 | 1.0000 | 1.0000 | 1.0000 |

Zero errors on 260 test rows. This is not a strong model; it is a model that has seen every test
template during training. The supplied `split` column has the same defect — it shares 32
templates between train and test.

A perfect score on a real task is evidence of leakage, not of quality.

### Template-grouped split — F1 = 0.0000

Grouping by template so no skeleton appears in more than one partition, the corpus collapses:
with only 56 groups, `StratifiedGroupKFold` cannot produce balanced folds. The test partition
drew **3 templates, all scam** — 738 rows, zero legitimate examples — and the model, thresholded
on a validation set that was itself only 6 templates, classified all 738 as legitimate.

| | Rows | Templates | F1 (scam) |
|---|---:|---:|---:|
| Validation | 84 | 6 | 1.0000 |
| Test | 738 | 3 | 0.0000 |

Both numbers are artefacts of the partition, not measurements of the model. A three-template
test set cannot estimate anything, and a single-class test set cannot compute a meaningful F1 at
all.

## Decision: do not fine-tune DistilBERT on this corpus

The skill sequence is baseline first, then DistilBERT, then an honest comparison — with a
negative result being a legitimate Chapter 4 finding. That sequence presumes a baseline worth
beating. Here there is none: the comparison would be between two numbers that both measure
template memorisation.

Fine-tuning `distilbert-base-multilingual-cased` on 39 training templates would cost hours of CPU
and produce:

- a near-perfect score under a random split, indistinguishable from the baseline's 1.0000, and
- noise under a grouped split, from a test set of three templates.

Neither supports the "does DistilBERT earn its cost?" question ADR 0001 committed to answering.
Worse, producing the number at all creates something quotable: a figure in `metrics.json` that a
later reader — or a tired student the night before submission — could lift into a table.

The decision is therefore to **stop Phase 3 here** and resume when a real corpus exists. The
pipeline is built and tested, so resumption is a single command.

## What a usable corpus needs

For a defensible F1 on the message task:

1. **Genuine message diversity, not row count.** Target 300+ distinct scam messages and 300+
   distinct legitimate messages as an absolute floor, ideally over 1,000 total. The relevant
   count is distinct messages, and `train_message_model.py` now prints the template count on
   every run precisely so this cannot be misjudged again.
2. **A legitimate class as varied as the scam class.** The current negative class is one
   template. Real legitimate messages — bank TAC codes, delivery notifications, appointment
   reminders, telco top-ups, government announcements — must come from as many distinct sources
   as the scam examples do.
3. **Real messages.** Templated generation cannot produce the variation that makes a held-out
   set meaningful, however authentic the vocabulary.
4. **Recorded provenance and consent** for every message, per `training/DATASETS.md`.

The synthetic corpus retains one legitimate use: as a smoke-test fixture proving the pipeline
runs end to end. It is kept for that purpose only.

## Consequences

- `/health` continues to report a null message-model version, which remains the honest answer.
- Objective O1 is half met: the URL task has a defensible F1 of 0.8984 (ADR 0004); the message
  task has no figure at all.
- Phases 4, 5 and 6 are **not blocked**. The prediction API, the Laravel integration and the
  public UI can all be built and tested against the contract, and AGENTS.md §2 rule 4 already
  requires the system to degrade gracefully when a model is unavailable — which is now simply
  the real state of the message classifier rather than a hypothetical.
- Both artefacts are retained with their metrics, since the 1.0000/0.0000 pair **is** the
  finding. Each carries a `REPORTING_QUALIFIER` field stating it is not a performance result.
- This is a genuine Chapter 4 contribution. Demonstrating that a corpus cannot support the
  evaluation it appears to support, and quantifying it, is a stronger methodological result than
  an unexamined 0.99 would have been.
