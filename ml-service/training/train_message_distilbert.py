"""Fine-tune DistilBERT on the collected Malaysian corpus, once, for comparison.

    python training/train_message_distilbert.py \
        --data training/data/messages_my_with_metadata.csv \
        --baseline models/tfidf-msg-v2.0.0-collected-my

This is an EXPERIMENT, not a deployment path. The `ml-endpoint` skill requires a
TF-IDF baseline before any transformer, and requires the comparison to be
reported honestly either way — a negative result is a legitimate finding, and
"we tried it and here is the number" is a far better answer than "we didn't".

## The question this script actually answers

Not "which model scores higher" — on 106 test messages almost any difference
falls inside the noise. The question is **whether this corpus can resolve a
difference at all**, so the headline output is a bootstrap confidence interval
on the F1 *difference* between the two models on the same test set.

If that interval spans zero, the honest conclusion is that DistilBERT has not
been shown to beat the baseline on this corpus, whichever number is larger.
Reporting the point estimate alone would be exactly the overclaim this project
has been careful to avoid.

## Why the comparison is fair

Same corpus, same splits (taken from the corpus, never recomputed), same
threshold-selection procedure on validation, same test set opened once. The only
thing that differs is the model.
"""

from __future__ import annotations

import argparse
import json
import platform
import random
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.features.text_preprocess import normalise  # noqa: E402
from training.train_message_model import (  # noqa: E402
    build_qualifier,
    choose_threshold,
    describe_corpus,
    evaluate,
    load_messages,
    print_report,
    split_from_column,
)

SEED = 42
MODEL_NAME = "distilbert-base-multilingual-cased"
MAX_LENGTH = 128
BOOTSTRAP_SAMPLES = 5000


def set_seeds(seed: int) -> None:
    import torch

    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)


def bootstrap_f1_difference(
    y_true: np.ndarray,
    probabilities_a: np.ndarray,
    probabilities_b: np.ndarray,
    threshold_a: float,
    threshold_b: float,
    samples: int = BOOTSTRAP_SAMPLES,
    seed: int = SEED,
) -> dict[str, float]:
    """Percentile bootstrap on F1(B) − F1(A) over the same test rows.

    Resampling the *rows*, and scoring both models on each resample, keeps the
    two models paired — which is the point. An unpaired comparison of two
    independent intervals would be far more conservative and would not answer
    the question actually being asked.
    """
    from sklearn.metrics import f1_score

    rng = np.random.default_rng(seed)
    n = len(y_true)
    differences = []

    for _ in range(samples):
        idx = rng.integers(0, n, n)
        if len(np.unique(y_true[idx])) < 2:
            continue

        f1_a = f1_score(y_true[idx], (probabilities_a[idx] >= threshold_a).astype(int), zero_division=0)
        f1_b = f1_score(y_true[idx], (probabilities_b[idx] >= threshold_b).astype(int), zero_division=0)
        differences.append(f1_b - f1_a)

    array = np.array(differences)

    return {
        "mean_difference": float(array.mean()),
        "ci_lower_95": float(np.percentile(array, 2.5)),
        "ci_upper_95": float(np.percentile(array, 97.5)),
        "share_of_resamples_favouring_distilbert": float((array > 0).mean()),
        "resamples": int(len(array)),
    }


