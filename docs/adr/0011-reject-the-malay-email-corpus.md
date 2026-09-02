# ADR 0011 — The Malay email corpus is rejected for label contamination

- **Status:** Accepted
- **Date:** 2026-08-11
- **Phase:** 3 (message classifier) — corpus assembly
- **Outcome:** `emails_cleaned.csv` is excluded entirely. It contributes no rows to any split.

---

## Summary

> **A third candidate corpus was inspected and rejected before training. Its labels
> contradict themselves, and training on it would have depressed real performance while
> *raising* the reported score.**

This is the second of two rejections. ADR 0005 rejected the synthetic Malaysian message
corpus because no valid metric could be produced from it. This one rejects a corpus that
would have produced a metric — a flattering, meaningless one.

Recorded as a decision rather than left in `DATASETS.md` alone because the two rejections
together are the corpus-audit story, and an examiner asking "how do you know your training
data is sound?" needs both halves.

---

## Context

Phase 3 needed Malay-language message data. Three candidate corpora were available:

| Corpus | Rows | Outcome |
|---|---:|---|
| `messages.csv` (synthetic, templated) | 1,732 | Rejected — ADR 0005 |
| `emails_cleaned.csv` (Malay email) | — | **Rejected — this ADR** |
| `messages_my.csv` (collected) | 528 | **Accepted** — ships as `tfidf-msg-v2.0.0-collected-my` |

Only the third survived. The message model is therefore trained on 528 rows rather than
several thousand, and that scarcity is the direct cause of the generalisation limit reported
in `RESULTS.md`. The trade is deliberate and worth stating plainly: a smaller honest corpus
was chosen over a larger contaminated one.

---

## What inspection found

Two independent defects, either of which is disqualifying.

**70 clause fragments appear under both labels.** Identical text is labelled scam in one row
and legitimate in another. Those rows are not merely noise — they guarantee leakage across
any split, because the same string necessarily lands on both sides of the partition. No split
strategy fixes this, exactly as no split strategy fixed the template collapse in ADR 0005.

**140 texts labelled legitimate contain clear phishing markers.** Mislabelled positives sitting
in the negative class is the most damaging label error available for this task: it teaches the
model that phishing language is benign, which is precisely the discrimination the model exists
to make.

---

## Why the reported score would have gone *up*

This is the part worth understanding, because it is counter-intuitive and it is what makes
contaminated data more dangerous than missing data.

The contaminated rows would appear in the test set as well as the training set. A model that
learned "this phishing-shaped text is legitimate" from the training half would be rewarded for
that same error on the test half. The metric would improve while the product got worse.

A corpus that quietly inflates its own evaluation cannot be corrected downstream. There is no
threshold, no reweighting and no split strategy that recovers a true figure from labels that
disagree with themselves, because there is no ground truth left to recover.

---

## Options considered

**Clean it and use it.** Rejected. The 70 duplicated fragments could be dropped mechanically,
but the 140 mislabelled rows were found *by inspection*, not by a rule — there is no way to
know how many more went unnoticed. Cleaning a corpus by hand until it stops looking wrong
produces a corpus that has been fitted to the inspector, not corrected.

**Use it for training only, evaluate on the collected corpus.** Rejected. It removes the
inflated-metric problem but keeps the mislabelling, which would still teach the model that
phishing language is benign. A clean test set measures that damage honestly; it does not
prevent it.

**Reject it entirely.** Accepted.

---

## Consequences

**Accepted cost.** The message model trains on 528 rows. That scarcity is visible in the
generalisation probe — 70.8% on hand-written out-of-corpus messages, with misses clustering
in the categories holding fewest rows — and it is why the DistilBERT comparison cannot be
resolved. Those limits are the price of this decision and are reported as such.

**What it buys.** Every figure quoted for the message model is measured on data whose labels
were checked. That is a claim the project can defend under questioning; a higher figure from
this corpus would not have been.

**Reproducibility.** The file is excluded, not deleted — the finding can be re-verified by
anyone who repeats the inspection.

**Residual risk.** The collected corpus was labelled by one person and has not been
independently audited for the same defect. It is small enough to read end to end, which is a
weaker guarantee than inter-annotator agreement and should be described as such rather than as
verification.
