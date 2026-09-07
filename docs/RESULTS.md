# ScamSense — canonical results

**The single source of truth for every number quoted in the report.** If a figure appears
anywhere else — a commit message, an older ADR revision, a chat summary — and disagrees with
this file, this file wins.

Every figure here is traceable to a `metrics.json` in `ml-service/models/`, which is committed.
Nothing here is estimated or remembered.

Last verified against the artefacts: 2026-09-07.

---

## Objective O1 — detection engine

### URL classifier — **complete**

> ## Test F1 = 0.8979
>
> Phishing class, `rf-url-v2.0.0-pathnorm`, on a frozen 44,890-row test set, domain-grouped
> split, 22 lexical features.
>
> **This is a corpus metric, not real-world accuracy.** On a probe of real Malaysian sites the
> model produces **one hard false positive and nine calibrated uncertainties** out of 19 genuine
> sites, and catches **9 of 9** phishing shapes in the high band. See `adr/0006`.

### Message classifier — **complete**

> ## Test F1 = 0.9836
>
> Scam class, `tfidf-msg-v3.0.0-collected-my`, on the corpus's own held-out test split
> (223 messages, 191 distinct templates).
>
> **Quote it as:** *"F1 on a 1,113-message collected Malaysian corpus."* Real collected
> SMS/WhatsApp, redacted at source, 940 distinct templates (84% distinct). Class balance is
> uneven — **legitimate is the smaller class at 504** against 609 scam.
>
> Precision 0.9756, recall 0.9917, ROC AUC 0.9961. One scam missed of 121; three false positives
> of 102 legitimate messages.
>
> **Secondary figure: F1 = 0.9823** on the 214 test rows whose template never appeared in train
> or validation. The shipped split still carries 5 shared templates (6 test rows, 2.7%, down from
> 8.5%), so the leak-free subset bounds the primary figure from below. Both are reported because
> both are informative; the gap is small.
>
> **This is still a small corpus.** With 121 positives in test the interval is narrower than
> v2's, but do not present this figure as equivalent to one measured on a large public dataset.

#### v3 against v2 — what actually improved

**Do not report 0.9735 → 0.9836 as an improvement.** They are measured on different test sets:
v2's was 106 rows, v3's is 223. The 106 are a subset — they kept their partition through the
merge — so a like-for-like comparison exists and is the only one that answers the question.

| On the identical 106 rows | F1 | Precision | Recall | Missed of 58 | False positives |
|---|---:|---:|---:|---:|---:|
| `tfidf-msg-v2.0.0` | 0.9735 | 1.0000 | 0.9483 | 3 | 0 |
| `tfidf-msg-v3.0.0` | **0.9744** | 0.9661 | 0.9828 | **1** | 2 |

**F1 moved +0.0009 — essentially nothing — and that conceals the real change.** The model traded
two missed scams for two false positives, which a harmonic mean treats as a wash. By the
asymmetry this project states everywhere else — a missed scam costs a user money, a false
positive costs them ten seconds — that is a clearly favourable trade, and it is invisible in the
headline number. Report the confusion matrix, not the F1, when comparing these two.

#### The figure that actually justifies the collection

The corpus metric barely moved. The out-of-corpus probe moved a great deal, and that is what the
extra 585 rows were collected for.

| `training/message_probe.py`, 24 hand-written messages | v2.0.0 | v3.0.0 |
|---|---:|---:|
| Overall | 17/24 (70.8%) | **22/24 (91.7%)** |
| Scams missed | 6/12 | **2/12** |
| Legitimate flagged | 1/12 | **0/12** |

Four of the six misses closed: the JPJ summons demand, the LHDN refund, the Touch 'n Go
suspension and the Shopee delivery notice. The Grab false positive closed too.

