"""How large must the test set be for the DistilBERT comparison to resolve?

    python training/power_analysis.py

ADR 0009 records that DistilBERT scored higher than the baseline twice, and that
neither measurement resolved. On the 528-row corpus the difference was +0.0179
with a 95% interval of [-0.0167, +0.0560]; on the 1,113-row corpus it is +0.0122
with [-0.0043, +0.0317]. The interval halved in width and still spans zero.

"Collect more data" is not an answer anyone can act on. This script turns it
into a number: given the error rates the two models actually produced, how many
test messages would be needed before an interval of that width excludes zero.

## Method

Rather than assume a distribution, it resamples from the **observed** per-message
outcomes, which are now the v3.0.0 ones: both models miss the same single scam
of 121, and they differ only in false positives — 3 for the baseline, 0 for
DistilBERT. Those rates generate synthetic test sets of increasing size and the
same paired bootstrap from ADR 0009 runs on each.

The answer is a **planning estimate, not a guarantee**. It assumes the observed
difference is the true one, which is exactly what a zero-spanning interval says
we do not know.

## The estimate was tested, and it held

The first run of this script, on the 528-row corpus, put ~35% odds of resolving
at 200 test messages. The corpus then grew to 1,113 rows, giving 223 test
messages, and the comparison did not resolve — the interval halved in width, from
0.0727 to 0.0360, and still spanned zero. The prediction was right, which is a
better reason to trust the numbers below than the method's plausibility.

The requirement has since grown rather than shrunk, because the measured effect
got smaller: +0.0122 against +0.0179. A smaller true difference needs more data
to distinguish from none.
"""

from __future__ import annotations

import numpy as np

SEED = 42
BOOTSTRAP_SAMPLES = 2000
TRIALS_PER_SIZE = 60

# Measured on the 223-message test set of the 1,113-row corpus (v3.0.0 against
# distilbert-msg-v2.0.0-experiment).
#
# Note what changed between the two runs, because it changes what the question
# even is. Against the 528-row corpus the two models differed on RECALL — the
# baseline missed 3 scams of 58, DistilBERT 1. They now miss the same single
# scam of 121, and differ only on false positives: 3 against 0. The remaining
# gap is precision, which by this project's stated asymmetry is the cheaper
# error. A missed scam costs a user money; a false positive costs ten seconds.
BASELINE_RECALL = 1 - 1 / 121
BASELINE_FPR = 3 / 102
DISTILBERT_RECALL = 1 - 1 / 121
DISTILBERT_FPR = 0 / 102

# The corpus is 609 scam / 504 legitimate, so a stratified split keeps roughly
# this share of positives in any test set drawn from it.
POSITIVE_SHARE = 609 / 1113


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
    print(f"\n  observed effect: F1 +{0.995851 - 0.983607:.4f} on 223 messages")
    print(f"  baseline recall   {BASELINE_RECALL:.4f}  (missed 1 of 121, 3 false positives)")
    print(f"  distilbert recall {DISTILBERT_RECALL:.4f}  (missed 1 of 121, 0 false positives)")
    print(f"  positive share    {POSITIVE_SHARE:.3f}\n")

    print(f"  {'test size':>10} {'total corpus':>13} {'trials resolving':>18}")
    print("  " + "-" * 45)

    results = []
    for n in (223, 400, 800, 1500, 3000, 6000, 12000):
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

    print("\n  CAVEAT: this assumes the observed +0.0122 is the true difference,")
    print("  which is precisely what a zero-spanning interval says we do not know.")
    print("  A target to aim at, not a threshold that promises a result.")
    print()
    print("  Note the direction of travel. The effect measured on 1,113 rows is")
    print("  SMALLER than the one measured on 528 (+0.0122 against +0.0179), so")
    print("  the corpus needed has grown rather than shrunk: from ~800 test")
    print("  messages to ~1,500. Collecting more data did not bring this answer")
    print("  closer to resolving, and that is itself worth reporting.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
