# ADR 0009 — DistilBERT scores higher, but not measurably, and is not shipped

- **Status:** Accepted
- **Date:** 2026-08-12
- **Phase:** 3 (message classifier)
- **Shipped model:** `tfidf-msg-v2.0.0-collected-my`
- **Experiment artefact:** `distilbert-msg-v1.0.0-experiment` (metrics only, weights not saved)

---

## Summary

> DistilBERT scored **F1 0.9913** against the TF-IDF baseline's **0.9735** — a point-estimate
> advantage of **+0.0179**.
>
> A paired bootstrap over the 106 test messages puts the 95% interval on that difference at
> **[−0.0167, +0.0560]**. **The interval spans zero.** On this corpus DistilBERT has not been
> shown to beat the baseline, notwithstanding the larger point estimate.
>
> The baseline ships. DistilBERT is not deployed.

## Why the experiment was run at all

The proposal names DistilBERT, so a panel will ask whether it was tried. "We did, and here is the
number" is a far better answer than "we didn't". The `ml-endpoint` skill also requires the
comparison to be reported either way, treating a negative result as a legitimate finding rather
than a failure.

It was run once, deliberately, and the weights were not saved.

## The measurement

Same corpus, same splits (taken from the corpus, never recomputed), same threshold-selection
procedure on validation, same test set opened once. Only the model differs.

| | TF-IDF + LogReg | DistilBERT | Difference |
|---|---:|---:|---:|
| Test F1 (scam) | 0.9735 | **0.9913** | +0.0179 |
| Precision (scam) | 1.0000 | 1.0000 | 0 |
| Recall (scam) | 0.9483 | 0.9828 | +0.0345 |
| ROC AUC | 0.9896 | 0.9932 | +0.0036 |
| Missed scams (of 58) | 3 | **1** | −2 |
| False positives (of 48) | 0 | 0 | 0 |

**In absolute terms the entire advantage is two messages.** Both models produce zero false
positives on the legitimate class.

### The confidence interval is the actual result

A paired percentile bootstrap, 5,000 resamples of the 106 test rows, scoring both models on each
resample so the comparison stays paired:

| | |
|---|---|
| 95% CI on F1(DistilBERT) − F1(TF-IDF) | **[−0.0167, +0.0560]** |
| Resamples favouring DistilBERT | 77.2% |

77.2% is suggestive and nothing more. An interval spanning zero means a corpus this size cannot
distinguish the two models, and reporting +0.0179 as though it were an established improvement
would be an overclaim of exactly the kind this project has avoided elsewhere.

The reason is sample size, not modelling. With 58 positives in the test set, the standard error
on recall alone is around 0.03, so differences smaller than roughly six points are inside the
noise. The observed difference is 1.8 points.

## Decision: ship the baseline

Three reasons, in order of weight.

**1. The improvement is not demonstrated.** The interval spans zero. Deploying a model on the
strength of a difference the evaluation cannot resolve would mean choosing by point estimate,
which is the habit that produces unreproducible results.

**2. The cost is large and certain, where the benefit is small and uncertain.** DistilBERT is
roughly 250 MB of weights plus 1–2 GB of runtime memory, against a TF-IDF artefact measured in
kilobytes. ADR 0001 deferred the deployment target specifically to keep a small VPS viable, and
ADR 0004 accepted a real 0.0028 F1 reduction to shrink the URL model from 432 MB to 130 MB.
Adding a transformer would discard both of those decisions to buy two test messages.

**3. Latency.** Objective O2 allows three seconds end to end. TF-IDF inference is sub-millisecond;
a CPU transformer forward pass is two to three orders of magnitude slower, and on the hardware
this project can actually deploy to that is a meaningful share of the budget.

If the corpus grows to a few thousand messages, this decision deserves revisiting — at that size
the evaluation could resolve a difference of this magnitude, and the answer might change.

## What would have changed the decision

Recorded so the reasoning is falsifiable rather than a preference:

- A 95% interval excluding zero, at any effect size.
- A materially better **recall** at equal precision on a test set large enough to measure it —
  missed scams are the costly error for this project.
- Evidence of better generalisation to message types absent from the training data, which 528
  messages across 18 categories cannot demonstrate.

## Consequences

- `tfidf-msg-v2.0.0-collected-my` is the serving message model.
- Torch and transformers live in `requirements-experiments.txt`, not `requirements.txt`. The
  running service does not import them, and a deployment does not carry them.
- The experiment's `metrics.json` is committed with a `STATUS` field marking it experiment-only;
  the weights are not saved, because nothing loads them.
- Chapter 4 can state that DistilBERT was evaluated, scored 0.9913 against 0.9735, and was not
  adopted because the difference is inside the confidence interval and the deployment cost is
  not. That is a stronger methodological result than a bare adoption would have been.
