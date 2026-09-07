"""The TF-IDF message classifier, loaded once at startup.

Serves `tfidf-msg-v3.0.0-collected-my`: character n-grams (char_wb 2–5) into a
Logistic Regression, trained on 1,113 collected Malaysian SMS/WhatsApp messages.
Test F1 0.9836 on the split as shipped, 0.9823 on the leak-free subset — see
docs/RESULTS.md for which figure is canonical and the qualifier each must be
quoted with.

## Why this and not DistilBERT

DistilBERT was trained and measured as a documented comparison (ADR 0009), on
both the 528-row and the 1,113-row corpus. On the current one it scored F1
0.9959 against this model's 0.9836, a difference of +0.0122 whose 95% paired
bootstrap interval is [-0.0043, +0.0317] — still spanning zero, so the corpus
still cannot resolve it. Doubling the corpus halved the interval width and did
not change the answer.

Note where the remaining difference sits: both models miss the same single scam
of 121, and DistilBERT's whole advantage is three fewer false positives. Under
this project's stated asymmetry a missed scam costs a user money and a false
positive costs ten seconds, so the surviving advantage is in the cheaper error.
TF-IDF ships because a 250 MB transformer that has not been shown to be better
is not worth the cold start.

## The preprocessor travels inside the artefact

`normalise_lower` is pickled by reference into the vectoriser, so loading the
artefact imports it from `app.features.text_preprocess`. That is the same
function training used, which is precisely the point: two copies of the
normalisation would drift and the model would see differently-shaped text at
serving time than it was fitted on.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

import joblib

from app.indicators import build_message_indicators
from app.predictors.prediction import Prediction

#: The artefact must be able to resolve `normalise_lower` on unpickle. Importing
#: it here makes that dependency explicit rather than incidental — a reader
#: removing the "unused" import would produce a service that fails at load.
from app.features.text_preprocess import normalise_lower  # noqa: F401  isort:skip


class MessageClassifier:
    """Wraps the trained artefact and its metadata."""

    def __init__(self, model_dir: Path) -> None:
        self.model_dir = model_dir
        self.version = model_dir.name

        metrics = json.loads((model_dir / "metrics.json").read_text(encoding="utf-8"))

        # The threshold chosen on the validation set during training. Serving
        # must use the same one — defaulting to 0.5 would put the deployed
        # system at an operating point that was never evaluated, and this model
        # was tuned to favour recall on the scam class.
        self.threshold: float = float(metrics["test"]["threshold"])

        #: Carried so the dashboard and any screenshot can quote the figure with
        #: the sentence it has to be quoted with.
        self.reporting_qualifier: str = metrics.get("REPORTING_QUALIFIER", "")

        self.model = joblib.load(model_dir / "model.joblib")

    def predict(self, text: str) -> Prediction:
        started = time.perf_counter()

        # The pipeline applies its own preprocessing, so raw text goes in. Doing
        # it here as well would double-normalise and shift the feature
        # distribution away from training.
        probability = float(self.model.predict_proba([text])[0][1])
        label = "scam" if probability >= self.threshold else "legitimate"

        # Confidence is reported for the label actually returned, matching the
        # URL classifier: a "legitimate" verdict at probability 0.02 reads as
        # 0.98 confident rather than 0.02, which a user would read as the
        # opposite of what it means.
        confidence = probability if label == "scam" else 1.0 - probability

        return Prediction(
            label=label,
            confidence=round(confidence, 4),
            indicators=build_message_indicators(text),
            model_version=self.version,
            inference_ms=int((time.perf_counter() - started) * 1000),
        )