**This is the strongest result in the project, because it was predicted before it was produced.**
The v2 probe showed recall tracking category support — every miss in a category under 21 rows,
every category above 39 caught. Collection was weighted to the three sparse categories on that
basis: parcel 18 → 111, prize 20 → 106, impersonation 19 → 102. Recall then moved as predicted.
Hypothesis, intervention, confirmation — which is worth more in Chapter 5 than any single F1.

**Two misses remain**, and they are a different shape rather than more of the same. A failed
Shopee delivery and a Petronas prize draw both invoke a brand in routine-sounding language,
without the urgency and credential vocabulary the model has learned. Tripling the rows in those
categories did not close them, so the remaining gap looks like phrasing rather than volume — a
distinction worth stating, because "collect more" is not the answer to everything.

#### URL classifier, in full — `rf-url-v2.0.0-pathnorm`

| Metric | Value |
|---|---:|
| **F1 (phishing)** | **0.8979** |
| Precision (phishing) | 0.8368 |
| Recall (phishing) | 0.9687 |
| ROC AUC | 0.9576 |
| Decision threshold | 0.3105 |
| Test rows | 51,467 (30,607 phishing) |
| Domain leakage | 0 |

Configuration: 22 lexical features (`uses_https` excluded, trailing-slash path normalisation
applied), Random Forest with 200 trees at `max_depth=18`, `class_weight="balanced"`, seed 42,
domain-grouped stratified split. Trained on 298,490 URLs across 136,258 registrable domains —
PhiUSIIL, two Kaggle sets, Tranco, and 221 collected Malaysian URLs.

#### Message classifier, in full — `tfidf-msg-v3.0.0-collected-my`

| Metric | Full test split | Leak-free subset |
|---|---:|---:|
| **F1 (scam)** | **0.9836** | 0.9823 |
| Precision (scam) | 0.9756 | 0.9737 |
| Recall (scam) | 0.9917 | 0.9911 |
| ROC AUC | 0.9961 | 0.9958 |
| Rows | 223 | 214 |
| Distinct templates | 191 | 183 |

Configuration: TF-IDF over character n-grams (`char_wb`, 2–5), Logistic Regression with
`class_weight="balanced"`, seed 42, decision threshold 0.4717 chosen on validation, using the
split shipped with the corpus rather than a recomputed one.

Superseded: `tfidf-msg-v2.0.0-collected-my`, F1 0.9735 on a 106-row test set drawn from the
528-row corpus. Retained in `models/` and reachable from the dashboard's superseded list.

**Both models meet the 0.85 threshold objective O1 sets for the metric.**

That sentence is narrower than it looks, and the distinction matters twice over:

- **Until 2026-08-18 the message model was not being served at all.** The artefact was trained,
  measured and committed, but nothing loaded it: `app/predictors/__init__.py` never constructed
  a message classifier and `/predict/message` returned a hardcoded 503. Every message submission
  in the running product came back *"We could not check this properly."* The metric was honest;
  the claim that the product met O1 on both tasks was not. Fixed and covered by a regression
  test that asserts `/health` reports both models loaded.
- **The message model still generalises less well than its corpus figure suggests, though much
  less so than it did.** On 24 hand-written messages outside the corpus, v3 reaches **91.7% and
  misses 2 of 12 scams** — against 70.8% and 6 of 12 for v2. The frozen-test-set figure remains
  the honest headline for O1; the probe is what it means in a user's hands, and the gap between
  0.9836 and 91.7% is the honest measure of how far a corpus this size can be trusted.

### Was DistilBERT tried? Yes — see `adr/0009`

The proposal names DistilBERT, so the answer needs to be a number rather than an excuse. It has
now been run **twice**: once against v2.0.0 on the 528-row corpus, and again against v3.0.0 on
the 1,113-row corpus. The table below is the second, current run.

