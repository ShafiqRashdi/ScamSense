"""The shape every predictor returns.

Shared by the URL and message classifiers so the two cannot drift apart. The
route layer maps this onto `PredictionResponse`, which is the wire contract in
AGENTS.md §4; keeping them as separate types means a change to the internal
shape does not silently become a breaking API change.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class Prediction:
    label: str
    confidence: float
    indicators: list[dict[str, object]]
    model_version: str
    inference_ms: int
