# ADR 0009 — DistilBERT scores higher, but not measurably, and is not shipped

- **Status:** Accepted — **re-measured 2026-09-07 on a corpus twice the size; decision unchanged**
- **Date:** 2026-08-12 *(amended 2026-09-07)*
- **Phase:** 3 (message classifier)
- **Shipped model:** `tfidf-msg-v3.0.0-collected-my` *(was v2.0.0 when this was written)*
- **Experiment artefacts:** `distilbert-msg-v1.0.0-experiment` (528-row corpus),
  `distilbert-msg-v2.0.0-experiment` (1,113-row corpus). Metrics only; weights not saved.

> **Read the 2026-09-07 amendment at the bottom first if you want the current numbers.** The body
> of this ADR records the original measurement on the 528-row corpus and is left intact, because
> the movement between the two runs is itself the result.

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

---

## Amendment, 2026-09-07 — re-measured on 1,113 rows

The body above rests on 106 test messages. The corpus has since roughly doubled, to 1,113
messages, and the experiment was re-run end to end: `tfidf-msg-v3.0.0-collected-my` against
`distilbert-msg-v2.0.0-experiment`, same splits, same threshold procedure, same paired bootstrap.

| | TF-IDF v3.0.0 | DistilBERT v2.0.0 | Difference |
|---|---:|---:|---:|
| Test F1 (scam) | 0.9836 | **0.9959** | +0.0122 |
| Precision (scam) | 0.9756 | 1.0000 | +0.0244 |
| Recall (scam) | 0.9917 | 0.9917 | **0** |
| Missed scams (of 121) | 1 | 1 | 0 |
| False positives (of 102) | 3 | **0** | −3 |

| | 528-row run | 1,113-row run |
|---|---|---|
| Point estimate | +0.0179 | +0.0122 |
| 95% CI | [−0.0167, +0.0560] | **[−0.0043, +0.0317]** |
| Interval width | 0.0727 | **0.0360** |
| Resamples favouring DistilBERT | 77.2% | **88.0%** |
| Interval spans zero | yes | **yes** |

### The decision does not change, and one of the reasons for it got stronger

**The interval still spans zero.** Doubling the corpus halved the interval width and moved the
resample share from 77.2% to 88.0%. That is real movement in the expected direction, and it is
not a result. The honest statement remains that DistilBERT may be better and this corpus cannot
show it.

**The nature of the difference changed, and the change argues against adoption.** On 528 rows the
two models differed on **recall** — the baseline missed 3 scams of 58, DistilBERT 1 — which is
the error type this project explicitly treats as costly. They now **miss the same single scam of
121**, and the whole remaining difference is **false positives**, 3 against 0. A missed scam costs
a user money; a false positive costs them ten seconds of unnecessary caution. The advantage that
survived the larger corpus is in the cheaper error, so the second of the three reasons above —
large certain cost against a small uncertain benefit — is now a worse trade than when it was
written, not a better one.

This also settles the second bullet under *What would have changed the decision*: "a materially
better recall at equal precision" was the condition, and the re-measurement shows **identical
recall**. The condition was tested and not met.

### The power analysis predicted this, which is why it should be trusted

`training/power_analysis.py`, run against the 528-row rates, put the odds of resolving at 200 test
messages at ~35% and the 80% target at ~800. The corpus reached 223 test messages and the
comparison did not resolve. The prediction held.

Re-run against the current rates, the 80% target has moved to **~1,500 test messages, roughly
7,500 collected** — it grew rather than shrank, because the measured effect got smaller. A smaller
true difference needs more data to distinguish from none. Collecting more data narrowed the
interval and moved the target further away at the same time; both of those are worth reporting.

### Consequences of the amendment

- `tfidf-msg-v3.0.0-collected-my` is the serving message model. DistilBERT remains unshipped, and
  torch/transformers remain in `requirements-experiments.txt`.
- Chapter 4 should cite the **1,113-row** figures — 0.9959 against 0.9836, CI [−0.0043, +0.0317] —
  and should report the interval alongside the point estimate. The point estimate has moved twice;
  the conclusion has not.
- Revisit at a few thousand messages, as before, but with the target now at ~7,500 collected.
  Reaching that inside a single semester by one collector is not realistic, and saying so is a
  legitimate finding rather than an unfinished task.