def train_distilbert(
    texts_train: list[str],
    y_train: np.ndarray,
    texts_val: list[str],
    y_val: np.ndarray,
    epochs: int,
    seed: int,
) -> Any:
    """Fine-tune, keeping the checkpoint with the best validation F1.

    Deliberately plain: no learning-rate search, no layer freezing, no
    augmentation. A heavily tuned transformer compared against an untuned
    baseline would not be a fair comparison, and with 342 training examples the
    tuning would be fitting the validation set rather than the task.
    """
    import torch
    from sklearn.metrics import f1_score
    from torch.utils.data import DataLoader, TensorDataset
    from transformers import AutoModelForSequenceClassification, AutoTokenizer

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
    model = AutoModelForSequenceClassification.from_pretrained(MODEL_NAME, num_labels=2)

    def encode(texts: list[str]) -> tuple[torch.Tensor, torch.Tensor]:
        enc = tokenizer(
            [normalise(t) for t in texts],
            truncation=True,
            padding="max_length",
            max_length=MAX_LENGTH,
            return_tensors="pt",
        )
        return enc["input_ids"], enc["attention_mask"]

    ids_tr, mask_tr = encode(texts_train)
    ids_val, mask_val = encode(texts_val)

    loader = DataLoader(
        TensorDataset(ids_tr, mask_tr, torch.tensor(y_train, dtype=torch.long)),
        batch_size=16,
        shuffle=True,
        generator=torch.Generator().manual_seed(seed),
    )

    # Class weights rather than resampling: legitimate is the smaller class at
    # 238 against 290, and duplicating rows in a 342-row training set would be
    # asking a transformer to memorise them.
    counts = np.bincount(y_train, minlength=2)
    weights = torch.tensor((counts.sum() / (2.0 * counts)), dtype=torch.float)
    loss_fn = torch.nn.CrossEntropyLoss(weight=weights)
    optimiser = torch.optim.AdamW(model.parameters(), lr=2e-5)

    best_f1, best_state = -1.0, None

    for epoch in range(1, epochs + 1):
        model.train()
        total = 0.0
        for ids, mask, labels in loader:
            optimiser.zero_grad()
            logits = model(input_ids=ids, attention_mask=mask).logits
            loss = loss_fn(logits, labels)
            loss.backward()
            optimiser.step()
            total += float(loss)

        model.eval()
        with torch.no_grad():
            val_logits = model(input_ids=ids_val, attention_mask=mask_val).logits
        val_pred = val_logits.argmax(dim=1).numpy()
        f1 = f1_score(y_val, val_pred, zero_division=0)

        print(f"  epoch {epoch}  train loss {total / len(loader):.4f}  val F1 {f1:.4f}")

        if f1 > best_f1:
            best_f1 = f1
            best_state = {k: v.clone() for k, v in model.state_dict().items()}

    if best_state is not None:
        model.load_state_dict(best_state)

    print(f"  keeping the checkpoint with the best validation F1: {best_f1:.4f}")
    return model, tokenizer


