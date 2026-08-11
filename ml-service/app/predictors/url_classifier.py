"""The Random Forest URL classifier, loaded once at startup.

Loading a model per request would cost far more than the whole 3-second budget
allows, so the artefact is read at application start and held in a module-level
singleton (AGENTS.md §5 and the ml-endpoint skill).

## The feature list is read from the artefact, not from the code

`feature_list.json` records which features the model was trained on, in order.
Serving rebuilds its vector from that file rather than from `FEATURE_NAMES`,
because the shipped model was trained on 22 of the 23 available features —
`uses_https` was ablated out (ADR 0004). Reading the order from code would
silently feed the model a 23-column vector it was never fitted for, and
scikit-learn would either raise or, worse, accept a shifted vector and return
confident nonsense.
"""

from __future__ import annotations

import json
import time
from dataclasses import dataclass
from pathlib import Path

import joblib

from app.features.url_features import extract_features
from app.indicators import build_url_indicators


@dataclass(frozen=True)
class Prediction:
    label: str
    confidence: float
    indicators: list[dict[str, object]]
    model_version: str
    inference_ms: int


class UrlClassifier:
    """Wraps the trained artefact and its metadata."""

    def __init__(self, model_dir: Path) -> None:
        self.model_dir = model_dir
        self.version = model_dir.name

        feature_list = json.loads((model_dir / "feature_list.json").read_text(encoding="utf-8"))
        self.feature_names: list[str] = feature_list["features"]

        metrics = json.loads((model_dir / "metrics.json").read_text(encoding="utf-8"))
        self.importances: dict[str, float] = metrics.get("feature_importances", {})

        # The threshold chosen on the validation set during training. Serving
        # must use the same one — re-deriving it here, or defaulting to 0.5,
        # would mean the deployed system operates at a point that was never
        # evaluated.
        self.threshold: float = float(metrics["test"]["threshold"])

        self.model = joblib.load(model_dir / "model.joblib")

        expected = getattr(self.model, "n_features_in_", len(self.feature_names))
        if expected != len(self.feature_names):
            raise ValueError(
                f"{model_dir.name}: model expects {expected} features but "
                f"feature_list.json declares {len(self.feature_names)}."
            )

    def predict(self, url: str) -> Prediction:
        started = time.perf_counter()

        features = extract_features(url)
        vector = [[features[name] for name in self.feature_names]]

        probability = float(self.model.predict_proba(vector)[0][1])
        label = "scam" if probability >= self.threshold else "legitimate"

        # Confidence is reported for the label actually returned, so a
        # "legitimate" verdict at probability 0.02 reads as 0.98 confident
        # rather than 0.02 — which a user would read as the opposite.
        confidence = probability if label == "scam" else 1.0 - probability

        return Prediction(
            label=label,
            confidence=round(confidence, 4),
            indicators=build_url_indicators(url, features, self.importances),
            model_version=self.version,
            inference_ms=int((time.perf_counter() - started) * 1000),
        )
