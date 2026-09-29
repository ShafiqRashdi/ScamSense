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

## Design & UI skills

Installed from [emilkowalski/skills](https://github.com/emilkowalski/skills) and the
`web-design-engineer` pack. These cover the Blade + Bootstrap + Sass front end. The
React Native, Expo and Swift skills from those packs were left out — ScamSense has no
mobile-native codebase.

| Skill | Loads when | Purpose |
|-------|-----------|---------|
| `web-design-engineer` | Building or redesigning any page, dashboard, prototype or data visualisation | The broadest one: layout, type scale, colour, design critique, browser QA. Ships 30+ style recipes under `references/style-recipes/` |
| `apple-design` | Gesture-driven UI, spring motion, translucent materials, typography detail | Apple's HIG principles translated for the web |
| `emil-design-eng` | General UI polish and component design decisions | The invisible details — borders vs shadows, spacing rhythm, state transitions |
| `mobile-native` | Anything that will be used on a phone | Sticky hover, tap-highlight flashes, the 100vh bug, inputs that zoom, safe areas. Directly relevant given ScamSense's audience |
| `animate` | Asked to animate something or build a transition | Picks the curve, duration and properties before writing the implementation |
| `animation-vocabulary` | You can describe a motion effect but don't know its name | Reverse-lookup glossary, so you can prompt precisely |
| `find-animation-opportunities` | "What could be animated here?" | Read-only; proposes motion with exact values, and rejects what shouldn't move |
| `improve-animations` | Auditing motion across the whole front end | Produces a prioritised plan for another agent to execute |

Three of these only run when you ask for them by name (`disable-model-invocation: true`):

| Skill | Ask for it when |
|-------|-----------------|
| `pick-ui-library` | You need a library for charts, OTP inputs, command menus, drag-and-drop and want a curated pick rather than whatever the model recalls |
| `prototype` | You want several genuinely different versions of one UI piece behind a live switcher |
| `review-animations` | You want existing motion critiqued strictly — it defaults to flagging, approval is earned |

### From ECC

Installed from [affaan-m/ECC](https://github.com/affaan-m/ECC). Only the framework-neutral
design skills were taken. The React/Next.js ones (`frontend-a11y`, `motion-*`,
`frontend-patterns`), the iOS `liquid-glass-design`, and the video "taste" skills were
left out — they don't apply to a Blade + Bootstrap front end.

| Skill | Loads when | Purpose |
|-------|-----------|---------|
| `frontend-design-direction` | Starting or reworking a page and it needs a clear visual direction | Pins down purpose, audience, tone and one memorable detail before coding; lists generic AI-UI anti-patterns to avoid |
| `design-system` | Auditing visual consistency or reviewing a styling change | Pulls colours, type, spacing and radii into tokens; scores the UI on 10 dimensions; includes an "AI slop" check |
| `accessibility` | Building or auditing UI against WCAG 2.2 AA | Contrast, target size, focus visibility, error messages, live regions. Especially important for ScamSense's older, less tech-confident users |
| `make-interfaces-feel-better` | The UI feels off, cramped or unfinished | Concrete polish rules (concentric radius, text wrapping, tabular numbers, hit areas, transition scope) with before/after review output. Overlaps with `emil-design-eng`; this one is the shorter checklist |
| `browser-qa` | Checking a deployed or local page in a real browser | Smoke test, interactions, screenshots at 375/768/1440px, and an axe pass. Read-only by default; needs a browser MCP |

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
