# SKILLS.md — ScamSense

Index of the agent skills in this repository. Each skill is a folder under `.claude/skills/`
containing a `SKILL.md` with YAML frontmatter. Claude Code discovers them automatically and
loads one when its `description` matches what you're doing — you don't invoke them manually,
though you can nudge with "use the security-review skill on this".

Keep each skill under ~200 lines. A skill that tries to cover everything gets loaded for
everything, which defeats the purpose.

| Skill | Loads when | Purpose |
|-------|-----------|---------|
| `laravel-feature` | Building any Laravel route, controller, service, model, migration or Blade view | Enforces the thin-controller / service-layer / DTO pattern and the validation-and-escaping rules |
| `ml-endpoint` | Working in `ml-service/`, training a model, or changing the prediction contract | Feature engineering, training reproducibility, honest evaluation, versioned artefacts |
| `security-review` | Before merging anything touching user input, auth, external APIs, storage or deployment | Structured OWASP-style pass tailored to this project's threat model |
| `fyp-documentation` | Writing report chapters, ADRs, diagrams, or the presentation | Academic formatting, APA 7, and Shafiq's writing preferences |

## Adding a new skill

```
.claude/skills/<kebab-case-name>/SKILL.md
```

```markdown
---
name: skill-name
description: Use when <specific trigger>. Covers <what it does>.
---

# Skill Name
...
```

Write the `description` for the trigger, not the content. "Use when writing Laravel
controllers, services, or migrations" beats "Laravel best practices" — the first tells the
agent when to load it, the second doesn't.
