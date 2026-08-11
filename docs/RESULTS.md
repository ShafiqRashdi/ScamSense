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

> ## Test F1 = 0.8984
>
> Phishing class, `rf-url-v1.2.0-no-https`, on a frozen 44,890-row test set.
>
> **This is a corpus metric, not real-world accuracy.** On a 20-URL probe of real Malaysian
> sites the same model has a **40% false-positive rate**, flagging genuine banking and
> government pages including the Maybank login page at 0.93 confidence. Recall is unaffected —
> all 8 phishing shapes probed were caught. See `adr/0006`. Quote 0.8984 as measured
> performance on the held-out corpus and cite that limitation alongside it.

| Metric | Value |
|---|---:|
| **F1 (phishing)** | **0.8984** |
| Precision (phishing) | 0.8308 |
| Recall (phishing) | 0.9780 |
| ROC AUC | 0.9658 |
| Decision threshold | 0.2640 |
| Test rows | 44,890 |
| Domain leakage | 0 |

Configuration: 22 lexical features (`uses_https` excluded — see below), Random Forest with 200
trees at `max_depth=18`, `class_weight="balanced"`, seed 42, domain-grouped stratified split.
Trained on 298,272 URLs from PhiUSIIL, two Kaggle sets and Tranco.

**Meets the 0.85 threshold objective O1 requires.**

### Message classifier — **no result**

There is no message-classifier figure, and none can be produced from the corpus currently
available. `messages.csv` is 1,732 rows generated from 56 templates; a random split reports
F1 1.0000 by memorisation and a template-grouped split gives a single-class test partition and
F1 0.0000. See `adr/0005`.

Do not quote a message-detection metric. Blocked on the collection of real Malaysian messages.

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

Measured on the frozen test set. Phase 6 uses three bands rather than a binary flag, because the
recall-weighted threshold alone produces a high false-alarm rate.

| Band | Probability | Test rows | Actually phishing |
|---|---|---:|---:|
| Low | < 0.20 | 17,345 | 2.0% |
| Medium | 0.20 – 0.60 | 4,437 | 28.3% |
| High | > 0.60 | 23,108 | 95.2% |

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

| Source | Contains |
|---|---|
| `ml-service/models/*/metrics.json` | Raw measurements, committed |
| `ml-service/models/*/config.json` | Hyperparameters, seed, library versions |
| `ml-service/models/*/split_indices.json` | Exact row assignment, so the split is verifiable |
| `docs/adr/0004` | URL model methodology and reasoning |
| `docs/adr/0005` | Why there is no message figure |
| `ml-service/training/DATASETS.md` | Corpus provenance, including rejected sources |