| | TF-IDF + LogReg *(shipped)* | DistilBERT *(experiment)* |
|---|---:|---:|
| Artefact | `tfidf-msg-v3.0.0-collected-my` | `distilbert-msg-v2.0.0-experiment` |
| Test F1 (scam) | 0.9836 | **0.9959** |
| Precision | 0.9756 | 1.0000 |
| Recall | 0.9917 | 0.9917 |
| Missed scams (of 121) | 1 | 1 |
| False positives (of 102 legitimate) | 3 | 0 |

**DistilBERT scored 0.0122 higher.** A paired bootstrap over the 223 test messages (5,000
resamples) puts the 95% interval on that difference at **[−0.0043, +0.0317]** — it **still spans
zero**, with 88.0% of resamples favouring DistilBERT.

**More data narrowed the interval without resolving it.** Doubling the corpus halved the interval
width, from 0.0727 to 0.0360, and moved the resample share from 77.2% to 88.0% — real movement,
in the expected direction, and not enough. Report the interval, not the point estimate; the point
estimate has moved twice and the conclusion has not.

**What changed is which error the two models differ on, and it argues against DistilBERT rather
than for it.** On the 528-row corpus they differed on *recall*: the baseline missed 3 scams of 58,
DistilBERT 1. They now **miss the same single scam of 121**, and the entire remaining difference
is false positives — 3 against 0. By this project's own stated asymmetry a missed scam costs a
user money and a false positive costs them ten seconds of doubt, so the advantage that survives is
in the **cheaper** error. Paying 250 MB of weights and 1–2 GB of runtime memory to remove three
moments of unnecessary caution is a worse trade than the first run suggested.

It is therefore **still not shipped**: the improvement is not demonstrated, the cost is certain,
and it would discard the deployment headroom ADR 0001 and ADR 0004 were written to protect.

The correct sentence for the report: *"DistilBERT was evaluated twice and scored F1 0.9959 against
the baseline's 0.9836 on a 1,113-message corpus; the difference is within the 95% confidence
interval, so the baseline was retained."* Not *"DistilBERT was no better"* — it may well be
better, and the measurement still cannot say.

<details>
<summary>The first run, for comparison — v2.0.0 on 528 rows, 106 test messages</summary>

| | TF-IDF v2.0.0 | DistilBERT v1.0.0 |
|---|---:|---:|
| Test F1 (scam) | 0.9735 | 0.9913 |
| Missed scams (of 58) | 3 | 1 |
| False positives (of 48) | 0 | 0 |

Difference +0.0179, 95% CI **[−0.0167, +0.0560]**, 77.2% of resamples favouring DistilBERT.
Kept because the movement between the two runs is itself a result: the measured effect **shrank**
as the corpus grew.

</details>

#### How much data would settle it? — `training/power_analysis.py`

"Collect more data" is not a plan anyone can act on, so the requirement was measured rather than
guessed. The script resamples from the error rates the two models actually produced — both miss
the same 1 of 121 scams, the baseline adds 3 false positives in 102, DistilBERT none — and runs
the same paired bootstrap at increasing corpus sizes.

| Test messages | Total corpus at 70/15/15 | Trials where the interval excludes zero |
|---:|---:|---:|
| 223 *(current)* | 1,115 | 22% |
| 400 | 2,000 | 40% |
| 800 | 4,000 | 73% |
| 1,500 | 7,500 | 90% |
| 3,000 | 15,000 | 100% |

**Around 1,500 test messages — roughly 7,500 collected in total — gives an ~80% chance of
resolving a difference this size.**

**The requirement grew rather than shrank, and that is the interesting part.** The first run of
this script, on the 528-row corpus, put the target at ~800 test messages and gave ~35% odds of
resolving at 200. The corpus then reached 1,113 rows, giving 223 test messages, and the comparison
did not resolve. **The prediction held** — which is a better reason to trust the table than the
method's plausibility. The target moved to ~1,500 because the measured effect got *smaller*
(+0.0122 against +0.0179), and a smaller true difference needs more data to distinguish from none.

