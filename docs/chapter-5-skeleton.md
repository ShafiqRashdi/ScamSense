# Chapter 5 skeleton — Testing and Evaluation

**A drafting scaffold, not finished prose.** Every figure below is real and traceable; the
connecting text is yours to write. `[WRITE: …]` marks a paragraph to compose, `[FIGURE: …]` and
`[TABLE: …]` are greppable placeholders in the house convention, and `[BLOCKED: …]` marks
something that cannot be written until work outside the codebase is done.

Chapter 4 closes by promising this chapter will present *"the performance of both classifiers,
the results of user acceptance testing, and an assessment of the extent to which the project
objectives were met."* The structure below delivers those three in that order.

Every number is cited. If a claim here has no citation it is not yet evidenced — see
`docs/citation-index.md`, whose closing section lists what still has no source.

---

## 5.1 Introduction

`[WRITE: one paragraph.]` What this chapter evaluates and how. Worth stating the principle
plainly up front, because it governs everything that follows and it is what the chapter's
credibility rests on: **a figure is reported with the conditions under which it was measured, or
it is not reported.** The same discipline is why two corpora were rejected and why a negative
result is included rather than omitted.

---

## 5.2 Testing approach

`[WRITE: introduce the three layers and, more importantly, what each cannot show.]`

| Layer | Count | What it establishes | What it cannot |
|---|---:|---|---|
| Laravel feature and unit tests | **319** (1 skipped) | Behaviour of the application: validation, escaping, rate limiting, the moderation gate, verdict presentation | Anything about model quality — the ML service is faked throughout |
| Python unit and integration tests | **232** (220 passed, 12 xfailed) | Feature extraction, HMAC verification, prediction routes, and behaviour of the **shipped artefacts** | Anything about the user interface |
| Hand-built probes | 24 URLs, 24 messages | How the models behave on real Malaysian input outside the corpus | A generalisable metric — they are small and deliberately adversarial |

**The layers are complementary and none substitutes for another.** The Laravel tests fake the
ML response, so they would pass with the model broken; the Python tests assert on the model, so
they would pass with a page that rendered nothing. Both halves are needed, and the two UAT
scenarios are covered at both layers for that reason.

`[WRITE: the xfail convention.]` Twelve Python tests are marked `xfail`. They are known defects
written as executable assertions rather than deleted or left passing — they keep the suite
honest, document each limit precisely, and report `XPASS` the moment a fix lands, which is what
makes an improvement measurable rather than asserted. **Cite:** `tests/test_real_world_urls.py`,
`tests/test_real_world_messages.py`.

---

## 5.3 Classifier performance

### 5.3.1 URL classifier

**Cite:** `docs/RESULTS.md`; `ml-service/models/rf-url-v2.0.0-pathnorm/metrics.json`.

| Metric | Value |
|---|---:|
| F1 (phishing class) | **0.8979** |
| Precision | 0.8368 |
| Recall | 0.9687 |
| ROC AUC | 0.9576 |
| Decision threshold | 0.3105 |
| Test rows | 51,467 |
| Domain leakage between train and test | **0** |

`[WRITE: why recall exceeds precision by design.]` A missed scam costs a user money; a false
positive costs them ten seconds of doubt. The threshold was chosen on the validation set to
weight recall accordingly, not defaulted to 0.5.

`[WRITE: the split, and why the honest number is lower than the flattering one.]` 56.7% of the
corpus sits on repeated registrable domains — free hosting platforms abused at scale. A random
split leaks those domains across partitions and reports **0.9606**; the domain-grouped split
reports **0.8967**. The 6.4-point gap is not noise, it is the difference between measuring
generalisation and measuring corpus concentration. **Cite:** ADR 0004.

### 5.3.2 Message classifier

**Cite:** `docs/RESULTS.md`; `models/tfidf-msg-v2.0.0-collected-my/metrics.json`.

| Metric | Full test split | Leak-free subset |
|---|---:|---:|
| F1 (scam class) | **0.9735** | 0.9684 |
| Precision | 1.0000 | 1.0000 |
| Recall | 0.9483 | 0.9388 |
| Rows | 106 | 97 |

