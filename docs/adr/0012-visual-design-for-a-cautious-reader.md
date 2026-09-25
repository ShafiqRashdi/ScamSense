# ADR 0012 — The interface is designed for an anxious reader, not a browsing one

- **Status:** Accepted. Contrast defect closed 2026-09-08; **amended 2026-09-25** — the page now hangs from a masthead (see the amendment at the end). The reasoning below is unchanged; the execution is.
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
| `$sc-line-strong` on `$sc-surface` | 3.73 | 3.0 — AA non-text, SC 1.4.11 | pass *(was 1.57, see below)* |
| `$sc-line-strong` on `$sc-paper` | 3.25 | 3.0 | pass |
| `$sc-line-strong` on `$sc-paper-tint` | 3.40 | 3.0 | pass |

Every text pairing clears AA comfortably, including the muted secondary tone, which is the one
that usually fails in a warm palette.

### Closed 2026-09-08: the input border now meets SC 1.4.11

As first written, `$sc-line-strong` was `#d8cbb9` and its comment read *"input borders, which
must be findable"*. Measured against the input's own background (`--bs-body-bg`, `#fffdfa`) it
reached **1.57:1** against a required **3.0:1** — a hairline that looked tasteful on a designer's
monitor and disappeared for exactly the reduced-contrast-sensitivity audience the criterion
exists to protect. The 2px border helps a sighted user with good vision and does not satisfy
SC 1.4.11, which governs the visual boundary that identifies the component.

It is now `#96805f`: **3.73:1** on `$sc-surface`, 3.40:1 on `$sc-paper-tint` and 3.25:1 on
`$sc-paper`. Chosen against every surface an input could sit on rather than only the one it sits
on today, so moving a form into a tinted panel cannot quietly reintroduce the defect.

> **A candidate value of `#b9a68d` was named in the first draft of this ADR as reaching 3.07:1.
> That figure was wrong — it measures 2.32:1 and would not have fixed anything.** It was caught
> by recomputing before applying it rather than by trusting the note. The table above is now
> enforced by `backend/tests/Feature/PaletteContrastTest.php`, which reads the Sass variables and
> does the arithmetic, so no future value can be adopted on the strength of a remembered number.

`$sc-line-strong` is used for nothing but `$input-border-color`, so the change is confined to
form controls.

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
- **The contrast table is enforced, not documented.** `PaletteContrastTest` reads the Sass
  variables and recomputes every pair, so a palette change that breaks one is a failing test
  rather than a restyle nobody noticed. It also covers the focus outline, which is the only thing
  telling a keyboard or switch-access user where they are.
- Do not satisfy that test by lowering `$min-contrast-ratio`; it asserts the WCAG thresholds
  directly and is unaffected by the Sass setting.
- Dark mode, if added, is a second full contrast pass — not an inversion of these values.

---

## Amendment, 2026-09-25 — the masthead

The reasoning in this ADR was right and the execution was not. Asked to look at
the design with a presentation approaching, the verdict was "it's very boring" —
and on inspection that was a fair reading of what was built rather than of what
was decided.

**Restraint and dullness are not the same thing, and this page had the costs of
restraint without the confidence.** Specifically:

- No identity at all. The brand was the word "ScamSense" in semibold text.
- No display tier. `verify/index` rendered its `h1` at `.h3` — about 25px — so
  the product's entire proposition was sized like a section heading.
- Depth encoded nothing. The form card and the privacy notice carried the same
  `shadow-sm`, although one is the thing you must use and the other is
  reference.
- The brand colour appeared twice on the whole page.

Nothing above was required by the rules in this document. **Rule 2 says nothing
may compete with a risk signal; it never said the page may not have a face.**

### What changed

The page now hangs from a **deep green masthead** (`$sc-deep-2 #1f4a3e`), and
the content card **breaks out of it** with a negative margin. A dark footer
(`$sc-deep #173a30`) bookends the page. Everything between them stays on warm
paper.