**The caveat is not optional.** This assumes the observed +0.0122 is the true difference, which is
precisely what a zero-spanning interval says we do not know. If the real gap is smaller the
requirement grows again; if larger, it shrinks. It is a target to aim at, not a threshold that
promises a result.

**Collection was targeted at 1,200–1,500 rows, not 7,500.** That is a deliberate scope decision,
and the corpus reached 1,113. The table above places that well below the point where this
comparison resolves, and it did not resolve — **that is the finding, not a failure**. A
single-semester collection effort by one person cannot support a definitive answer to this
question, and reporting that honestly is worth more than reporting a result the data cannot carry.


### Message generalisation probe — `training/message_probe.py`

A corpus figure is measured on messages drawn from the same collection effort as the training
rows: same categories, same collector, same weeks. The probe asks the harder question — does it
work on a scam it has never seen phrased this way? — using 24 messages written by hand,
independently of the corpus, following patterns documented by the NSRC and Bank Negara Malaysia.

| | v2.0.0 (528 rows) | v3.0.0 (1,113 rows) |
|---|---:|---:|
| Overall accuracy | 17/24 (70.8%) | **22/24 (91.7%)** |
| Scams missed | 6/12 | **2/12** |
| Legitimate flagged | 1/12 | **0/12** |

**Recall tracked category support, and then moved when the support did.** Under v2 every miss
fell in a category holding fewer than 21 rows while every category above 39 was caught. That was
a prediction about what collection would fix, and it was acted on directly:

| Category | v2 rows | v2 probe | v3 rows | v3 probe |
|---|---:|---:|---:|---:|
| Parcel scam | 18 | 0/2 | **111** | 1/2 |
| Macau scam / impersonation | 19 | 1/3 | **102** | 3/3 |
| Lucky draw / prize | 20 | 1/2 | **106** | 1/2 |
| Phishing link | 40 | 2/3 | 77 | 3/3 |
| Loan scam | 39 | 1/1 | 45 | 1/1 |
| Job offer scam | 76 | 1/1 | 84 | 1/1 |

Four misses closed: a JPJ summons demand, an LHDN refund, a Touch 'n Go suspension and a Shopee
delivery notice — each a message that a user pasting it into ScamSense would previously have been
told showed no warning signs.

**Two misses remain, and they are a different problem.** A failed Shopee delivery and a Petronas
prize draw both invoke a brand in routine-sounding language, without the urgency or credential
vocabulary the model has learned to weigh. Their categories grew six-fold and five-fold
respectively and the misses did not close, so the remaining gap is phrasing rather than volume.
Worth stating plainly, because it is the one place where "collect more data" is demonstrably not
the answer.

Quote this as a generalisation probe, never as the model's F1; it is small and deliberately
adversarial, and the two figures measure different things.

### Provenance — keep `collected_my` separate

Two corpora were collected specifically for this project and must stay distinguishable from the
public datasets in every table:

| Corpus | Rows | Source tag | Role |
|---|---:|---|---|
| `messages_my.csv` | 528 | `collected_my` | The message model is trained and measured entirely on this |
| `legit_urls_my.csv` | 221 | `collected_my` | Folded into the URL corpus — but see the note below |

**The 221 Malaysian URLs are 0.074% of the combined URL corpus.** At that share they cannot
meaningfully shift what the URL model learns. Their value is as *evidence*, not training signal,
and the URL figure above should not be described as having been improved by them. The honest
statement is that the collected URLs confirmed the defect and the retrain fixed part of it.

The superseded synthetic message corpus (1,732 rows, 56 templates) produced no usable figure and
is retained only as a demonstration of why — see `adr/0005`.

---

## Superseded and comparison figures

These are real measurements from real runs, retained because the comparisons are themselves
results. **None of them is the headline figure.**

