# ADR 0004 — URL model: split methodology, feature ablation, threshold, and artefact selection

- **Status:** Accepted
- **Date:** 2026-08-10
- **Phase:** 2 (URL classifier) — complete
- **Shipped artefact:** `models/rf-url-v1.2.0-no-https`

---

## The canonical figure

> **Objective O1, URL task: test F1 = 0.8984 on the phishing class.**
>
> Produced by `rf-url-v1.2.0-no-https`, the artefact ScamSense ships. Measured once on a frozen
> 44,890-row test set, split by registrable domain with zero domain leakage, using 22 lexical
> features with the contaminated `uses_https` feature excluded. Recall 0.9780, precision 0.8308,
> ROC AUC 0.9658, decision threshold 0.2640.
>
> **This is the number to quote in Chapter 4.** Every other figure in this document belongs to a
> superseded or comparison artefact and exists to show how this one was arrived at.

Four artefacts were trained. Only the last is shipped:

| Artefact | Split | Features | Val F1 | Test F1 | Role |
|---|---|---:|---:|---:|---|
| `rf-url-v1.0.0-randomsplit` | random | 23 | 0.9620 | 0.9606 | Split-leakage comparison only |
| `rf-url-v1.0.0` | domain | 23 | 0.8632 | 0.8967 | Superseded — 432 MB |
| `rf-url-v1.1.0` | domain | 23 | 0.8604 | 0.8952 | Superseded — contaminated feature |
| **`rf-url-v1.2.0-no-https`** | **domain** | **22** | **0.8578** | **0.8984** | **Shipped** |

---

## Context

A 298,272-row URL corpus was assembled from PhiUSIIL, two Kaggle phishing sets and Tranco,
balanced exactly 50/50. This record covers four decisions taken while training a Random Forest on
it. All figures come from runs executed on 2026-08-10; nothing is estimated.

## Decision 1 — split by registrable domain, not at random

### The problem, measured

56.7% of the corpus sits on a registrable domain that appears more than once, and the most
frequent domains are free hosting and CDN platforms abused at scale for phishing: `web.app`
(6,869 rows), `firebaseapp.com` (6,682), `weeblysite.com` (6,189), `repl.co` (3,592),
`workers.dev` (3,172), `r2.dev` (2,390).

Under a random split, **2,979 registrable domains appear in both train and test**. The model can
then score by memorising that `web.app` hosts phishing, which says nothing about whether it can
recognise a phishing URL on a domain it has never seen — the only thing that matters in
production, because tomorrow's campaign is on tomorrow's domain.

### The measured difference

Identical model, identical features, identical seed. Only the split strategy differs.

| Split | Test F1 | Precision | Recall | Domains leaked |
|---|---:|---:|---:|---:|
| Random | 0.9606 | 0.9386 | 0.9836 | 2,979 |
| Domain-grouped | 0.8967 | 0.8209 | 0.9880 | 0 |

**The random split overstates F1 by 6.4 points.** That gap is the value of the shortcut, and it is
why every subsequent figure uses the domain-grouped split.

Implemented with `StratifiedGroupKFold`, which keeps groups intact while holding class balance
roughly constant — seven folds giving one for test, one for validation and five for training,
landing close to the project's 70/15/15 standard. `--split-strategy domain` is the default, and a
test asserts no domain straddles two partitions.

The random-split artefact is retained because the comparison is itself a Chapter 4 result, and
because most published work on this task splits randomly. Quoting only the lower figure without
explanation would make ScamSense look worse than comparable systems rather than more honest.

## Decision 2 — drop `uses_https`, because the corpus invented it

### The contamination

All 23,936 Tranco rows — 16% of the legitimate class — had `https://` **added during dataset
assembly**. The scheme on those rows was not observed; it was written in. The remaining
legitimate rows, from PhiUSIIL and the Kaggle sets, carry observed schemes.

`uses_https` was the single most important feature in the 23-feature model, at 0.265 importance.
A feature that dominant, derived partly from a value the dataset author supplied rather than
measured, is not a finding about phishing — it is a finding about how the CSV was built. Left in,
it would have been the first thing to collapse against real traffic, and the hardest to defend at
a viva.

### The ablation

Same split, same seed, same hyperparameters. Only the feature set differs.

| | With `uses_https` (23 feats) | Without (22 feats) | Change |
|---|---:|---:|---:|
| Validation F1 | 0.8604 | 0.8578 | −0.0026 |
| **Test F1** | 0.8952 | **0.8984** | **+0.0032** |
| Test precision | 0.8175 | 0.8308 | +0.0133 |
| Test recall | 0.9892 | 0.9780 | −0.0112 |
| Test ROC AUC | 0.9848 | 0.9658 | −0.0190 |
| Chosen threshold | 0.1177 | 0.2640 | — |

**The feature was not load-bearing.** Removing it changes test F1 by +0.003 — the model reaches
the same operating quality without it.

