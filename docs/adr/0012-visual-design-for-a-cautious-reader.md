# ADR 0012 — The interface is designed for an anxious reader, not a browsing one

- **Status:** Accepted. Contrast defect closed 2026-09-08; **amended 2026-09-25, 2026-09-27 and 2026-09-30** — the page now hangs from a masthead (see the amendment at the end). The reasoning below is unchanged; the execution is.
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

---

## Amendment, 2026-09-27 — one green system, and reasons that point at the text

### Context

A design audit of the result page, run after the masthead amendment above,
found that the redesign had quietly undone parts of this ADR:

- **Decision 2 was reversed.** The verdict moved from Bootstrap's `.card` to the
  new `.sc-card`, which carried 1.1rem corners. The rule that made the verdict
  firmer (`.card.border-2`) still existed but matched nothing, so the verdict
  had become the *most* rounded thing on the page.
- **The page label outranked the answer.** "We checked it" was set at 28–40px
  bold white; the verdict heading beneath it was 22px. Rule 1 held in the
  document outline and failed on the screen.
- **Warning signs were marked in the "no warning" colour.** Each indicator
  carried a brand-green diamond, and since `$sc-success == $sc-brand`, a
  "Likely a scam" verdict listed its reasons in the colour of "No warning
  signs" — a direct conflict with rule 2.
- **The echo of the submission was the smallest text on the page** (12.5px
  after two nested reductions), although it is the text the reader is asked to
  check character by character.

Those four were corrected first. The redesign below was then built on the
corrected page, previewed as a clickable prototype, and adopted.

### Options considered for the colour

| Option | Trade-off |
|---|---|
| Keep the warm paper neutrals | Already measured and documented, but the page read as a green band laid over a cream page rather than one system. |
| **Green-biased neutrals under the same brand green** | One coherent system; every risk colour re-measured and still passes. Requires this amendment and new contrast values. |
| Deep teal brand (`#1d4f5a`) | Also far from red and amber, and passes AA throughout. But it is no longer green, so "No warning signs" would need its own colour and decision 1's deliberate collapse (`$sc-success == $sc-brand`) would have to be unpicked. |
| Navy or royal blue | Passes AA, but most Malaysian banks and government sites are blue, and those are the brands scam pages imitate. A blue ScamSense looks like the thing it warns about — rule 3. |
| Purple / violet | Reads as a generic technology product, and purple-to-blue is one of the most recognisable defaults of generated interfaces. Wrong register for a calm tool used by older adults. |

Teal and navy were compared against green on the live prototype before the
choice was made.

### Decision