| Figure | Artefact | Why it exists | Why it is not canonical |
|---:|---|---|---|
| 0.9606 | `rf-url-v1.0.0-randomsplit` | Quantifies split leakage | Random split leaves 2,979 registrable domains in both train and test; overstates F1 by 6.4 points |
| 0.8967 | `rf-url-v1.0.0` | Same-model baseline for the split comparison | 432 MB artefact; breaks the load-at-startup requirement |
| 0.8952 | `rf-url-v1.1.0` | Shows depth-bounding costs little | Still includes the contaminated `uses_https` feature |
| 1.0000 | `tfidf-msg-v1.0.0-randomsplit` | Demonstrates template memorisation | Not a performance result; every test template was seen in training |
| 0.0000 | `tfidf-msg-v1.0.0` | Demonstrates the corpus cannot be grouped | Not a performance result; test partition was 3 templates, all one class |

### Why `uses_https` was removed

All 23,936 Tranco rows — 16% of the legitimate class — had `https://` **added during dataset
assembly**. The scheme was written in, not observed, so the feature partly measured how the CSV
was built. It was also the most important feature at 0.265.

Ablation, identical split, seed and hyperparameters:

| | With (23 features) | Without (22) | Change |
|---|---:|---:|---:|
| Validation F1 | 0.8604 | 0.8578 | −0.0026 |
| Test F1 | 0.8952 | **0.8984** | +0.0032 |
| Test recall | 0.9892 | 0.9780 | −0.0112 |
| Test ROC AUC | 0.9848 | 0.9658 | −0.0190 |

The feature was not load-bearing. The AUC reduction is real and recorded — it did carry ranking
signal, but signal that cannot be trusted when 16% of the negative class had the value supplied.

### Why the forest is depth-bounded

An accepted **0.0028 validation F1 reduction** (0.8632 → 0.8604) in exchange for a **70% smaller
artefact** (432 MB → 130 MB), justified by the load-at-startup requirement in AGENTS.md and the
small-VPS deployment option ADR 0001 keeps open. A deliberate accuracy-for-deployability trade,
not a claim of parity.

---

## Risk bands for the UI

Measured on the frozen test set. Phase 6 uses three bands rather than a binary flag.

| Band | Probability | Test rows | Actually phishing |
|---|---|---:|---:|
| Low | < 0.20 | 17,345 | 2.0% |
| Medium | 0.20 – 0.60 | 4,437 | 28.3% |
| High | > 0.60 | 23,108 | 95.2% |

### The real-site probe, banded — the accurate characterisation

Reporting a flat "40% false-positive rate" overstates the problem, because it counts every
verdict above the recall-weighted 0.2640 threshold as a failure. Banded, the picture is
different and better:

| Band | Genuine Malaysian sites (17) | Known phishing (8) |
|---|---:|---:|
| Low | 9 | 0 |
| Medium | **7** | 0 |
| High | **1** | **8** |

**One hard false positive, plus seven calibrated uncertainties.** The seven medium-band sites
score 0.2916–0.5651, which is exactly what a band measured at 28.3% actually-phishing is *for*:
the model is expressing genuine uncertainty, not asserting a wrong answer. Shown as "be careful,
we are not sure", those seven are honest rather than incorrect.

There is also a clean separation: every medium-band false positive is ≤ 0.5651 and every probed
phishing URL is ≥ 0.9004. Nothing genuine intrudes into the high band except one case.

**The single hard failure is `maybank2u.com.my/home/m2u/common/login.do` at 0.9337** — a genuine
bank login page presented as high risk. Banding does not rescue it. That one case is what the
Phase 5 verified-domain gate addresses (`adr/0007`), and what corpus work must eventually fix.

---

## Read before citing

The URL figure has real limitations, set out in `adr/0004`:

- The corpus is **not Malaysian** — few `.com.my` phishing domains, none of the campaigns the
  project targets. The Malaysian brand tables are barely exercised by this evaluation.
