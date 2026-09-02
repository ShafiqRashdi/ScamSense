# Citation index

**Every claim the report makes, and the file that supports it.**

Written so that any figure or assertion in Chapters 4 and 5 can be traced to something
committed. If a claim is not in this table, it is not yet evidenced — treat that as a gap
rather than as licence to assert it.

Verified against the artefacts on 2026-09-03. Every file path below is asserted to exist by
`backend/tests/Feature/CitationIndexTest.php`, so a reference cannot rot silently.

---

## Headline figures

Quote these exactly. `docs/RESULTS.md` is the single source of truth; where any other file
disagrees with it, it is wrong.

| Claim | Figure | Cite |
|---|---|---|
| URL classifier, test F1 | **0.8979** | `RESULTS.md`; `models/rf-url-v2.0.0-pathnorm/metrics.json` |
| URL precision / recall / ROC AUC | 0.8368 / 0.9687 / 0.9576 | same |
| URL decision threshold | 0.3105 | same |
| Message classifier, test F1 | **0.9735** | `RESULTS.md`; `models/tfidf-msg-v2.0.0-collected-my/metrics.json` |
| Message precision / recall | 1.0000 / 0.9483 | same |
| Message F1, leak-free subset | 0.9684 | same, `test_leak_free_subset` |
| DistilBERT experiment F1 | 0.9913 | `models/distilbert-msg-v1.0.0-experiment/metrics.json`; ADR 0009 |

**Every message figure must carry its qualifier.** It is stored in the artefact as
`REPORTING_QUALIFIER` and rendered on the dashboard beside the number, so the caveat and the
figure cannot be separated: *"F1 on a 528-message collected Malaysian corpus… legitimate is the
smaller class at 238… treat the confidence interval as wide."*

---

## Corpus, and what was rejected

| Claim | Cite |
|---|---|
| `messages_my.csv` — 528 rows, 493 distinct skeletons, 290 scam / 238 legitimate | `RESULTS.md`; `models/tfidf-msg-v2.0.0-collected-my/metrics.json` (`corpus.unique_templates`); `DATASETS.md` for provenance |
| `legit_urls_my.csv` — 221 collected Malaysian URLs | `DATASETS.md`; `RESULTS.md` |
| Collected URLs are 0.074% of the URL corpus and cannot have shifted the model | `RESULTS.md` — states this explicitly so the corpus is not over-claimed |
| **Rejection 1** — synthetic corpus, no valid metric obtainable by any split | **ADR 0005** |
| **Rejection 2** — Malay email corpus, label contamination | **ADR 0011** |
| Domain leakage in the public URL corpus (56.7% of rows on repeated domains) | ADR 0004; `RESULTS.md` |
| Random split overstates F1 by 6.4 points (0.9606 vs 0.8967) | ADR 0004 |

Both rejections belong together in the write-up. One corpus could produce no metric; the other
would have produced a flattering meaningless one. Together they are the corpus audit.

---

## Known limits — Chapter 5

| Claim | Figure | Cite |
|---|---|---|
| Out-of-corpus generalisation probe | **70.8%, 6 of 12 scams missed** | `training/message_probe.py`; `RESULTS.md`; AGENTS.md |
| Misses cluster in sparse categories | Parcel 18 rows → 0/2; Macau 19 → 1/3; Prize 20 → 1/2 | `RESULTS.md` table; `message_probe.py` output |
| DistilBERT scored higher but not measurably | +0.0179, 95% CI **[−0.0167, +0.0560]**, spans zero | ADR 0009; `RESULTS.md` |
| Corpus needed to resolve it | **~800 test messages (~4,000 total)** for ~80% | `training/power_analysis.py`; `RESULTS.md` |
| 1,200–1,500 will probably not resolve it, and that is the finding | `RESULTS.md` — recorded as a deliberate scope decision |
| URL model flags genuine institutional pages | 1 of 17 genuine sites in the high band | ADR 0006; `tests/test_real_world_urls.py` |
| `url_length` is source-correlated yet load-bearing | label AUC 0.5896 vs source AUC 0.8978; dropping it costs 8.9 F1 | `RESULTS.md`; ADR 0010; `training/audit_url_artefacts.py` |
| Several high-importance features are deliberately never surfaced | `url_length` fires at 52.3% precision against a 49.7% base rate | **ADR 0010**; `NOT_SURFACED` in `app/indicators.py` |
| Admin interface is English-only by scope decision | — | This file; state it as a decision, not an omission |

---

## Security and process — Chapters 4 and 5

| Claim | Cite |
|---|---|
| Ten security findings, reported before any were fixed | `docs/security-review-2026-08.md` |
| Sanitisation boundaries — redaction applied inbound, absent outbound | `security-review-2026-08.md` findings 1 and 2 |
| A documented control that nothing implements is not a control | `security-review-2026-08.md` finding 10 follow-up; `deployment-boundary-defects.md` §4 |
| **Four defects invisible to a fully green test suite** | **`docs/deployment-boundary-defects.md`** |
| Graceful degradation converts a loud failure into a quiet one | `deployment-boundary-defects.md`, closing section |
| Moderation gate enforced as a model invariant, not in a service | ADR 0008; `app/Models/TrainingSample.php`; `docs/code-snippets.md` Fig 4.17 |
| Verified-domain gate is a safety mitigation, never model accuracy | ADR 0007; stated on the dashboard beside the count |
| Threat intel may raise a verdict, never lower one | ADR 0007 |
| Message indicators are a pattern layer, not interpretability | **ADR 0010**; asserted by a test |

---

## Schema and figures

| Claim | Cite |
|---|---|
| Data dictionary, all seven tables | **`docs/data-dictionary.md`** — verified against migrations *and* the live database |
| No `model_versions` table; traceability by denormalisation | `data-dictionary.md`; `tests/Feature/DataDictionaryTest.php` |
| Wireframes 4.1–4.4, diagrams 4.5, 4.6, 4.8 | `docs/wireframes/` + its README |
| Code snippets 4.16–4.21, with line ranges | `docs/code-snippets.md` |
| Redaction runs in the controller, not the form request | `docs/code-snippets.md` closing note — **§4.4.1(a) of the draft is wrong** |

---

## Claims with no source yet

Honest gaps. Each is either work not done or a claim that should be softened.

| Claim the report may want to make | Status |
|---|---|
| UAT results with real participants | **No participants recruited.** Nothing to cite |
| "Legitimate bank message → low risk" as a passing test | **Not covered on the real model.** `test_real_world_urls.py` is URLs only; the Laravel band tests fake the ML response |
| Adversarial robustness | Not tested. Say so in limitations |
| Feedback loop validated at scale | Not validated. The gate is tested; throughput under real volume is not |
| Latency against the 3-second objective in production | Only measured locally. `verdicts.inference_ms` will evidence it once deployed |
| Inter-annotator agreement on the collected corpus | Labelled by one person. Describe as "read end to end", never as "verified" |