**Quote this figure only with its qualifier**, which ships inside the artefact as
`REPORTING_QUALIFIER` precisely so the two cannot be separated: *F1 on a 528-message collected
Malaysian corpus, 493 distinct templates, real collected SMS and WhatsApp redacted at source,
class balance uneven with legitimate the smaller class at 238.*

`[WRITE: why both figures are given.]` Five templates appear on both sides of the shipped split.
Rather than reshuffle a partition the corpus author constructed deliberately, both figures are
reported: the leak-free subset bounds the primary figure from below, and the gap is small.

### 5.3.3 Was a transformer better? — a documented negative result

**Cite:** ADR 0009; `docs/RESULTS.md`; `training/power_analysis.py`.

| | TF-IDF + LogReg *(shipped)* | DistilBERT *(experiment)* |
|---|---:|---:|
| F1 (scam) | 0.9735 | **0.9913** |
| Missed scams, of 58 | 3 | 1 |
| False positives, of 48 | 0 | 0 |

DistilBERT scored **0.0179 higher**. A paired bootstrap over the 106 test messages puts the 95%
interval on that difference at **[−0.0167, +0.0560]** — it **spans zero**, with 77.2% of
resamples favouring DistilBERT.

`[WRITE: the correct sentence, and why the wrong one is tempting.]` The defensible claim is
*"DistilBERT was evaluated and scored 0.9913 against the baseline's 0.9735; the difference lies
within the confidence interval this corpus can resolve, so the baseline was retained."* Not
*"DistilBERT was no better"* — it may well be better, and the measurement cannot say. The whole
advantage is two messages.

**How much data would settle it.** Resampling from the observed error rates:

| Test messages | Total corpus | Trials resolving |
|---:|---:|---:|
| 106 *(current)* | 530 | 7% |
| 400 | 2,000 | 55% |
| 800 | 4,000 | **83%** |
| 1,500 | 7,500 | 97% |

`[WRITE: and why the target is nevertheless lower.]` Collection is aimed at 1,200–1,500 total,
which the table places below the point of resolution. **If the interval still spans zero at
that size, that is itself the finding** — a single-semester collection effort by one person
cannot settle this question, and reporting that honestly is worth more than reporting a result
the data cannot carry.

### 5.3.4 What the corpus figures do not tell you

`[WRITE: the most important subsection in this chapter.]`

Both headline figures are measured on held-out data drawn from the same collection effort as
the training rows. A hand-built probe of messages written independently asks the harder
question. **Cite:** `training/message_probe.py`; `tests/test_real_world_messages.py`.

| | Result |
|---|---:|
| Overall accuracy | **17/24 (70.8%)** |
| Scams missed | **6/12** |
| Legitimate messages flagged | 1/12 |

**Recall tracks category support almost exactly.** Every miss falls in a category holding fewer
than 21 training rows; every category above 39 rows was caught:

| Category | Corpus rows | Probe |
|---|---:|---:|
| Parcel scam | 18 | 0/2 |
| Macau scam / impersonation | 19 | 1/3 |
| Lucky draw / prize | 20 | 1/2 |
| Loan scam | 39 | 1/1 |
| Job offer scam | 76 | 1/1 |

`[WRITE: state the consequence without softening it.]` The missed messages are textbook
Malaysian scams — a JPJ summons demand, a customs-held parcel, an LHDN refund, a Touch 'n Go
suspension. A user pasting any of those today is told there are no warning signs. The figure of
0.9735 is honest about the corpus and silent about this, which is why both are reported.

The equivalent probe on the URL side finds **1 of 19 genuine Malaysian sites** in the high band,
and the count of genuine sites the model mislabels at any level fell from **8 to 5** across the v2
retrain. Three moved from wrong to right — `jpj.gov.my`, `bnm.gov.my`, `cimb.com.my` — recorded
as `xfail` cases turning `XPASS`. **Cite:** ADR 0006; `docs/RESULTS.md`.

---