def probabilities_for(model: Any, tokenizer: Any, texts: list[str]) -> np.ndarray:
    import torch

    enc = tokenizer(
        [normalise(t) for t in texts],
        truncation=True,
        padding="max_length",
        max_length=MAX_LENGTH,
        return_tensors="pt",
    )
    model.eval()
    with torch.no_grad():
        logits = model(input_ids=enc["input_ids"], attention_mask=enc["attention_mask"]).logits

    return torch.softmax(logits, dim=1)[:, 1].numpy()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--data", type=Path, required=True)
    parser.add_argument("--baseline", type=Path, required=True, help="TF-IDF artefact directory")
    parser.add_argument("--version", default="1.0.0-experiment")
    parser.add_argument("--epochs", type=int, default=4)
    parser.add_argument("--seed", type=int, default=SEED)
    parser.add_argument(
        "--out", type=Path, default=Path(__file__).resolve().parent.parent / "models"
    )
    args = parser.parse_args()

    set_seeds(args.seed)

    frame = load_messages(args.data)
    shape = describe_corpus(frame)
    qualifier = build_qualifier(frame, shape)
    split = split_from_column(frame)

    texts = frame["text"].tolist()
    y = frame["label"].to_numpy()
    templates = frame["template"].to_numpy()

    print(f"corpus  {shape.rows} rows, {shape.templates} templates")
    print(f"split   train {len(split.train)}  val {len(split.val)}  test {len(split.test)}")
    print(f"\nFine-tuning {MODEL_NAME} for {args.epochs} epochs on CPU…")

    model, tokenizer = train_distilbert(
        [texts[i] for i in split.train],
        y[split.train],
        [texts[i] for i in split.val],
        y[split.val],
        args.epochs,
        args.seed,
    )

    val_probabilities = probabilities_for(model, tokenizer, [texts[i] for i in split.val])
    threshold = choose_threshold(y[split.val], val_probabilities)
    val_metrics = evaluate(y[split.val], val_probabilities, threshold)
    print_report("DistilBERT — validation", val_metrics, len(set(templates[split.val])))

    test_probabilities = probabilities_for(model, tokenizer, [texts[i] for i in split.test])
    test_metrics = evaluate(y[split.test], test_probabilities, threshold)
    print_report("DistilBERT — test", test_metrics, len(set(templates[split.test])))

    # ── The comparison ────────────────────────────────────────────────────
    baseline_model = joblib.load(args.baseline / "model.joblib")
    baseline_metrics = json.loads((args.baseline / "metrics.json").read_text(encoding="utf-8"))
    baseline_threshold = float(baseline_metrics["test"]["threshold"])
    baseline_test_probabilities = baseline_model.predict_proba(
        np.array([texts[i] for i in split.test])
    )[:, 1]

    comparison = bootstrap_f1_difference(
        y[split.test],
        baseline_test_probabilities,
        test_probabilities,
        baseline_threshold,
        threshold,
        seed=args.seed,
    )

    baseline_f1 = float(baseline_metrics["test"]["f1_scam"])
    distilbert_f1 = float(test_metrics["f1_scam"])

    print("\n" + "=" * 68)
    print("DOES DISTILBERT EARN ITS COST?")
    print("=" * 68)
    print(f"\n  TF-IDF + LogReg  test F1 {baseline_f1:.4f}")
    print(f"  DistilBERT       test F1 {distilbert_f1:.4f}")
    print(f"  difference               {distilbert_f1 - baseline_f1:+.4f}")
    print(
        f"\n  paired bootstrap, {comparison['resamples']} resamples of the {len(split.test)} test rows:"
    )
    print(
        f"    95% CI on the difference  [{comparison['ci_lower_95']:+.4f}, "
        f"{comparison['ci_upper_95']:+.4f}]"
    )
    print(
        f"    resamples favouring DistilBERT  "
        f"{comparison['share_of_resamples_favouring_distilbert']:.1%}"
    )

    spans_zero = comparison["ci_lower_95"] <= 0 <= comparison["ci_upper_95"]
    if spans_zero:
        print(
            "\n  The interval spans zero. On this corpus, DistilBERT has NOT been\n"
            "  shown to beat the TF-IDF baseline — whichever point estimate is\n"
            "  larger. That is the finding, and it is a legitimate one."
        )
    else:
        direction = "beats" if comparison["ci_lower_95"] > 0 else "loses to"
        print(f"\n  The interval excludes zero: DistilBERT {direction} the baseline here.")

    out_dir = args.out / f"distilbert-msg-v{args.version}"
    out_dir.mkdir(parents=True, exist_ok=True)

    (out_dir / "metrics.json").write_text(
        json.dumps(
            {
                "model": f"distilbert-msg-v{args.version}",
                "base_checkpoint": MODEL_NAME,
                "trained_at": datetime.now(UTC).isoformat(),
                "REPORTING_QUALIFIER": qualifier,
                "STATUS": (
                    "EXPERIMENT ONLY — NOT SHIPPED. Run once to answer whether "
                    "DistilBERT earns its cost on this corpus. The serving model "
                    "is the TF-IDF baseline. See docs/adr/0009."
                ),
                "split_strategy": "given",
                "epochs": args.epochs,
                "max_length": MAX_LENGTH,
                "seed": args.seed,
                "validation": val_metrics,
                "test": test_metrics,
                "comparison_vs_baseline": {
                    "baseline_artefact": args.baseline.name,
                    "baseline_test_f1_scam": baseline_f1,
                    "distilbert_test_f1_scam": distilbert_f1,
                    "difference": distilbert_f1 - baseline_f1,
                    **comparison,
                    "interval_spans_zero": spans_zero,
                },
                "python": platform.python_version(),
            },
            indent=2,
        ),
        encoding="utf-8",
    )

    # The weights are deliberately NOT saved: they are ~250 MB, gitignored, and
    # nothing loads them. metrics.json is the artefact that matters here.
    print(f"\nWrote {out_dir}/metrics.json (weights not saved — this is not a shipping model)")
    print(f"\n  REPORT THIS AS:\n  {qualifier}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