**The brand stays green, and the neutrals follow it.** `$sc-paper`,
`$sc-paper-tint`, `$sc-surface`, `$sc-line`, `$sc-ink` and `$sc-ink-muted` now
carry a slight green bias instead of a warm one. `$sc-mint` (#9fd3bd) is added as
the accent *on* the dark band only: the brand mark and the step numbers.

**Each reason points at the words it came from.** `SubmissionExplainer` looks
for each indicator's evidence in the defanged, redacted submission. Where it is
found, the reason is numbered and the same number is marked in the reader's own
text; hovering or focusing either one lights both. Where it is not found, the
reason keeps a plain diamond and its evidence line — a number pointing at
nothing would break the promise the hint line makes. This is the product's
third step ("the reasons — so the next one you can spot yourself") made
visible rather than asserted.

**Links are taken apart.** Under a checked link, the address is split into what
comes before the host, the labels in front of the owner, the registrable domain
that says who owns it, and everything after the first single slash — each with
a one-line key. On `maybank2u.secure-verify.xyz` it shows that the owner is
`secure-verify.xyz` and that "maybank2u" is a copied name anyone could write
there. The registrable domain comes from `VerifiedDomainRegistry`, the same code
that decides whether a domain is verified, so the two cannot disagree.

**The wait is shown, but nothing is claimed during it.** On submit the box gives
way to the pasted text; its links are defanged in front of the reader, a neutral
line reads down the text, and the three existing status lines become a short
checklist. **No marks appear during the wait.** The page does not know the result
yet, and marking suspicious phrases before the answer exists would be the kind of
invented progress this ADR has rejected twice already. Defanging is shown because
it is real and deterministic.

**"Warn your family" on the high band.** A copyable message for family groups,
where Malaysian scam messages circulate. It keeps the verdict's hedge ("likely a
scam") and the defanged link, because a forwarded message is where an overclaim
or a live link would travel furthest.

**Decision 2 is restored** on the new component: the verdict and verified cards
use `$border-radius-sm` while the form card keeps its softer corners.

### Rejected or deferred

- **An "Already replied, clicked or paid?" panel** with the National Scam
  Response Centre number and bank-first advice. Useful, but deferred: the
  hotline and the advice must be confirmed against official sources before the
  platform tells a frightened reader to act on them.
- **IBM Plex Mono for the echo.** The prototype used it; production uses the
  system monospace stack instead, because the CSP is `font-src 'self'` and a
  second self-hosted family is 30–40 KB for text every phone can already render
  legibly.
- **Colouring the question word in the hero.** It would split "Is this a scam?"
  into two translation strings for one decorative colour.

### Contrast, re-measured

Computed from the Sass source by `PaletteContrastTest`.

| Pair | Ratio | Requirement | |
|---|---:|---|---|
| `$sc-ink` on `$sc-surface` | 16.07 | 4.5 | pass |
| `$sc-ink` on `$sc-paper` | 14.50 | 4.5 | pass |
| `$sc-ink-muted` on `$sc-surface` | 6.46 | 4.5 | pass |
| `$sc-ink-muted` on `$sc-paper-tint` | 6.05 | 4.5 | pass |
| `$sc-ink-muted` on `$sc-paper` | 5.83 | 4.5 | pass |
| `$sc-brand` on `$sc-surface` | 7.02 | 4.5 | pass |
| `$sc-danger` on `$sc-surface` | 7.38 | 4.5 | pass |
| `$sc-danger` on `$sc-paper` | 6.66 | 4.5 | pass |
| `$sc-warning` on `$sc-surface` | 5.81 | 4.5 | pass |
| `$sc-warning` on `$sc-paper` | 5.24 | 4.5 | pass |
| `$sc-line-strong` on `$sc-surface` | 3.91 | 3.0 — SC 1.4.11 | pass |
| `$sc-line-strong` on `$sc-paper-tint` | 3.66 | 3.0 | pass |
| `$sc-line-strong` on `$sc-paper` | 3.53 | 3.0 | pass |
| `$sc-mint` on `$sc-deep-2` | 5.94 | 4.5 | pass |

> The first green candidate for the input border, `#7d9187`, measured **2.96:1**
> on the new page ground — under the 3.0 floor by a hair, and invisible to the
> eye as a failure. It was caught by computing it before it reached
> `PaletteContrastTest`. `#708479` clears every ground. This is the third time
> in this ADR that a colour chosen by eye did not survive arithmetic.

### Consequences

- The result view receives a `SubmissionExplanation` DTO from the controller.
  Every segment of the echo is still escaped by `{{ }}`; a segment only selects
  a class, so the marks add no route for submitted content to reach the page
  unescaped. The single `{!! !!}` (the enum's icon SVG) is unchanged.
- Marks depend on the ML service returning evidence as a substring of the
  submission. If that contract changes, reasons lose their numbers rather than
  pointing at the wrong words — the safe failure.
- The reading view re-implements `DefangService`'s URL pattern in JavaScript.
  The two must change together.
- `DefangService` only recognises links that begin with a scheme or `www.`, so a
  bare `pos-semakan.xyz/update` inside a message is echoed as typed. Recorded
  here rather than changed, because widening the pattern changes what the
  security tests assert.

---

## Amendment, 2026-09-30 — the task beside the question, and a background that stays still

### Context

Asked to improve the design using the installed design skills and the Inspo
reference archive, with a preview to approve before any code changed. A
clickable mockup was built outside the repository from the real tokens, copy
and icons, reviewed at desktop and phone width, and approved ("i like it").
Animation was then added with the `animate` skill, and the user asked what
could make the background less plain.

What the audit of the live pages found:

- **Desktop wasted its first screen.** The heading sat above a full-width form
  (the textarea was ~1000px wide), and below the privacy notes the page was
  empty. At 1280x800 the button was the last thing visible.
- **The verdict heading was still only a step above its own body text** (26px
  against 19px).
- **"What to do" was grey reference**, visually unrelated to the verdict it
  belonged to, and set as one paragraph.
- **The segment control's pill and its labels ran on different timers** (380ms
  against 240ms), so mid-travel a label sat on the green in the wrong colour.

### Decisions

**The form moves up beside the heading on wide screens.** Heading, promises and
the specimen on the left; the form card on the right, lifting out of the band.
The whole task fits the first 1280x800 viewport. Heading and form are one grid
inside `<main>` (`.sc-home-top`), on a band that continues the header's (the
header is `sc-mast--flush` on this page, so the two meet without a seam). The
skip link lands on the heading, then the form. Below `lg` the band is painted
from the heading column down to just inside the card, as before.

