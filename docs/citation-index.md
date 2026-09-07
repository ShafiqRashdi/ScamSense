# Citation index

**Every claim the report makes, and the file that supports it.**

Written so that any figure or assertion in Chapters 4 and 5 can be traced to something
committed. If a claim is not in this table, it is not yet evidenced — treat that as a gap
rather than as licence to assert it.

Verified against the artefacts on 2026-09-07. Every file path below is asserted to exist by
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
| Message classifier, test F1 | **0.9836** | `RESULTS.md`; `models/tfidf-msg-v3.0.0-collected-my/metrics.json` |
| Message precision / recall | 0.9756 / 0.9917 | same |
| Message F1, leak-free subset | 0.9823 | same, `test_leak_free_subset` |
| DistilBERT experiment F1 | **0.9959** | `models/distilbert-msg-v2.0.0-experiment/metrics.json`; ADR 0009 amendment |
| DistilBERT precision / recall | 1.0000 / 0.9917 | same — **recall is identical to the baseline's**; the whole difference is 3 false positives against 0 |

**Every message figure must carry its qualifier.** It is stored in the artefact as
`REPORTING_QUALIFIER` and rendered on the dashboard beside the number, so the caveat and the
figure cannot be separated: *"F1 on a 1113-message collected Malaysian corpus (940 distinct
templates, 84% distinct)… legitimate is the smaller class at 504… treat the confidence interval
as wide, and do not present this figure as equivalent to one measured on a large public
dataset."*

---

## Corpus, and what was rejected

| Claim | Cite |
|---|---|
| `messages_my.csv` — 1,113 rows, 940 distinct skeletons, 609 scam / 504 legitimate | `RESULTS.md`; `models/tfidf-msg-v3.0.0-collected-my/metrics.json` (`corpus.unique_templates`); `DATASETS.md` for provenance |
| `legit_urls_my.csv` — 459 collected Malaysian URLs, 76% path-bearing | `DATASETS.md`; `RESULTS.md` |
| Collected URLs are 0.15% of the URL corpus and cannot have shifted the model | `RESULTS.md` — states this explicitly so the corpus is not over-claimed |
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
| Out-of-corpus generalisation probe | **91.7%, 2 of 12 missed** (v2: 70.8%, 6 of 12) | `training/message_probe.py`; `tests/test_real_world_messages.py`; `RESULTS.md` |
| Recall tracks category support, confirmed by acting on it | parcel 18→111, prize 20→106, impersonation 19→102; four of six misses closed | `RESULTS.md`, v2/v3 probe table |
| DistilBERT scored higher but not measurably — **measured twice** | +0.0122, 95% CI **[−0.0043, +0.0317]**, spans zero (528-row run: +0.0179, [−0.0167, +0.0560]) | ADR 0009 amendment; `RESULTS.md` |
| Doubling the corpus halved the interval without resolving it | width 0.0727 → **0.0360**; resamples favouring DistilBERT 77.2% → **88.0%** | ADR 0009 amendment; `models/distilbert-msg-v2.0.0-experiment/metrics.json` (`comparison_vs_baseline`) |
| The remaining difference is precision, not recall — the cheaper error | both miss the same 1 of 121; baseline adds 3 false positives of 102 | ADR 0009 amendment; `RESULTS.md` |
| Corpus needed to resolve it | **~1,500 test messages (~7,500 total)** for ~80% — the target **grew** from ~800 because the effect shrank | `training/power_analysis.py`; `RESULTS.md` |
| The power analysis was tested against reality and held | predicted ~35% at 200 test rows; 223 rows did not resolve | `training/power_analysis.py` docstring; ADR 0009 amendment |
| URL model flags genuine institutional pages | 1 of 17 genuine sites in the high band | ADR 0006; `tests/test_real_world_urls.py` |
| `url_length` is source-correlated yet load-bearing | label AUC 0.5896 vs source AUC 0.8978; dropping it costs 8.9 F1 | `RESULTS.md`; ADR 0010; `training/audit_url_artefacts.py` |
| Several high-importance features are deliberately never surfaced | `url_length` fires at 52.3% precision against a 49.7% base rate | **ADR 0010**; `NOT_SURFACED` in `app/indicators.py` |
| Admin interface is English-only by scope decision | — | This file; state it as a decision, not an omission |

---

## Interface design — Chapter 4

Design choices that are reasoning rather than taste, and that a panel is likely to probe.

| Claim | Figure | Cite |
|---|---|---|
| The design follows from one fact — the user is already anxious | three stated rules | **ADR 0012** |
| The brand green is deliberately far from the risk hues | — | ADR 0012 §1; `backend/resources/css/app.scss` palette block |
| The verdict card is deliberately *less* rounded than the rest of the page | radius 0.75rem → 0.5rem, 6px top rule | ADR 0012 §2 — rounded pastel is scam-site visual language |
| Colour is never the only carrier of a verdict | border + badge + icon + text label, three times over | ADR 0012 §3; WCAG 1.4.1; `resources/views/verify/result.blade.php` |
| Base type raised above Bootstrap's default | 17px, and the scale set against `.h3`/`.h4` as used | ADR 0012 §4 |
| The verified panel renders first and owns the `h1` | it previously fell below the fold at 375px | ADR 0012 §5 |
| The indicator card renders even when empty | an empty list is a real finding, not a failed load | ADR 0012 §6; ADR 0010 |
| Self-hosting the webfont is what lets `font-src 'self'` stand | 41 KB, two variable files | ADR 0012 §7 |
| **Palette contrast measured, not asserted** | 11 pairs computed; every text pair clears AA | **ADR 0012** contrast table |
| **Open defect: input border fails SC 1.4.11** | **1.57:1 against a required 3.0:1** | **ADR 0012** — report as a limitation if unfixed at demonstration |

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
| UAT scenarios from the brief, both halves | `ml-service/tests/test_real_world_messages.py` (real model); `backend/tests/Feature/VerifyPageTest.php` (presentation, tests named `UAT:`) |

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
| Adversarial robustness | Not tested. Say so in limitations |
| WCAG conformance as a whole | **Only the palette was measured** (ADR 0012). No audit of focus order, labels or screen-reader flow. Claim contrast, not conformance |
| Feedback loop validated at scale | Not validated. The gate is tested; throughput under real volume is not |
| Latency against the 3-second objective in production | Only measured locally. `verdicts.inference_ms` will evidence it once deployed |
| Inter-annotator agreement on the collected corpus | Labelled by one person. Describe as "read end to end", never as "verified" |