That single move does most of the work: it gives the page two planes instead of
one, it gives the brand somewhere to live, and it makes the card's elevation
mean something because it is lifting off a visible surface.

Also: a real display tier (`clamp()`, `-0.04em` tracking, 0.98 leading — large
type needs *negative* tracking, which the old fixed scale did not have); a
shield-and-check mark as inline SVG; a numbered three-step band that teaches
rather than decorates; and indicator rows that carry a drawn mark instead of a
bullet character.

**The dark is confined to those two bands.** That is what keeps rule 2 intact:
the risk colours are still the loudest thing on any page, because they are the
only saturated colour anywhere near the content.

### The grain, and what was rejected

Asked for "a background effect", the answer was **no** to an animated one and
**yes** to a static one. A moving background is decoration with no meaning, it
costs frames and battery on the mid-range Android this targets, and a drifting
gradient is precisely the register a well-made phishing page uses.

Instead the masthead carries **fine fractal noise at 50% opacity in overlay
blend** — one static SVG, zero frames. It gives the green the quality of printed
stock rather than flat ink. That is craft; movement would have been motion for
its own sake.

### Motion

Four additions, each answering a question rather than filling a gap:

| | |
|---|---|
| **Sliding pill** on the message/link control | Teaches that the two are alternatives. Interruptible by construction — a `transform` transition re-targets from wherever the pill is, rather than jumping, which the `apple-design` reference calls the single most important principle in motion |
| **Spring press** on the primary action | `linear()` curve, ~2.5% overshoot. Physical, not bouncy — which is the line this ADR draws |
| **The rule draws** across the verdict card as it lands | The only motion on the site carrying *meaning*: the risk colour arrives as a decision being made rather than a bar that was always there |
| **The wait folds into the button** | A rail along its bottom edge and a crossfading label, instead of a spinner and a separate progress panel competing for one moment |

All of it is `transform`, `opacity` or `filter`. **Nothing moves layout** — a
control that shifts under a finger mid-tap is worse than no motion at all. Every
piece is switched off under `prefers-reduced-motion`, and the arrival animations
declare their starting state *only inside the keyframes*, so a card whose
animation never runs is fully visible.

### Contrast, re-measured

The original table covered only ink-on-paper. The masthead inverts that
relationship, and a tone that looks fine on cream can fail badly reversed — so
`PaletteContrastTest` was extended to cover it:

| Pair | Ratio | |
|---|---:|---|
| white on `$sc-deep-2` | 9.97 | pass |
| `$sc-on-deep` on `$sc-deep-2` | 8.67 | pass |
| `$sc-on-deep-muted` on `$sc-deep-2` | 6.05 | pass |
| white on `$sc-deep` | 12.47 | pass |
| `$sc-on-deep-muted` on `$sc-deep` | 7.57 | pass |
| `$sc-deep-2` on white (language pill) | 9.97 | pass |

> The mockup used `#a9c4b9` as the muted tone on green. It measures **5.35:1** —
> passing, but with little room, and it would have been adopted for looking
> right rather than measuring right. `#b7cfc5` reaches 6.05:1 and was chosen by
> computing both. This is the second time in this ADR that a colour picked by
> eye did not survive arithmetic.

### Consequences

- The layout yields a `hero` section into the band, so each page supplies its
  own and the verdict page can be shallower than the landing page without a
  second layout.
- **The result page's masthead line is a `<p>`, not an `h1`.** Rule 5 keeps one
  `h1` per document and it belongs to the strongest claim on the page — the
  verified panel's, or failing that the verdict's. "We checked it" is a page
  label; making it a heading would demote the answer to second place in the
  outline. Asserted by the existing tests.
- The admin area borrows the brand surface for its navigation bar **and nothing
  else**. It is a tool: a hero band above a moderation queue would be decoration
  where density is the point.
- The segmented control keeps real radio inputs underneath and positions the
  pill from `:checked` in CSS, so it is correct on first paint and submits with
  no JavaScript at all.
