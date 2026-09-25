# ADR 0006 — The URL model flags real banking and government pages

- **Status:** Open defect. Recorded, not fixed.
- **Date:** 2026-08-11
- **Discovered:** Phase 4, live-testing the prediction API against real URLs
- **Affects:** `rf-url-v1.2.0-no-https`, the shipped artefact

---

## Summary

> The URL classifier reports test F1 **0.8984** on its corpus and flags
> `https://www.maybank2u.com.my/home/m2u/common/login.do` as **scam at 0.93 confidence**.
>
> On a 20-URL probe of real Malaysian and global sites, **8 were flagged — a 40% false-positive
> rate**. Recall is unaffected: all 8 known phishing shapes were caught.
>
> The corpus metric is not wrong. The corpus is unrepresentative of what ScamSense will be asked
> about, in a way the held-out test set could not reveal because the test set is drawn from the
> same corpus.

## How it was found

Phase 4's live smoke check ran real URLs through the running service rather than corpus rows. The
genuine Maybank login page came back `scam`, which the corpus test set had given no hint of.

This is worth noting for the write-up: a held-out split of a corpus validates a model *against
that corpus*. It cannot detect that the corpus itself is drawn from a different distribution than
production. Only real inputs do that.

## The measurements

### Probe results

| Set | n | Wrong | Rate |
|---|---:|---:|---:|
| Real Malaysian and global sites | 20 | 8 | **40%** |
| Known phishing shapes | 8 | 0 | 0% |

Flagged genuine sites, with model probability:

| URL | p(phishing) |
|---|---:|
| `maybank2u.com.my/home/m2u/common/login.do` | 0.9337 |
| `tnb.com.my/residential/billing` | 0.5651 |
| `hasil.gov.my/en/` | 0.5524 |
| `poslaju.com.my/track-trace/` | 0.4165 |
| `kwsp.gov.my/en/member/overview` | 0.3291 |
| `jpj.gov.my/en/web/main-site/utama` | 0.3140 |
| `cimb.com.my/en/personal/index.html` | 0.3114 |
| `bnm.gov.my/` | 0.2916 |

The same domains **without a path** score low: `maybank2u.com.my` 0.1498,
`cimbclicks.com.my` 0.1069, `shopee.com.my` 0.0391.

### A hypothesis that was dismissed too quickly — CORRECTION, 2026-08-12

The obvious explanation — Tranco contributed 23,936 bare domains, so the model learned
"path present = phishing" — was originally tested against the **marginal** distribution and
recorded here as rejected. Path presence is indeed near-identical across classes: legitimate
37.2%, phishing 38.0%, and "has a path" alone classifies 50.4% of the corpus, which is chance.

**That test was the wrong test, and the conclusion drawn from it was wrong.** A feature can be
useless on its own and enormously influential in combination, which is precisely what a Random
Forest is built to exploit.

Measured directly, by adding a single trailing slash and changing nothing else:

| URL | no slash | with `/` | change |
|---|---:|---:|---|
| `https://shopee.com.my` | 0.0391 | **0.8814** | low → HIGH |
| `https://www.publicbank.com.my` | 0.1082 | **0.7861** | low → HIGH |
| `https://en.wikipedia.org` | 0.2637 | **0.7046** | medium → HIGH |
| `https://www.maybank2u.com.my` | 0.1498 | **0.6426** | low → HIGH |
| `https://www.google.com` | 0.2395 | **0.6735** | medium → HIGH |
| `https://www.uptm.edu.my` | 0.1013 | 0.2964 | low → medium |

The only features that differ are `url_length` (+1) and `path_length` (0 → 1).

**`path_length == 0` is functioning as a near-decisive legitimacy signal**, because PhiUSIIL and
Tranco together contributed 87,713 legitimate URLs — 59% of the negative class — of which
**none** carry a path. Tranco is a ranked list of *domains*, not URLs, so a path was never
possible in that source.

This is the same family of defect as the `uses_https` contamination in ADR 0004: a property of
how the corpus was assembled, learned as though it were a property of phishing.

It is worse than the credential-vocabulary finding below in one important respect: **browsers add
trailing slashes.** A user copying their bank's address from the URL bar will very often paste
one, so this affects ordinary usage rather than an unusual shape.