The ROC AUC drop of 0.019 is the honest counterweight and should not be glossed: `uses_https` did
carry real ranking signal, so the reduced model separates the classes slightly less cleanly across
all thresholds. But that signal cannot be trusted when 16% of the negative class had the value
written in, and F1 at the operating point is unaffected. Trading 0.019 AUC for a model free of a
known assembly artefact is the right trade for work that has to be defended.

Feature importance redistributes sensibly once it is gone — `host_entropy` (0.157),
`host_length` (0.149), `path_length` (0.133), `url_length` (0.112) — all genuine properties of
the URL string.

## Decision 3 — bound the tree depth: an accepted 0.003 F1 reduction for a 70% smaller artefact

The unbounded forest (300 trees, `max_depth=None`) produced a **432 MB** artefact. AGENTS.md
requires models to load once at startup and stay in memory, and ADR 0001 deferred the deployment
target on the basis that a small VPS remains viable. A 432 MB Random Forest, before DistilBERT is
loaded alongside it, makes that option untenable.

Comparison on the **validation** set, where model selection belongs:

| Artefact | Validation F1 | Validation ROC AUC | Artefact size |
|---|---:|---:|---:|
| `rf-url-v1.0.0` — 300 trees, unbounded | 0.8632 | 0.9434 | 432 MB |
| `rf-url-v1.1.0` — 200 trees, depth 18 | 0.8604 | 0.9480 | 130 MB |

**Bounding the depth costs 0.0028 validation F1 — a real reduction, not a wash.** It was accepted
because a 70% smaller artefact is what keeps the deployment option in ADR 0001 open, and because
validation ROC AUC is marginally higher (0.9480 vs 0.9434), indicating the smaller model has not
lost separating power across thresholds.

Stated plainly: this is a deliberate accuracy-for-deployability trade, justified by a hard memory
constraint. It is not a claim that the two models perform equally.

## Decision 4 — the decision threshold, and what it costs

`choose_threshold` maximises F-beta with beta=2 on the validation set. A missed scam costs a user
money; a false positive costs them ten seconds of doubt.

Measured on the frozen test set for the shipped 23-feature predecessor, whose threshold sweep was
run in full (44,890 rows, 23,596 phishing):

| Threshold | F1 | Precision | Recall | Missed scams | False alarms | FPR |
|---:|---:|---:|---:|---:|---:|---:|
| 0.136 | 0.8967 | 0.8209 | 0.9880 | 285 | 5,085 | 23.9% |
| 0.300 | 0.9253 | 0.8765 | 0.9799 | 474 | 3,259 | 15.3% |
| 0.500 | 0.9434 | 0.9308 | 0.9564 | 1,028 | 1,679 | 7.9% |
| 0.700 | 0.9313 | 0.9664 | 0.8986 | 2,392 | 738 | 3.5% |

**The chosen threshold does not maximise F1.** Threshold 0.5 scores 0.9434, 4.7 points higher. The
recall weighting buys 3.2 points of recall — 743 fewer missed scams — at the cost of 16 points of
false-positive rate, or 3,406 more false alarms.

That trade is defensible for a scam checker, but a 23.9% false-alarm rate is poor UX for
non-technical users: roughly one legitimate link in four flagged. Telling a 60-year-old that their
bank's real website is dangerous erodes exactly the trust the project exists to build. The shipped
model's higher threshold (0.264) sits in a better place on this curve, with precision 0.8308.

### Consequence for Phase 6: three bands, not a binary

The verdict schema stores a `risk_level` rather than a boolean, and the bands separate cleanly:

| Band | Probability | Test rows | Actually phishing |
|---|---|---:|---:|
| Low | < 0.20 | 17,345 | **2.0%** |
| Medium | 0.20 – 0.60 | 4,437 | 28.3% |
| High | > 0.60 | 23,108 | **95.2%** |

A low-band verdict is right 98% of the time and a high-band verdict 95%, with genuine uncertainty
concentrated in a middle band covering under 10% of traffic. Phase 6 should present that middle
band as "be careful, we are not sure" rather than forcing it to one side.

## Limitations

Read before citing 0.8984.

- **The corpus is not Malaysian.** It contains few if any `.com.my` phishing domains and none of
  the campaigns the project targets. `brand_lookalike` and the Malaysian brand tables are barely
  exercised by this evaluation, despite being the project's differentiator over ScamShield.
- **The legitimate class is Tranco-dominated.** Top-ranked global sites are not representative of
  the ordinary links a Malaysian user pastes, so the real-world false-positive rate on small
  business and personal sites is likely worse than measured here. The `uses_https` contamination
  was one symptom of this; there may be others not yet identified.
- **No temporal split.** Phishing shifts over time and a chronological split would be a harder,
  more realistic test. The corpus carries no reliable timestamps.
- **One ablation, not a full study.** Only `uses_https` was ablated, because it was both dominant
  and known to be contaminated. The remaining 22 features have not been individually tested for
  dataset artefacts.
