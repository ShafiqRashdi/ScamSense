---
name: fyp-documentation
description: Use when writing or editing FYP report chapters, ADRs in docs/adr/, system diagrams, the user manual, or presentation slides for ScamSense. Covers UPTM formatting, APA 7th edition, and the required writing style.
---

# FYP Documentation

## House style

- Times New Roman 12pt, double spacing, justified alignment.
- APA 7th edition for every citation and the reference list.
- Humanised, readable prose. Explain the idea, then name the technique — not the reverse.
  Avoid stacked jargon and avoid the padded academic register ("it is worth noting that it can
  be observed that..."). Write the way a competent engineer explains their work to a colleague.
- Client is named explicitly: **Dobi Eta**, a laundry business in Bangi, Selangor.
- Figures and tables numbered by chapter (Figure 3.2, Table 4.1) with captions above tables and
  below figures.
- Use `[FIGURE: description]` as a placeholder marker where a screenshot or diagram is pending,
  so it's greppable later.

## Chapter mapping

| Chapter | Source of truth in this repo |
|---|---|
| 1 Introduction | The project brief; problem statement, objectives O1–O3, scope |
| 2 Literature Review | Comparison of Google Safe Browsing, VirusTotal, ScamShield SG, plus academic sources on phishing detection |
| 3 Methodology | `docs/adr/`, the architecture diagram, Agile sprint log, requirements, use case and flowcharts |
| 4 Results | `models/*/metrics.json`, test suite output, security payload results, UAT feedback |
| 5 Conclusion | Limitations, and the honest gap between what was built and what was scoped |

Chapters 3 and 4 write themselves if the ADRs and `metrics.json` files are kept current. That is
the main reason those artefacts exist.

## ADR format

```markdown
# ADR-004: DistilBERT over full BERT for message classification

Status: Accepted — 2026-08-09

## Context
What problem forced a decision, and the constraints (inference budget, hardware, dataset size).

## Options considered
Each with its trade-off, honestly stated.

## Decision
What was chosen.

## Consequences
What this costs us, and what it makes harder later.
```

One ADR per real decision. "We used Laravel because the brief said so" is not an ADR. "We chose
a lexical-only URL analyser rather than fetching the URL, accepting reduced signal in exchange
for eliminating SSRF exposure" is.

## Reporting results honestly

- Report the metric from the frozen test set, whatever it is. If F1 is 0.81, write 0.81 and
  discuss why. A defended shortfall scores better than an unsupported 0.94.
- State the dataset size, class balance, and provenance next to every metric.
- Name the limitations before the panel does: dataset skew toward English SMS, limited Malay
  scam corpus, no adversarial robustness testing, feedback loop unvalidated at scale.
- Never invent a citation. If a claim needs a source and none is at hand, mark it
  `[CITATION NEEDED]` rather than fabricating a plausible-looking reference — a panel that
  checks one fake reference will distrust the whole list.

## Diagrams needed

Context diagram, system architecture, ML pipeline (data → features → model → verdict), ERD, use
case diagram, sequence diagram for a verification request including the ML-service-down path,
and the UI wireframes. Keep sources in `docs/diagrams/` so they can be regenerated.

## Presentation

Roughly 20 slides: problem and its scale in Malaysia, objectives, related systems and the gap,
architecture, methodology, live demo, results, limitations, future work.

Anticipate these questions and have an answer ready in the appendix:
- Why not just use VirusTotal directly?
- What stops someone poisoning your model through the feedback form?
- What happens when the ML service is down?
- Why DistilBERT rather than a simpler classifier — did you baseline it?
- How do you handle a false positive on a legitimate bank message?
- What personal data do you store, and for how long?
