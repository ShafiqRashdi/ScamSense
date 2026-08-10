# ADR 0004 — URL model: split methodology, threshold, and artefact selection

- **Status:** Accepted
- **Date:** 2026-08-10
- **Phase:** 2 (URL classifier) — now complete
- **Shipped artefact:** `models/rf-url-v1.1.0`

## Context

A 298,272-row URL corpus was assembled from PhiUSIIL, two Kaggle phishing sets and Tranco,
balanced exactly 50/50. This record covers three decisions taken while training the Random Forest
on it, and the measured evidence for each.

All figures below come from runs executed on 2026-08-10. Nothing here is estimated.

## Decision 1 — split by registrable domain, not at random

### The problem, measured

56.7% of the corpus sits on a registrable domain that appears more than once, and the most
frequent domains are free hosting and CDN platforms abused at scale for phishing: `web.app`
(6,869 rows), `firebaseapp.com` (6,682), `weeblysite.com` (6,189), `repl.co` (3,592),
`workers.dev` (3,172), `r2.dev` (2,390).

Under a random split, **2,979 registrable domains appear in both train and test**. The model can
then score by memorising that `web.app` hosts phishing, which says nothing about whether it can
recognise a phishing URL on a domain it has never seen — which is the only thing that matters in
production, because tomorrow's campaign is on tomorrow's domain.

### The measured difference

Identical model, identical features, identical seed. Only the split strategy differs.

| Split | Test F1 | Precision | Recall | Domains leaked |
|---|---:|---:|---:|---:|
| Random | 0.9606 | 0.9386 | 0.9836 | 2,979 |
| **Domain-grouped** | **0.8967** | 0.8209 | 0.9880 | **0** |

**The random split overstates F1 by 6.4 points.** That gap is the value of the shortcut, and it
is why the domain-grouped figure is the one reported.

Implemented with `StratifiedGroupKFold`, which keeps groups intact while holding class balance
roughly constant — seven folds giving one for test, one for validation and five for training,
which lands close to the project's 70/15/15 standard. `--split-strategy domain` is the default,
and a test asserts no domain straddles two partitions.

Both numbers are kept. The random-split artefact is retained as
`rf-url-v1.0.0-randomsplit` because the comparison is itself a Chapter 4 result, and because
most published work on this task splits randomly — quoting only the lower figure without
explanation would make ScamSense look worse than comparable systems rather than more honest.

## Decision 2 — the decision threshold, and what it costs

`choose_threshold` maximises F-beta with beta=2 on the validation set, selecting **0.1360**.
That weighting is deliberate: a missed scam costs a user money, a false positive costs them ten
seconds of doubt.

Measured on the frozen test set (44,890 rows, 23,596 phishing):

| Threshold | F1 | Precision | Recall | Missed scams | False alarms | FPR |
|---:|---:|---:|---:|---:|---:|---:|
| 0.136 (chosen) | 0.8967 | 0.8209 | 0.9880 | 285 | 5,085 | 23.9% |
| 0.300 | 0.9253 | 0.8765 | 0.9799 | 474 | 3,259 | 15.3% |
| 0.500 | 0.9434 | 0.9308 | 0.9564 | 1,028 | 1,679 | 7.9% |
| 0.700 | 0.9313 | 0.9664 | 0.8986 | 2,392 | 738 | 3.5% |

**This is worth being explicit about, because the chosen threshold does not maximise F1.**
Threshold 0.5 scores 0.9434 — 4.7 points higher. The recall-weighted choice buys 3.2 points of
recall (743 fewer missed scams) at the cost of 16 points of false-positive rate (3,406 more
false alarms).

That trade is defensible for a scam checker, but a 23.9% false-alarm rate is poor UX for a tool
aimed at non-technical users: roughly one legitimate link in four would be flagged. Telling a
60-year-old that their bank's real website is dangerous erodes exactly the trust the project
exists to build.

### Consequence for Phase 6: three bands, not a binary

The verdict schema already stores a `risk_level` rather than a boolean, and the test-set
distribution shows the bands separate cleanly:

| Band | Probability | Test rows | Actually phishing |
|---|---|---:|---:|
| Low | < 0.20 | 17,345 | **2.0%** |
| Medium | 0.20 – 0.60 | 4,437 | 28.3% |
| High | > 0.60 | 23,108 | **95.2%** |

A low-band verdict is right 98% of the time and a high-band verdict 95%, with genuine uncertainty
concentrated in a middle band covering under 10% of traffic. Phase 6 should present that middle
band as "be careful, we are not sure" rather than forcing it to one side — which is both more
honest and better UX than a binary flag at any single threshold.

## Decision 3 — bound the tree depth, for deployment

The unbounded forest (300 trees, `max_depth=None`) produced a **432 MB** artefact. That is a
problem, not a curiosity: AGENTS.md requires models to load once at startup and hold in memory,
and ADR 0001 deferred the deployment target on the basis that a small VPS remains viable.

A bounded forest (200 trees, `max_depth=18`) was trained and compared **on the validation set**,
where model selection belongs:

| Artefact | Val F1 | Val ROC AUC | Size | Test F1 |
|---|---:|---:|---:|---:|
| `rf-url-v1.0.0` (300 trees, unbounded) | 0.8632 | 0.9434 | 432 MB | 0.8967 |
| **`rf-url-v1.1.0` (200 trees, depth 18)** | 0.8604 | **0.9480** | **130 MB** | 0.8952 |

Validation F1 is 0.003 lower and validation ROC AUC is *higher*; the two are equivalent within
noise. A 70% size reduction for no measurable performance cost is an easy trade, so **v1.1.0 is
the artefact ScamSense ships**.

The selection was made on validation. Test figures are shown above for completeness, having been
computed once per artefact, and were not the basis for the choice.

## Results — objective O1, URL task

**Test F1 on the phishing class: 0.8952**, against a required 0.85. Recall 0.9892, precision
0.8175, ROC AUC 0.9848, on 44,890 held-out rows with zero domain leakage.

Top features by importance: `uses_https` (0.265), `path_length` (0.141), `host_entropy` (0.124),
`host_length` (0.092), `url_length` (0.055).

`uses_https` dominating is worth anticipating as a viva question. It does not mean HTTPS makes a
site safe — phishing sites obtain free certificates routinely. It means that within *this corpus*
the legitimate half comes largely from Tranco, which skews strongly to HTTPS. That is a property
of the data, and it is a soft warning that the corpus's negative class may be easier than the
real world's. The honest reading is that the model is partly learning "Tranco-like versus
not-Tranco-like", which a more varied legitimate corpus would dilute.

## Limitations

- **The legitimate class is Tranco-dominated.** Top-ranked global sites are not representative of
  the ordinary links a Malaysian user pastes. The false-positive rate on genuinely ordinary
  small-business URLs is likely higher than 23.9% suggests.
- **The corpus is not Malaysian.** It contains few if any `.com.my` phishing domains, and none of
  the campaigns the project actually targets. `brand_lookalike` and the Malaysian brand tables are
  therefore barely exercised by this evaluation, despite being the project's differentiator.
- **No temporal split.** Phishing shifts over time; a chronological split would be a harder and
  more realistic test. The corpus carries no reliable timestamps, so this was not possible.
