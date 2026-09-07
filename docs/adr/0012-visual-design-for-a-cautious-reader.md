# ADR 0012 — The interface is designed for an anxious reader, not a browsing one

- **Status:** Accepted, with one open defect recorded below
- **Date:** 2026-09-07
- **Phase:** 6 (public interface)
- **Artefacts:** `backend/resources/css/app.scss`, `backend/resources/views/verify/*`
- **Supersedes nothing.** ADR 0001 chose Bootstrap 5 over Tailwind and chose to compile it from
  Sass source. This ADR records what was done *with* that decision, and why.

---

## Summary

> The visual design is not a style preference. Every choice below follows from one fact about the
> user: **they are already worried when they arrive.** Somebody has just sent them something that
> felt wrong, and they are looking for an answer — often on a mid-range Android phone, often with
> presbyopia, often in a second language.
>
> Three rules fall out of that, and every decision is traceable to one of them:
>
> 1. **The answer must be findable in one glance** — above the fold, at 375px, with the phone's
>    text size turned up.
> 2. **Nothing on the page may compete with a risk signal**, including the brand itself.
> 3. **The verdict must not look like the thing it is warning about.**

Recorded as a decision rather than left in stylesheet comments because Chapter 4 has to answer
"why does it look like this?" with reasoning rather than taste, and because two of the choices
below are actively counter-intuitive and would otherwise read as mistakes.

---

## Context

The brief is unusual in one respect: **the interface has to be trusted by someone whose reason
for visiting is that they have stopped trusting things.** The conventional consumer-web aesthetic
— soft pastels, generous rounding, a friendly accent, a reassuring illustration — is precisely
the register a well-made phishing page uses. Looking approachable and looking legitimate are not
the same goal, and on this product they partly conflict.

The second constraint is the audience: Malaysian general public, low digital literacy assumed,
including older adults. That rules out several defaults that are fine elsewhere — hairline
borders, 16px body text, icon-only controls, colour-coded status with no text label, and
disclosure patterns that hide the thing the user came for.

---

## Decisions

### 1. Warm neutrals, and a brand colour deliberately far from the risk colours

Bootstrap ships a blue-grey neutral ramp. The palette replaces it with warm paper tones
(`$sc-paper #f3ede4`, `$sc-surface #fffdfa`, `$sc-ink #241f1a`) and sets the brand accent to a
calm green, `$sc-brand #2a6152`.

**The green is the load-bearing part, and the reason is rule 2.** If the brand accent sat in the
red-amber family — a warm orange, a confident crimson — then buttons, links, the navbar and the
footer would all be rendered in a hue adjacent to the danger signal. A user who sees that hue
forty times on a page where thirty-nine of them mean nothing learns, correctly, to stop attending
to it. The risk hues have to be the only place those colours appear.

Green is also **not** chosen to mean "safe". The success band uses the same value as the brand
(`$sc-success == $sc-brand`), which is a deliberate collapse: the platform never tells anyone
something is safe, only that it found no warning signs, so a distinct celebratory green would
overclaim. The distinction a user needs is *warning* against *no warning*, and that is carried by
the red and amber being present or absent — not by two shades of green.

### 2. The verdict card is deliberately *less* rounded than the rest of the page

This is the counter-intuitive one. `$border-radius` is a soft `0.75rem` everywhere, and
`.card.border-2` — the class `result.blade.php` puts on the verdict and the verified panel —
pulls it back to `0.5rem` and adds a 6px rule along the top edge.

Softness is right for the form: the person filling it in is anxious and the page should not add
to that. Softness is wrong for the moment of judgement, for two reasons. A verdict has to read as
**asserted rather than suggested** — this is the one place the interface should feel firm. And
rounded, gentle, pastel treatment is the visual language of scam sites themselves; the verdict is
the last element that should borrow it.

### 3. Colour is never the only carrier of the verdict

Each band is signalled three times over: the border colour, a filled badge carrying an icon
**and a text label**, and a heading that states the finding in words. That is WCAG 1.4.1, and it
is also simply necessary — the audience includes people with colour vision deficiency and people
reading cheap screens in bright sunlight.

The 6px top rule is *redundant with* the badge, on purpose. Redundancy is the point.

### 4. 17px base, and a type scale set against the classes the views actually use

`$font-size-base` is `1.0625rem` rather than Bootstrap's `1rem`. A single pixel sounds trivial
and compounds through every derived size, which is the intent: this is read by people with
presbyopia on a phone held at arm's length.

The heading scale is set against `.h3` and `.h4`, **not** against `h1`. `verify/index` renders
its `h1` as `.h3` and `verify/result` renders its as `.h4`, so in practice those two steps are
the page titles. A first pass sized them at 1.2× and 1.1× base, which left each title almost the
same size as the lead paragraph beneath it and the page with no visible hierarchy at all. Sizing
the tags rather than the classes actually in use is a standard way to produce a scale that looks
correct in the variables file and wrong in the browser.

### 5. The verified panel renders first and owns the `h1`

When a submitted address matches the verified-institution registry, that panel is emitted
**before** the verdict card and takes the document's single `h1`; the verdict card steps down to
`h2`.

The original order put it second, and it failed rule 1 concretely: at 375px with enlarged text
its heading fell below the fold, and it sat behind the verdict's generic hedge — *"we did not
find warning signs; this does not guarantee the page is safe."* **A hedge read first is a hedge
read instead.** The strongest, most specific reassurance the platform can produce was arriving
after the weakest, most general one.

The panel's scoping paragraph — what verification does and does not mean — is **not** collapsible
and must stay visible in every layout. Only the teaching section (how a lookalike domain is
built) is progressively disclosed, because four paragraphs is heavy at 375px and that section is
the one a hurried reader can skip without being misled.

