"""How large must the test set be for the DistilBERT comparison to resolve?

    python training/power_analysis.py

ADR 0009 records that DistilBERT scored F1 0.9913 against the baseline's 0.9735,
a difference of +0.0179 whose 95% bootstrap interval was [-0.0167, +0.0560] —
spanning zero, so the corpus could not resolve it.

"Collect more data" is not an answer anyone can act on. This script turns it
into a number: given the error rates the two models actually produced, how many
test messages would be needed before an interval of that width excludes zero.

## Method

Rather than assume a distribution, it resamples from the **observed** per-message
outcomes. The baseline missed 3 scams of 58 and produced 0 false positives;
DistilBERT missed 1 of 58 with 0 false positives. Those rates are used to
generate synthetic test sets of increasing size, drawing outcomes independently
for each model at its measured rate, and the same paired bootstrap from ADR 0009
is run on each.

The answer this produces is a **planning estimate, not a guarantee**. It assumes
the effect size observed on 106 messages is the true one, which is exactly the
thing a wide interval says we do not know. If the real difference is smaller
than +0.0179, the requirement grows; if larger, it shrinks. It is a target to
aim at rather than a threshold that promises significance.
"""

from __future__ import annotations

import numpy as np

SEED = 42
BOOTSTRAP_SAMPLES = 2000
TRIALS_PER_SIZE = 60

# Measured on the 106-message test set (docs/adr/0009).
BASELINE_RECALL = 1 - 3 / 58
BASELINE_FPR = 0 / 48
DISTILBERT_RECALL = 1 - 1 / 58
DISTILBERT_FPR = 0 / 48

# The corpus is 290 scam / 238 legitimate, so a stratified split keeps roughly
# this share of positives in any test set drawn from it.
POSITIVE_SHARE = 290 / 528


def simulate_outcomes(
    n: int, recall: float, fpr: float, y: np.ndarray, rng: np.random.Generator
) -> np.ndarray:
    """Predictions for one model, drawn at its measured error rates."""
    predictions = np.zeros(n, dtype=int)
    positives = y == 1
    negatives = ~positives

    predictions[positives] = rng.random(positives.sum()) < recall
    predictions[negatives] = rng.random(negatives.sum()) < fpr

    return predictions


def bootstrap_f1(y: np.ndarray, pred: np.ndarray, idx: np.ndarray) -> np.ndarray:
    """F1 for every resample at once.

    `idx` is (B, n) — one row of row-indices per bootstrap resample. Counting
    true/false positives and negatives with sums over axis 1 computes all B
    scores in one pass, which is what makes sweeping several corpus sizes
    tractable. sklearn's f1_score called B times per trial is the same
    arithmetic with per-call overhead that dominates at this scale.
    """
    yt = y[idx]
    yp = pred[idx]

    tp = np.sum((yt == 1) & (yp == 1), axis=1)
    fp = np.sum((yt == 0) & (yp == 1), axis=1)
    fn = np.sum((yt == 1) & (yp == 0), axis=1)

    denominator = 2 * tp + fp + fn
    # A resample with no positives and no predicted positives scores 0, which
    # matches sklearn's zero_division=0.
    return np.where(denominator == 0, 0.0, 2 * tp / np.maximum(denominator, 1))


def interval_excludes_zero(n: int, rng: np.random.Generator) -> bool:
    """Run one trial: build a test set of size n, bootstrap the F1 difference."""
    n_pos = max(2, int(round(n * POSITIVE_SHARE)))
    y = np.zeros(n, dtype=int)
    y[:n_pos] = 1
    rng.shuffle(y)

    baseline = simulate_outcomes(n, BASELINE_RECALL, BASELINE_FPR, y, rng)
    distilbert = simulate_outcomes(n, DISTILBERT_RECALL, DISTILBERT_FPR, y, rng)

    # Paired: both models are scored on the SAME resampled rows, so the
    # difference cancels the shared sampling noise. That pairing is why the
    # interval is narrower than differencing two independent intervals.
    #
    # Chunked because the index matrix is B x n; at the largest size swept that
    # is tens of millions of entries, and materialising it whole is needless
    # memory pressure for arithmetic that batches perfectly well.
    chunk = max(1, 500_000 // max(n, 1))
    differences = []
    remaining = BOOTSTRAP_SAMPLES

    while remaining > 0:
        b = min(chunk, remaining)
        idx = rng.integers(0, n, size=(b, n), dtype=np.int32)
        differences.append(bootstrap_f1(y, distilbert, idx) - bootstrap_f1(y, baseline, idx))
        remaining -= b

    return bool(np.percentile(np.concatenate(differences), 2.5) > 0)


def main() -> int:
    rng = np.random.default_rng(SEED)

    print("=" * 68)
    print("HOW BIG A TEST SET BEFORE THE DISTILBERT DIFFERENCE RESOLVES?")
    print("=" * 68)
    print(f"\n  observed effect: F1 +{0.9913 - 0.9735:.4f} on 106 messages")
    print(f"  baseline recall   {BASELINE_RECALL:.4f}  (missed 3 of 58)")
    print(f"  distilbert recall {DISTILBERT_RECALL:.4f}  (missed 1 of 58)")
    print(f"  positive share    {POSITIVE_SHARE:.3f}\n")

    print(f"  {'test size':>10} {'total corpus':>13} {'trials resolving':>18}")
    print("  " + "-" * 45)

    results = []
    for n in (106, 200, 400, 800, 1500, 3000, 6000):
        wins = sum(interval_excludes_zero(n, rng) for _ in range(TRIALS_PER_SIZE))
        share = wins / TRIALS_PER_SIZE
        results.append((n, share))
        # Test is ~20% of a 70/15/15 split, so the corpus is roughly 5x the
        # test set.
        print(f"  {n:>10,} {n * 5:>13,} {share:>17.0%}")

    print("\n  'resolving' means the 95% bootstrap interval on the F1 difference")
    print("  excludes zero — the same test ADR 0009 applied and failed.\n")

    adequate = [n for n, share in results if share >= 0.80]
    if adequate:
        target = adequate[0]
        print(f"  => around {target:,} test messages ({target * 5:,} collected in total)")
        print("     gives an ~80% chance of resolving a difference this size.")
    else:
        print("  => no size tested reached 80%. The effect is small enough that")
        print("     the corpus needed is larger than anything sampled here.")

    print("\n  CAVEAT: this assumes +0.0179 is the true difference, which is")
    print("  precisely what a zero-spanning interval says we do not know. Treat")
    print("  it as a target to aim at, not a threshold that promises a result.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