> **The first build of this was wrong in Malay.** It kept the heading in the
> header and pulled the form up by a fixed distance, which held only while the
> left column stayed one exact height. "Adakah ini Scam?" is longer and the
> Malay promises wrap, so the heading ran under the card and the specimen was
> cropped. Caught by Shafiq on screen the same day. A shared grid sizes each
> column from its own content, in any language and at any text size.

**The verdict is two columns on wide screens**: the finding on the left, "What
to do" on the right, both in one glance. The heading is display-sized
(`clamp(1.9rem, 4.2vw, 2.75rem)`). When the verified panel owns the `h1`, the
verdict below it steps down so the two do not compete (decision 5).

**"What to do" takes the band's own tint** (`$sc-wash-danger`,
`-warning`, `-success`) with its heading in the band colour, and is shown one
instruction per line. The lines are the translation's own sentences, split in
the view: the lang files are unchanged, so the test that every band carries
both submission types still covers exactly what is shown. The verified panel's
"we checked the address, not the page" stays neutral grey: it is a caveat, and
tinting it green would read as more reassurance.

**The segment control reveals a clipped copy** of both labels, white on green,
instead of sliding a pill under labels that recolour on their own. One element
cannot drift out of step with itself. Still driven by `:checked`, still correct
on first paint, still last in the DOM (pinned by `VerifyPageTest`).

**Other surfaces:** "How it works" gets a section heading and large brand
numerals and becomes a real `<ol>` (the old `aria-label` on a `<span>` is
ignored by many screen readers); the privacy notice becomes a panel with an icon
per promise; the footer puts "If you have lost money…" in its own box and adds
About / How to use / the project line; About and How to use share the frame,
the entrance and the new numerals.

### The background

The 2026-09-25 answer stands: **no moving background.** Three static pieces,
each carrying meaning:

1. **A security-print guilloche** — the interlaced line pattern on banknotes
   and cheques, i.e. the visual language of "hard to forge". One ribbon where
   the band meets the paper, fading both ways, and a faint echo in the footer.
   Brand green only (rule 2). `public/images/guilloche.svg`, 14 KB gzipped,
   generated by `backend/resources/svg/guilloche.mjs`. Public pages only; the
   admin area gets none of it.
2. **A specimen in the home band**: two scam texts as ghosted message bubbles,
   the tell-tale phrases underlined in **mint, never red** (red on the band
   would put a risk hue in furniture). `aria-hidden`, desktop only, home page
   only (the result page stays free of illustration), bodies in Malay in both
   locales, and the link written already defanged. A test asserts all of that.
