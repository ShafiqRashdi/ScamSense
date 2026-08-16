# ScamSense — canonical results

**The single source of truth for every number quoted in the report.** If a figure appears
anywhere else — a commit message, an older ADR revision, a chat summary — and disagrees with
this file, this file wins.

Every figure here is traceable to a `metrics.json` in `ml-service/models/`, which is committed.
Nothing here is estimated or remembered.

Last verified against the artefacts: 2026-08-11.

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

> ## Test F1 = 0.9735
>
> Scam class, `tfidf-msg-v2.0.0-collected-my`, on the corpus's own held-out test split
> (106 messages, 105 distinct templates).
>
> **Quote it as:** *"F1 on a 528-message collected Malaysian corpus."* Real collected
> SMS/WhatsApp, redacted at source, 493 distinct templates (93% distinct). Class balance is
> uneven — **legitimate is the smaller class at 238** against 290 scam.
>
> Precision 1.0000, recall 0.9483, ROC AUC 0.9896. Zero false positives on the 48 legitimate
> test messages; 3 scams missed out of 58.
>
> **Secondary figure: F1 = 0.9684** on the 97 test rows whose template never appeared in train or
> validation. The shipped split has 5 templates shared across partitions (9 test rows, 8.5%), so
> the leak-free subset bounds the primary figure from below. Both are reported because both are
> informative; the gap is small.
>
> **This is a small corpus.** With 58 positives in test, the 95% interval on recall is roughly
> ±6 points. Do not present this figure as equivalent to one measured on a large public dataset.

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

#### Message classifier, in full — `tfidf-msg-v2.0.0-collected-my`

| Metric | Full test split | Leak-free subset |
|---|---:|---:|
| **F1 (scam)** | **0.9735** | 0.9684 |
| Precision (scam) | 1.0000 | 1.0000 |
| Recall (scam) | 0.9483 | 0.9388 |
| ROC AUC | 0.9896 | 0.9877 |
| Rows | 106 | 97 |
| Distinct templates | 105 | 97 |

Configuration: TF-IDF over character n-grams (`char_wb`, 2–5), Logistic Regression with
`class_weight="balanced"`, seed 42, using the split shipped with the corpus rather than a
recomputed one.

**Both meet the 0.85 threshold objective O1 requires.**

### Was DistilBERT tried? Yes — see `adr/0009`

The proposal names DistilBERT, so the answer needs to be a number rather than an excuse.

| | TF-IDF + LogReg *(shipped)* | DistilBERT *(experiment)* |
|---|---:|---:|
| Test F1 (scam) | 0.9735 | **0.9913** |
| Precision | 1.0000 | 1.0000 |
| Recall | 0.9483 | 0.9828 |
| Missed scams (of 58) | 3 | 1 |
| False positives (of 48) | 0 | 0 |

**DistilBERT scored 0.0179 higher.** But a paired bootstrap over the 106 test messages
(5,000 resamples) puts the 95% interval on that difference at **[−0.0167, +0.0560]** — it
**spans zero**, with 77.2% of resamples favouring DistilBERT.

**The corpus cannot resolve a difference this size.** With 58 positives in test, the standard
error on recall alone is around 0.03, so anything under roughly six points is inside the noise;
the observed difference is 1.8 points, and in absolute terms the whole advantage is two messages.

It is therefore **not shipped**: the improvement is not demonstrated, while the cost — roughly
250 MB of weights plus 1–2 GB of runtime memory — is certain, and would discard the deployment
headroom that ADR 0001 and ADR 0004 were written to protect. Revisit at a few thousand messages,
where the evaluation could actually resolve it.

The correct sentence for the report: *"DistilBERT was evaluated and scored F1 0.9913 against the
baseline's 0.9735; the difference is within the 95% confidence interval on a 528-message corpus,
so the baseline was retained."* Not *"DistilBERT was no better"* — it may well be better, and the
measurement simply cannot say.

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
