---
name: ml-endpoint
description: Use when working in ml-service/ — building Flask routes, extracting URL features, preprocessing text, training or evaluating the DistilBERT message classifier or the Random Forest URL classifier, or changing the prediction JSON contract.
---

# ML Service

## Service shape

App factory, blueprints, models loaded once at startup into a module-level singleton. Loading a
transformer per request costs ~2s and blows the 3-second budget on its own.

```python
# app/predictors/message_classifier.py
class MessageClassifier:
    _instance = None
    def __init__(self, model_dir: Path) -> None: ...
    def predict(self, text: str) -> Prediction: ...
```

Every route validates its body with a Pydantic schema and returns 422 on failure. Never guess a
prediction from malformed input — a silent wrong answer is worse than an error here.

Verify the HMAC signature on every prediction route before doing any work. Reject signatures
older than 60 seconds.

## URL features (Random Forest)

Lexical only. **Never fetch the URL.** No `requests.get()`, no DNS resolution of user input, no
WHOIS on demand. Everything is computed from the string:

| Feature | Why it signals |
|---|---|
| `url_length`, `path_depth`, `query_param_count` | Scam URLs are longer and deeper |
| `host_is_ip_literal` | Legitimate brands don't use bare IPs |
| `has_punycode` / `xn--` prefix | Homograph attacks |
| `subdomain_count` | `maybank.com.secure-login.xyz` |
| `tld` (categorical, one-hot top-N) | `.xyz`, `.top`, `.tk` skew malicious |
| `is_known_shortener` | Obscures the destination |
| `has_at_symbol`, `has_double_slash_in_path` | Classic redirect tricks |
| `digit_ratio`, `hyphen_count`, `entropy_of_host` | DGA-style hosts |
| `brand_token_in_subdomain` | Lookalike targeting MY banks/couriers |
| `uses_https` | Weak alone, useful combined |

Keep feature extraction in `app/features/url_features.py` as pure functions. Every one gets a
unit test with a malicious and a benign example. The same module is imported by both training
and serving — training/serving skew from two copies of the extraction code is the most common
way an ML project silently breaks.

## Message classifier (DistilBERT)

- Base: `distilbert-base-multilingual-cased`. The corpus is Malay/English code-switched
  (*"Tahniah! Anda telah memenangi RM5,000, sila klik link"*), so an English-only checkpoint
  underperforms on exactly the messages that matter.
- Fine-tune for binary classification, max_length 128, early stopping on validation F1.
- Preprocess: normalise whitespace and unicode, lowercase only if the tokenizer is uncased,
  replace URLs with `[URL]` and numbers with `[NUM]` so the message model learns language
  patterns rather than memorising specific links (the URL model handles links).
- Train a TF-IDF + Logistic Regression baseline **first**. If DistilBERT doesn't clearly beat
  it, that finding goes in Chapter 4 — it's a legitimate result, not a failure.

## Datasets

Start from public sets (UCI SMS Spam Collection, PhishTank, Kaggle phishing URL sets), then
supplement with locally sourced Malaysian scam messages. Record provenance and licence for each
source in `training/DATASETS.md` — the panel will ask where the data came from.

Class imbalance is the norm here. Use stratified splits, report per-class metrics, and prefer
class weights over naive oversampling. Never resample before splitting — that leaks.

## Training discipline

- Split **once**, save the split indices, commit them. `train/val/test = 70/15/15`, stratified.
- The test set is frozen. Never train on it, never tune on it, look at it once per model
  version. The number you report in Chapter 4 comes from here.
- Set and log seeds (`random`, `numpy`, `torch`).
- Every run writes `models/<name>-v<major.minor.patch>/` containing the artefact,
  `metrics.json`, `feature_list.json`, and the config used. Bump the version on any retrain.
- `training/train_*.py` must be runnable end-to-end from a clean checkout. Notebooks are for
  exploration; the script is the source of truth.

## Evaluation

Report accuracy, precision, recall, F1, the confusion matrix, and a PR curve. For this project
**recall on the scam class matters more than accuracy** — a missed scam costs a user money, a
false positive costs them ten seconds of doubt. Say so explicitly when you present results, and
pick the decision threshold accordingly rather than defaulting to 0.5.

A pytest asserts F1 ≥ 0.85 on the frozen test set and fails the build below that. Never adjust
that threshold downward to make a build pass — fix the model or report the real number.

## Explainability

The `indicators` array is what makes the UI trustworthy, so it can't be decorative.
- Random Forest: use feature importances plus the actual feature values for that URL.
- DistilBERT: attention or SHAP is expensive at request time; a pragmatic approach is a curated
  keyword/pattern layer (urgency, prize claims, credential requests, payment demands) run
  alongside the model, contributing evidence strings while the model contributes the score.
  Document this honestly as a hybrid — do not present pattern matches as model interpretability.

## Never

- Fabricate metrics, or report numbers from a run you didn't execute.
- Commit model binaries or datasets over 50 MB.
- Retrain automatically on unmoderated user feedback (see the poisoning rule in AGENTS.md §3).