3. The existing shield in the band's corner, unchanged.

### Motion

Every addition names what it is for (the `animate` skill's gate):

| | Purpose | Ingredients |
|---|---|---|
| Home entrance, 50ms stagger, form last | Rare, first view | `sc-rise` 500ms ease-out; card `sc-lift-in` 600ms |
| "How it works" and privacy reveal | Explanation, once | Armed only if the section starts off screen; fires once |
| Segment clip | State indication | `clip-path` 250ms `$sc-ease-in-out` |
| Press on every primary button | Feedback | `scale(0.97)` 160ms `$sc-ease-out`, release on the existing spring |
| "Check it" → "Checking..." | Preventing a jarring change | Label crossfade 150ms |
| Waiting checklist | State indication | `@starting-style` rise, 50ms stagger |
| Loading rail | Constant motion | Now `linear`: an eased sweep appears to stall at each end |
| Copy → tick | Feedback | Icon crossfade 200ms, transitions not keyframes |

Hover effects are gated to `(hover: hover) and (pointer: fine)`. All of it is
off under `prefers-reduced-motion`, as the rest of the site's motion is.
Rejected: movement on the reasons or marked words on hover (text being read
does not move), and any animated background.

### Contrast, measured

The three tints were computed before use and are asserted by
`PaletteContrastTest`:

| Pair | Ratio |
|---|---:|
| `$sc-ink` on `$sc-wash-danger` | 14.20 |
| `$sc-danger` on `$sc-wash-danger` | 6.52 |
| `$sc-ink` on `$sc-wash-warning` | 14.47 |
| `$sc-warning` on `$sc-wash-warning` | 5.23 |
| `$sc-ink` on `$sc-wash-success` | 14.39 |
| `$sc-success` / `$sc-brand` on `$sc-wash-success` | 6.29 |
| `$sc-brand` on `$sc-paper` (step numerals) | 6.34 |
| `$sc-mint` on `$sc-deep-2` (eyebrow) | 5.94 |

### Consequences

- No new unescaped output. The segment icons are literal SVG and the paste tip
  uses quotation marks rather than bold, so the enum icon on the result page
  stays the only `{!! !!}` in the application.
- Seven new English strings, translated in `lang/ms.json`. **The Malay is a
  first draft for Shafiq's review.** The paste tip names the phone's own menu
  words, "Salin" and "Tampal", which is deliberate even though the site itself
  now says "letakkan".
- Not verified on a physical phone. Headless Edge cannot lay out narrower than
  496px, so the narrowest checked width is 496px; the 390px layout was checked
  on the mockup, which uses the same breakpoints.

### Later the same day: How to use drawn, "Warn your family" removed

- **How to use now shows each step.** Every instruction is paired with a small
  drawing of the real control (the Message / Link choice, the box with the
  phone's own Copy / Paste menu, the button, a result), built from the site's
  tokens and strings rather than screenshots, so it follows the language switch
  and the design. The address section draws both examples with the result
  page's marks (owner boxed, copied name wavy-underlined), reusing the result
  page's own legend strings. "Lost money" is three cards with 997 set large.
  Previewed as a mockup and approved before it was built.
- **"Warn your family" is removed from the high band** (Shafiq's decision:
  not needed). The view section, its clipboard script, its styles and its
  strings in both locales are gone, so nothing is left half-wired. This
  supersedes that part of the 2026-09-27 amendment.
- One test (`defangs urls embedded inside a message`) had been passing only
  because the removed bubble repeated the link unbroken. It now reads the
  echo's visible text, which is what it always meant to check.
- Last design change before deployment. A dead-code pass followed: every `sc-`
  class, keyframe and palette variable in `app.scss` is now used by a view or
  the script; four rules that matched nothing were deleted and a duplicated
  `.sc-bar` merged.
