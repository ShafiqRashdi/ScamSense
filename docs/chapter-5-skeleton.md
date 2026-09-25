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

**Cite:** `docs/RESULTS.md`; `models/tfidf-msg-v3.0.0-collected-my/metrics.json`.

| Metric | Full test split | Leak-free subset |
|---|---:|---:|
| F1 (scam class) | **0.9836** | 0.9823 |
| Precision | 0.9756 | 0.9737 |
| Recall | 0.9917 | 0.9911 |
| Rows | 223 | 214 |

**Quote this figure only with its qualifier**, which ships inside the artefact as
`REPORTING_QUALIFIER` precisely so the two cannot be separated: *F1 on a 1,113-message collected
Malaysian corpus, 940 distinct templates, real collected SMS and WhatsApp redacted at source,
class balance uneven with legitimate the smaller class at 504.*

`[WRITE: why both figures are given.]` Five templates still appear on both sides of the split —
6 test rows, 2.7%, down from 8.5% — so the leak-free subset bounds the primary figure from below.

#### The comparison that is easy to report wrongly

`[WRITE: this needs stating carefully, and a panel will probe it.]`

The previous model scored 0.9735 and this one scores 0.9836, and **those are not comparable**:
the test set grew from 106 rows to 223 when the corpus grew. The 106 kept their partition through
the merge, so a like-for-like comparison exists.

| On the identical 106 rows | F1 | Precision | Recall | Missed of 58 | False positives |
|---|---:|---:|---:|---:|---:|
| v2.0.0 | 0.9735 | 1.0000 | 0.9483 | 3 | 0 |
| v3.0.0 | 0.9744 | 0.9661 | 0.9828 | 1 | 2 |

**F1 moved +0.0009, and that near-zero figure hides the real change.** The model traded two
missed scams for two false positives, which a harmonic mean treats as a wash. Under the
asymmetry this project applies everywhere else — a missed scam costs money, a false positive
costs ten seconds — that is a favourable trade, and it is invisible in the headline metric.
Report the confusion matrix here, not the F1.

### 5.3.3 Was a transformer better? — a documented negative result, measured twice

**Cite:** ADR 0009 and its 2026-09-07 amendment; `docs/RESULTS.md`; `training/power_analysis.py`.

| | TF-IDF + LogReg *(shipped)* | DistilBERT *(experiment)* |
|---|---:|---:|
| F1 (scam) | 0.9836 | **0.9959** |
| Precision / recall | 0.9756 / 0.9917 | 1.0000 / **0.9917** |
| Missed scams, of 121 | 1 | 1 |
| False positives, of 102 | 3 | **0** |

DistilBERT scored **0.0122 higher**. A paired bootstrap over the 223 test messages puts the 95%
interval on that difference at **[−0.0043, +0.0317]** — it **still spans zero**, with 88.0% of
resamples favouring DistilBERT.

`[WRITE: the correct sentence, and why the wrong one is tempting.]` The defensible claim is
*"DistilBERT was evaluated twice and scored 0.9959 against the baseline's 0.9836 on a
1,113-message corpus; the difference lies within the confidence interval this corpus can resolve,
so the baseline was retained."* Not *"DistilBERT was no better"* — it may well be better, and the
measurement cannot say. **Report the interval, not the point estimate:** the point estimate has
moved twice and the conclusion has not.

**What doubling the corpus actually bought.**

| | 528 rows, 106 test | 1,113 rows, 223 test |
|---|---:|---:|
| Point estimate | +0.0179 | +0.0122 |
| 95% CI | [−0.0167, +0.0560] | [−0.0043, +0.0317] |
| Interval width | 0.0727 | **0.0360** |
| Resamples favouring DistilBERT | 77.2% | **88.0%** |
| Spans zero | yes | **yes** |

`[WRITE: this is the paragraph a panel will remember.]` The interval halved and the answer did
not change. That is what a small corpus looks like from the inside: visible progress towards a
conclusion it cannot reach.

**The kind of error changed, and it argues against DistilBERT.** On 528 rows the two models
differed on *recall* — the baseline missed 3 scams of 58, DistilBERT 1. They now **miss the same
single scam of 121**, and the entire remaining difference is false positives, 3 against 0. Under
the asymmetry stated in 5.3.2, the surviving advantage is in the **cheaper** error. Paying 250 MB
of weights to remove three moments of unnecessary caution is a worse trade than the first
measurement suggested, not a better one.

**How much data would settle it.** Resampling from the observed error rates:

| Test messages | Total corpus | Trials resolving |
|---:|---:|---:|
| 223 *(current)* | 1,115 | 22% |
| 400 | 2,000 | 40% |
| 800 | 4,000 | 73% |
| 1,500 | 7,500 | **90%** |
| 3,000 | 15,000 | 100% |

`[WRITE: the methodological point — the estimate was tested against reality.]` The first run of
this analysis, on the 528-row corpus, predicted ~35% odds of resolving at 200 test messages. The
corpus reached 223 test messages and the comparison did not resolve. **The prediction held**,
which is a better reason to trust the table than the method's plausibility.

The target has since **grown**, from ~800 test messages to ~1,500, because the measured effect got
smaller. Collecting more data narrowed the interval and moved the finish line further away at the
same time. Collection was aimed at 1,200–1,500 rows in total and reached 1,113 — well below the
point of resolution, and **the interval still spanning zero at that size is itself the finding**.
A single-semester collection effort by one person cannot settle this question, and reporting that
honestly is worth more than reporting a result the data cannot carry.

### 5.3.4 What the corpus figures do not tell you — and what fixed it

`[WRITE: the most important subsection in this chapter, and now the most positive.]`

A corpus figure is measured on messages drawn from the same collection effort as the training
rows. A hand-built probe of 24 messages written independently asks the harder question.
**Cite:** `training/message_probe.py`; `tests/test_real_world_messages.py`.

| | v2.0.0 (528 rows) | v3.0.0 (1,113 rows) |
|---|---:|---:|
| Overall accuracy | 17/24 (70.8%) | **22/24 (91.7%)** |
| Scams missed | 6/12 | **2/12** |
| Legitimate flagged | 1/12 | **0/12** |

`[WRITE: this is the chapter's strongest passage — a prediction that was acted on and held.]`

The v2 probe showed recall tracking category support: every miss sat in a category holding fewer
than 21 rows, every category above 39 was caught. That was a testable claim about what collection
would fix, and it was acted on directly rather than by collecting uniformly:

| Category | v2 rows | v2 probe | v3 rows | v3 probe |
|---|---:|---:|---:|---:|
| Parcel scam | 18 | 0/2 | 111 | 1/2 |
| Macau scam / impersonation | 19 | 1/3 | 102 | **3/3** |
| Lucky draw / prize | 20 | 1/2 | 106 | 1/2 |
| Phishing link | 40 | 2/3 | 77 | **3/3** |

Four misses closed — a JPJ summons demand, an LHDN refund, a Touch 'n Go suspension and a Shopee
delivery notice. Hypothesis, intervention, result.

**Two misses remain, and they are a different problem.** A failed Shopee delivery and a Petronas
prize draw both invoke a brand in routine-sounding language, without the urgency or credential
vocabulary the model weighs. Their categories grew six-fold and five-fold and the misses did not
close. `[WRITE: say plainly that this is the one place where more data is demonstrably not the
answer — it distinguishes a scarcity problem from a representation problem, and being able to
tell them apart is worth more than closing both.]`

The URL probe finds **1 of 19 genuine Malaysian sites** in the high band, with the count of
mislabelled genuine sites falling from 8 to 5 across the v2 retrain. **Cite:** ADR 0006.

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
