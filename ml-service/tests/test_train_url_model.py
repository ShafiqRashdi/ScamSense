"""Plumbing tests for the URL training script.

**These tests say nothing whatsoever about model quality.** They run the
pipeline over a tiny synthetic fixture to prove it executes end to end and
writes correctly-shaped artefacts. Any accuracy figure produced here is
meaningless — the fixture is a few dozen hand-written strings, not a dataset.

The real numbers come from running the script against a real corpus, and until
that has happened this repository contains no URL model metrics at all.

What these tests DO protect is the discipline the script is supposed to enforce:
that the split is stratified and disjoint, that duplicate URLs cannot straddle
train and test, and that the threshold is chosen on validation rather than test.
Those are the properties that make a later, real number trustworthy.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np
import pandas as pd
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from training.train_url_model import (  # noqa: E402
    build_feature_matrix,
    choose_threshold,
    evaluate,
    load_dataset,
    make_split,
)


@pytest.fixture
def synthetic_csv(tmp_path: Path) -> Path:
    """A deliberately trivial, obviously-fake dataset.

    Structured so the two classes are separable, purely so the pipeline has
    something to chew on. It is not representative of anything.
    """
    phishing = [
        f"http://maybank2u-verify{i}.xyz/login/update-account?ref={i}" for i in range(30)
    ] + [f"http://192.168.1.{i}/cimb/secure/login.php" for i in range(1, 21)]

    legitimate = [f"https://www.maybank2u.com.my/home/page{i}" for i in range(25)] + [
        f"https://www.hasil.gov.my/en/section{i}/" for i in range(25)
    ]

    frame = pd.DataFrame(
        {
            "url": phishing + legitimate,
            "label": [1] * len(phishing) + [0] * len(legitimate),
        }
    )

    path = tmp_path / "synthetic.csv"
    frame.to_csv(path, index=False)
    return path


# ── Dataset loading ────────────────────────────────────────────────────────


def test_missing_dataset_exits_with_guidance_rather_than_inventing_data(
    tmp_path: Path,
) -> None:
    with pytest.raises(SystemExit) as excinfo:
        load_dataset(tmp_path / "does-not-exist.csv")

    assert "does not generate data" in str(excinfo.value)


def test_duplicate_urls_are_dropped_before_splitting(tmp_path: Path) -> None:
    """The single most common way a URL classifier reports a score it has not
    earned: the same URL appearing in both train and test."""
    path = tmp_path / "dupes.csv"
    pd.DataFrame(
        {
            "url": ["http://a.xyz/1", "http://a.xyz/1", "https://b.com.my", "https://b.com.my"],
            "label": [1, 1, 0, 0],
        }
    ).to_csv(path, index=False)

    assert len(load_dataset(path)) == 2


def test_a_missing_column_is_rejected(tmp_path: Path) -> None:
    path = tmp_path / "bad.csv"
    pd.DataFrame({"url": ["http://a.xyz"]}).to_csv(path, index=False)

    with pytest.raises(SystemExit, match="missing required column"):
        load_dataset(path)


def test_a_single_class_dataset_is_rejected(tmp_path: Path) -> None:
    path = tmp_path / "one-class.csv"
    pd.DataFrame({"url": ["http://a.xyz", "http://b.xyz"], "label": [1, 1]}).to_csv(
        path, index=False
    )

    with pytest.raises(SystemExit, match="only one class"):
        load_dataset(path)


def test_non_binary_labels_are_rejected(tmp_path: Path) -> None:
    path = tmp_path / "bad-labels.csv"
    pd.DataFrame({"url": ["http://a.xyz", "http://b.xyz"], "label": [1, 7]}).to_csv(
        path, index=False
    )

    with pytest.raises(SystemExit, match="Labels must be"):
        load_dataset(path)


# ── The split ──────────────────────────────────────────────────────────────


def test_the_split_partitions_are_disjoint(synthetic_csv: Path) -> None:
    """If these overlap, every reported metric is inflated."""
    frame = load_dataset(synthetic_csv)
    split = make_split(frame["label"].to_numpy())

    assert not set(split.train) & set(split.val)
    assert not set(split.train) & set(split.test)
    assert not set(split.val) & set(split.test)


def test_the_split_covers_every_row_exactly_once(synthetic_csv: Path) -> None:
    frame = load_dataset(synthetic_csv)
    split = make_split(frame["label"].to_numpy())

    assert sorted(split.train + split.val + split.test) == list(range(len(frame)))


def test_the_split_is_roughly_seventy_fifteen_fifteen(synthetic_csv: Path) -> None:
    frame = load_dataset(synthetic_csv)
    total = len(frame)
    split = make_split(frame["label"].to_numpy())

    assert len(split.train) / total == pytest.approx(0.70, abs=0.03)
    assert len(split.val) / total == pytest.approx(0.15, abs=0.03)
    assert len(split.test) / total == pytest.approx(0.15, abs=0.03)


def test_the_split_is_stratified(synthetic_csv: Path) -> None:
    frame = load_dataset(synthetic_csv)
    labels = frame["label"].to_numpy()
    split = make_split(labels)

    overall = labels.mean()
    for partition in (split.train, split.val, split.test):
        assert labels[partition].mean() == pytest.approx(overall, abs=0.06)


def test_the_split_is_reproducible_from_the_seed(synthetic_csv: Path) -> None:
    """The committed split_indices.json is only meaningful if the same seed
    reproduces the same partition."""
    labels = load_dataset(synthetic_csv)["label"].to_numpy()

    assert make_split(labels, seed=42) == make_split(labels, seed=42)
    assert make_split(labels, seed=42) != make_split(labels, seed=7)


# ── Feature matrix ─────────────────────────────────────────────────────────


def test_the_feature_matrix_uses_the_shared_extractor(synthetic_csv: Path) -> None:
    from app.features.url_features import FEATURE_NAMES

    frame = load_dataset(synthetic_csv)
    matrix = build_feature_matrix(frame["url"])

    assert matrix.shape == (len(frame), len(FEATURE_NAMES))
    assert matrix.dtype == np.float64
    assert np.isfinite(matrix).all()


# ── Threshold selection and metrics ────────────────────────────────────────


def test_the_threshold_is_a_probability() -> None:
    y = np.array([0, 0, 1, 1, 0, 1])
    probabilities = np.array([0.1, 0.2, 0.8, 0.9, 0.3, 0.7])

    assert 0.0 <= choose_threshold(y, probabilities) <= 1.0


def test_the_metrics_payload_has_every_field_chapter_four_needs() -> None:
    y = np.array([0, 0, 1, 1])
    probabilities = np.array([0.1, 0.2, 0.8, 0.9])

    metrics = evaluate(y, probabilities, 0.5)

    for key in (
        "accuracy",
        "f1_phishing",
        "precision_phishing",
        "recall_phishing",
        "roc_auc",
        "average_precision",
        "confusion_matrix",
        "support",
        "threshold",
    ):
        assert key in metrics, key

    assert set(metrics["confusion_matrix"]) == {
        "true_negative",
        "false_positive",
        "false_negative",
        "true_positive",
    }


def test_the_confusion_matrix_counts_are_correct() -> None:
    y = np.array([0, 0, 1, 1])
    probabilities = np.array([0.1, 0.9, 0.2, 0.8])  # one FP, one FN

    cm = evaluate(y, probabilities, 0.5)["confusion_matrix"]

    assert cm == {
        "true_negative": 1,
        "false_positive": 1,
        "false_negative": 1,
        "true_positive": 1,
    }


# ── End to end ─────────────────────────────────────────────────────────────


def test_the_script_runs_end_to_end_and_writes_every_artefact(
    synthetic_csv: Path, tmp_path: Path
) -> None:
    """Proves the plumbing works so that a real run later fails for real
    reasons. Says NOTHING about model quality — see the module docstring.
    """
    import subprocess

    out = tmp_path / "models"
    # S603: the command is this interpreter and a path resolved from __file__.
    # No part of it comes from user input, and running the script as a real
    # subprocess is the point — importing main() would not catch an argument
    # parsing or __main__ guard mistake.
    result = subprocess.run(  # noqa: S603
        [
            sys.executable,
            str(Path(__file__).resolve().parent.parent / "training" / "train_url_model.py"),
            "--data",
            str(synthetic_csv),
            "--out",
            str(out),
            "--version",
            "0.0.0-smoketest",
            "--n-estimators",
            "20",
        ],
        capture_output=True,
        text=True,
        check=False,
    )

    artefact = out / "rf-url-v0.0.0-smoketest"
    assert artefact.is_dir(), result.stdout + result.stderr

    for filename in (
        "model.joblib",
        "metrics.json",
        "feature_list.json",
        "split_indices.json",
        "config.json",
    ):
        assert (artefact / filename).exists(), filename

    metrics = json.loads((artefact / "metrics.json").read_text(encoding="utf-8"))
    assert "test" in metrics and "validation" in metrics
    assert "feature_importances" in metrics

    # The threshold reported for the test set must be the one chosen on
    # validation — not re-optimised against test.
    assert metrics["test"]["threshold"] == metrics["validation"]["threshold"]

    config = json.loads((artefact / "config.json").read_text(encoding="utf-8"))
    assert config["seed"] == 42
    assert "scikit_learn" in config