- The legitimate class is **Tranco-dominated**, so real-world false positives on ordinary small
  business sites are likely worse than measured. This was confirmed in Phase 4 and quantified in
  `adr/0006`: 76% of corpus URLs containing credential vocabulary are labelled phishing, and
  real legitimate login pages are outnumbered 6,954 to 2,026 — so the model flags genuine
  banking pages, which is the most common thing a worried user will paste.
- **No temporal split** — the corpus carries no reliable timestamps.

---

## Where each number comes from

### A methodological result from Phase 9

The security review produced a finding worth reporting alongside the model
numbers: redaction had been applied thoroughly to **inbound** user content, but
a submitted URL re-entering the system through an **outbound** API call bypassed
it entirely, reaching the log through an HTTP client's exception message. See
"The sanitisation-boundary lesson" in `security-review-2026-08.md`. Neither
defect was visible by reading the code.

| Source | Contains |
|---|---|
| `ml-service/models/*/metrics.json` | Raw measurements, committed |
| `ml-service/models/*/config.json` | Hyperparameters, seed, library versions |
| `ml-service/models/*/split_indices.json` | Exact row assignment, so the split is verifiable |
| `docs/adr/0004` | URL model methodology and reasoning |
| `docs/adr/0005` | Why there is no message figure |
| `ml-service/training/DATASETS.md` | Corpus provenance, including rejected sources |

---

## v1 → v2 URL model: what the retrain changed

Two corpus artefacts were fixed together in `rf-url-v2.0.0-pathnorm`.

### The corpus figure barely moved

| | v1.2.0 | v2.0.0 |
|---|---:|---:|
| Test F1 (phishing) | 0.8984 | **0.8979** |

That is the point. The defects were invisible to the corpus metric, which is why they survived
two ADRs — a held-out split of a corpus validates a model against *that corpus* and cannot detect
that the corpus is drawn from a different distribution than production.

### The real-world behaviour moved a great deal

| | v1.2.0 | v2.0.0 |
|---|---|---|
| Trailing slash shifts the score | up to **+0.84** | **0.000000** |
| Genuine sites in the high band | 1 of 17 | 1 of 19 |
| Genuine sites the model mislabels | 8 | **5** |
| Phishing shapes caught in the high band | 8 of 8 | **9 of 9** |

Three sites moved from wrong to right — `jpj.gov.my`, `bnm.gov.my`, `cimb.com.my` — recorded as
`xfail` cases turning XPASS, which is what makes a fix measurable rather than asserted.

### The systematic artefact audit

`training/audit_url_artefacts.py` looks for the *next* `uses_https` instead of waiting for it to
be found by accident. Within the legitimate class alone, the features predict which source file a
row came from with **97.99% accuracy** against a 42.70% majority baseline — the legitimate class
is five different populations wearing one label.

Per feature, ranked by how much better it predicts source than label:

| feature | label AUC | source AUC |
|---|---:|---:|
| `url_length` | 0.5896 | **0.8978** |
| `subdomain_count` | 0.5693 | **0.8975** |
| `dot_count` | 0.5620 | **0.8811** |
| `path_length` | 0.5888 | **0.8005** |
| `host_length` | 0.6839 | 0.7705 |

`path_length` was addressed by normalisation. `url_length` was tested for removal and **kept**,
because the audit produces suspects rather than verdicts and this one turned out to be
load-bearing:

| Artefact | Features | Test F1 |
|---|---:|---:|
| `rf-url-v2.0.0-pathnorm` | 22 | **0.8979** |
| `rf-url-v2.1.0-minus-urllength` | 21 | 0.8093 |

Dropping it costs **8.9 F1 points** and falls below the 0.85 objective O1 requires. So
`url_length` correlates strongly with corpus source *and* carries real signal the model needs;
both are true at once. It is documented as a known artefact-correlated feature rather than
removed, and it is one more reason the legitimate class needs to be broadened rather than the
feature set narrowed.

`subdomain_count` and `dot_count` were not ablated: they carry obvious phishing semantics, and
removing them would trade a false-positive problem for a false-negative one — the wrong direction
for this project.