## 5.4 System and security testing

`[WRITE: brief framing — the platform accepts hostile input by design, so this is functional
testing rather than hardening.]`

**Cite:** `tests/Feature/SecurityPayloadTest.php`.

| Payload | Expected | Result |
|---|---|---|
| `<script>alert(1)</script>` | Rendered escaped, never executed | Pass |
| `<img src=x onerror=alert(1)>` | Rendered escaped | Pass |
| `javascript:alert(document.domain)` | Rejected at validation | Pass |
| `http://127.0.0.1:5001/api/v1/predict` | Accepted as text, never fetched | Pass |
| `' OR '1'='1` | Stored as literal text | Pass |
| 6,000 characters | Rejected with 422 | Pass |
| 11 requests in 60 seconds | 429 on the eleventh | Pass |
| Any submitted URL | Never rendered inside an `href` | Pass |

`[TABLE: 5.x — Security payload results]`

`[WRITE: the moderation gate.]` No code path promotes feedback into the training set without
explicit approval, and the constraint is enforced as a model invariant rather than in a service
class, so it binds write paths that do not yet exist. **Cite:** ADR 0008;
`tests/Feature/ModerationGateTest.php`.

`[WRITE: log leakage.]` A dedicated test asserts that no submitted URL and no API key appears
in `laravel.log` at any level, including on the connection-timeout path — the route by which
findings 1 and 2 originally escaped. **Cite:** `tests/Feature/LogLeakageTest.php`;
`docs/security-review-2026-08.md`.

---

## 5.5 User acceptance testing

**`[BLOCKED: no participants have been recruited. This section cannot be written yet.]`**

`[WRITE, once run: participant count, recruitment method, tasks, and what they were not told.]`

The two acceptance criteria from the brief have automated coverage at both layers already,
which is not a substitute for user testing but does establish the system behaves as specified
before anyone is asked to try it:

| Criterion | Model layer | Presentation layer |
|---|---|---|
| Known phishing URL → high risk with reasons | `test_real_world_urls.py` | `VerifyPageTest.php` (`UAT:`) |
| Legitimate bank message → low risk with explanation | `test_real_world_messages.py` | `VerifyPageTest.php` (`UAT:`) |

`[WRITE, once run: what to actually measure.]` Suggested — whether a participant can state what
the verdict means in their own words, whether the medium band reads as uncertainty rather than
accusation, and whether anyone treats "no warning signs" as a guarantee of safety. That last one
is the failure the interface copy is most carefully written to prevent, and the only way to know
whether it worked is to ask someone.

---

## 5.6 Attainment of objectives

### O1 — detection engine

> *Two models served over an internal API; F1 ≥ 0.85 on a held-out test set for both tasks.*

**Met, with a stated qualification.** URL 0.8979, message 0.9735, both above 0.85 and both
served over the HMAC-authenticated internal API.

`[WRITE: the qualification, and do not bury it.]` Two things narrow the claim. Until August the
message model was trained and committed but never loaded, so the running product returned
"could not check" for every message while the metric stood — the metric was honest, the claim
about the product was not. And the message model's out-of-corpus generalisation is materially
weaker than its corpus figure suggests, as §5.3.4 sets out. **Cite:**
`docs/deployment-boundary-defects.md`; `docs/RESULTS.md`.

### O2 — public interface

> *Paste → verdict → confidence → indicators, mobile-first, WCAG 2.1 AA, p95 < 3s.*

**Met for the interface; latency evidenced locally only.**

`[WRITE: what was built.]` Mobile-first at 375px, fully bilingual, risk never conveyed by colour
alone — every band pairs its colour with an icon and a text label, which is a WCAG 1.4.1
requirement and also the right choice for the older users this exists for.

`[BLOCKED: production p95. `verdicts.inference_ms` records it per verdict and will evidence this
once deployed.]`

### O3 — community reporting loop

> *Feedback captured, moderated, exported to a versioned retraining set.*

**Met structurally; unvalidated at volume.**

