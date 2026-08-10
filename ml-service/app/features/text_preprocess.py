"""Text normalisation for the message classifier.

Shared by training and serving, for the same reason `url_features` is: two
copies of preprocessing drift, and the model then sees differently-normalised
text at serving time than it was trained on.

## Why URLs and numbers are replaced rather than kept

The `ml-endpoint` skill specifies substituting `[URL]` and `[NUM]`. The reason
is division of labour: ScamSense already has a dedicated URL classifier that
analyses links properly, from 22 lexical features. If the message model could
also see raw URLs it would memorise specific domains from the training corpus —
domains that are dead within weeks, since phishing infrastructure is disposable.
Replacing them forces the message model to learn the *language* of a scam, which
is the durable signal and the thing the URL model cannot see.

The same argument applies to amounts and reference numbers. "RM5,000" and
"RM3,917" are the same rhetorical move; a model that treats them as distinct
tokens learns the corpus rather than the pattern.

## A second use

Normalising to `[URL]` and `[NUM]` also collapses a templated message to its
skeleton, which makes it possible to detect when two "different" messages are
the same template with different slot fillers. `template_key` exposes that,
and the training script uses it to group the split — the message-corpus
equivalent of grouping URLs by registrable domain.
"""

from __future__ import annotations

import re
import unicodedata

# S105 flags these as possible hardcoded credentials. They are substitution
# placeholders written into normalised text, not secrets.
URL_TOKEN = "[URL]"  # noqa: S105
NUM_TOKEN = "[NUM]"  # noqa: S105

_URL_RE = re.compile(r"(?:https?://|www\.)\S+", re.IGNORECASE)
_EMAIL_RE = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")
_NUMBER_RE = re.compile(r"\d[\d,.]*")
_WHITESPACE_RE = re.compile(r"\s+")
_PUNCT_RE = re.compile(r"[^\w\s\[\]]")


def normalise(text: str, *, lowercase: bool = False) -> str:
    """Canonicalise a message for classification.

    Unicode is normalised to NFKC first, which folds the full-width and
    look-alike characters that scam messages use to evade naive keyword filters.

    `lowercase` is off by default because the project's DistilBERT checkpoint is
    `distilbert-base-multilingual-cased`, and lowercasing input to a cased
    tokenizer discards information it was trained to use. The TF-IDF baseline
    sets it to True, matching its own vectoriser.
    """
    if not text:
        return ""

    result = unicodedata.normalize("NFKC", str(text))
    result = _URL_RE.sub(f" {URL_TOKEN} ", result)
    # After URL substitution, so an address inside a URL is not matched twice.
    result = _EMAIL_RE.sub(f" {URL_TOKEN} ", result)
    result = _NUMBER_RE.sub(f" {NUM_TOKEN} ", result)
    result = _WHITESPACE_RE.sub(" ", result).strip()

    return result.lower() if lowercase else result


def normalise_lower(text: str) -> str:
    """`normalise` with lowercasing, as a named module-level function.

    Exists because scikit-learn pickles the vectoriser's `preprocessor` by
    reference. A lambda cannot be pickled at all, and a local function is
    unresolvable on load — the artefact would train fine and then fail when the
    Flask service tried to load it, which is the worst place to discover it.
    """
    return normalise(text, lowercase=True)


def template_key(text: str) -> str:
    """Collapse a message to the template it was generated from.

    Strips punctuation as well as URLs and numbers, so that two messages
    differing only in slot fillers produce the same key. Used to group the
    train/validation/test split so that no template appears in more than one
    partition.

    Without this, a templated corpus reports near-perfect scores that measure
    template recognition rather than language understanding.
    """
    result = normalise(text, lowercase=True)
    result = _PUNCT_RE.sub(" ", result)

    return _WHITESPACE_RE.sub(" ", result).strip()