### 6. The indicators card renders even when there is nothing to list

Hiding an empty section is the obvious choice, and it is wrong here. An empty indicator list is a
**real finding** — most often in the medium band, where the model reached "not certain" on
continuous features that ADR 0010 documents as deliberately never surfaced. Hiding the card left
the reader with *"parts of this look odd"* and nothing named, which reads as a list that failed
to load: the interface appearing broken at precisely the moment it is least confident.

The empty state says so in words instead, and the copy differs by submission type.

### 7. Self-hosted webfont, because a security claim depends on it

Instrument Sans is served from `public/fonts/` rather than Google Fonts. The content security
policy sets `font-src 'self'`, and the security write-up argues the policy can be that strict
*because the interface loads nothing third-party*. Linking Google Fonts would have required
loosening the policy and contradicted that claim, and would have disclosed every visitor's IP to
a third party on a site whose entire premise is caution. Two variable files, 41 KB for the pair.

### 8. Chrome that fails quietly for this audience is replaced

- `.form-control` gets a 2px border. A 1px hairline is fashionable and fails badly for a user who
  cannot see where to tap.
- `:focus-visible` gets an explicit 3px outline. Bootstrap's focus ring is a box-shadow, which
  some Android browsers render faintly; an outline survives that. This is a requirement for
  keyboard and switch-access users, not a nicety.
- The defanged submission is monospaced, so an address can be read character by character —
  which is the habit the interface is trying to teach.

---

## Contrast, measured rather than asserted

`$min-contrast-ratio` is left at Bootstrap's 4.5 so its automatic foreground selection cannot
silently produce an unreadable filled badge. The palette was then measured against WCAG 2.1:

| Pair | Ratio | Requirement | |
|---|---:|---|---|
| `$sc-ink` on `$sc-surface` | 16.08 | 4.5 — AA text | pass |
| `$sc-ink` on `$sc-paper` | 14.03 | 4.5 | pass |
| `$sc-ink-muted` on `$sc-surface` | 5.96 | 4.5 | pass |
| `$sc-ink-muted` on `$sc-paper-tint` | 5.43 | 4.5 | pass |
| `$sc-brand` on `$sc-surface` | 7.06 | 4.5 | pass |
| `$sc-danger` on `$sc-surface` | 7.41 | 4.5 | pass |
| `$sc-warning` on `$sc-surface` | 5.84 | 4.5 | pass |
| white on `$sc-danger` | 7.53 | 4.5 | pass |
| white on `$sc-warning` | 5.93 | 4.5 | pass |
| white on `$sc-success` / `$sc-brand` | 7.17 | 4.5 | pass |
| **`$sc-line-strong` on `$sc-surface`** | **1.57** | **3.0 — AA non-text, SC 1.4.11** | **FAIL** |

Every text pairing clears AA comfortably, including the muted secondary tone, which is the one
that usually fails in a warm palette.

### Open defect: the input border does not meet SC 1.4.11

`$sc-line-strong #d8cbb9` is `$input-border-color`, and its comment in the stylesheet reads
*"input borders, which must be findable"*. Measured against the input's own background
(`--bs-body-bg`, `#fffdfa`) it reaches **1.57:1** against a required **3.0:1**; against
`$sc-paper` it is 1.37:1. The border is 2px, which helps a sighted user with good vision and does
not satisfy the criterion — SC 1.4.11 governs the visual boundary that identifies the component.

It is recorded here as a defect rather than fixed in the same pass, because changing it is a
visible design change and the palette should move as one deliberate decision rather than as a
patch. **The audience this project names — older adults with reduced contrast sensitivity — is
exactly the group the criterion protects, so this is not a technicality.** A candidate value is
`#b9a68d`, which reaches 3.07:1 against `$sc-surface`, but it has not been evaluated in situ.

Nothing else in the palette fails. The hairline `$sc-line` at 1.32:1 is decorative — it separates
regions already distinguished by background and spacing — and decorative boundaries are out of
scope for 1.4.11.

---

## What was rejected

| Option | Why not |
|---|---|
| Bootstrap's default palette | The blue-grey ramp reads clinical, and its bright `$danger`/`$warning` make the whole page feel alarmed before anything has been judged |
| A red or orange brand accent | Rule 2. Puts a risk hue in the ordinary furniture of every page and trains the user to ignore it |
| A distinct "safe green" separate from the brand | Overclaims. The platform reports *no warning signs found*, never *safe* |
| Traffic-light colour as the primary verdict signal | Fails WCAG 1.4.1 and fails this audience. Colour is one of three redundant carriers, not the carrier |
| An illustration or mascot on the result page | Competes with the verdict for the single glance rule 1 allows, and pushes the answer below the fold at 375px |
| Google Fonts | Would have required loosening `font-src` and contradicted the security write-up |
| Hiding the indicator card when empty | Makes the interface look broken exactly when it is least confident |
| Dark mode | **Deferred, not rejected.** It doubles the contrast surface to verify and nothing above depends on it. Out of scope for the semester |

---

## Consequences

- These are testable claims, and several are already pinned: a test asserts the rendered verdict
  SVG is unaffected by submitted content, and the empty-indicator state has copy of its own in
  both locales rather than an absent section.
- **The contrast defect above is open.** It should be fixed before the demonstration, and belongs
  in Chapter 5's limitations if it is not. Do not close it by lowering `$min-contrast-ratio`.
- Any future palette change must re-run the contrast table. Those numbers were computed, not
  estimated, and a change that breaks one of them is a regression rather than a restyle.
- Dark mode, if added, is a second full contrast pass — not an inversion of these values.