The credential-vocabulary finding remains real and is documented below; it is simply not the
dominant effect. Both share one root cause — the legitimate class does not look like the URLs
real users paste.

### The actual cause

The association is with **credential vocabulary**, not paths:

| | No credential vocabulary | Has credential vocabulary |
|---|---:|---:|
| Legitimate | 146,942 | **2,194** |
| Phishing | 142,182 | **6,954** |

**Of corpus URLs containing credential vocabulary — `login`, `verify`, `secure`, `account`,
`akaun`, `sahkan` — 76% are labelled phishing.** Narrowing to a deep path *and* credential
vocabulary, 70.6% is phishing.

The model learned that association correctly. It is a genuine property of the corpus, not an
assembly artefact like `uses_https` was.

The problem is that **the corpus prior does not match ScamSense's deployment prior.** The single
most common thing a worried user pastes is a link that claims to be their bank's login page. The
corpus says that shape is 76% phishing. Real legitimate login pages are outnumbered 6,954 to
2,026 — a shortfall of roughly 3.4 to 1 in exactly the region where users will concentrate.

The recall-weighted decision threshold (0.2640, chosen to weight recall four times precision)
then converts a moderate probability into a scam verdict, which is why sites scoring 0.29–0.57
are flagged.

## Why this is not fixed in this commit

Three candidate fixes, none of which should be applied on the evidence available:

1. **Raise the threshold.** On this probe, 0.60 would eliminate seven of the eight false
   positives while catching all eight phishing URLs. **This must not be done on this basis.**
   Re-choosing an operating threshold to fit twenty hand-picked URLs is the same error as tuning
   against a test set, with a smaller and more biased sample. A threshold change needs a
   validation set representative of deployment.
2. **Add legitimate authenticated-area URLs to the corpus.** The principled fix: real banking,
   government and utility pages with credential vocabulary in the path, in volume comparable to
   the phishing examples. This is corpus work, not model work.
3. **Drop `has_sensitive_keyword`.** Rejected. Unlike `uses_https` it is a genuine lexical
   property of the URL, and credential vocabulary really is a phishing signal — removing it
   would trade a false-positive problem for a false-negative one, which is the wrong direction
   for this project.

Recommendation: **(2), then re-derive the threshold on the widened corpus.** In the meantime the
defect is documented, tested and visible.

### A fourth option, added with the trailing-slash correction

4. **Normalise an empty path and a bare `/` to the same value before feature extraction.**
   `https://x.com` and `https://x.com/` are the same resource, and treating them as different
   inputs is indefensible on its own terms — this is ordinary URL normalisation, not threshold
   tuning, and it would remove the single sharpest edge in the model's behaviour.

   **It requires a retrain, and must not be applied at serving time alone.** The shipped model
   was fitted on unnormalised features; changing extraction only on the serving side would
   create exactly the training/serving skew that `url_features` being a shared module exists to
   prevent. Retraining also means the F1 in `docs/RESULTS.md` is re-measured, since the feature
   distribution changes.

   This is worth doing when the corpus work in (2) happens, in the same retrain — not as a
   separate hurried change.

## What was done

- `tests/test_real_world_urls.py` pins the behaviour. The eight false positives are `xfail` with
  a reason pointing here, so the suite stays green while the defect stays recorded — and turns
  XPASS the moment it is genuinely fixed.
- A bound test asserts the probe's false-positive count does not get worse.
- The nine sites the model gets right, and all eight phishing shapes, are asserted normally.

## Consequences

- **The 0.8984 figure stands as a corpus metric and must not be presented as real-world
  accuracy.** `docs/RESULTS.md` carries the qualification.
- **Phase 6 must not ship a binary flag.** ADR 0004 already recommended three risk bands; this
  finding makes that mandatory. Note that banding alone does not rescue the Maybank login case,
  which scores 0.93 and would land in the high band regardless — only corpus work fixes that one.
- **Phase 9's UAT should include a real-site panel**, not just the corpus test set. This defect
  would have reached the viva otherwise.
- For Chapter 4 this is a stronger result than a clean 0.90 would have been: a measured
  demonstration that held-out corpus performance and deployment performance are different
  quantities, with the mechanism identified and quantified.