`[WRITE: the gate is the substance.]` Feedback lands as `pending` and reaches the training set
only through explicit approval, with provenance preserved so the proportion of any future
training set that came from users is answerable per row. No path exists from a submission to a
live model without a human decision — which is the data-poisoning defence, and it is enforced in
the schema rather than only described in this report.

`[WRITE: the honest limit.]` The gate is tested; throughput under real volume is not, because
there has been no real volume.

---

## 5.7 Limitations

`[WRITE: introduce these as findings rather than apologies. Each was measured, not guessed.]`

**Corpus scarcity is the binding constraint.** 528 messages, of which the sparsest scam
categories hold 18 to 20 rows. This is the direct cause of the 6-of-12 miss rate, and it is why
the DistilBERT comparison cannot be resolved. Two candidate corpora were rejected to get here —
one that could produce no valid metric by any split strategy, and one whose labels contradicted
themselves and would have *raised* the reported score while lowering real performance. **Cite:**
ADR 0005; ADR 0011.

**The URL corpus is not Malaysian.** Its legitimate class is dominated by bare domains from
Tranco and PhiUSIIL, while the model's remaining false positives are all deep paths on genuine
Malaysian government and banking sites — the shape the corpus under-represents. The 221 collected
Malaysian URLs are 0.074% of the corpus and cannot have shifted what the model learned; their
value was diagnostic, and the report should say so rather than claim they improved it.
**Cite:** ADR 0006; `docs/RESULTS.md`.

**Explainability is a hybrid, and is labelled as one.** The message model is character n-grams,
whose real coefficients are per-n-gram and useless to a reader, so indicators come from a
curated pattern layer running alongside it. Every one carries `source: pattern` and `weight: 0`
so a regex match can never be presented as model attribution. Several high-importance URL
features are deliberately never shown to users: `url_length` fires at 52.3% precision against a
49.7% base rate, and an unverifiable reason at coin-flip precision manufactures false
confidence. **Cite:** ADR 0010.

**Four defects were invisible to a fully passing test suite.** Each made the running system
produce wrong output for a user while 311 tests passed, because they lived between the code and
the environment it runs in — process launch, a cached manifest, a seeder that refuses to act.
Two lessons generalise: graceful degradation hides its own trigger, converting a loud failure
into a quiet one; and a documented control that nothing implements is not a control. **Cite:**
`docs/deployment-boundary-defects.md`.

**The moderation gate binds every application path, but not a suppressed one.** It is enforced
in model hooks, which is what makes it cover write paths that do not yet exist — but
`Model::withoutEvents()` is outside it, and `DatabaseSeeder` wraps every seeder in exactly that.
No seeder creates a training sample and three tests now assert that, so there is no live bypass;
the guarantee for that one path rests on a checked convention rather than on the invariant. A
database `CHECK` constraint would close it below the ORM and is recorded as deferred, not
rejected. **Cite:** ADR 0008; `tests/Feature/ModerationGateTest.php`.

**Not tested at all.** Adversarial robustness. Feedback throughput at volume. Inter-annotator
agreement on the collected corpus, which one person labelled — describe it as read end to end,
never as verified.

---

## 5.8 Conclusion

`[WRITE: two or three paragraphs.]` What the evaluation establishes, what it does not, and the
single most useful thing a successor would do first — which on this evidence is targeted corpus
collection in the three sparse categories, since the failure mode is measured and its cause is
identified.

---

## Corrections needed in Chapter 4 before this chapter is consistent with it

Three claims in §4.6 and the data dictionary contradict the schema. They are listed in full in
`docs/data-dictionary.md`; the ones that also appear in Chapter 4's conclusion are:

1. *"the eight-table database schema"* → **seven** application tables.
2. *"Machine learning scores and threat intelligence scores are stored separately"* → they are
   not. One fused `confidence` column; the contribution of each is recoverable through
   `indicators.source`.
3. §4.4.1(a), *"Redaction is implemented within the Laravel form request"* → it runs in the
   controller, before `Submission::create()`. The claim it supports is true; the location is
   wrong. **Cite:** `docs/code-snippets.md`.
